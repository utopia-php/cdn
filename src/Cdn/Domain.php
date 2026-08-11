<?php

namespace Utopia\Cdn;

final class Domain
{
    public static function validate(string $domain): string
    {
        if ($domain === '' || $domain !== \strtolower($domain) || \filter_var($domain, \FILTER_VALIDATE_DOMAIN, \FILTER_FLAG_HOSTNAME) === false) {
            throw new \InvalidArgumentException('Domain must be a lowercase hostname without a scheme, port, path, or trailing slash.');
        }

        return $domain;
    }

    /**
     * @param array<int, mixed> $paths
     * @return array<int, string>
     */
    public static function validatePaths(array $paths): array
    {
        foreach ($paths as $path) {
            if (!\is_string($path) || !\str_starts_with($path, '/')) {
                throw new \InvalidArgumentException('Every cache path must be a string beginning with "/".');
            }
        }

        return $paths;
    }
}
