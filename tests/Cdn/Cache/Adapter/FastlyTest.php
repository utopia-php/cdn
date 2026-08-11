<?php

namespace Utopia\Tests\Cdn\Cache\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Cache\Adapter\Fastly;
use Utopia\Fetch\Response;
use Utopia\Tests\Cdn\TestClient;

class FastlyTest extends TestCase
{
    public function testPurgesPathsDomainAndKeys(): void
    {
        $client = new TestClient(\array_fill(0, 3, new Response(200, '{"status":"ok"}', [])));
        $cdn = new Fastly('token', 'service-id', true, $client);

        $cdn->purgePaths('example.com', ['/hello world?x=1']);
        $cdn->purgeDomain('example.com');
        $cdn->purgeKeys(['key']);

        $this->assertSame('https://api.fastly.com/purge/example.com/hello%20world?x=1', $client->calls[0]['url']);
        $this->assertSame('https://api.fastly.com/service/service-id/purge_all', $client->calls[1]['url']);
        $this->assertSame('https://api.fastly.com/service/service-id/purge/key', $client->calls[2]['url']);
        $this->assertSame('1', $client->headers['fastly-soft-purge'] ?? null);
    }

    public function testDomainPurgeRequiresServiceId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('service ID');
        (new Fastly('token', null, false, new TestClient([])))->purgeDomain('example.com');
    }
}
