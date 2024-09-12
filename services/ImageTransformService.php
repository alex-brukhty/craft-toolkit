<?php

namespace modules\toolkit\services;

use Craft;
use craft\errors\InvalidFieldException;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\models\ImageTransform as ImageTransformModel;
use craft\helpers\FileHelper;
use craft\elements\Asset;
use GuzzleHttp\Exception\GuzzleException;
use Imagick;
use ImagickException;
use yii\base\ErrorException;
use yii\base\Exception;
use Throwable;
use yii\web\BadRequestHttpException;

class ImageTransformService
{
    private static string $cloudflare_zone = 'monotone.store';

    private static function _getUriFromUrl(string $url): string
    {
        $parts = parse_url($url);
        return $parts ? ltrim($parts['path'], "/") : $url;
    }

    /**
     * Example transform url
     *
     * https://monotone.store/cdn-cgi/image/width=1400,quality=80,format=webp/media/products/IMG_1727.jpeg
     */
    public static function getTransformedImage(ImageTransformModel $transform, Asset $asset): string
    {

        if (App::devMode()) {
            // this is for testing only
            // comment skip in Module.php
            $assetUrl = "/".str_replace(UrlHelper::hostInfo($asset->url), 'https://monotone.store', $asset->url);
        } else {
            $assetUrl = $asset->url;
        }

        // //wsrv.nl/?url=wsrv.nl/lichtenstein.jpg&w=300

        $zone = self::$cloudflare_zone;
        $url = "https://$zone/cdn-cgi/image/width=$transform->width,quality=$transform->quality,format=$transform->format$assetUrl";
        $save = self::getTransformUri($asset, $transform, true);

        try {
            $image = new Imagick($url);

            FileHelper::createDirectory(self::getTransformFolderFull($asset, $transform, true));

            if ($image->writeImages($save, true)) {
                return self::getTransformUri($asset, $transform);
            }

            return new BadRequestHttpException("Failed to transform using: $url, and save to: $save");
        } catch (ImagickException|Exception $e) {
            return new BadRequestHttpException("Failed to transform: $e");
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

        $urls = array_map(function ($transform) use ($asset) {
            return [
                'uri' => "/".self::getTransformedImage($transform, $asset),
                'width' => $transform->width
            ];
        }, $transforms);

        $asset->setFieldValue('transformUrls', json_encode($urls));
        Craft::$app->elements->saveElement($asset);
    }

    /**
     * @throws Throwable
     * @throws ErrorException
     */
    public static function deleteImage(string $assetId): void
    {
        $asset = Asset::find()->id($assetId)->one();
        FileHelper::removeDirectory(self::getTransformFolder($asset, true));
    }

    public static function getTransformFolder($asset, $asFile = false): string
    {
        $uri = self::_getUriFromUrl($asset->url);
        $uriWithoutMedia = ltrim($uri, 'media');
        return ($asFile ? App::env('CRAFT_WEB_ROOT').'/' : '')."media_optimised$uriWithoutMedia";
    }

    public static function getTransformFolderFull(Asset $asset, ImageTransformService $transform, $asFile = false): string
    {
        return self::getTransformFolder($asset, $asFile).'/'.$transform->width;
    }

    // get ulr of transformed image
    public static function getTransformUri(Asset $asset, ImageTransformService $transform, $asFile = false): string
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
    public static function getSrc(Asset $asset): string
    {
        $transformsString = $asset->getFieldValue('transformUrls');
        if (!$transformsString) {
            return $asset->url;
        }

        $transforms = json_decode($transformsString);

        return $transforms[0]->uri;
    }

    public static function placeholderSVG(): ?string
    {
        $color = $config['color'] ?? 'transparent';

        return 'data:image/svg+xml;charset=utf-8,'.rawurlencode(sprintf('<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'%s\' height=\'%s\' style=\'background:%s\'/>', 1, 1, $color));
    }
}
