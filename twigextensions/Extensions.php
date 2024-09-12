<?php

namespace modules\toolkit\twigextensions;

use Craft;
use craft\elements\Asset;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use Throwable;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * @author    Alex
 * @package   Useful twig stuff
 * @since     0.0.1
 */

class Extensions extends AbstractExtension
{

    public $formHandels = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getName()
    {
        return 'TheExtensions';
    }

    /**
     * @inheritdoc
     */
    public function getFilters()
    {
        return [
            new TwigFilter('halfSplit', [$this, 'halfSplit']),
        ];
    }

    /**
     * @inheritdoc
     */
    public function getFunctions()
    {
        return [
            new TwigFunction('getAssetRatio', [$this, 'getAssetRatio']),
            new TwigFunction('urlHelper', [$this, 'urlHelper']),
            new TwigFunction('getIconName', [$this, 'getIconName']),
            new TwigFunction('fixSrcsetSpaces', [$this, 'fixSrcsetSpaces']),
            new TwigFunction('getSrcsetSizes', [$this, 'getSrcsetSizes']),
            new TwigFunction('class', [$this, 'classHelper'], ['is_safe' => ['html']]),
            new TwigFunction('style', [$this, 'styleHelper'], ['is_safe' => ['html']]),
            new TwigFunction('mediaBase', [$this, 'mediaBase'], ['is_safe' => ['html']]),
            new TwigFunction('media', [$this, 'media'], ['is_safe' => ['html']]),
            new TwigFunction('player', [$this, 'player'], ['is_safe' => ['html']]),
        ];
    }

    public function getAssetRatio($asset)
    {
        if (!$asset) return '';

        $width = $asset->assetWidth ?? $asset->width ?? 1920;
        $height = $asset->assetHeight ?? $asset->height ?? 1080;

        return round(($height / $width) * 100, 1) . '%';
    }

    public function urlHelper() {
        return new UrlHelper();
    }

    public function getIconName(string $url)
    {
        $available = [
            'behance',
            'discord',
            'dribbble',
            'facebook',
            'instagram',
            'linkedin',
            'medium',
            'pinterest',
            'tiktok',
            'vimeo',
            'x-twitter',
            'youtube'
        ];
        preg_match('/^(?:https?:\/\/)?(?:www\.)?([^:\/\n.]+)/', $url, $matches);
        $textPart = $matches[1];
        $availableAlt = $textPart === 'x' ? 'x-twitter' : (
            $textPart === 'twitter'
                ? 'x-twitter'
                : $textPart
        );

        return $available[array_search($availableAlt, $available)].'.svg';
    }

    public function classHelper(Array $array)
    {
        return Html::renderTagAttributes(['class' => $array]);
    }

    public function styleHelper(Array $obg)
    {
        $filtered = array_filter($obg, function ($val) {
            return !!$val;
        });

        return Html::renderTagAttributes(['style' => $filtered]);
    }

    public function fixSrcsetSpaces($string)
    {
        $parts = explode(', ', $string);
        $fixedParts = [];
        foreach ($parts as $part) {
            $_part = explode('.', $part);
            $_part[0] = str_replace(' ', '%20', $_part[0]);
                $fixedParts[] = implode('.', $_part);
        }
        return implode(', ', $fixedParts);
    }

    /**
     * @throws ImagerException
     */
    public function getSrcsetSizes(Asset|null $asset, string $transformName)
    {

        if (!$asset || $asset->extension === 'gif') {
            return [
                'srcset' => null,
                'src' => $asset?->url
            ];
        }

        $imagerX = Craft::$app->plugins->getPlugin('imager-x');
        if ($imagerX instanceof ImagerX) {
            $transforms = $imagerX->imager->transformImage($asset, $transformName);

            return [
                'srcset' => $this->fixSrcsetSpaces($imagerX->imager->srcset($transforms)) ?? null,
                'src' => str_replace(' ' , '%20',end($transforms)->url ?? $asset->url)
            ];
        }
    }

