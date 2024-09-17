<?php

namespace modules\toolkit\console\controllers;

use modules\toolkit\services\CacheService;
use yii\console\Controller;
use yii\console\ExitCode;

class CacheController extends Controller
{
    public $queue = false;

    public $defaultAction = 'index';

    public function actionIndex(): int
    {
        CacheService::clearAllCache();

        return ExitCode::OK;
    }
}
