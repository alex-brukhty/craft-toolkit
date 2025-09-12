<?php

namespace alexbrukhty\crafttoolkit\console\controllers;

use alexbrukhty\crafttoolkit\Toolkit;
use craft\errors\SiteNotFoundException;
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
        if (Toolkit::getInstance()->cacheService->warmUrlsJob()) {
            $this->stdout('Warming Cache' . PHP_EOL, BaseConsole::FG_YELLOW);
        } else {
            $this->stdout('Warming Cache already running' . PHP_EOL, BaseConsole::FG_YELLOW);
        }
        return ExitCode::OK;
    }

    public function actionPurgeCloudflare()
    {
        Toolkit::getInstance()->cloudflareService->purgeAllPage();
        return ExitCode::OK;
    }
}
