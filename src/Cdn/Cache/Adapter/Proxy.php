<?php

namespace Utopia\Cdn\Cache\Adapter;

use Utopia\Cdn\Cache\Adapter;
use Utopia\Cdn\Domain;
use Utopia\Cdn\Exception\Configuration;
use Utopia\Cdn\Exception\UnsupportedOperation;

class Proxy implements Adapter
{
    /**
     * @param array<int, Adapter> $customDomainAdapters
     * @param array<int, string> $networkDomains
     */
    public function __construct(
        private string $appDomain,
        private Adapter $appDomainAdapter,
        private Adapter $networkAdapter,
        private array $customDomainAdapters,
        private array $networkDomains = [],
    ) {
        $this->appDomain = Domain::validate($this->appDomain);
        $this->networkDomains = \array_map([Domain::class, 'validate'], $this->networkDomains);
    }

    public function purgePaths(string $domain, array $paths): void
    {
        $domain = Domain::validate($domain);
        $paths = Domain::validatePaths($paths);

        if ($paths === []) {
            return;
        }

        foreach ($this->select($domain) as $adapter) {
            $adapter->purgePaths($domain, $paths);
        }
    }

    public function purgeDomain(string $domain): void
    {
        $domain = Domain::validate($domain);

        foreach ($this->select($domain) as $adapter) {
            $adapter->purgeDomain($domain);
        }
    }

    public function purgeKeys(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        throw new UnsupportedOperation(
            'Cache key purging cannot be routed by domain. Select the service or zone adapter before purging keys.'
        );
    }

    /** @return array<int, Adapter> */
    private function select(string $domain): array
    {
        if ($domain === $this->appDomain) {
            return [$this->appDomainAdapter];
        }

        if (\in_array($domain, $this->networkDomains, true)) {
            return [$this->networkAdapter];
        }

        if ($this->customDomainAdapters === []) {
            throw new Configuration('No cache adapters are configured for custom domains.');
        }

        return $this->customDomainAdapters;
    }
}
