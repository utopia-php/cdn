<?php

namespace Utopia\Tests\Cdn\Cache;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Cache;
use Utopia\Cdn\Cache\Adapter;
use Utopia\Cdn\Cache\Adapter\Cloudflare;
use Utopia\Cdn\Cache\Adapter\Fastly;

/**
 * The interface is what keeps the adapters interchangeable, so it is asserted directly. A provider
 * that grew a purge the others lacked, or that spelled one differently, would show up here rather
 * than in a caller that assumed both behaved alike.
 */
class AdapterTest extends TestCase
{
    private const array OPERATIONS = ['purgePaths', 'purgeDomain', 'purgeKeys', 'purgeZone'];

    public function testEveryAdapterOffersTheSameOperations(): void
    {
        $this->assertSame(self::OPERATIONS, \get_class_methods(Adapter::class));

        foreach ([Fastly::class, Cloudflare::class] as $adapter) {
            $this->assertContains(Adapter::class, \class_implements($adapter), $adapter . ' must implement the adapter interface');

            foreach (self::OPERATIONS as $operation) {
                $this->assertTrue(\method_exists($adapter, $operation), $adapter . ' is missing ' . $operation . '()');
            }
        }
    }

    public function testTheFacadeExposesEveryOperation(): void
    {
        // A purge reachable on an adapter but not through Cache would push callers
        // back to holding concrete adapters, which is what the interface avoids.
        foreach (self::OPERATIONS as $operation) {
            $this->assertTrue(\method_exists(Cache::class, $operation), 'Cache is missing ' . $operation . '()');
        }
    }

    public function testProviderAdaptersNameTheirBatchCeilingsAlike(): void
    {
        // Same names, provider-specific numbers: Fastly batches 256 keys per request
        // and purges one URL at a time, Cloudflare takes 30 of either.
        foreach ([Fastly::class, Cloudflare::class] as $adapter) {
            $this->assertTrue(\defined($adapter . '::PATHS_PER_PURGE'), $adapter . ' must declare PATHS_PER_PURGE');
            $this->assertTrue(\defined($adapter . '::KEYS_PER_PURGE'), $adapter . ' must declare KEYS_PER_PURGE');
        }

        $this->assertSame(1, Fastly::PATHS_PER_PURGE);
        $this->assertSame(256, Fastly::KEYS_PER_PURGE);
        $this->assertSame(30, Cloudflare::PATHS_PER_PURGE);
        $this->assertSame(30, Cloudflare::KEYS_PER_PURGE);
    }
}
