<?php

namespace alexbrukhty\crafttoolkit\models;

use craft\base\Model;

/**
 * Toolkit settings
 */
class Settings extends Model
{
    public bool $cacheEnabled = false;
    public array $cacheInclude = [];
    public array $cacheExclude = [];
    public array $excludeSiteIds = [];
    public string $cacheBasePath = '@webroot/static';
    public bool $imageTransformEnabled = false;
    public string $imageTransformApiUrl = 'https://wsrv.nl/';
    public array $imageTransformVolumes = [];

    public array $warmSiteIds = [];

    public string $sitemapUrl = 'sitemap.xml';

    /**
     * @var string
     * for use in dev mode only to make local images available for external API
     */
    public string $imageTransformPublicUrl = '';

    public string $ga4MeasurementId = '';
    public string $ga4MeasurementSecret = '';

    public string $mailchimpApiKey = '';
    public string $mailchimpListId = '';
}
