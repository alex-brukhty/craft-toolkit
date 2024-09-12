<?php

namespace modules\toolkit\jobs;

use craft\queue\BaseJob;
use GuzzleHttp\Exception\GuzzleException;
use modules\toolkit\services\ImageTransformService;
use Throwable;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\queue\RetryableJobInterface;

/**
 *
 * @property-read int $ttr
 */
class TransformImageJob extends BaseJob implements RetryableJobInterface
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
     * @throws GuzzleException
     * @throws ErrorException
     * @throws Exception
     */
    public function execute($queue): void
    {
        ImageTransformService::transformImage($this->assetId);
    }

    public function getDescription(): string
    {
        return 'Transforming Image';
    }
}
