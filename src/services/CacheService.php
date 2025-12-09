<?php

namespace alexbrukhty\crafttoolkit\services;

use alexbrukhty\crafttoolkit\jobs\ClearCacheJob;
use alexbrukhty\crafttoolkit\jobs\WarmCacheJob;
use alexbrukhty\crafttoolkit\Toolkit;
use alexbrukhty\crafttoolkit\utilities\CacheUtility;
use alexbrukhty\crafttoolkit\models\Settings;
use Craft;
use craft\base\Element;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\Asset;
use craft\errors\SiteNotFoundException;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\App;
use craft\helpers\ElementHelper;
use craft\helpers\FileHelper;
use craft\helpers\Queue;
use craft\services\Elements;
use craft\services\Utilities;
use craft\utilities\ClearCaches;
use craft\web\Response;
use GuzzleHttp\Promise\Promise;
use Ryssbowh\PhpCacheWarmer\Warmer;
use vipnytt\SitemapParser;
use vipnytt\SitemapParser\Exceptions\SitemapParserException;
use yii\base\ErrorException;
use yii\base\Event;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\db\Query;
use yii\web\Response as ResponseAlias;
use Illuminate\Support\Collection;

class CacheService
{
    private bool $enabled;
    private string $cacheBasePath;

    public function __construct() {
        $this->enabled = $this->getSettings()->cacheEnabled ?? false;
        $cacheBasePath = $this->getSettings()->cacheBasePath ?? '@webroot/static';
        $this->cacheBasePath = FileHelper::normalizePath(App::parseEnv($cacheBasePath));
    }

    public function getSettings(): Settings
    {
        return Toolkit::getInstance()->getSettings();
    }

