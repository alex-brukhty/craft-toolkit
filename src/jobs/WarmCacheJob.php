<?php

namespace alexbrukhty\crafttoolkit\jobs;

use craft\base\Batchable;
use craft\queue\BaseBatchedJob;
use alexbrukhty\crafttoolkit\helpers\DataBatcher;
use alexbrukhty\crafttoolkit\Toolkit;
use yii\queue\RetryableJobInterface;

class WarmCacheJob extends BaseBatchedJob implements RetryableJobInterface
{
    public function getTtr(): int
    {
        return 600;
    }

    public function canRetry($attempt, $error): bool
    {
        return true;
    }

    public function loadData(): Batchable
    {
        $urls = Toolkit::getInstance()->cacheService->getUrlsToWarm();
        return new DataBatcher(array_chunk($urls, 10));
    }

    public function processItem(mixed $item): void
    {
        Toolkit::getInstance()->cacheService->warmUrls($item)->wait();
    }

    protected function defaultDescription(): ?string
    {
        return 'Warming Urls';
    }
}
