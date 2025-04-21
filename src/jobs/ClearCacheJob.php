<?php

namespace alexbrukhty\crafttoolkit\jobs;

use alexbrukhty\crafttoolkit\Toolkit;
use craft\base\Element;
use craft\queue\BaseJob;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\queue\RetryableJobInterface;

class ClearCacheJob extends BaseJob implements RetryableJobInterface
{

    public array $urls = [];
    public Element|null $element;
    public bool $all = false;

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
        if ($this->element) {
            Toolkit::getInstance()->cacheService->clearCacheByElement($this->element);
        } elseif ($this->all) {
            Toolkit::getInstance()->cacheService->clearAllCache();
        } else {
            Toolkit::getInstance()->cacheService->clearCacheByUrls($this->urls);
        }
    }

    public function getDescription(): string
    {
        return 'Clearing static cache';
    }
}
