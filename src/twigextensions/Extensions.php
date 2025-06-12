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
            new TwigFunction('player', [$this, 'player'], ['is_safe' => ['html']]),
            new TwigFunction('htmxValsObj', [$this, 'htmxValsObj']),
            new TwigFunction('htmxVals', [$this, 'htmxVals']),
            new TwigFunction('arrayGet', [ArrayHelper::class, 'getValue']),
        ];
    }

    public function getAssetRatio($asset): string
    {
        if (!$asset) return '';

        $width = $asset[ImageTransformService::overrideFields('assetWidth')] ?? $asset->width ?? 1920;
        $height = $asset[ImageTransformService::overrideFields('assetHeight')] ?? $asset->height ?? 1080;

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
    public function mediaBase(Asset|null $asset, $options = [], $transformName = 'fullWidth'): string
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
                            'crossorigin' => 'anonymous',
                            'data-src' => $lazy ? $asset->url : null,
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
            $imagerX = Craft::$app->plugins->getPlugin('imager-x');

            if ($imagerX && $imagerX->isInstalled) {
                $transforms = $imagerX->imager->transformImage($asset, $transformName) ?? [];
                $srcset = $this->fixSrcsetSpaces($imagerX->imager->srcset($transforms)) ?? null;
                $src = str_replace(' ', '%20', end($transforms)->url ?? $asset->url);
            } elseif (ImageTransformService::isEnabled()) {
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
    public function media(Asset|null $asset, $options = [], $transformName = 'fullWidth'): string
    {
        $mobileMedia = $asset[ImageTransformService::overrideFields('mobileImage')][0] ?? ($asset[ImageTransformService::overrideFields('mobileVideo')][0] ?? null);
        return ($mobileMedia ? $this->mediaBase($mobileMedia, [...$options, 'isMobile' => !!$mobileMedia], $transformName) : '').$this->mediaBase($asset, [...$options, 'hasMobile' => !!$mobileMedia], $transformName);
    }

    public function player(Asset|null $asset): string
    {
        $poster = $asset[ImageTransformService::overrideFields('isExternalVideo')] ? $asset : $asset[ImageTransformService::overrideFields('videoThumbnail')]->eagerly()->one();
        $url = $asset[ImageTransformService::overrideFields('externalVideoUrl')];

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
}
