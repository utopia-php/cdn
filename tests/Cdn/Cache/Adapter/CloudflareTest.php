<?php

namespace Utopia\Tests\Cdn\Cache\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Cache\Adapter\Cloudflare;
use Utopia\Fetch\Client;
use Utopia\Fetch\Response;
use Utopia\Tests\Cdn\FetchAdapter;

class CloudflareTest extends TestCase
{
    public function testPurgePathsSendsUrlsAsProvided(): void
    {
        $adapter = new FetchAdapter([
            new Response(200, '{"success":true}', []),
        ]);
        $client = (new Client($adapter))->setBaseUrl('https://api.cloudflare.com/client/v4');

        $cdn = new Cloudflare('zone-id', 'token', $client);
        $cdn->purgePaths(['https://example.com/a', 'https://example.com/b']);

        $this->assertCount(1, $adapter->calls);
        $this->assertSame('POST', $adapter->calls[0]['method']);
        $this->assertSame('https://api.cloudflare.com/client/v4/zones/zone-id/purge_cache', $adapter->calls[0]['url']);
        $this->assertSame(
            '{"files":["https:\/\/example.com\/a","https:\/\/example.com\/b"]}',
            $adapter->calls[0]['body']
        );
    }
}
