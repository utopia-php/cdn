<?php

namespace Utopia\Tests\Cdn\Certificates\Provider;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Cdn\Certificates\Provider\Proxy;
use Utopia\Cdn\Certificates\Status;
use Utopia\Cdn\Exception\Configuration;

class ProxyTest extends TestCase
{
    public function testRoutesAndAggregatesProviders(): void
    {
        $calls = new \ArrayObject();
        $app = $this->provider('app', Status::ISSUED, false, null, false, $calls);
        $network = $this->provider('network', Status::PENDING, false, null, true, $calls);
        $instant = $this->provider('cloudflare', Status::UNKNOWN, true, null, false, $calls);
        $fastly = $this->provider('fastly', Status::ISSUED, false, '2027-01-01', false, $calls);
        $proxy = new Proxy('app.example.com', $app, $network, [$instant, $fastly]);

        $this->assertSame('2027-01-01', $proxy->issueCertificate('cert', 'custom.example.com', null));
        $this->assertFalse($proxy->isInstantGeneration('custom.example.com', null));
        $this->assertSame(Status::ISSUED, $proxy->getCertificateStatus('custom.example.com', null));
        $this->assertTrue($proxy->isRenewRequired('site.example.com', 'site'));
        $proxy->deleteCertificate('app.example.com');
        $proxy->deleteCertificate('site.example.com', 'site');
        $this->assertContains('app:delete', $calls->getArrayCopy());
        $this->assertContains('network:delete', $calls->getArrayCopy());
    }

    public function testRejectsMissingCustomProviders(): void
    {
        $provider = $this->provider('app', Status::ISSUED, false, null, false, new \ArrayObject());
        $proxy = new Proxy('app.example.com', $provider, $provider, []);
        $this->expectException(Configuration::class);
        $proxy->issueCertificate('cert', 'custom.example.com', null);
    }

    /** @param \ArrayObject<int, mixed> $calls */
    private function provider(string $name, string $status, bool $instant, ?string $date, bool $renew, \ArrayObject $calls): Provider
    {
        return new class ($name, $status, $instant, $date, $renew, $calls) implements Provider {
            /** @param \ArrayObject<int, mixed> $calls */
            public function __construct(private string $name, private string $status, private bool $instant, private ?string $date, private bool $renew, private \ArrayObject $calls)
            {
            }
            public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
            {
                $this->calls->append($this->name . ':issue');
                return $this->date;
            }
            public function isInstantGeneration(string $domain, ?string $domainType): bool
            {
                return $this->instant;
            }
            public function getCertificateStatus(string $domain, ?string $domainType): string
            {
                return $this->status;
            }
            public function isRenewRequired(string $domain, ?string $domainType): bool
            {
                return $this->renew;
            }
            public function deleteCertificate(string $domain, ?string $domainType = null): void
            {
                $this->calls->append($this->name . ':delete');
            }
        };
    }
}
