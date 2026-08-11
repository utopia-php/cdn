<?php

namespace Utopia\Tests\Cdn\Cache\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Cache\Adapter\Cloudflare;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Tests\Cdn\TestClient;

class CloudflareTest extends TestCase
{
    public function testPurgesPathsAndDomain(): void
    {
        $client = new TestClient([new Response(200, body: new Stream('{"success":true}')), new Response(200, body: new Stream('{"success":true}'))]);
        $cdn = new Cloudflare('zone-id', 'token', $client);

        $cdn->purgePaths('example.com', ['/a', '/b?x=1']);
        $cdn->purgeDomain('example.com');

        $this->assertSame(['files' => ['https://example.com/a', 'https://example.com/b?x=1']], $client->calls[0]['body']);
        $this->assertSame(['hosts' => ['example.com']], $client->calls[1]['body']);
    }

    public function testBatchesPaths(): void
    {
        $client = new TestClient([new Response(200, body: new Stream('{"success":true}')), new Response(200, body: new Stream('{"success":true}'))]);
        $cdn = new Cloudflare('zone', 'token', $client);
        $cdn->purgePaths('example.com', \array_fill(0, 31, '/a'));
        $this->assertCount(2, $client->calls);
    }

    public function testPurgesCacheTags(): void
    {
        $client = new TestClient([new Response(200, body: new Stream('{"success":true}'))]);

        (new Cloudflare('zone', 'token', $client))->purgeKeys(['tag-a', 'tag-b']);

        $this->assertSame(['tags' => ['tag-a', 'tag-b']], $client->calls[0]['body']);
    }

    public function testBatchesCacheTags(): void
    {
        $client = new TestClient([new Response(200, body: new Stream('{"success":true}')), new Response(200, body: new Stream('{"success":true}'))]);

        (new Cloudflare('zone', 'token', $client))->purgeKeys(\array_fill(0, 31, 'tag'));

        $this->assertCount(2, $client->calls);
    }
}
