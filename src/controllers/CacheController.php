<?php

namespace alexbrukhty\crafttoolkit\controllers;

use alexbrukhty\crafttoolkit\services\CacheService;
use craft\web\Controller;

class CacheController extends Controller
{
    public $defaultAction = 'index';

    public function actionIndex()
    {
        $cacheService = new CacheService();
        $cacheService->clearAllCache();

        return $this->asSuccess('Cache cleared');
    }
}
