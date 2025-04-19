<?php

namespace alexbrukhty\crafttoolkit\services;

use Craft;
use alexbrukhty\crafttoolkit\helpers\FileHelper;
use alexbrukhty\crafttoolkit\Toolkit;
use craft\errors\InvalidFieldException;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\ElementHelper;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use craft\models\ImageTransform as ImageTransformModel;
use craft\helpers\FileHelper as CraftFileHelper;
use craft\elements\Asset;
use GuzzleHttp\Exception\GuzzleException;
use alexbrukhty\crafttoolkit\jobs\TransformImageJob;
use yii\base\ErrorException;
use yii\base\Event;
use yii\base\Exception;
use Throwable;
use yii\base\InvalidConfigException;
use yii\log\Logger;

class ImageTransformService
{
    public const TRANSFORMED_IMAGES_PATH = '@webroot/media_optimised';
    public const SKIP_TRANSFORM = 'skipTransform';

    public static function isEnabled(): bool
    {
        return Toolkit::getInstance()->getSettings()->imageTransformEnabled ?? false;
    }

    public static function getApiUrl(): string
    {
        return Toolkit::getInstance()->getSettings()->imageTransformApiUrl;
    }

    public static function overrideFields($fieldHandle): string
    {
        $imageTransformFieldsOverride = Toolkit::getInstance()->getSettings()->imageTransformFieldsOverride ?? [];

        if (isset($imageTransformFieldsOverride[$fieldHandle])) {
            return $imageTransformFieldsOverride[$fieldHandle];
        }

        return $fieldHandle;
    }

    public static function getTransformFieldHandle($asset)
    {
        $handle = self::overrideFields('transformUrls');
        return $asset[$handle] ? $handle : 'transformUrls';
    }

    public static function getWebsiteDomain(): string
    {
        return App::devMode()
            ? Toolkit::getInstance()->getSettings()->imageTransformPublicUrl
            : (Craft::$app->getSites()->getAllSites()[0]?->baseUrl ?? App::parseEnv('PRIMARY_SITE_URL'));
    }

    public static function registerEvents(): void
    {
        Event::on(
            Asset::class, Asset::EVENT_BEFORE_SAVE,
            function(Event $event) {
                /* @var $asset Asset */
                $asset = $event->sender;

                if (in_array($asset->getScenario(), [Asset::SCENARIO_FILEOPS, Asset::SCENARIO_MOVE])) {
                    $transformFieldHandle = self::getTransformFieldHandle($asset);
                    if ($transformFieldHandle) {
                        $asset->setFieldValue($transformFieldHandle, '');
                    }
                    self::deleteTransformedImage($asset, true);
                }
            }
        );

        Event::on(
            Asset::class,
            Asset::EVENT_AFTER_SAVE,
            function(Event $event) {
                /* @var $asset Asset */
                $asset = $event->sender;
                $allowedVolumes = Toolkit::getInstance()->getSettings()->imageTransformVolumes;
                $transformFieldHandle = self::getTransformFieldHandle($asset);

                if (
                    !self::isEnabled()
                    || $asset->kind !== 'image'
                    || ElementHelper::isDraftOrRevision($asset)
                    || in_array(strtolower($asset->extension), ['svg', 'gif', 'webp', 'avif'])
                    || ($transformFieldHandle && trim($asset[$transformFieldHandle]) !== '' && trim($asset[$transformFieldHandle]) !== '[]')
                    || (isset($asset[self::SKIP_TRANSFORM]) && $asset[self::SKIP_TRANSFORM])
                    || (count($allowedVolumes) > 0 && !in_array($asset->volumeId, $allowedVolumes))
                ) {
                    return;
                }

                Queue::push(new TransformImageJob([
                    'assetId' => $event->sender->id,
                ]));
            }
        );

        Event::on(
            Asset::class,
            Asset::EVENT_BEFORE_DELETE,
            function(Event $event) {
                /** @var Asset $asset */
                $asset = $event->sender;
                ImageTransformService::deleteTransformedImage($asset, true);
            }
        );
    }


    /**
     * @param ImageTransformModel $transform
     * @param Asset $asset
     * @return string
     *
     * Main method for transforming images using the external API like `wsrv.nl`
     * Example transformation: https://wsrv.nl/?url=wsrv.nl/lichtenstein.jpg&w=300&q=80&output=webp
     * @throws InvalidConfigException
     */
    public static function getTransformedImage(ImageTransformModel $transform, Asset $asset): string
    {
        if (!$asset->url) {
            return '';
        }
        $domain = self::getWebsiteDomain();
        $assetUrl = $domain.UrlHelper::rootRelativeUrl($asset->url);
        $isGrayscale = $asset->grayscale ?? false;
        $params = [
            'url' => $assetUrl,
            'w' => $transform->width,
            'q' => $transform->quality,
            'output' => $transform->format,
        ];

        if ($isGrayscale) {
            $params['filt'] = 'greyscale';
            $params['con'] = '-20';
        }

        $url = UrlHelper::url(self::getApiUrl(), $params);

        $save = self::getTransformUri($asset, $transform, true);

        Craft::getLogger()->log("Transform: $url", Logger::LEVEL_INFO, 'image-transform');

        try {
            CraftFileHelper::createDirectory(self::getTransformFolderFull($asset, $transform, true));
            if (FileHelper::downloadFile($url, $save, 1, false)) {
                return self::getTransformUri($asset, $transform);
            }
            return '';
        } catch (Exception $e) {
            Craft::getLogger()->log("Failed to transform: $url, $e", Logger::LEVEL_ERROR,'image-transform');
            return '';
        }
    }

