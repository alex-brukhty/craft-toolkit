<?php

namespace alexbrukhty\crafttoolkit\jobs;

use alexbrukhty\crafttoolkit\Toolkit;
use craft\errors\SiteNotFoundException;
use craft\queue\BaseJob;
use vipnytt\SitemapParser\Exceptions\SitemapParserException;
use yii\queue\RetryableJobInterface;

class WarmCacheJob extends BaseJob implements RetryableJobInterface
{
    public function getTtr(): int
    {
        return 600;
    }

    public function canRetry($attempt, $error): bool
    {
        return true;
    }

    /**
     * @throws SiteNotFoundException
     * @throws SitemapParserException
     */
    public function execute($queue): void
    {
        $urls = Toolkit::getInstance()->cacheService->getUrlsToWarm();
        $chunks = array_chunk($urls, 10);
        $progress = 0;
        foreach ($chunks as $chunk) {
            $progress = $progress + count($chunk);
            $this->setProgress($queue, $progress / count($urls), "$progress of ".count($urls));
            Toolkit::getInstance()->cacheService->warmUrls($chunk)->wait();
        }
    }

    public function getDescription(): string
    {
        return 'Warming Urls';
    }
}
