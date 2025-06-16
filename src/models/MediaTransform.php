<?php

namespace alexbrukhty\crafttoolkit\models;

use craft\base\Model;

/**
 * Toolkit settings
 */
class MediaTransform extends Model
{
    public int $width;

    public int $height;

    public string $format = 'auto';

    public int $quality;

    public string $fit = 'cover';
}
