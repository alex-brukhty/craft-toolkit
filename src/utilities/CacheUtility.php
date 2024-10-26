<?php

namespace alexbrukhty\crafttoolkit\utilities;

use alexbrukhty\crafttoolkit\Toolkit;
use Craft;
use craft\base\Utility;

class CacheUtility extends Utility
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Toolkit Cache';
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'toolkit-cache';
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {

        return '';
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        return Craft::$app->getView()->renderTemplate('_toolkit/cacheUtility', [
            'cachedPagesCount' => Toolkit::getInstance()->cacheService->getCachedPageCount(),
        ]);
    }
}