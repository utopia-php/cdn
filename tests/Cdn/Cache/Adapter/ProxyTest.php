<?php

namespace Utopia\Tests\Cdn\Cache\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Cache\Adapter;
use Utopia\Cdn\Cache\Adapter\Proxy;
use Utopia\Cdn\Exception\Configuration;
use Utopia\Cdn\Exception\UnsupportedOperation;

class ProxyTest extends TestCase
{
    public function testRoutesAndFansOut(): void
    {
        $calls = new \ArrayObject();
        $app = $this->adapter('app', $calls);
        $network = $this->adapter('network', $calls);
        $customA = $this->adapter('custom-a', $calls);
        $customB = $this->adapter('custom-b', $calls);
        $proxy = new Proxy('app.example.com', $app, $network, [$customA, $customB], ['network.example.com']);

        $proxy->purgeDomain('app.example.com');
        $proxy->purgePaths('network.example.com', ['/a']);
        $proxy->purgeDomain('customer.example.com');
        $proxy->purgeKeys(['key']);

        $this->assertSame(['app:domain', 'network:paths', 'custom-a:domain', 'custom-b:domain', 'app:keys', 'network:keys', 'custom-a:keys', 'custom-b:keys'], $calls->getArrayCopy());
    }

    public function testRejectsMissingCustomAdapters(): void
    {
        $adapter = $this->adapter('app', new \ArrayObject());
        $proxy = new Proxy('app.example.com', $adapter, $adapter, []);
        $this->expectException(Configuration::class);
        $proxy->purgeDomain('custom.example.com');
    }

    public function testKeyPurgeSkipsUnsupportedAdapters(): void
    {
        $calls = new \ArrayObject();
        $unsupported = $this->adapter('cloudflare', $calls, false);
        $fastly = $this->adapter('fastly', $calls);
        $proxy = new Proxy('app.example.com', $unsupported, $fastly, [$unsupported, $fastly]);

        $proxy->purgeKeys(['key']);

        $this->assertSame(['fastly:keys'], $calls->getArrayCopy());
    }

    public function testKeyPurgeFailsWhenEveryAdapterIsUnsupported(): void
    {
        $unsupported = $this->adapter('cloudflare', new \ArrayObject(), false);
        $proxy = new Proxy('app.example.com', $unsupported, $unsupported, [$unsupported]);

        $this->expectException(UnsupportedOperation::class);
        $proxy->purgeKeys(['key']);
    }

    /** @param \ArrayObject<int, mixed> $calls */
    private function adapter(string $name, \ArrayObject $calls, bool $supportsKeys = true): Adapter
    {
        return new class ($name, $calls, $supportsKeys) implements Adapter {
            /** @param \ArrayObject<int, mixed> $calls */
            public function __construct(private string $name, private \ArrayObject $calls, private bool $supportsKeys)
            {
            }
            public function purgePaths(string $domain, array $paths): void
            {
                $this->calls->append($this->name . ':paths');
            }
            public function purgeDomain(string $domain): void
            {
                $this->calls->append($this->name . ':domain');
            }
            public function purgeKeys(array $keys): void
            {
                if (!$this->supportsKeys) {
                    throw new UnsupportedOperation('Unsupported.');
                }
                $this->calls->append($this->name . ':keys');
            }
        };
    }
}
