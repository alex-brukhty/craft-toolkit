<?php

namespace alexbrukhty\crafttoolkit\jobs;

use Craft;
use alexbrukhty\crafttoolkit\Toolkit;
use craft\base\Element;
use craft\errors\MutexException;
use craft\queue\BaseJob;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\queue\RetryableJobInterface;

class ClearCacheJob extends BaseJob implements RetryableJobInterface
{

    public array $urls = [];
    public string|null $elementId = null;
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
        $lockKey = 'clear:' . $this->elementId;
        $mutex = Craft::$app->getMutex();

        if ($mutex->acquire($lockKey) || !$this->elementId) {
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

            $mutex->release($lockKey);
        } else {
            throw new \yii\db\Exception('asdasd');
        }
    }

    public function getDescription(): string
    {
        return 'Clearing static cache';
    }
}
