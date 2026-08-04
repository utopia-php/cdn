<?php

namespace Utopia\Cdn\Cache\Adapter;

use Utopia\Cdn\Cache\Adapter;
use Utopia\Cdn\Domain;
use Utopia\Cdn\Exception\Configuration;

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
        foreach ($this->all() as $adapter) {
            $adapter->purgeKeys($keys);
        }
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

    /** @return array<int, Adapter> */
    private function all(): array
    {
        $adapters = [$this->appDomainAdapter, $this->networkAdapter, ...$this->customDomainAdapters];
        $unique = [];

        foreach ($adapters as $adapter) {
            $unique[\spl_object_id($adapter)] = $adapter;
        }

        return \array_values($unique);
    }
}
