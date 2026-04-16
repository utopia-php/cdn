<?php

namespace Utopia\Tests\Cdn;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Cache;
use Utopia\Cdn\Cache\Adapter;

class CacheTest extends TestCase
{
    public function testPurgeUrlsDelegatesToAdapter(): void
    {
        $calls = new \ArrayObject();

        $cache = new Cache(new class ($calls) implements Adapter {
            /**
             * @param \ArrayObject<int, array<string, array<int, string>>> $calls
             */
            public function __construct(private \ArrayObject $calls)
            {
            }

            public function purgeUrls(array $urls): void
            {
                $this->calls->append(['urls' => $urls]);
            }

            public function purgeKeys(array $keys): void
            {
                $this->calls->append(['keys' => $keys]);
            }
        });

        $cache->purgeUrls(['https://example.com/file.png']);

        $this->assertSame([['urls' => ['https://example.com/file.png']]], $calls->getArrayCopy());
    }

    public function testPurgeKeysDelegatesToAdapter(): void
    {
        $calls = new \ArrayObject();

        $cache = new Cache(new class ($calls) implements Adapter {
            /**
             * @param \ArrayObject<int, array<string, array<int, string>>> $calls
             */
            public function __construct(private \ArrayObject $calls)
            {
            }

            public function purgeUrls(array $urls): void
            {
                $this->calls->append(['urls' => $urls]);
            }

            public function purgeKeys(array $keys): void
            {
                $this->calls->append(['keys' => $keys]);
            }
        });

        $cache->purgeKeys(['host-deadbeef']);

        $this->assertSame([['keys' => ['host-deadbeef']]], $calls->getArrayCopy());
    }
}
