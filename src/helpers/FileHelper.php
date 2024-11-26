<?php

namespace alexbrukhty\crafttoolkit\helpers;

use Craft;
use craft\elements\Asset;
use craft\errors\AssetException;
use craft\errors\ElementNotFoundException;
use craft\errors\FsException;
use craft\errors\FsObjectExistsException;
use craft\helpers\FileHelper as CraftFileHelper;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\VolumeFolder;
use Exception;
use Throwable;

class FileHelper
{

    public static function downloadFile($srcName, $dstName, int $chunkSize = 1, bool $returnbytes = true): bool|int
    {
        $newChunkSize = $chunkSize * (1024 * 1024);
        $bytesCount = 0;
        $handle = fopen($srcName, 'rb');
        $fp = fopen($dstName, 'wb');

        if ($handle === false) {
            return false;
        }

        while (!feof($handle)) {
            $data = fread($handle, $newChunkSize);
            fwrite($fp, $data, strlen($data));

            if ($returnbytes) {
                $bytesCount += strlen($data);
            }
        }

        $status = fclose($handle);

        fclose($fp);

        if ($returnbytes && $status) {
            return $bytesCount;
        }

        return $status;
    }

    public static function getRemoteUrlExtension($url): string
    {
        // PathInfo can't really deal with query strings, so remove it
        $extension = UrlHelper::stripQueryString($url);

        // Can we easily get the extension for this URL?
        $extension = StringHelper::toLowerCase(pathinfo($extension, PATHINFO_EXTENSION));

        // We might now have a perfectly acceptable extension, but is it real and allowed by Craft?
        if (!in_array($extension, Craft::$app->getConfig()->getGeneral()->allowedFileExtensions, true)) {
            $extension = '';
        }

        // If we can't easily determine the extension of the url, fetch it
        if (!$extension) {
            $client = Craft::createGuzzleClient();
            $response = null;

            // Try using HEAD requests (for performance), if it fails use GET
            try {
                $response = $client->head($url);
            } catch (Throwable $e) {
            }

            try {
                if (!$response) {
                    $response = $client->get($url);
                }
            } catch (Throwable $e) {
            }

            if ($response) {
                $contentType = $response->getHeader('Content-Type');

                if (isset($contentType[0])) {
                    // Because some servers cram unnecessary things into the Content-Type header.
                    $contentType = explode(';', $contentType[0]);
                    // Convert MIME type to extension
                    $extension = CraftFileHelper::getExtensionByMimeType($contentType[0]);
                }
            }
        }

        return StringHelper::toLowerCase($extension);
    }

    /**
     * Function to extract a filename from a URL path. It does not query the actual URL, however.
     * // There are some tricky cases being tested again, and mostly revolves around query strings. We do our best to figure it out!
     * // http://example.com/test.php
     * // http://example.com/test.php?pubid=image.jpg
     * // http://example.com/image.jpg?width=1280&cid=5049
     * // http://example.com/image.jpg?width=1280&cid=5049&un=support%40gdomain.com
     * // http://example.com/test
     * // http://example.com/test?width=1280&cid=5049
     * // http://example.com/test?width=1280&cid=5049&un=support%40gdomain.com
     */

    public static function getRemoteUrlFilename($url, $newName = ''): string
    {

        $extension = self::getRemoteUrlExtension($url);

        // PathInfo can't really deal with query strings, so remove it
        $filename = UrlHelper::stripQueryString($url);

        // Can we easily get the extension for this URL?
        $filename = pathinfo($filename, PATHINFO_FILENAME);

        // If there was a query string, append it so this asset remains unique
        $query = parse_url($url, PHP_URL_QUERY);

        if ($query) {
            $filename .= '-' . $query;
        }

        return AssetsHelper::prepareAssetName(($newName ?? $filename).'.'.$extension);
    }

    /**
     * @throws AssetException
     * @throws Throwable
     * @throws ElementNotFoundException
     * @throws FsException
     * @throws \yii\base\Exception
     * @throws FsObjectExistsException
     */
    public static function createAssetFromUrl(string $url, string $fileName = '', $folderName = '', $volumeId = 1): Asset|null
    {
        $filename = self::getRemoteUrlFilename($url, $fileName);
        $tempFilename = CraftFileHelper::uniqueName($filename);
        $tempPath = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . $tempFilename;

        if (self::downloadFile($url, $tempPath, 1, false)) {
            $parent = Craft::$app->getAssets()->getRootFolderByVolumeId($volumeId);
            $folder = $folderName ? Craft::$app->getAssets()->findFolder(['name' => $folderName]) : null;
            $asset = new Asset();

            if ($folderName && !$folder) {
                $folder = new VolumeFolder();
                $folder->name = $folderName;
                $folder->parentId = $parent->id;
                $folder->volumeId = 1;
                $folder->path = $parent->path . $folder->name . '/';
                Craft::$app->getAssets()->createFolder($folder);
            }

            $asset->tempFilePath = $tempPath;
            $asset->setFilename($filename);
            $asset->volumeId = $folder ? $folder->volumeId : $parent->volumeId;
            $asset->newFolderId = $folder ? $folder->id : $parent->id;
            $asset->avoidFilenameConflicts = true;
            $asset->setScenario(Asset::SCENARIO_CREATE);

            Craft::$app->getElements()->saveElement($asset);

            return $asset;
        } else {
            return throw new Exception("Failed to download asset from $url");
        }
    }
}