    /**
     * @throws ErrorException
     * @throws Exception
     * @throws GuzzleException
     * @throws Throwable
     */
    public static function transformImage(string $assetId, $forced = false): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $asset = Asset::find()->id($assetId)->one();

        if (!$asset) {
            return;
        }

        if ((isset($asset[self::SKIP_TRANSFORM]) && $asset[self::SKIP_TRANSFORM])) {
            return;
        }

        $transformFieldHandle = self::getTransformFieldHandle($asset);
        if (!$forced && $transformFieldHandle && trim($asset[$transformFieldHandle]) !== '' && trim($asset[$transformFieldHandle]) !== '[]') {
            return;
        }

        $transforms = Craft::$app->imageTransforms->getAllTransforms();
        ArrayHelper::multisort($transforms, 'width');

        $parsed = [];

        try {
            $parsed = array_map(function ($transform) use ($asset) {
                return [
                    'uri' => "/".self::getTransformedImage($transform, $asset),
                    'width' => $transform->width
                ];
            }, $transforms);
        } catch (Throwable $e) {
            Craft::$app->getLog()->logger->log($e->getMessage(), Logger::LEVEL_ERROR);
            return;
        }

        $parsed = array_filter($parsed, fn ($tr) => $tr['uri'] !== '/');
        if (count($parsed) > 0) {
            $transformFieldHandle = self::getTransformFieldHandle($asset);
            if ($transformFieldHandle) {
                $asset->setFieldValue($transformFieldHandle, json_encode($parsed));
                $asset->setAttributes([self::SKIP_TRANSFORM => true], false);
                Craft::$app->elements->saveElement($asset);
            }
        }
    }

    /**
     * @throws Throwable
     * @throws ErrorException
     */
    public static function deleteTransformedImage(Asset $asset, $skipSave = false): void
    {
        $transformFieldHandle = self::getTransformFieldHandle($asset);
        if ($transformFieldHandle) {
            $asset->setFieldValue($transformFieldHandle, '');
        }
        $asset->setAttributes([self::SKIP_TRANSFORM => true], false);
        if (!$skipSave) {
            Craft::$app->elements->saveElement($asset);
        }

        CraftFileHelper::removeDirectory(self::getTransformFolder($asset, true));
    }

    /**
     * @throws InvalidConfigException
     */
    public static function getTransformFolder(Asset $asset, $asFile = false): string
    {
        $uri = UrlHelper::rootRelativeUrl($asset->url);
        $rootUrl = $asset->getVolume()->getFs()->getRootUrl();
        $uriWithoutMedia = ltrim($uri, $rootUrl);
        $replaced = preg_replace('/(@|%40)/', '_', $uriWithoutMedia);

        if ($asFile) {
            $root = App::parseEnv(self::TRANSFORMED_IMAGES_PATH);
            return CraftFileHelper::normalizePath($root . DIRECTORY_SEPARATOR . $replaced);
        } else {
            $baseFolder = ltrim(self::TRANSFORMED_IMAGES_PATH, '@webroot/');
            return CraftFileHelper::normalizePath($baseFolder . DIRECTORY_SEPARATOR . $replaced);
        }
    }

    /**
     * @throws InvalidConfigException
     */
    public static function getTransformFolderFull(Asset $asset, ImageTransformModel $transform, $asFile = false): string
    {
        return CraftFileHelper::normalizePath(self::getTransformFolder($asset, $asFile).'/'.$transform->width);
    }

    /**
     * @throws InvalidConfigException
     */
    public static function getTransformUri(Asset $asset, ImageTransformModel $transform, $asFile = false): string
    {
        $filename = preg_replace('/(@|%40)/', '_', $asset->filename);
        $withoutExt = preg_replace('/\.\w+$/', '', $filename);
        return self::getTransformFolderFull($asset, $transform, $asFile).'/'.$withoutExt.'.'.$transform->format;
    }


    /**
     * @throws InvalidFieldException
     */
    public static function getSrcset(Asset $asset): string
    {
        $transformFieldHandle = self::getTransformFieldHandle($asset);
        $transformsString = $transformFieldHandle && isset($asset->$transformFieldHandle) ? $asset->getFieldValue($transformFieldHandle) : null;
        if (!$transformsString) {
            return  '';
        }
        $transforms = (array)json_decode($transformsString);
        return implode(', ', array_map(function ($tr) {
            return $tr->uri." ".$tr->width."w";
        }, $transforms));
    }

    /**
     * @throws InvalidFieldException
     */
    public static function getSrc(Asset $asset, bool $last = false): string
    {
        $transformFieldHandle = self::getTransformFieldHandle($asset);
        $transformsString = $transformFieldHandle && isset($asset->$transformFieldHandle) ? $asset->getFieldValue($transformFieldHandle) : null;
        if (!$transformsString) {
            return $asset->url;
        }

        $transforms = (array)json_decode($transformsString) ?? array();
        if (count($transforms) === 0) {
            return $asset->url;
        }

        return $last ? ($transforms[array_key_last($transforms)]->uri ?? '') : ($transforms[0]->uri ?? '');
    }

    public static function placeholderSVG(): ?string
    {
        $color = $config['color'] ?? 'transparent';

        return 'data:image/svg+xml;charset=utf-8,'.rawurlencode(sprintf('<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'%s\' height=\'%s\' style=\'background:%s\'/>', 1, 1, $color));
    }
}
