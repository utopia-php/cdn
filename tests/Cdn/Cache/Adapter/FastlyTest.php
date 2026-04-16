<?php

namespace Utopia\Tests\Cdn\Cache\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Cache\Adapter\Fastly;
use Utopia\Fetch\Response;
use Utopia\Tests\Cdn\TestClient;

class FastlyTest extends TestCase
{
    public function testPurgeUrlsBuildsFastlyPurgeUrl(): void
    {
        $client = new TestClient([
            new Response(200, '{"status":"ok"}', []),
        ]);

        $cdn = new Fastly('token', null, false, $client);
        $cdn->purgeUrls(['https://example.com/hello/world']);

        $this->assertCount(1, $client->calls);
        $this->assertSame('POST', $client->calls[0]['method']);
        $this->assertSame('https://api.fastly.com/purge/https://example.com/hello/world', $client->calls[0]['url']);
        $this->assertSame([], $client->calls[0]['body']);
    }

    public function testPurgeKeysBuildsFastlyServicePurgeUrl(): void
    {
        $client = new TestClient([
            new Response(200, '{"status":"ok"}', []),
        ]);

        $cdn = new Fastly('token', 'service-id', false, $client);
        $cdn->purgeKeys(['host-deadbeef']);

        $this->assertCount(1, $client->calls);
        $this->assertSame('POST', $client->calls[0]['method']);
        $this->assertSame('https://api.fastly.com/service/service-id/purge/host-deadbeef', $client->calls[0]['url']);
        $this->assertSame([], $client->calls[0]['body']);
    }
}
