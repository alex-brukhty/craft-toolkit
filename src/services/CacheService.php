<?php

namespace alexbrukhty\crafttoolkit\services;

use alexbrukhty\crafttoolkit\Toolkit;
use alexbrukhty\crafttoolkit\utilities\CacheUtility;
use alexbrukhty\crafttoolkit\models\Settings;
use Craft;
use craft\base\Element;
use craft\elements\Entry;
use craft\elements\Asset;
use craft\errors\SiteNotFoundException;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\App;
use craft\helpers\ElementHelper;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;
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
use yii\web\Response as ResponseAlias;
use Illuminate\Support\Collection;

class CacheService
{
    private bool $enabled;
    private string $cacheBasePath;
    private string $siteUrl;

    public function __construct() {
        $this->enabled = $this->getSettings()->cacheEnabled ?? false;
        $this->siteUrl = Craft::$app->getSites()->currentSite->baseUrl;
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
                    $siteId = Craft::$app->getSites()->getCurrentSite()->id;

                    if (in_array($siteId, $this->getSettings()->excludeSiteIds ?? [], true)) {
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

                    $this->saveCache($response->content, $uri);
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
                        if (
                            !ElementHelper::isDraftOrRevision($element)
                            && ($element::class === Entry::class
                                || $element::class === 'craft\\commerce\\elements\\Product'
                                || $element::class === Asset::class)
                        ) {
                            $this->clearCacheByElement($element);
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

    private function cacheFilePath(string $url): string
    {
        $uri = UrlHelper::rootRelativeUrl($url);
        $uriIsFile = str_contains($uri, '.');
        $uri = str_replace('__home__', '',  $uri);
        $uri = str_replace($this->siteUrl, '',  $uri);
        $siteHostPath = preg_replace('/^(http|https):\/\//i', '', $this->siteUrl);

        return FileHelper::normalizePath($this->cacheBasePath . DIRECTORY_SEPARATOR . $siteHostPath . DIRECTORY_SEPARATOR . ($uriIsFile ? $uri : $uri.'/index.html'));
    }

    private function saveCache(string $content, string $uri): void
    {
        try {
            if (!in_array($uri, ['site.webmanifest', 'robots.txt'])) {
                $content .= '<!-- Cached on ' . date('c') . ' -->';
            }
            $path = $this->cacheFilePath($uri);
            FileHelper::writeToFile($path, $content);
        } catch (Exception|ErrorException|InvalidArgumentException $exception) {
            Craft::$app->log->logger->log($exception->getMessage(), 'warning', 'static-cache');
        }
    }

    public function clearCacheByUrls($urls = []): void
    {
        // $this->writeLog(implode(PHP_EOL, $urs));
        foreach ($urls as $url) {
            $path = $this->cacheFilePath($url);
            if ($path) {
                $this->delete($path);
            }
        }

        Toolkit::getInstance()->cloudflareService->purgeUrls($urls);
    }

    public function clearCacheByElement(Element $element): void
    {
        $urls = Collection::make();
        $productElementClass = 'craft\\commerce\\elements\\Product';
        /* @var Element|null $productElement */
        $productElement = class_exists($productElementClass) ? $productElementClass : null;
        $cacheRelations = $this->getSettings()->cacheRelations;

        if (count($cacheRelations) > 0) {
            if ($element::class === Asset::class) {
                $entries = Entry::find()->relatedTo($element)->collect();
                $products = $productElement ? $productElement::find()->relatedTo($element)->collect() : Collection::make();
                foreach ($entries->merge($products)->all() as $entry) {
                    $url = $entry->getRootOwner()->uri;
                    $urls->put($entry->id, $url);
                };
            } else {
                $entry = $element->getRootOwner();
                $urls->put($entry->id, $entry->url);

                $handle = $entry->section->handle ?? ($entry->type->handle ?? null);
                if ($handle && isset($cacheRelations[$handle])) {
                    $entries = Entry::find()->section($cacheRelations[$handle])->collect();
                    $products = $productElement ? $productElement::find()->type($cacheRelations[$handle])->collect() : Collection::make();
                    foreach ($entries->merge($products)->all() as $entry) {
                        $urls->put($entry->id, $entry->url);
                    };
                }
            }

            $this->clearCacheByUrls($urls->all());
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
            $site = Craft::$app->sites->getSiteById($id);
            if (!$url = $site->getBaseUrl()) {
                continue;
            }
            $parser->parseRecursive($url.$settings->sitemapUrl);
            $data = array_merge([$url], array_keys($parser->getUrls()));
        }

        return $data;
    }

    public function warmUrls($urls, $concurrent = 10): Promise
    {
        $warmer = new Warmer($concurrent);
        $warmer->addUrls($urls);
        return $warmer->warm();
    }

    public function writeLog($message)
    {
        $folder = FileHelper::normalizePath(App::parseEnv('@webroot/logs'));
        $timestamp = time();
        $path = "$folder/cache-$timestamp.txt";
        FileHelper::writeToFile($path, $message);
    }
}
