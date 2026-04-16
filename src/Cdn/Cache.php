<?php

namespace Utopia\Cdn;

use Utopia\Cdn\Cache\Adapter;

class Cache
{
    public function __construct(private Adapter $adapter)
    {
    }

    /**
     * @param array<int, string> $urls
     */
    public function purgeUrls(array $urls): void
    {
        $this->adapter->purgeUrls($urls);
    }

    /**
     * @param array<int, string> $keys
     */
    public function purgeKeys(array $keys): void
    {
        $this->adapter->purgeKeys($keys);
    }
}
