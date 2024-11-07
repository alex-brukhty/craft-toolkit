<?php

namespace alexbrukhty\crafttoolkit\services;

use Craft;
use alexbrukhty\crafttoolkit\Toolkit;
use craft\errors\InvalidFieldException;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use craft\models\ImageTransform as ImageTransformModel;
use craft\helpers\FileHelper;
use craft\elements\Asset;
use GuzzleHttp\Exception\GuzzleException;
use alexbrukhty\crafttoolkit\jobs\TransformImageJob;
use yii\base\ErrorException;
use yii\base\Event;
use yii\base\Exception;
use Throwable;
use yii\base\InvalidConfigException;
use yii\log\Logger;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class ImageTransformService
{
    public const TRANSFORMED_IMAGES_PATH = '@webroot/media_optimised';

    public static function isEnabled(): bool
    {
        return Toolkit::getInstance()->getSettings()->imageTransformEnabled ?? false;
    }

    public static function getApiUrl(): string
    {
        return Toolkit::getInstance()->getSettings()->imageTransformApiUrl;
    }

    public static function getWebsiteDomain(): string
    {
        return App::devMode()
            ? Toolkit::getInstance()->getSettings()->imageTransformPublicUrl
            : Craft::$app->getSites()->currentSite->baseUrl;
    }

    public static function registerEvents(): void
    {
        Event::on(
            Asset::class,
            Asset::EVENT_AFTER_SAVE,
            function(Event $event) {
                if (
                    $event->sender->transformUrls
                    || in_array(
                        strtolower($event->sender->extension),
                        ['svg', 'gif', 'webp', 'avif']
                    )
                ) {
                    return;
                }

                Queue::push(new TransformImageJob(['assetId' => $event->sender->id]));
            }
        );

        Event::on(
            Asset::class,
            Asset::EVENT_BEFORE_DELETE,
            function(Event $event) {
                /** @var Asset $asset */
                $asset = $event->sender;
                ImageTransformService::deleteTransformedImage($asset);
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
        $domain = self::getWebsiteDomain();
        $assetUrl = $domain.UrlHelper::rootRelativeUrl($asset->url);

        $url = UrlHelper::url(self::getApiUrl(), [
            'url' => $assetUrl,
            'w' => $transform->width,
            'q' => $transform->quality,
            'output' => $transform->format,
        ]);

        $save = self::getTransformUri($asset, $transform, true);

        Craft::getLogger()->log("Transform: $url", Logger::LEVEL_INFO, 'image-transform');

        try {
            $manager = new ImageManager(new Driver());
            $image = $manager->read(file_get_contents($url));

            FileHelper::createDirectory(self::getTransformFolderFull($asset, $transform, true));
            $image->save($save);

            return self::getTransformUri($asset, $transform);
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
        $asset = Asset::find()->id($assetId)->one();

        if (!$asset) {
            return;
        }
        if ($asset->transformUrls && !$forced) {
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

        $asset->setFieldValue('transformUrls', json_encode($parsed));
        Craft::$app->elements->saveElement($asset);
    }

    /**
     * @throws Throwable
     * @throws ErrorException
     */
    public static function deleteTransformedImage(Asset $asset): void
    {
        FileHelper::removeDirectory(self::getTransformFolder($asset, true));
    }

    /**
     * @throws InvalidConfigException
     */
    public static function getTransformFolder(Asset $asset, $asFile = false): string
    {
        $uri = UrlHelper::rootRelativeUrl($asset->url);
        $rootUrl = $asset->getVolume()->getFs()->getRootUrl();
        $uriWithoutMedia = ltrim($uri, $rootUrl);

        if ($asFile) {
            $root = App::parseEnv(self::TRANSFORMED_IMAGES_PATH);
            return FileHelper::normalizePath($root . DIRECTORY_SEPARATOR . $uriWithoutMedia);
        } else {
            $baseFolder = ltrim(self::TRANSFORMED_IMAGES_PATH, '@webroot/');
            return FileHelper::normalizePath($baseFolder . DIRECTORY_SEPARATOR . $uriWithoutMedia);
        }
    }

    /**
     * @throws InvalidConfigException
     */
    public static function getTransformFolderFull(Asset $asset, ImageTransformModel $transform, $asFile = false): string
    {
        return FileHelper::normalizePath(self::getTransformFolder($asset, $asFile).'/'.$transform->width);
    }

    /**
     * @throws InvalidConfigException
     */
    public static function getTransformUri(Asset $asset, ImageTransformModel $transform, $asFile = false): string
    {
        $withoutExt = preg_replace('/\.\w+$/', '', $asset->filename);
        return self::getTransformFolderFull($asset, $transform, $asFile).'/'.$withoutExt.'.'.$transform->format;
    }


    /**
     * @throws InvalidFieldException
     */
    public static function getSrcset(Asset $asset): string
    {
        $transformsString = $asset->getFieldValue('transformUrls');
        if (!$transformsString) {
            return  '';
        }
        $transforms = json_decode($transformsString);
        return implode(', ', array_map(function ($tr) {
            return $tr->uri." ".$tr->width."w";
        }, $transforms));
    }

    /**
     * @throws InvalidFieldException
     */
    public static function getSrc(Asset $asset, bool $last = false): string
    {
        $transformsString = $asset->getFieldValue('transformUrls');
        if (!$transformsString) {
            return $asset->url;
        }

        $transforms = json_decode($transformsString);
        if (count($transforms) === 0) {
            return $asset->url;
        }

        return $last ? $transforms[array_key_last($transforms)]->uri : $transforms[0]->uri;
    }

    public static function placeholderSVG(): ?string
    {
        $color = $config['color'] ?? 'transparent';

        return 'data:image/svg+xml;charset=utf-8,'.rawurlencode(sprintf('<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'%s\' height=\'%s\' style=\'background:%s\'/>', 1, 1, $color));
    }
}
