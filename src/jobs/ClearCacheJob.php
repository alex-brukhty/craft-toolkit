<?php

namespace alexbrukhty\crafttoolkit\jobs;

use Craft;
use alexbrukhty\crafttoolkit\Toolkit;
use craft\elements\Entry;
use craft\queue\BaseJob;
use yii\queue\RetryableJobInterface;

class ClearCacheJob extends BaseJob implements RetryableJobInterface
{

    public array $urls = [];
    public string|null $elementId = null;
    public bool $all = false;
    public string $elementClass = Entry::class;
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
            $elements = $this->elementClass::find()->id($this->elementId)->siteId('*')->all();
            foreach ($elements as $element) {
                if ($element) {
                    Toolkit::getInstance()->cacheService->clearCacheByElement($element);
                }
            }
        } elseif (count($this->urls)) {
            Toolkit::getInstance()->cacheService->clearCacheByUrls($this->urls);
        } else {
            Toolkit::getInstance()->cacheService->clearAllCache();
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
