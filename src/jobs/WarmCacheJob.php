<?php

namespace alexbrukhty\crafttoolkit\jobs;

use Craft;
use craft\base\Batchable;
use craft\queue\BaseBatchedJob;
use alexbrukhty\crafttoolkit\helpers\DataBatcher;
use alexbrukhty\crafttoolkit\Toolkit;
use yii\queue\RetryableJobInterface;

class WarmCacheJob extends BaseBatchedJob implements RetryableJobInterface
{
    public string $mutexKey = 'warmer';
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
        return new DataBatcher(array_chunk($urls, 5));
    }

    public function processItem(mixed $item): void
    {
        Toolkit::getInstance()->cacheService->warmUrls($item, 5)->wait();
    }

    public function after(): void
    {
        if ($this->mutexKey) {
            $mutex = Craft::$app->getMutex();
            $mutex->release($this->mutexKey);
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'Warming Urls';
    }
}
