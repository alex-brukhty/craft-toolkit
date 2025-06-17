<?php

namespace alexbrukhty\crafttoolkit\models;

use craft\base\Model;

/**
 * Toolkit settings
 */
class MediaTransform extends Model
{
    public int|null $width = null;

    public int|null $height = null;

    public string $format = 'auto';

    public int|null $quality = null;

    public string $fit = 'cover';
}
