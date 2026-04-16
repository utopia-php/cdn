<?php

namespace Utopia\Tests\Cdn;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Cache;
use Utopia\Cdn\Cache\Adapter;

class CacheTest extends TestCase
{
    public function testPurgeDelegatesToAdapter(): void
    {
        $calls = new \ArrayObject();

        $cache = new Cache(new class ($calls) implements Adapter {
            /**
             * @param \ArrayObject<int, array<int, string>> $calls
             */
            public function __construct(private \ArrayObject $calls)
            {
            }

            public function purgePaths(array $paths): void
            {
                $this->calls->append($paths);
            }
        });

        $cache->purge(['https://example.com/file.png']);

        $this->assertSame([['https://example.com/file.png']], $calls->getArrayCopy());
    }
}
