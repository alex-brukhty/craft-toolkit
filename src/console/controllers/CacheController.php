<?php

namespace AlexBrukhty\CraftToolkit\console\controllers;

use AlexBrukhty\CraftToolkit\services\CacheService;
use yii\console\Controller;
use yii\console\ExitCode;

class CacheController extends Controller
{
    public $queue = false;

    public $defaultAction = 'index';

    public function actionIndex(): int
    {
        $cache = new CacheService();
        $cache->clearAllCache();

        return ExitCode::OK;
    }
}
