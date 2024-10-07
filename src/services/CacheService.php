<?php

namespace alexbrukhty\crafttoolkit\services;

use alexbrukhty\crafttoolkit\Toolkit;
use Craft;
use craft\base\Element;
use craft\events\ElementEvent;
use craft\events\MultiElementActionEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\services\Elements;
use craft\utilities\ClearCaches;
use craft\web\Response;
use yii\base\ErrorException;
use yii\base\Event;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\web\Response as ResponseAlias;

class CacheService
{
    private int $countCachedFiles = 0;
    private bool $enabled;
    private array $includePattern;
    private array $excludePattern;
    private string $siteUrl;
    public string $cacheBasePath;

    public function __construct() {
        $this->enabled = Toolkit::getInstance()->getSettings()->cacheEnabled ?? false;
        $this->includePattern = Toolkit::getInstance()->getSettings()->cacheInclude ?? [];
        $this->excludePattern = Toolkit::getInstance()->getSettings()->cacheExclude ?? [];
        $this->siteUrl = Craft::$app->getSites()->currentSite->baseUrl;

        $cacheBasePath = Toolkit::getInstance()->getSettings()->cacheBasePath ?? '@webroot/static';
        $this->cacheBasePath = FileHelper::normalizePath(App::parseEnv($cacheBasePath));
    }

    public function registerEvents(): void
    {
        if ($this->enabled) {
            Event::on(Response::class, ResponseAlias::EVENT_AFTER_PREPARE,
                function(Event $event) {
                    /** @var Response $response */
                    $request = Craft::$app->getRequest();
                    $response = $event->sender;
                    $uri = $request->getFullUri();

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

                    if (
                        $this->excludePattern
                        && $this->matchesUriPatterns(
                            $uri,
                            $this->excludePattern
                        )
                    ) {
                        return;
                    }

                    if (
                        $this->includePattern
                        && !$this->matchesUriPatterns(
                            $uri,
                            $this->includePattern
                        )
                    ) {
                        return;
                    }

                    if (
                        $this->matchesUriPatterns(
                            $uri,
                            ['sitemap.xml']
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
                    function(ElementEvent|MultiElementActionEvent $event) {
                        /** @var Element $element */
                        // $element = $event->element;

                        // TODO: this is temp
                        $this->clearAllCache();
                    }
                );
            }
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

    private function cacheFilePath(string $uri): string
    {
        $uriIsFile = str_contains($uri, '.');
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

    public function getCachedPageCount(string $path): int
    {
        if (!$this->countCachedFiles) {
            return 0;
        }

        if (!is_dir($path)) {
            return 0;
        }

        return count(FileHelper::findFiles($path, [
            'only' => ['index.html'],
        ]));
    }
}