    /**
     * @throws Throwable
     */
    public function mediaBase(Asset|null $asset, $transformName = 'fullWidth', $options = []): string
    {
        if (!$asset) return '';

        $width = $options['width'] ?? ($asset->assetWidth ?? ($asset->width ?? 16));
        $height = $options['height'] ?? ($asset->assetHeight ?? ($asset->height ?? 9));
        $lazy = $options['lazy'] ?? true;
        $autoplay = $options['autoplay'] ?? true;
        $class = $options['class'] ?? '';
        $grayscale = $options['grayscale'] ?? false;
        $sizes = $options['sizes'] ?? '100vw';
        $isGif = $asset->extension == 'gif';
        $isPng = $asset->extension == 'png';
        $inset = $options['inset'] ?? false;
        $mobileMedia = $options['hasMobile'] ?? false;

        $ratioSvg = Html::tag('svg', null, [
            'class' => 'image-ratio',
            'viewBox' => "0 0 $width $height",
            'xmlns' => 'http://www.w3.org/2000/svg',
        ]);

        if ($asset->kind === 'video') {
            return Html::tag(
                'div',
                Html::tag(
                    'video',
                    Html::tag(
                        'source',
                        null,
                        [
                            'src' => !$lazy ? $asset->url : null,
                            'data-src' => $lazy ? $asset->url : null,
                            'type' => 'video/mp4'
                        ]
                    ),
                    [
                        'muted' => '',
                        'playsinline' => '',
                        'loop' => '',
                        'autoplay' => $autoplay,
                        'poster' => $asset->videoThumbnail?->one()->url ?? null
                    ]
                ).$ratioSvg,
                [
                    'class' => [
                        'video-wrap',
                        $mobileMedia ? 'to-md:hidden' : null,
                        $lazy ? 'lazy-video' : null,
                        $inset ? 'inset-image' : null,
                        $class,
                    ]
                ]
            );
        }

        if ($asset->extension === 'svg') {
            return Html::svg($asset);
        }

        if ($isGif) {
            $srcset = null;
            $src = $asset->url;
        } else {
            $imagerX = Craft::$app->plugins->getPlugin('imager-x');

            if ($imagerX && $imagerX->isInstalled) {
                $transforms = $imagerX->imager->transformImage($asset, $transformName) ?? [];
                $srcset = $this->fixSrcsetSpaces($imagerX->imager->srcset($transforms)) ?? null;
                $src = str_replace(' ' , '%20',end($transforms)->url ?? $asset->url);
            } else {
                $srcset = $asset->getSrcset([800, 1300, 1920], ['format' => 'webp']);
                $src = $asset->url;
            }
        }

        return Html::tag(
            'div',
            Html::tag('img', null, [
                'class' => [
                    $lazy ? 'lazy' : null,
                    $grayscale ? 'grayscale' : null,
                ],
                'alt' => $asset->alt ?? (strip_tags($asset->caption) ?? $asset->title),
                'sizes' => !$isGif ? $sizes : null,
                'srcset' => $srcset,
                'src' => $src,
                'loading' => $lazy ? 'lazy' : null,
                'onload' => $lazy ? 'this.classList.add("loaded")' : null
            ]).$ratioSvg,
            [
                'class' => [
                    'image-wrap',
                    $mobileMedia ? 'to-md:hidden' : null,
                    $lazy ? 'lazy-wrap' : null,
                    $isPng ? 'is-transparent' : null,
                    $inset ? 'inset-image' : null,
                    $class,
                ]
            ]
        );
    }

    /**
     * @throws Throwable
     */
    public function media(Asset|null $asset, $transformName = 'fullWidth', $options = []): string
    {
        $mobileMedia = $asset->mobileImage->one ?? $asset->mobileVideo->one ?? null;

        return $this->mediaBase($mobileMedia, 'fullWidth', $options).$this->mediaBase($asset, $transformName, [...$options, 'hasMobile' => $mobileMedia]);
    }

    public function player(Asset|null $asset)
    {
        $poster = $asset->isExternalVideo ? $asset : $asset->videoThumbnail->eagerly()->one();
        $url = $asset->externalVideoUrl;

        return Html::tag(
            'b-player',
            Html::tag(
                'div',
                Html::tag('video', null, [
                    'src' => $url ?? ($asset->url ?? null),
                    'data' => [
                        'el' => 'main',
                        'poster' => $poster->url
                    ]
                ]),
                ['class' => 'video-wrap2']
            )
        );
    }

    public function halfSplit(string $string): string
    {
        $parts = explode(' ', $string);
        $half = ceil(count($parts) / 2);

        $firstHalf = implode(' ', array_slice($parts, 0, $half));
        $secondHalf = implode(' ', array_slice($parts, $half));
        return $firstHalf . '
        ' . $secondHalf;
    }
}
