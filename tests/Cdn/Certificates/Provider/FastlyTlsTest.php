<?php

namespace Utopia\Tests\Cdn\Certificates\Provider;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Certificates\Provider\FastlyTls;
use Utopia\Cdn\Certificates\Status;
use Utopia\Fetch\Response;
use Utopia\Tests\Cdn\TestClient;

class FastlyTlsTest extends TestCase
{
    public function testIssueCertificateCreatesSubscriptionWhenMissing(): void
    {
        $client = new TestClient([
            new Response(200, '{"data":[]}', []),
            new Response(200, '{"data":{"id":"sub_123","attributes":{"state":"pending"}}}', []),
        ]);

        $provider = new FastlyTls('token', 'tls-config-id', 'certainly', $client);
        $renewDate = $provider->issueCertificate('ignored', 'example.com', null);

        $this->assertNull($renewDate);
        $this->assertCount(2, $client->calls);
        $this->assertSame('GET', $client->calls[0]['method']);
        $this->assertStringContainsString('filter%5Btls_domains.id%5D=example.com', $client->calls[0]['url']);
        $this->assertSame('POST', $client->calls[1]['method']);
        $this->assertSame('tls-config-id', $client->calls[1]['body']['data']['relationships']['tls_configuration']['data']['id']);
    }

    public function testGetCertificateStatusMapsFastlyState(): void
    {
        $client = new TestClient([
            new Response(200, '{"data":[{"id":"sub_123","attributes":{"state":"issued"}}]}', []),
            new Response(200, '{"data":[{"id":"sub_123","attributes":{"state":"issued"}}]}', []),
        ]);

        $provider = new FastlyTls('token', 'tls-config-id', 'certainly', $client);

        $this->assertSame(Status::ISSUED, $provider->getCertificateStatus('example.com', null));
        $this->assertFalse($provider->isRenewRequired('example.com', null));
    }

    public function testDeleteCertificateRemovesSubscription(): void
    {
        $client = new TestClient([
            new Response(200, '{"data":[{"id":"sub_123","attributes":{"state":"issued"}}]}', []),
            new Response(204, '', []),
        ]);

        $provider = new FastlyTls('token', 'tls-config-id', 'certainly', $client);
        $provider->deleteCertificate('example.com');

        $this->assertCount(2, $client->calls);
        $this->assertSame('DELETE', $client->calls[1]['method']);
        $this->assertSame('https://api.fastly.com/tls/subscriptions/sub_123', $client->calls[1]['url']);
    }
}
