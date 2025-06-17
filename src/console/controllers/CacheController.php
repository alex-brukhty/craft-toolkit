<?php

namespace alexbrukhty\crafttoolkit\console\controllers;

use alexbrukhty\crafttoolkit\Toolkit;
use craft\errors\SiteNotFoundException;
use craft\helpers\Console;
use vipnytt\SitemapParser\Exceptions\SitemapParserException;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\BaseConsole;

class CacheController extends Controller
{
    public $queue = false;

    public $defaultAction = 'index';

    public function actionIndex(): int
    {
        Toolkit::getInstance()->cacheService->clearAllCache();
        $this->stdout('Cache cleared' . PHP_EOL, BaseConsole::FG_YELLOW);
        return ExitCode::OK;
    }

    /**
     * @throws SiteNotFoundException
     * @throws SitemapParserException
     */
    public function actionWarm(): int
    {
        $this->stdout('Warming Cache' . PHP_EOL, BaseConsole::FG_YELLOW);

        $cacheService = Toolkit::getInstance()->cacheService;
        $cacheService->clearAllCache();
        $urls = $cacheService->getUrlsToWarm();
        $urlsCount = count($urls);
        $chunks = array_chunk($urls, 10);
        $progress = 0;
        Console::startProgress(0, $urlsCount, '', 0.8);

        foreach ($chunks as $chunk) {
            $progress = $progress + count($chunk);
            Console::updateProgress($progress, $urlsCount);
            $cacheService->warmUrls($chunk)->wait(true);
        }

        if ($progress === $urlsCount) {
            Console::endProgress();
        }

        return ExitCode::OK;
    }

    public function actionPurgeCloudflare()
    {
        Toolkit::getInstance()->cloudflareService->purgeAllPage();
        return ExitCode::OK;
    }
}
