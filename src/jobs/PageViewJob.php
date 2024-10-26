<?php

namespace alexbrukhty\crafttoolkit\jobs;

use alexbrukhty\crafttoolkit\Toolkit;
use craft\queue\BaseJob;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\queue\RetryableJobInterface;

class PageViewJob extends BaseJob implements RetryableJobInterface
{

    public string $url;
    public string $title;

    public function getTtr(): int
    {
        return 300;
    }

    public function canRetry($attempt, $error): bool
    {
        return false;
    }

    /**
     * @throws Throwable
     * @throws GuzzleException
     * @throws ErrorException
     * @throws Exception
     */
    public function execute($queue): void
    {
        $this->setProgress($queue, 1, $this->url);
        $analytics = Toolkit::getInstance()->analyticsService;
        $analytics->pageViewEvent(
            $this->url,
            $this->title
        );

        $analytics->sendEvent();
    }

    public function getDescription(): string
    {
        return 'Page View Event';
    }
}
