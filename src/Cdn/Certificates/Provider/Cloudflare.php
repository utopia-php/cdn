<?php

namespace Utopia\Cdn\Certificates\Provider;

use Utopia\Cdn\Certificates\Provider;
use Utopia\Cdn\Domain;
use Utopia\Cdn\Exception\UnsupportedOperation;
use Utopia\Fetch\Client;
use Utopia\Fetch\Exception as FetchException;

class Cloudflare implements Provider
{
    public function __construct(
        private string $zoneId,
        private string $apiToken,
        private ?Client $client = null,
        private string $apiBase = 'https://api.cloudflare.com/client/v4',
    ) {
        $this->client ??= new Client();
        $this->client
            ->setUserAgent('Utopia CDN Cloudflare Certificates Provider')
            ->addHeader('Authorization', 'Bearer ' . $this->apiToken)
            ->addHeader('Content-Type', Client::CONTENT_TYPE_APPLICATION_JSON);
    }

    public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
    {
        $domain = Domain::validate($domain);
        $result = $this->request(Client::METHOD_POST, $this->hostnamesPath(), [
            'hostname' => $domain,
            'ssl' => [
                'method' => 'http',
                'type' => 'dv',
                'wildcard' => false,
            ],
        ]);

        if ($this->isDuplicate($result)) {
            return null;
        }

        $this->assertSuccess('create Cloudflare custom hostname', $result, [201]);

        return null;
    }

    public function isInstantGeneration(string $domain, ?string $domainType): bool
    {
        Domain::validate($domain);

        return true;
    }

    public function getCertificateStatus(string $domain, ?string $domainType): string
    {
        throw new UnsupportedOperation('Certificate status retrieval is not supported by the Cloudflare provider.');
    }

    public function isRenewRequired(string $domain, ?string $domainType): bool
    {
        return $this->findHostname(Domain::validate($domain)) === null;
    }

    public function deleteCertificate(string $domain, ?string $domainType = null): void
    {
        $hostname = $this->findHostname(Domain::validate($domain));
        if ($hostname === null) {
            return;
        }

        $id = $hostname['id'] ?? null;
        if (!\is_string($id) || $id === '') {
            throw new \RuntimeException('Cloudflare custom hostname response was missing an ID.');
        }

        $result = $this->request(Client::METHOD_DELETE, $this->hostnamesPath() . '/' . \rawurlencode($id));
        $this->assertSuccess('delete Cloudflare custom hostname', $result);
    }

    /** @return array<string, mixed>|null */
    private function findHostname(string $domain): ?array
    {
        $result = $this->request(Client::METHOD_GET, $this->hostnamesPath() . '?' . \http_build_query(['hostname' => $domain]));
        $this->assertSuccess('fetch Cloudflare custom hostnames', $result);

        if (!\is_array($result['response'])) {
            throw new \RuntimeException('Cloudflare custom hostname response was not valid JSON.');
        }

        $hostnames = $result['response']['result'] ?? null;
        if (!\is_array($hostnames)) {
            throw new \RuntimeException('Cloudflare custom hostname response was missing its result list.');
        }

        foreach ($hostnames as $hostname) {
            if (\is_array($hostname) && ($hostname['hostname'] ?? null) === $domain) {
                return $hostname;
            }
        }

        return null;
    }

    private function hostnamesPath(): string
    {
        return '/zones/' . $this->zoneId . '/custom_hostnames';
    }

    /** @param array{statusCode:int,response:array<string, mixed>|string|null,error:string|null} $result */
    private function isDuplicate(array $result): bool
    {
        return \is_array($result['response']) && (($result['response']['errors'][0]['code'] ?? null) === 1406);
    }

    /**
     * @param array{statusCode:int,response:array<string, mixed>|string|null,error:string|null} $result
     * @param array<int, int>|null $expectedStatuses
     */
    private function assertSuccess(string $operation, array $result, ?array $expectedStatuses = null): void
    {
        $httpSuccess = $expectedStatuses === null
            ? $result['statusCode'] >= 200 && $result['statusCode'] < 300
            : \in_array($result['statusCode'], $expectedStatuses, true);
        $envelopeSuccess = !\is_array($result['response']) || !\array_key_exists('success', $result['response']) || $result['response']['success'] === true;

        if (!$httpSuccess || !$envelopeSuccess) {
            $message = $result['error'];
            if (\is_array($result['response'])) {
                $message ??= $result['response']['errors'][0]['message'] ?? null;
            }

            throw new \RuntimeException('Failed to ' . $operation . ' with status ' . $result['statusCode'] . ': ' . ($message ?? 'Unknown Cloudflare error'));
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{statusCode:int,response:array<string, mixed>|string|null,error:string|null}
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        try {
            $response = $this->client->fetch(url: $this->apiBase . $path, method: $method, body: $body);
            try {
                $decoded = $response->json();
            } catch (\Throwable) {
                $decoded = $response->text();
            }

            return ['statusCode' => $response->getStatusCode(), 'response' => $decoded, 'error' => null];
        } catch (FetchException $error) {
            return ['statusCode' => 0, 'response' => null, 'error' => $error->getMessage()];
        }
    }
}
