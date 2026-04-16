<?php

namespace Utopia\Cdn;

use Utopia\Cdn\Cache\Adapter;

class Cache
{
    public function __construct(private Adapter $adapter)
    {
    }

    /**
     * @param array<int, string> $paths
     */
    public function purge(array $paths): void
    {
        $this->adapter->purgePaths($paths);
    }
}
