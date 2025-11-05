<?php

namespace alexbrukhty\crafttoolkit\twigextensions;

use Craft;
use craft\elements\Asset;
use craft\errors\InvalidFieldException;
use craft\errors\SiteNotFoundException;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\helpers\ArrayHelper;
use alexbrukhty\crafttoolkit\services\ImageTransformService;
use Throwable;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * @author    Alex
 * @package   Useful twig stuff
 * @since     0.0.1
 */

class Extensions extends AbstractExtension
{

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
            new TwigFunction('getSrc', [$this, 'getSrc']),
            new TwigFunction('isExternalUrl', [$this, 'isExternalUrl']),
            new TwigFunction('class', [$this, 'classHelper'], ['is_safe' => ['html']]),
            new TwigFunction('style', [$this, 'styleHelper'], ['is_safe' => ['html']]),
            new TwigFunction('styleFiltered', [$this, 'styleFiltered']),
            new TwigFunction('mediaBase', [$this, 'mediaBase'], ['is_safe' => ['html']]),
            new TwigFunction('media', [$this, 'media'], ['is_safe' => ['html']]),
            new TwigFunction('imageMarkup', [$this, 'imageMarkup'], ['is_safe' => ['html']]),
            new TwigFunction('transformedMedia', [$this, 'transformedMedia'], ['is_safe' => ['html']]),
            new TwigFunction('player', [$this, 'player'], ['is_safe' => ['html']]),
            new TwigFunction('transformedSrc', [$this, 'transformedSrc']),
            new TwigFunction('htmxValsObj', [$this, 'htmxValsObj']),
            new TwigFunction('htmxVals', [$this, 'htmxVals']),
            new TwigFunction('hexBrightness', [$this, 'hexBrightness']),
            new TwigFunction('arrayGet', [ArrayHelper::class, 'getValue']),
        ];
    }

    public function getAssetRatio($asset): string
    {
        if (!$asset) return '';

        $width = $asset[ImageTransformService::overrideFields('assetWidth')] ?? $asset->width ?? $asset['width'] ?? 1920;
        $height = $asset[ImageTransformService::overrideFields('assetHeight')] ?? $asset->height ?? $asset['height'] ?? 1080;

        return round(($height / $width) * 100, 1) . '%';
    }

    public function urlHelper(): UrlHelper
    {
        return new UrlHelper();
    }

    public function getIconName(string $url): string
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

    public function classHelper(Array $array): string
    {
        return Html::renderTagAttributes(['class' => $array]);
    }

    public function styleFiltered(Array $obg): array
    {
        return array_filter($obg, function ($val) {
            return !!$val;
        });
    }

    public function styleHelper(Array $obg): string
    {
        return Html::renderTagAttributes(['style' => $this->styleFiltered($obg)]);
    }

    public function fixSrcsetSpaces($string): string
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

    private function _parseSized(string $sizes): string
    {
        // sm:2,md:4,lg:3,4
        $breaks = [
            'sm' => '(max-width: 767px) ',
            'md' => '(max-width: 1023px) ',
            'lg' => '(max-width: 1366px) ',
            'xl' => '(max-width: 1719px) ',
        ];

        $sizeParts = explode(',', $sizes);
        $parsedParts = [];
        foreach ($sizeParts as $part) {
            $innerParts = explode(':', $part);
            if (count($innerParts) == 2) {
                $col = floor(100 / $innerParts[1]) . "vw";
                $parsedParts[] = $breaks[$innerParts[0]] . $col;
            } else {
                $parsedParts[] = floor(100 / $innerParts[0]) . "vw";
            }
        }

        return implode(', ', $parsedParts);
    }

    /**
     * @throws InvalidFieldException
     */
    public function getSrc(Asset|null $asset, bool $last = false): string
    {
        return $asset ? ImageTransformService::getSrc($asset, $last) : '';
    }

    public function imageMarkup(string $src, array $options): string
    {
        $srcset    = $options['srcset'] ?? null;
        $alt       = $options['alt'] ?? null;
        $class     = $options['class'] ?? null;
        $width     = $options['width'] ?? null;
        $height    = $options['height'] ?? null;
        $lazy      = $options['lazy'] ?? true;
        $sizes     = $options['sizes'] ?? '';
        $isGif     = $options['isGif'] ?? false;
        $isPng     = $options['isPng'] ?? false;
        $inset     = $options['inset'] ?? false;
        $grayscale = $options['grayscale'] ?? false;
        $hasMobile = $options['hasMobile'] ?? false;
        $isMobile = $options['isMobile'] ?? false;

        $parsedSizes = $sizes ? $this->_parseSized($sizes) : '';
        $ratioSvg = $width && $height ? Html::tag('svg', null, [
            'class' => 'image-ratio',
            'viewBox' => "0 0 $width $height",
            'xmlns' => 'http://www.w3.org/2000/svg',
        ]) : '';

        return Html::tag(
            'div',
            Html::tag('img', null, [
                'class' => [
                    $lazy ? 'lazy' : null,
                    $grayscale ? 'grayscale' : null,
                ],
                'alt' => $alt ?? null,
                'sizes' => !$isGif ? $parsedSizes : null,
                'srcset' => $srcset,
                'src' => $src,
                'loading' => $lazy ? 'lazy' : null,
                'onload' => $lazy ? 'this.classList.add("loaded")' : null
            ]).$ratioSvg,
            [
                'class' => [
                    'image-wrap',
                    $hasMobile ? 'to-md:hidden' : null,
                    $isMobile ? 'md:hidden' : null,
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
    public function mediaBase(Asset|null $asset, $options = [], int|null $transformWidth = null): string
    {
        if (!$asset) return '';

        $width = $options['width'] ?? ($asset[ImageTransformService::overrideFields('assetWidth')] ?? ($asset->width ?? 16));
        $height = $options['height'] ?? ($asset[ImageTransformService::overrideFields('assetHeight')] ?? ($asset->height ?? 9));
        $lazy = $options['lazy'] ?? true;
        $alt = $options['alt'] ?? null;
        $autoplay = $options['autoplay'] ?? true;
        $class = $options['class'] ?? '';
        $grayscale = $options['grayscale'] ?? false;
        $sizes = $options['sizes'] ?? '';
        $isGif = $asset->extension == 'gif';
        $isPng = $asset->extension == 'png';
        $inset = $options['inset'] ?? false;
        $hasMobile = $options['hasMobile'] ?? false;
        $isMobile = $options['isMobile'] ?? false;
        $asPlayer = $options['asPlayer'] ?? ($asset->asPlayer ?? false);

        $ratioSvg = Html::tag('svg', null, [
            'class' => 'image-ratio',
            'viewBox' => "0 0 $width $height",
            'xmlns' => 'http://www.w3.org/2000/svg',
        ]);

        if ($asset->kind === 'video') {
            $src = $asset->url;

            if (ImageTransformService::isVideoEnabled()) {
                $src = ImageTransformService::getSrc($asset, null, $transformWidth);
            }

            if ($asPlayer) {
                return $this->player($asset, [...$options, 'src' => $src]);
            }

            return Html::tag(
                'div',
                Html::tag(
                    'video',
                    Html::tag(
                        'source',
                        null,
                        [
                            'src' => !$lazy ? $src : null,
                            'crossorigin' => 'anonymous',
                            'data-src' => $lazy ? $src : null,
                            'type' => 'video/mp4',
                        ]
                    ),
                    [
                        'muted' => '',
                        'playsinline' => '',
                        'loop' => '',
                        'autoplay' => $autoplay,
                        'poster' => $asset[ImageTransformService::overrideFields('videoThumbnail')]?->one()->url ?? null
                    ]
                ).$ratioSvg,
                [
                    'class' => [
                        'video-wrap',
                        $hasMobile ? 'to-md:hidden' : null,
                        $isMobile ? 'md:hidden' : null,
                        $lazy ? 'lazy-video' : null,
                        $inset ? 'inset-image' : null,
                        $class,
                    ]
                ]
            );
        }

        if ($asset->extension === 'svg') {
            return Html::tag(
                'div',
                Html::svg($asset),
                [
                    'class' => [
                        $class ?? null,
                        $hasMobile ? 'to-md:hidden' : null,
                        $isMobile ? 'md:hidden' : null,
                    ],
                ]
            );
        }

        if ($isGif) {
            $srcset = null;
            $src = $asset->url;
        } else {
            if (ImageTransformService::isEnabled()) {
                $src = ImageTransformService::getSrc($asset);
                $srcset = ImageTransformService::getSrcset($asset);
            } else {
                $srcset = $asset->getSrcset([800, 1300, 1920], ['format' => 'webp']);
                $src = $asset->url;
            }
        }

        return $this->imageMarkup($src, [
            'srcset' => $srcset,
            'class' => $class,
            'alt' => $alt ?? ($asset->alt ?? $asset->title),
            'width' => $width,
            'height' => $height,
            'lazy' => $lazy,
            'sizes' => $sizes,
            'isGif' => $isGif,
            'isPng' => $isPng,
            'inset' => $inset,
            'grayscale' => $grayscale,
            'hasMobile' => $hasMobile,
            'isMobile' => $isMobile,
        ]);
    }

    /**
     * @throws Throwable
     */
    public function media(Asset|null $asset, $options = []): string
    {
        $mobileMedia = $asset[ImageTransformService::overrideFields('mobileImage')][0] ?? ($asset[ImageTransformService::overrideFields('mobileVideo')][0] ?? null);
        return ($mobileMedia ? $this->mediaBase($mobileMedia, [...$options, 'isMobile' => !!$mobileMedia]) : '').$this->mediaBase($asset, [...$options, 'hasMobile' => !!$mobileMedia]);
    }

    public function transformedMedia(Asset $asset, $options = [], $transforms = [], $transformsMobile = [], int|null $width = null, int|null $mobileWdith = null): string
    {
        $mobileMedia = $asset[ImageTransformService::overrideFields('mobileVideo')][0] ?? null;
        ImageTransformService::transformMediaOnDemand($asset, $transforms);
        $firstWidth = count($transforms) > 0 ?  $transforms[0]['width'] ?? null : null;
        $firstMobileWidth = count($transformsMobile) > 0 ?  $transformsMobile[0]['width'] ?? null : null;

        if ($mobileMedia) {
            ImageTransformService::transformMediaOnDemand($mobileMedia, $transformsMobile);
        }

        return ($mobileMedia ? $this->mediaBase($mobileMedia, [...$options, 'isMobile' => true], $firstMobileWidth) : '').$this->mediaBase($asset, [...$options, 'hasMobile' => !!$mobileMedia], $firstWidth);
    }

    public function transformedSrc($asset, $transforms = [])
    {
        if (count($transforms) > 0) {
            ImageTransformService::transformMediaOnDemand($asset, $transforms);
        }
        return $asset ? ImageTransformService::getSrc($asset, null, $transforms[0]['width'] ?? null) : '';
    }

    public function player(Asset|null $asset, $options = []): string
    {
        $inset = $options['inset'] ?? false;
        $poster = $asset[ImageTransformService::overrideFields('isExternalVideo')] ? $asset : $asset[ImageTransformService::overrideFields('videoThumbnail')]->eagerly()->one();
        $url = $options['src'] ?? $asset[ImageTransformService::overrideFields('externalVideoUrl')];
        $autoplay = $options['autoplay'] ?? null;
        $width = $asset->assetWidth ?? ($asset->width ?? 1920);
        $height = $asset->assetHeight ?? ($asset->height ?? 1080);

        return Html::tag(
            'b-player',
            Html::tag(
                'div',
                Html::tag('video', null, [
                    'src' => $url ?? ($asset->url ?? null),
                    'width' => $width,
                    'height' => $height,
                    'data' => [
                        'el' => 'main',
                        'poster' => $poster->url ?? null
                    ]
                ]),
                [
                    'class' => [
                        'video-player',
                        $inset ? 'inset-video' : null,
                    ]
                ]
            ),
            ['autoplay' => $autoplay]
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

    /**
     * @throws SiteNotFoundException
     */
    public function isExternalUrl($url): bool
    {
        return str_contains($url, '//') && !str_contains($url, UrlHelper::siteHost());
    }

    /**
     * @throws Exception
     * @throws InvalidConfigException
     */
    public function htmxValsObj(array $vals): array
    {
        $request = Craft::$app->getRequest();
        $vals[$request->csrfParam] = $request->getCsrfToken();

        if (isset($vals['redirect'])) {
            $vals['redirect'] = Craft::$app->getSecurity()->hashData($vals['redirect']);
        }

        return $vals;
    }

    /**
     * @throws Exception
     * @throws InvalidConfigException
     */
    public function htmxVals(array $vals): string
    {
        return json_encode($this->htmxValsObj($vals));
    }

     /**
      * Increases or decreases the brightness of a color by a percentage of the current brightness.
      *
      * @param string $hexCode        Supported formats: `#FFF`, `#FFFFFF`, `FFF`, `FFFFFF`
      * @param float  $adjustPercent  A number between -1 and 1. E.g. 0.3 = 30% lighter; -0.4 = 40% darker.
      *
      * @return  string
      *
      */
    public function hexBrightness(string $hexCode, float $adjustPercent): string {
        $hexCode = ltrim($hexCode, '#');

        if (strlen($hexCode) == 3) {
            $hexCode = $hexCode[0] . $hexCode[0] . $hexCode[1] . $hexCode[1] . $hexCode[2] . $hexCode[2];
        }

        $hexCode = array_map('hexdec', str_split($hexCode, 2));

        foreach ($hexCode as & $color) {
            $adjustableLimit = $adjustPercent < 0 ? $color : 255 - $color;
            $adjustAmount = ceil($adjustableLimit * $adjustPercent);

            $color = str_pad(dechex($color + $adjustAmount), 2, '0', STR_PAD_LEFT);
        }

        return '#' . implode($hexCode);
    }
}
