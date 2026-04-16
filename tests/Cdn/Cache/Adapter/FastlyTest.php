<?php

namespace Utopia\Tests\Cdn\Cache\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Cache\Adapter\Fastly;
use Utopia\Fetch\Client;
use Utopia\Fetch\Response;
use Utopia\Tests\Cdn\FetchAdapter;

class FastlyTest extends TestCase
{
    public function testPurgePathsBuildsFastlyPurgeUrl(): void
    {
        $adapter = new FetchAdapter([
            new Response(200, '{"status":"ok"}', []),
        ]);
        $client = (new Client($adapter))->setBaseUrl('https://api.fastly.com');

        $cdn = new Fastly('token', 'https://example.com', false, $client);
        $cdn->purgePaths(['/hello/world']);

        $this->assertCount(1, $adapter->calls);
        $this->assertSame('POST', $adapter->calls[0]['method']);
        $this->assertSame('https://api.fastly.com/purge/https://example.com/hello/world', $adapter->calls[0]['url']);
        $this->assertSame([], $adapter->calls[0]['body']);
    }
}
