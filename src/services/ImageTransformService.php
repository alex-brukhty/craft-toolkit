<?php

namespace alexbrukhty\crafttoolkit\services;

use alexbrukhty\crafttoolkit\models\MediaTransform;
use Craft;
use alexbrukhty\crafttoolkit\helpers\FileHelper;
use alexbrukhty\crafttoolkit\Toolkit;
use craft\base\Element;
use craft\errors\InvalidFieldException;
use craft\errors\SiteNotFoundException;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\ElementHelper;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use craft\helpers\FileHelper as CraftFileHelper;
use craft\elements\Asset;
use GuzzleHttp\Exception\GuzzleException;
use alexbrukhty\crafttoolkit\jobs\TransformImageJob;
use Illuminate\Support\Collection;
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

    public static function isVideoEnabled(): bool
    {
        return Toolkit::getInstance()->getSettings()->videoTransformEnabled ?? false;
    }

    public static function overrideFields($fieldHandle): string
    {
        $imageTransformFieldsOverride = Toolkit::getInstance()->getSettings()->imageTransformFieldsOverride ?? [];

        if (isset($imageTransformFieldsOverride[$fieldHandle])) {
            return $imageTransformFieldsOverride[$fieldHandle];
        }

        return $fieldHandle;
    }

    public static function getTransformFieldHandle($asset): string
    {
        $handle = self::overrideFields('transformUrls');
        return $asset[$handle] ? $handle : 'transformUrls';
    }

    /**
     * @throws Exception
     */
    public static function getWebsiteDomain(): string
    {
        $domain = App::devMode()
            ? Toolkit::getInstance()->getSettings()->imageTransformPublicUrl
            : (Craft::$app->getSites()->getAllSites()[0]?->baseUrl ?? App::env('PRIMARY_SITE_URL'));
        return rtrim($domain, '/');
    }

    public static function canTransform(Asset $asset, $forsed = false, $withVideo = false): bool
    {
        $allowedVolumes = Toolkit::getInstance()->getSettings()->imageTransformVolumes;
        $transformFieldHandle = self::getTransformFieldHandle($asset);

        return (
            // check if enabled
            (self::isEnabled() && $asset->kind === Asset::KIND_IMAGE)
            || ($withVideo && self::isVideoEnabled() && $asset->kind === Asset::KIND_VIDEO)
            )

            // check if asset not in draft
            && !ElementHelper::isDraftOrRevision($asset)

            // skip some unwanted extension
            && !in_array(strtolower($asset->extension), ['svg', 'gif', 'webp', 'avif'])

            // check if a field is empty or forced
            && (
                (
                    $transformFieldHandle && (
                        trim($asset[$transformFieldHandle]) === null
                        || trim($asset[$transformFieldHandle]) === ''
                        || trim($asset[$transformFieldHandle]) === '[]'
                    )
                ) || $forsed
            )

            // check for skip parameter
            && (isset($asset[self::SKIP_TRANSFORM]) ? !$asset[self::SKIP_TRANSFORM] : !isset($asset[self::SKIP_TRANSFORM]))

            // check for allowed volumes
            && (count($allowedVolumes) > 0 ? in_array($asset->volumeId, $allowedVolumes) : true);
    }

    public static function registerEvents(): void
    {
        Event::on(
            Asset::class, Element::EVENT_BEFORE_SAVE,
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
            Element::EVENT_AFTER_SAVE,
            function(Event $event) {
                /* @var $asset Asset */
                $asset = $event->sender;

                if (!self::canTransform($asset, false, false)) {
                    return;
                }

                Queue::push(new TransformImageJob([
                    'assetId' => $event->sender->id,
                ]));
            }
        );

        Event::on(
            Asset::class,
            Element::EVENT_BEFORE_DELETE,
            function(Event $event) {
                /** @var Asset $asset */
                $asset = $event->sender;
                ImageTransformService::deleteTransformedImage($asset, true);
            }
        );
    }

    /**
     * @throws Exception
     */
    public static function wsrvImageUrl(Asset $asset, MediaTransform $transform): string
    {
        $domain = self::getWebsiteDomain();
        $assetUrl = $domain . UrlHelper::rootRelativeUrl($asset->url);
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

        return UrlHelper::url('https://wsrv.nl/', $params);
    }

    /**
     * @param Asset $asset
     * @param MediaTransform $transform
     * @return string
     * @throws Exception
     *
     * https://<ZONE>/cdn-cgi/image/<OPTIONS>/<SOURCE-IMAGE>
     */
    public static function cloudflareImageUrl(Asset $asset, MediaTransform $transform): string
    {
        $zone = Toolkit::getInstance()->getSettings()->cloudflareDomain;
        if (!$zone) {
            throw new InvalidConfigException('No Cloudflare domain provided');
        }
        $assetUrl = ltrim(UrlHelper::rootRelativeUrl($asset->url), '/');
        $isGrayscale = $asset->grayscale ?? false;
        $options = [];

        if ($transform->width) {
            $options[] = "width=$transform->width";
        }

        if ($transform->height) {
            $options[] = "height=$transform->height";
        }

        if ($transform->format) {
            $options[] = "format=$transform->format";
        }

        if ($transform->fit) {
            $options[] = "fit=$transform->fit";
        }

        if ($transform->quality) {
            $options[] = "quality=$transform->quality";
        }

        if ($isGrayscale) {
            $options[] = 'saturation=0,contrast=0.8';
        }

        $options = implode(',', $options);
        return "https://$zone/cdn-cgi/image/$options/$assetUrl";
    }

    /**
     * @param Asset $asset
     * @param MediaTransform $transform
     * @return string
     * @throws Exception
     *
     * https://<ZONE>/cdn-cgi/media/<OPTIONS>/<SOURCE-VIDEO>
     */
    public static function cloudflareVideoUrl(Asset $asset, MediaTransform $transform): string
    {
        $zone = Toolkit::getInstance()->getSettings()->cloudflareDomain;
        if (!$zone) {
            throw new InvalidConfigException('No Cloudflare domain provided');
        }
        $assetUrl = ltrim(UrlHelper::rootRelativeUrl($asset->url), '/');
        $options = ['mode=video', 'audio=false'];

        if ($transform->width) {
            $options[] = "width=$transform->width";
        }

        if ($transform->height) {
            $options[] = "height=$transform->height";
        }

        if ($transform->fit) {
            $options[] = "fit=$transform->fit";
        }

        $options = implode(',', $options);
        return "https://$zone/cdn-cgi/media/$options/$assetUrl";
    }

    /**
     * @throws Exception
     */
    public static function getTransformUrl(Asset $asset, MediaTransform $transform): string
    {
        $adapter = $asset->kind === Asset::KIND_VIDEO
            ? Toolkit::getInstance()->getSettings()->videoTransformAdapter . 'Url'
            : Toolkit::getInstance()->getSettings()->imageTransformAdapter . 'Url';
        return self::$adapter($asset, $transform);
    }


    /**
     * @param MediaTransform $transform
     * @param Asset $asset
     * @return string
     * @throws InvalidConfigException|Exception
     *
     * Main method for transforming images using the external API like `wsrv.nl`
     * Example transformation: https://wsrv.nl/?url=wsrv.nl/lichtenstein.jpg&w=300&q=80&output=webp
     */
    public static function getTransformedMedia(MediaTransform $transform, Asset $asset): string
    {
        if (!$asset->url) {
            return '';
        }

        $url = self::getTransformUrl($asset, $transform);
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

    public static function getExistingTransforms(Asset $asset): Collection
    {
        $transformFieldHandle = self::getTransformFieldHandle($asset);
        $fieldValue = $transformFieldHandle ? $asset->getFieldValue($transformFieldHandle) : null;
        $array = $fieldValue ? (array)json_decode($fieldValue ?? '[]') : array();
        return Collection::make($array);
    }

    /**
     * @throws ErrorException
     * @throws Exception
     * @throws GuzzleException
     * @throws Throwable
     */
    public static function transformImage(string $assetId, bool $forced = false, $isVideo = false, $onDemandTransforms = []): void
    {
        $asset = Asset::find()->id($assetId)->one();

        if (!$asset) {
            return;
        }

        if (!self::canTransform($asset, $forced, $isVideo)) {
            return;
        }

        $existingTransforms = self::getExistingTransforms($asset);

        $transforms = [];
        if (count($onDemandTransforms) > 0) {
            foreach ($onDemandTransforms as $key => $transform) {
                $transforms[$key] = new MediaTransform($transform);
                $transforms[$key]->fit = 'scale-down';
                $transforms[$key]->format = $isVideo ? $asset->extension : 'webp';
            }
        } else {
            if ($isVideo) {
                $transforms = Toolkit::getInstance()->getSettings()->videoTransforms;
                foreach ($transforms as $key => $transform) {
                    $transforms[$key] = new MediaTransform($transform);
                    $transforms[$key]->fit = 'scale-down';
                    $transforms[$key]->format = $asset->extension;
                }
            } else {
                $transforms = array_map(function ($transform) {
                    return new MediaTransform([
                        'format' => $transform->format,
                        'width' => $transform->width,
                        'height' => $transform->height,
                        'quality' => $transform->quality,
                        'fit' => $transform->mode == 'fit' ? 'contain' : 'cover'
                    ]);
                }, Craft::$app->imageTransforms->getAllTransforms());
            }
        }

        $transforms = array_filter($transforms, function ($transform) use ($existingTransforms) {
            return $existingTransforms->where('width', $transform->width)->count() === 0;
        });

        try {
            $parsed = array_map(function ($transform) use ($asset) {
                return (object)[
                    'uri' => DIRECTORY_SEPARATOR . self::getTransformedMedia($transform, $asset),
                    'width' => $transform->width
                ];
            }, $transforms);
        } catch (Throwable $e) {
            Craft::$app->getLog()->logger->log($e->getMessage(), Logger::LEVEL_ERROR);
            return;
        }
        $parsed = array_merge($parsed, $existingTransforms->all());
        $parsed = array_filter($parsed, fn ($tr) => $tr->uri !== '/');
        ArrayHelper::multisort($parsed, 'width');

        if (count($parsed) > 0) {
            $transformFieldHandle = self::getTransformFieldHandle($asset);
            if ($transformFieldHandle) {
                $asset->setFieldValue($transformFieldHandle, json_encode($parsed));
                $asset->setAttributes([self::SKIP_TRANSFORM => true], false);
                Craft::$app->elements->saveElement($asset);
            }
        }
    }
    
    public static function transformMediaOnDemand(Asset $asset, $transforms = []): void
    {
        $isVideo = $asset->kind === Asset::KIND_VIDEO;

        if (!self::canTransform($asset, true, $isVideo)) {
            return;
        }

        $existingTransforms = self::getExistingTransforms($asset);
        $flattenTransforms = Collection::make($transforms)->select('width')->flatten();

        if ($flattenTransforms->every(function ($ft) use ($existingTransforms) {
            return $existingTransforms->contains('width', $ft);
        })) {
            return;
        }

        Queue::push(new TransformImageJob([
            'assetId' => $asset->id,
            'transforms' => $transforms,
            'forced' => true,
            'isVideo' => $isVideo,
        ]));
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
    public static function getTransformFolderFull(Asset $asset, MediaTransform $transform, $asFile = false): string
    {
        return CraftFileHelper::normalizePath(self::getTransformFolder($asset, $asFile).'/'.$transform->width);
    }

    /**
     * @throws InvalidConfigException
     */
    public static function getTransformUri(Asset $asset, MediaTransform $transform, $asFile = false): string
    {
        $filename = preg_replace('/(@|%40)/', '_', $asset->filename);
        $withoutExt = preg_replace('/\.\w+$/', '', $filename);
        return self::getTransformFolderFull($asset, $transform, $asFile).'/'.$withoutExt.'.'.$transform->format;
    }

    /**
     * @throws SiteNotFoundException
     * @throws Exception
     */
    public static function withSiteUrl($uri = ''): string
    {
        $siteUrl = App::env('PRIMARY_SITE_URL') ?? '/';
        $siteUrl = rtrim($siteUrl, '/');
        $uri = ltrim($uri, '/');
        return "$siteUrl/$uri";
    }

    /**
     * @throws InvalidFieldException
     * @throws Exception
     */
    public static function getSrcset(Asset $asset): string
    {
        $transformFieldHandle = self::getTransformFieldHandle($asset);
        $transformsString = $transformFieldHandle && isset($asset->$transformFieldHandle) ? $asset->getFieldValue($transformFieldHandle) : null;
        if (!$transformsString) {
            return  '';
        }
        $transforms = (array)json_decode($transformsString);
        return implode(', ', array_map(function ($tr) use ($asset) {
            return ImageTransformService::withSiteUrl($tr->uri)." ".$tr->width."w";
        }, $transforms));
    }

    /**
     * @throws InvalidFieldException
     * @throws SiteNotFoundException|Exception
     */
    public static function getSrc(Asset $asset, ?int $index = null, $width = ''): string
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
        
        if ($width) {
            $key = array_find_key($transforms, function ($transform) use ($width) {
                return $transform->width === $width;
            });
        } else {
            $key = $index ? ($index > -1 ? $index : array_key_last($transforms)) : null;
        }

        return ImageTransformService::withSiteUrl(
            $key ? ($transforms[$key]->uri ?? '') : ($transforms[0]->uri ?? '')
        );
    }
}
