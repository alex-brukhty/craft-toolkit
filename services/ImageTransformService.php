<?php

namespace modules\toolkit\services;

use Craft;
use craft\errors\InvalidFieldException;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\Queue;
use craft\models\ImageTransform as ImageTransformModel;
use craft\helpers\FileHelper;
use craft\elements\Asset;
use GuzzleHttp\Exception\GuzzleException;
use Imagick;
use ImagickException;
use modules\toolkit\jobs\RemoveTransformImageJob;
use modules\toolkit\jobs\TransformImageJob;
use yii\base\ErrorException;
use yii\base\Event;
use yii\base\Exception;
use Throwable;
use yii\log\Logger;
use yii\web\BadRequestHttpException;

class ImageTransformService
{
    private static function _getUriFromUrl(string $url): string
    {
        $parts = parse_url($url);
        return $parts ? ltrim($parts['path'], "/") : $url;
    }

    public static function isEnabled(): bool
    {
        return App::env('IMAGE_TRANSFORM_ENABLED') ?? false;
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
                if (App::devMode()) {
                    return;
                }
                Queue::push(new RemoveTransformImageJob(['assetId' => $event->sender->id]));
            }
        );
    }

    public static function getTransformedImage(ImageTransformModel $transform, Asset $asset): string
    {

        $domain = App::devMode() ? App::env('DEV_SERVER_PUBLIC') : Craft::$app->getSites()->currentSite->url;
        $assetUrl = $domain.$asset->url;

        // example https://wsrv.nl/?url=wsrv.nl/lichtenstein.jpg&w=300&q=80&output=webp

        $url = "https://wsrv.nl/?url=$assetUrl&w=$transform->width&q=$transform->quality,output=$transform->format";
        $save = self::getTransformUri($asset, $transform, true);

        try {
            $image = new Imagick($url);

            FileHelper::createDirectory(self::getTransformFolderFull($asset, $transform, true));

            if ($image->writeImages($save, true)) {
                return self::getTransformUri($asset, $transform);
            }

            return new BadRequestHttpException("Failed to transform using: $url, and save to: $save");
        } catch (ImagickException|Exception $e) {
            return new BadRequestHttpException("Failed to transform: $url, $e");
        }
    }

    /**
     * @throws ErrorException
     * @throws Exception
     * @throws GuzzleException
     * @throws Throwable
     */
    public static function transformImage(string $assetId): void
    {
        $asset = Asset::find()->id($assetId)->one();

        if (!$asset) {
            return;
        }
        if ($asset->transformUrls) {
            return;
        }

        $transforms = Craft::$app->imageTransforms->getAllTransforms();
        ArrayHelper::multisort($transforms, 'width');

        try {
            $urls = array_map(function ($transform) use ($asset) {
                return [
                    'uri' => "/".self::getTransformedImage($transform, $asset),
                    'width' => $transform->width
                ];
            }, $transforms);
        } catch (Throwable $e) {
            Craft::$app->getLog()->logger->log($e->getMessage(), Logger::LEVEL_ERROR);
            return;
        }

        $asset->setFieldValue('transformUrls', json_encode($urls));
        Craft::$app->elements->saveElement($asset);
    }

    /**
     * @throws Throwable
     * @throws ErrorException
     */
    public static function deleteTransformedImage(string $assetId): void
    {
        $asset = Asset::find()->id($assetId)->one();
        $asset->setFieldValue('transformUrls', '');
        Craft::$app->elements->saveElement($asset);
        FileHelper::removeDirectory(self::getTransformFolder($asset, true));
    }

    public static function getTransformFolder($asset, $asFile = false): string
    {
        $uri = self::_getUriFromUrl($asset->url);
        $uriWithoutMedia = ltrim($uri, 'media');
        return ($asFile ? App::env('CRAFT_WEB_ROOT').'/' : '')."media_optimised$uriWithoutMedia";
    }

    public static function getTransformFolderFull(Asset $asset, ImageTransformModel $transform, $asFile = false): string
    {
        return self::getTransformFolder($asset, $asFile).'/'.$transform->width;
    }

    // get ulr of transformed image
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

        return $last ? $transforms[array_key_last($transforms)]->uri : $transforms[0]->uri;
    }

    public static function placeholderSVG(): ?string
    {
        $color = $config['color'] ?? 'transparent';

        return 'data:image/svg+xml;charset=utf-8,'.rawurlencode(sprintf('<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'%s\' height=\'%s\' style=\'background:%s\'/>', 1, 1, $color));
    }
}
