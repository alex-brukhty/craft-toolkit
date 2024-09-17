<?php

namespace modules\toolkit\jobs;

use craft\errors\ElementNotFoundException;
use craft\queue\BaseJob;
use modules\toolkit\services\ImageTransformService;
use Throwable;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\queue\RetryableJobInterface;

/**
 *
 * @property-read int $ttr
 */
class RemoveTransformImageJob extends BaseJob implements RetryableJobInterface
{
    public string $assetId;
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
     * @throws ElementNotFoundException
     * @throws Exception
     * @throws ErrorException
     */
    public function execute($queue): void
    {
        ImageTransformService::deleteTransformedImage($this->assetId);
    }

    public function getDescription(): string
    {
        return 'Removing transformed image';
    }
}
