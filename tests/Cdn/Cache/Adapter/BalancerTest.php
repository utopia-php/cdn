<?php

namespace Utopia\Tests\Cdn\Cache\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Cache;
use Utopia\Cdn\Cache\Adapter;
use Utopia\Cdn\Cache\Adapter\Balancer;
use Utopia\Cdn\Exception\Configuration;
use Utopia\Cdn\Exception\Purge;
use Utopia\Cdn\Exception\UnsupportedOperation;
use Utopia\Cdn\Extend\CdnBalancer;
use Utopia\Cdn\Extend\CdnOption;
use Utopia\Cdn\Provider;

class BalancerTest extends TestCase
{
    public function testFansOutToEveryMatchingOption(): void
    {
        $calls = new \ArrayObject();
        $balancer = (new CdnBalancer())
            ->addOption(new CdnOption($this->adapter('fastly-edge', $calls), Provider::Fastly, true))
            ->addOption(new CdnOption($this->adapter('fastly-run', $calls), Provider::Fastly))
            ->addOption(new CdnOption($this->adapter('cloudflare', $calls), Provider::Cloudflare));

        $cache = new Cache(new Balancer($balancer));

        $cache->purgeDomain('example.com');
        $cache->purgePaths('example.com', ['/index.html']);
        $cache->purgeKeys(['domain-example.com']);

        $this->assertSame([
            'fastly-edge:domain', 'fastly-run:domain', 'cloudflare:domain',
            'fastly-edge:paths', 'fastly-run:paths', 'cloudflare:paths',
            'fastly-edge:keys', 'fastly-run:keys', 'cloudflare:keys',
        ], $calls->getArrayCopy());
    }

    public function testFiltersNarrowTheOptionsPurged(): void
    {
        $calls = new \ArrayObject();
        $balancer = (new CdnBalancer())
            ->addOption(new CdnOption($this->adapter('fastly-edge', $calls), Provider::Fastly, true))
            ->addOption(new CdnOption($this->adapter('fastly-run', $calls), Provider::Fastly))
            ->addOption(new CdnOption($this->adapter('cloudflare', $calls), Provider::Cloudflare));

        $balancer
            ->addFilter(fn (CdnOption $option): bool => $option->getProvider() === Provider::Fastly)
            ->addFilter(fn (CdnOption $option): bool => $option->isEdge());

        (new Cache(new Balancer($balancer)))->purgeDomain('example.com');

        $this->assertSame(['fastly-edge:domain'], $calls->getArrayCopy());
    }

    public function testCustomDomainsReachBothProviders(): void
    {
        $calls = new \ArrayObject();
        $balancer = (new CdnBalancer())
            ->addOption(new CdnOption($this->adapter('fastly-edge', $calls), Provider::Fastly, true))
            ->addOption(new CdnOption($this->adapter('fastly-run', $calls), Provider::Fastly))
            ->addOption(new CdnOption($this->adapter('cloudflare', $calls), Provider::Cloudflare));

        $balancer->addFilter(fn (CdnOption $option): bool => !$option->isEdge());

        (new Cache(new Balancer($balancer)))->purgeDomain('customer.example.com');

        $this->assertSame(['fastly-run:domain', 'cloudflare:domain'], $calls->getArrayCopy());
    }

    public function testOneFailingProviderStillPurgesTheOthers(): void
    {
        $calls = new \ArrayObject();
        $balancer = (new CdnBalancer())
            ->addOption(new CdnOption($this->adapter('fastly', $calls, fails: true), Provider::Fastly))
            ->addOption(new CdnOption($this->adapter('cloudflare', $calls), Provider::Cloudflare));

        try {
            (new Cache(new Balancer($balancer)))->purgeKeys(['domain-example.com']);
            $this->fail('Expected the failed provider to be reported.');
        } catch (Purge $error) {
            $this->assertSame('Cache cache key purging failed for fastly.', $error->getMessage());
            $this->assertCount(1, $error->getErrors());
        }

        // The point of the fan-out: Cloudflare was still purged after Fastly failed.
        $this->assertSame(['fastly:keys', 'cloudflare:keys'], $calls->getArrayCopy());
    }

    public function testUnsupportedOptionsAreSkippedButStillPurgeTheRest(): void
    {
        $calls = new \ArrayObject();
        $balancer = (new CdnBalancer())
            ->addOption(new CdnOption($this->adapter('fastly-no-service', $calls, supportsKeys: false), Provider::Fastly))
            ->addOption(new CdnOption($this->adapter('cloudflare', $calls), Provider::Cloudflare));

        (new Cache(new Balancer($balancer)))->purgeKeys(['domain-example.com']);

        $this->assertSame(['cloudflare:keys'], $calls->getArrayCopy());
    }

    public function testFailsWhenEveryOptionIsUnsupported(): void
    {
        $balancer = (new CdnBalancer())
            ->addOption(new CdnOption($this->adapter('fastly', new \ArrayObject(), supportsKeys: false), Provider::Fastly));

        $this->expectException(UnsupportedOperation::class);
        (new Cache(new Balancer($balancer)))->purgeKeys(['domain-example.com']);
    }

    public function testFailsWhenNoOptionMatchesTheFilters(): void
    {
        $balancer = (new CdnBalancer())
            ->addOption(new CdnOption($this->adapter('fastly', new \ArrayObject()), Provider::Fastly));

        $balancer->addFilter(fn (CdnOption $option): bool => $option->getProvider() === Provider::Cloudflare);

        $this->expectException(Configuration::class);
        $this->expectExceptionMessage('No cache options matched the balancer filters.');
        (new Cache(new Balancer($balancer)))->purgeDomain('example.com');
    }

    public function testEmptyPurgesTouchNoProvider(): void
    {
        $calls = new \ArrayObject();
        $balancer = (new CdnBalancer())
            ->addOption(new CdnOption($this->adapter('fastly', $calls), Provider::Fastly));

        $cache = new Cache(new Balancer($balancer));
        $cache->purgePaths('example.com', []);
        $cache->purgeKeys([]);

        $this->assertSame([], $calls->getArrayCopy());
    }

    public function testRejectsInvalidDomain(): void
    {
        $balancer = (new CdnBalancer())
            ->addOption(new CdnOption($this->adapter('fastly', new \ArrayObject()), Provider::Fastly));

        $this->expectException(\InvalidArgumentException::class);
        (new Balancer($balancer))->purgeDomain('https://example.com');
    }

    /** @param \ArrayObject<int, mixed> $calls */
    private function adapter(string $name, \ArrayObject $calls, bool $supportsKeys = true, bool $fails = false): Adapter
    {
        return new class ($name, $calls, $supportsKeys, $fails) implements Adapter {
            /** @param \ArrayObject<int, mixed> $calls */
            public function __construct(
                private string $name,
                private \ArrayObject $calls,
                private bool $supportsKeys,
                private bool $fails,
            ) {
            }

            public function purgePaths(string $domain, array $paths): void
            {
                $this->record('paths');
            }

            public function purgeDomain(string $domain): void
            {
                $this->record('domain');
            }

            public function purgeKeys(array $keys): void
            {
                if (!$this->supportsKeys) {
                    throw new UnsupportedOperation($this->name . ' cannot purge keys.');
                }

                $this->record('keys');
            }

            private function record(string $operation): void
            {
                $this->calls->append($this->name . ':' . $operation);

                if ($this->fails) {
                    throw new \RuntimeException($this->name . ' purge failed.');
                }
            }
        };
    }
}
