<?php

namespace alexbrukhty\crafttoolkit\controllers;

use alexbrukhty\crafttoolkit\Toolkit;
use craft\errors\SiteNotFoundException;
use craft\web\Controller;
use vipnytt\SitemapParser\Exceptions\SitemapParserException;

class CacheController extends Controller
{
    public $defaultAction = 'index';

    public function actionIndex()
    {
        Toolkit::getInstance()->cacheService->clearAllCache();
        return $this->asSuccess('Cache cleared');
    }

    /**
     * @throws SiteNotFoundException
     * @throws SitemapParserException
     */
    public function actionWarm()
    {
        if (Toolkit::getInstance()->cacheService->warmUrlsJob()) {
            return $this->asSuccess('Warming started');
        }
        return $this->asSuccess('Warming already running');
    }
}
