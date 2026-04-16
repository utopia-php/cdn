<?php

namespace Utopia\Cdn\Cache;

interface Adapter
{
    /**
     * @param array<int, string> $urls
     */
    public function purgeUrls(array $urls): void;

    /**
     * @param array<int, string> $keys
     */
    public function purgeKeys(array $keys): void;
}
