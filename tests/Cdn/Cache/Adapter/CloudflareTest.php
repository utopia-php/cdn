<?php

namespace Utopia\Tests\Cdn\Cache\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Cache\Adapter;
use Utopia\Cdn\Cache\Adapter\Cloudflare;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Tests\Cdn\TestClient;

class CloudflareTest extends TestCase
{
    public function testPurgesPathsAndDomain(): void
    {
        $client = new TestClient(\array_fill(0, 2, $this->ok()));
        $cdn = new Cloudflare('zone-id', 'token', $client);

        $cdn->purgePaths('example.com', ['/a', '/b?x=1']);
        $cdn->purgeDomain('example.com');

        $this->assertSame(['files' => ['https://example.com/a', 'https://example.com/b?x=1']], $client->calls[0]['body']);
        // Cloudflare purges a hostname natively, so the domain reaches the request
        // and nothing served for another hostname is touched.
        $this->assertSame(['hosts' => ['example.com']], $client->calls[1]['body']);
        $this->assertSame('https://api.cloudflare.com/client/v4/zones/zone-id/purge_cache', $client->calls[1]['url']);
    }

    public function testBatchesPaths(): void
    {
        $client = new TestClient(\array_fill(0, 2, $this->ok()));
        $cdn = new Cloudflare('zone', 'token', $client);

        $cdn->purgePaths('example.com', \array_fill(0, Cloudflare::ITEMS_PER_PURGE + 1, '/a'));

        $this->assertCount(2, $client->calls);
        $this->assertCount(Cloudflare::ITEMS_PER_PURGE, $client->calls[0]['body']['files']);
        $this->assertCount(1, $client->calls[1]['body']['files']);
    }

    public function testPurgesCacheTags(): void
    {
        $client = new TestClient([$this->ok()]);

        (new Cloudflare('zone', 'token', $client))->purgeKeys(['tag-a', 'tag-b']);

        $this->assertSame(['tags' => ['tag-a', 'tag-b']], $client->calls[0]['body']);
    }

    public function testBatchesCacheTags(): void
    {
        $client = new TestClient(\array_fill(0, 2, $this->ok()));

        (new Cloudflare('zone', 'token', $client))->purgeKeys(\array_fill(0, Cloudflare::ITEMS_PER_PURGE + 1, 'tag'));

        $this->assertCount(2, $client->calls);
    }

    public function testBatchSizeIsConfigurableForPlansThatAllowMore(): void
    {
        $client = new TestClient([$this->ok()]);

        (new Cloudflare('zone', 'token', $client, itemsPerPurge: 100))
            ->purgeKeys(\array_fill(0, 100, 'tag'));

        // One request rather than four, for a plan whose documented ceiling is higher.
        $this->assertCount(1, $client->calls);
    }

    public function testZonePurgeIsItsOwnOperation(): void
    {
        $client = new TestClient([$this->ok()]);

        (new Cloudflare('zone', 'token', $client))->purgeZone();

        $this->assertSame(['purge_everything' => true], $client->calls[0]['body']);
    }

    public function testZonePurgeIsNotReachableThroughTheAdapterInterface(): void
    {
        $this->assertNotContains('purgeZone', \get_class_methods(Adapter::class));
    }

    public function testRejectsAPurgeTheBodyReportsAsFailed(): void
    {
        // A 2xx alone does not mean the purge happened.
        $client = new TestClient([new Response(200, body: new Stream('{"success":false,"errors":[{"message":"Invalid zone"}]}'))]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cloudflare purge failed with status 200: Invalid zone');
        (new Cloudflare('zone', 'token', $client))->purgeDomain('example.com');
    }

    public function testEmptyPurgesTouchNothing(): void
    {
        $client = new TestClient([]);
        $cdn = new Cloudflare('zone', 'token', $client);

        $cdn->purgePaths('example.com', []);
        $cdn->purgeKeys([]);

        $this->assertSame([], $client->calls);
    }

    private function ok(): Response
    {
        return new Response(200, body: new Stream('{"success":true}'));
    }
}
