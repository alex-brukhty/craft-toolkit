<?php

namespace alexbrukhty\crafttoolkit\jobs;

use Craft;
use alexbrukhty\crafttoolkit\Toolkit;
use craft\queue\BaseJob;
use yii\queue\RetryableJobInterface;

class ClearCacheJob extends BaseJob implements RetryableJobInterface
{

    public array $urls = [];
    public string|null $elementId = null;
    public bool $all = false;
    public string $mutexKey = '';

    public function getTtr(): int
    {
        return 300;
    }

    public function canRetry($attempt, $error): bool
    {
        return false;
    }

    public function execute($queue): void
    {
        if ($this->elementId) {
            $element = Craft::$app->getElements()->getElementById($this->elementId);
            if ($element) {
                Toolkit::getInstance()->cacheService->clearCacheByElement($element);
            }
        } elseif ($this->all) {
            Toolkit::getInstance()->cacheService->clearAllCache();
        } else {
            Toolkit::getInstance()->cacheService->clearCacheByUrls($this->urls);
        }

        if ($this->mutexKey) {
            $mutex = Craft::$app->getMutex();
            $mutex->release($this->mutexKey);
        }
    }

    public function getDescription(): string
    {
        return 'Clearing static cache';
    }
}
