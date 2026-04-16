<?php

namespace Utopia\Tests\Cdn\Cache\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Cache\Adapter\Cloudflare;
use Utopia\Fetch\Response;
use Utopia\Tests\Cdn\TestClient;

class CloudflareTest extends TestCase
{
    public function testPurgeUrlsSendsUrlsAsProvided(): void
    {
        $client = new TestClient([
            new Response(200, '{"success":true}', []),
        ]);

        $cdn = new Cloudflare('zone-id', 'token', $client);
        $cdn->purgeUrls(['https://example.com/a', 'https://example.com/b']);

        $this->assertCount(1, $client->calls);
        $this->assertSame('POST', $client->calls[0]['method']);
        $this->assertSame('https://api.cloudflare.com/client/v4/zones/zone-id/purge_cache', $client->calls[0]['url']);
        $this->assertSame([
            'files' => [
                'https://example.com/a',
                'https://example.com/b',
            ],
        ], $client->calls[0]['body']);
    }

    public function testPurgeKeysThrowsUnsupportedException(): void
    {
        $client = new TestClient([]);

        $cdn = new Cloudflare('zone-id', 'token', $client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cloudflare cache key purging is not supported by this adapter.');

        $cdn->purgeKeys(['host-deadbeef']);
    }
}
