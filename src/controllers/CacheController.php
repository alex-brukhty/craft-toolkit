<?php

namespace alexbrukhty\crafttoolkit\controllers;

use alexbrukhty\crafttoolkit\jobs\WarmCacheJob;
use alexbrukhty\crafttoolkit\Toolkit;
use craft\errors\SiteNotFoundException;
use craft\helpers\Queue;
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
        $cacheService = Toolkit::getInstance()->cacheService;
        $urls = $cacheService->getUrlsToWarm();
        Queue::push(new WarmCacheJob([
            'urls' => $cacheService->getUrlsToWarm(),
        ]));

        return $this->asSuccess('Added to queue '.count($urls).' urls');
    }
}
