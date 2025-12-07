<?php

namespace alexbrukhty\crafttoolkit\models;

use craft\base\Model;

/**
 * Toolkit settings
 */
class Settings extends Model
{
    public const TRANSFORMER_IMAGE_WSRV = 'wsrvImage';
    public const TRANSFORMER_IMAGE_CLOUDFLARE = 'cloudflareImage';
    public const TRANSFORMER_VIDEO_CLOUDFLARE = 'cloudflareVideo';
    public bool $cacheEnabled = false;
    public array $cacheInclude = [];
    public array $cacheExclude = [];
    public array $cacheRelations = [];
    public array $excludeSiteIds = [];
    public string $cacheBasePath = '@webroot/static';
    public string $cloudflareToken = '';
    public string $cloudflareZone = '';
    public string $cloudflareDomain = '';
    public bool $cloudflareEnabled = false;
    public bool $imageTransformEnabled = false;
    public bool $videoTransformEnabled = false;
    public array $videoTransforms = [];
    public string $imageTransformAdapter = self::TRANSFORMER_IMAGE_WSRV;
    public string $videoTransformAdapter = self::TRANSFORMER_VIDEO_CLOUDFLARE;
    public array $imageTransformVolumes = [];
    public array $imageTransformFieldsOverride = [];
    public array $warmSiteIds = [];
    public string $sitemapUrl = 'sitemap.xml';
    // for use in dev mode only to make local images available for external API
    public string $imageTransformPublicUrl = '';
    public string $ga4MeasurementId = '';
    public string $ga4MeasurementSecret = '';
    public string $mailchimpApiKey = '';
    public string $mailchimpListId = '';
    public string $mailchimpHoneypotFieldHandle = 'zipCode';
}