    public function registerEvents(): void
    {
        if ($this->enabled) {
            Event::on(Response::class, ResponseAlias::EVENT_AFTER_SEND,
                function(Event $event) {
                    /** @var Response $response */
                    $request = Craft::$app->getRequest();
                    $response = $event->sender;

                    if (!$request->getIsSiteRequest()
                        || $request->getQueryString()
                        || !$request->getIsGet()
                        || $request->getIsConsoleRequest()
                        || $request->getIsActionRequest()
                        || $request->getIsPreview()
                    ) {
                        return;
                    }

                    if ($response === null || $response->content === null) {
                        return;
                    }

                    if ($response->getIsOk() === false) {
                        return;
                    }

                    if ($response->format !== ResponseAlias::FORMAT_HTML
                        && $response->format !== 'template') {
                        return;
                    }

                    if (!$this->enabled) {
                        return;
                    }

                    $uri = $request->getFullUri();
                    $site = Craft::$app->getSites()->getCurrentSite();

                    if (in_array($site->id, $this->getSettings()->excludeSiteIds ?? [], true)) {
                        return;
                    }

                    $excludePattern = $this->getSettings()->cacheExclude ?? [];

                    if (
                        count($excludePattern)
                        && $this->matchesUriPatterns(
                            $uri,
                            $excludePattern
                        )
                    ) {
                        return;
                    }

                    $includePattern = $this->getSettings()->cacheInclude ?? [];

                    if (
                        count($includePattern)
                        && !$this->matchesUriPatterns(
                            $uri,
                            $includePattern
                        )
                    ) {
                        return;
                    }

                    $this->saveCache($response->content, $request->getAbsoluteUrl(), $site->baseUrl);
                },
                append: false,
            );

            $events = [
                Elements::EVENT_BEFORE_SAVE_ELEMENT,
                Elements::EVENT_BEFORE_RESAVE_ELEMENT,
                Elements::EVENT_BEFORE_UPDATE_SLUG_AND_URI,
                Elements::EVENT_BEFORE_DELETE_ELEMENT,
                Elements::EVENT_BEFORE_RESTORE_ELEMENT,
            ];

            foreach ($events as $event) {
                Event::on(Elements::class, $event,
                    function(mixed $event) {
                        /** @var Element $element */
                        $element = $event->element;
                        $cacheRelations = $this->getSettings()->cacheRelations;

                        if (
                            !ElementHelper::isDraftOrRevision($element)
                            && !in_array($element->siteId, $this->getSettings()->excludeSiteIds ?? [], true)
                            && (count($cacheRelations) || $element->url)
                            && (
                                $element::class === Entry::class
                                || $element::class === 'craft\\commerce\\elements\\Product'
                                || $element::class === 'craft\\shopify\\elements\\Product'
                                || $element::class === Asset::class
                            )
                        ) {
                            $mutex = Craft::$app->getMutex();
                            $lockKey = count($cacheRelations) ? 'clear:'.$element->id : 'clear:all';
                            if ($mutex->acquire($lockKey)) {
                                Queue::push(new ClearCacheJob([
                                    'elementId' => $element->id,
                                    'elementClass' => $element::class,
                                    'mutexKey' => $lockKey,
                                ]));
                            }
                        }
                    }
                );
            }

        }

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES,
                function(RegisterComponentTypesEvent $event) {
                    $event->types[] = CacheUtility::class;
                }
            );
        }
    }

    public function registerClearCaches(): void
    {
        Event::on(ClearCaches::class, ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            function(RegisterCacheOptionsEvent $event) {
                $event->options[] = [
                    'key' => '_toolkit',
                    'label' => 'Static HTML Cache',
                    'action' => [$this, 'clearAllCache'],
                ];
            }
        );
    }

    public function matchesUriPatterns(string $uri, array $siteUriPatterns): bool
    {

        foreach ($siteUriPatterns as $uriPattern) {
            // Replace a blank string with the homepage with query strings allowed
            if ($uriPattern == '') {
                $uriPattern = '^(\?.*)?$';
            }

            // Replace "*" with 0 or more characters as otherwise it'll throw an error
            if ($uriPattern == '*') {
                $uriPattern = '.*';
            }

            // Trim slashes
            $uriPattern = trim($uriPattern, '/');

            // Escape delimiters, removing already escaped delimiters first
            // https://github.com/putyourlightson/craft-blitz/issues/261
            $uriPattern = str_replace(['\/', '/'], ['/', '\/'], $uriPattern);

            if (preg_match('/' . $uriPattern . '/', trim($uri, '/'))) {
                return true;
            }
        }

        return false;
    }

    private function cacheFilePath(string $uri, $siteUrl = ''): string
    {
        $url = str_replace($siteUrl, '', $uri);
        $urlIsFile = str_contains($url, '.');
        $host = preg_replace('/^(http|https):\/\//i', '', $siteUrl);
        return FileHelper::normalizePath($this->cacheBasePath . DIRECTORY_SEPARATOR . $host . DIRECTORY_SEPARATOR . ($urlIsFile ? $url : $url.'/index.html'));
    }

    private function saveCache(string $content, string $url, $baseUrl): void
    {
        $uri = str_replace($baseUrl, '', $url);
        $uri = str_replace('__home__', '',  $uri);
        $urlIsFile = str_contains($uri, '.');

        try {
            if (!$urlIsFile) {
                $content .= '<!-- Cached on ' . date('c') . ' -->';
            }
            $path = $this->cacheFilePath($uri, $baseUrl);
            FileHelper::writeToFile($path, $content);
        } catch (Exception|ErrorException|InvalidArgumentException $exception) {
            Craft::$app->log->logger->log($exception->getMessage(), 'warning', 'static-cache');
        }
    }

    /**
     * @throws ErrorException
     */
    public function clearCacheByUrls($urls = [], $siteUrl = ''): void
    {
        $siteUrl = $siteUrl ?? Craft::$app->getSites()->getSiteById(1)->baseUrl;
        foreach ($urls as $url => $siteId) {
            if ($url) {
                $url = str_replace('__home__', '',  $url);
                $path = $this->cacheFilePath($url, $siteUrl);
                if ($path) {
                    $this->delete($path);
                }
            }
        }

        $this->deletePaginationPages();
        Toolkit::getInstance()->cloudflareService->purgeUrls(array_keys($urls));
    }

    /**
     * @throws Exception|ErrorException
     */
    public function clearCacheByElement(Element $element): void
    {
        $urls = Collection::make();
        $site = Craft::$app->getSites()->getSiteById($element->siteId);
        $productElementClass = 'craft\\commerce\\elements\\Product';
        $shopifyProductElementClass = 'craft\\shopify\\elements\\Product';
        /* @var Element|null $productElement */
        $productElement = class_exists($productElementClass) ? $productElementClass : null;
        /* @var Element|null $shopifyProductElement */
        $shopifyProductElement = class_exists($shopifyProductElementClass) ? $shopifyProductElementClass : null;
        $cacheRelations = $this->getSettings()->cacheRelations;

        if (count($cacheRelations) > 0) {
            $entries = Entry::find()->relatedTo($element)->collect();
            $products = $productElement ? $productElement::find()->relatedTo($element)->collect() : Collection::make();
            $shopifyProducts = $shopifyProductElement ? $shopifyProductElement::find()->relatedTo($element)->collect() : Collection::make();
            foreach ($entries->merge($products)->merge($shopifyProducts)->all() as $e) {
                if ($e->url) {
                    $urls->put($e->url, $element->siteId);
                }
            }
            if ($element::class !== Asset::class) {
                $url = $element->getRootOwner()->url ?? $element->url;
                if ($url) {
                    $urls->put($url, $element->siteId);
                }

                $handle = $element::class === $shopifyProductElementClass ? 'shopifyProduct' : ($element->section->handle ?? ($element->type->handle ?? null));
                if ($handle && isset($cacheRelations[$handle])) {
                    if ($cacheRelations[$handle] === 'all') {
                        $this->clearAllCache();
                        return;
                    }

                    $handles = array_filter($cacheRelations[$handle], fn ($item) => !str_starts_with($item, '/'));
                    $uris = array_filter($cacheRelations[$handle], fn ($item) => str_starts_with($item, '/'));
                    $entries = Entry::find()->section($handles)->collect();
                    $products = $productElement ? $productElement::find()->type($cacheRelations[$handle])->collect() : Collection::make();

                    foreach ($entries->merge($products)->all() as $entry) {
                        if ($entry->url) {
                            $urls->put($entry->url, $element->siteId);
                        }
                    }

                    foreach ($uris as $uri) {
                        $urls->put($uri, $element->siteId);
                    }
                }
            }

            $this->clearCacheByUrls($urls->all(), $site->baseUrl);
        } else {
            $this->clearAllCache();
        }
    }

    public function clearAllCache(): void
    {
        try {
            FileHelper::removeDirectory($this->cacheBasePath);
        } catch (ErrorException $exception) {
            Craft::$app->log->logger->log($exception->getMessage(), 'warning', 'static-cache');
        }
    }

    private function delete(string $filePath): void
    {
        FileHelper::unlink($filePath);
    }

    public function getCachedPageCount(): int
    {
        $path = $this->cacheBasePath;
        if (!is_dir($path)) {
            return 0;
        }

        return count(FileHelper::findFiles($path, [
//            'except' => [some . '/'],
            'only' => ['index.html'],
        ]));
    }

    /**
     * @throws ErrorException
     */
    public function deletePaginationPages(): int
    {
        $path = $this->cacheBasePath;
        if (!is_dir($path)) {
            return 0;
        }
        $pages = FileHelper::findDirectories($path);
        $pages = array_filter($pages, function ($p) {
            return preg_match('#/p\d+$#', $p) === 1;
        });
        foreach ($pages as $page) {
            FileHelper::removeDirectory($page);
        }

        return count($pages);
    }

    /**
     * @throws SiteNotFoundException
     * @throws SitemapParserException
     */
    public function getUrlsToWarm(): array
    {
        $settings = $this->getSettings();
        $parser = new SitemapParser(SitemapParser::DEFAULT_USER_AGENT);
        $data = [];
        $sitesIds = count($settings->warmSiteIds) > 0 ? $settings->warmSiteIds : [Craft::$app->sites->getCurrentSite()->id];
        foreach ($sitesIds as $id) {
            if (!$url = Craft::$app->sites->getSiteById($id)->getBaseUrl()) {
                continue;
            }
            $parser->parseRecursive($url.$settings->sitemapUrl);
            $data = array_merge($data, array_keys($parser->getUrls()));
        }

        return $data;
    }

    public function warmUrls($urls, $concurrent = 10): Promise
    {
        $warmer = new Warmer($concurrent);
        $warmer->addUrls($urls);
        return $warmer->warm();
    }

    public function warmUrlsJob()
    {
        $jobIsRunning = Collection::make(
                (new Query())
                    ->select(['description'])
                    ->from([Table::QUEUE])
                    ->cache(0)
                    ->all()
            )->filter(fn($el) => str_contains($el['description'], 'Warming Urls'))->count() > 1;

        if (!$jobIsRunning) {
            Queue::push(new WarmCacheJob([
                'description' => 'Warming Urls',
            ]));
            return false;
        }
        return true;
    }

    /**
     * @throws Exception
     * @throws ErrorException
     */
    public function writeLog($message): void
    {
        $folder = FileHelper::normalizePath(App::parseEnv('@webroot'));
        $path = "$folder/log.txt";
        $read = file_get_contents($path) ?? '';
        FileHelper::writeToFile($path, $read.PHP_EOL.$message);
    }
}
