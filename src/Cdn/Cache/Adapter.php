<?php

namespace Utopia\Cdn\Cache;

interface Adapter
{
    /**
     * @param array<int, string> $paths
     */
    public function purgePaths(string $domain, array $paths): void;

    public function purgeDomain(string $domain): void;

    /**
     * @param array<int, string> $keys
     */
    public function purgeKeys(array $keys): void;
}
