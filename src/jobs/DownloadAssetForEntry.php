<?php

namespace alexbrukhty\crafttoolkit\jobs;

use Craft;
use alexbrukhty\crafttoolkit\helpers\FileHelper;
use craft\elements\Entry;
use craft\elements\Asset;
use craft\errors\AssetException;
use craft\errors\ElementNotFoundException;
use craft\errors\FsException;
use craft\errors\FsObjectExistsException;
use craft\queue\BaseJob;
use Throwable;
use yii\base\Exception;

/**
 * Download asset for entry queue job
 */
class DownloadAssetForEntry extends BaseJob
{

    public string $url;
    public string $title;
    public string $folderName;
    public int $entryId;
    public int $siteId = 1;
    public string $entryFieldHandle;
    public function canRetry($attempt, $error): bool
    {
        return true;
    }

    /**
     * @throws AssetException
     * @throws Throwable
     * @throws ElementNotFoundException
     * @throws FsException
     * @throws Exception
     * @throws FsObjectExistsException
     */
    function execute($queue): void
    {
        $existingAsset = Asset::find()->title($this->title)->siteId($this->siteId)->one();
        $asset = $existingAsset ?? FileHelper::createAssetFromUrl($this->url, $this->title, $this->folderName);
        if ($asset) {
            $entry = Entry::find()->id($this->entryId)->siteId($this->siteId)->one();
            if ($entry) {
                $entry->setFieldValue($this->entryFieldHandle, [$asset->id]);
                Craft::$app->elements->saveElement($entry);
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'Downloading Asset for '.$this->title;
    }
}
