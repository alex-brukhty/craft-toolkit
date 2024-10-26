<?php

namespace alexbrukhty\crafttoolkit\batchers;

use craft\base\Batchable;

/**
 * @since 4.14.0
 */
class SiteUrlBatcher implements Batchable
{
    public function __construct(
        private array $siteUrls,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function count(): int
    {
        return count($this->siteUrls);
    }

    /**
     * @inheritdoc
     */
    public function getSlice(int $offset, int $limit): iterable
    {
        return array_slice($this->siteUrls, $offset, $limit);
    }
}
