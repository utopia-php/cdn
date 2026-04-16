<?php

namespace Utopia\Cdn\Cache;

interface Adapter
{
    /**
     * @param array<int, string> $paths
     */
    public function purgePaths(array $paths): void;
}
