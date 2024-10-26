<?php

namespace alexbrukhty\crafttoolkit\jobs;

use alexbrukhty\crafttoolkit\Toolkit;
use craft\queue\BaseJob;
use yii\queue\RetryableJobInterface;

class WarmCacheJob extends BaseJob implements RetryableJobInterface
{

    public array $urls = [];

    public function getTtr(): int
    {
        return 300;
    }

    public function canRetry($attempt, $error): bool
    {
        return true;
    }

    public function execute($queue): void
    {
        $chunks = array_chunk($this->urls, 10);
        $progress = 0;
        foreach ($chunks as $chunk) {
            $progress = $progress + count($chunk);
            $this->setProgress($queue, $progress / count($this->urls), "Warming $progress of ".count($this->urls)." urls");
            Toolkit::getInstance()->cacheService->warmUrls($chunk)->wait(true);
        }
    }

    public function getDescription(): string
    {
        return 'Warming Urls';
    }
}
