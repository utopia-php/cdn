<?php

namespace Utopia\Cdn\Certificates\Provider;

use Utopia\Cdn\Certificates\Provider;
use Utopia\Cdn\Certificates\Status;
use Utopia\Fetch\Client;
use Utopia\Fetch\Exception as FetchException;

class FastlyTls implements Provider
{
    public function __construct(
        private string $apiToken,
        private string $tlsConfigurationId,
        private string $certificateAuthority = 'certainly',
        private ?Client $client = null,
        private string $apiBase = 'https://api.fastly.com'
    ) {
        $this->client ??= new Client();
        $this->client
            ->setUserAgent('Utopia CDN Fastly TLS Provider')
            ->addHeader('Fastly-Key', $this->apiToken)
            ->addHeader('Accept', 'application/vnd.api+json')
            ->addHeader('Content-Type', 'application/vnd.api+json');
    }

    public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
    {
        $subscription = $this->findSubscription($domain);

        if ($subscription === null) {
            $subscription = $this->createSubscription($domain);
        } elseif ($this->mapStatus($subscription['resource']['attributes']['state'] ?? '') === Status::FAILED) {
            $subscription = $this->retrySubscription($subscription['resource']['id']);
        }

        return $this->extractRenewDate($subscription);
    }

    public function isInstantGeneration(string $domain, ?string $domainType): bool
    {
        return false;
    }

    public function getCertificateStatus(string $domain, ?string $domainType): string
    {
        $subscription = $this->findSubscription($domain);

        if ($subscription === null) {
            return Status::UNKNOWN;
        }

        return $this->mapStatus($subscription['resource']['attributes']['state'] ?? '');
    }

    public function isRenewRequired(string $domain, ?string $domainType): bool
    {
        $subscription = $this->findSubscription($domain);

        if ($subscription === null) {
            return true;
        }

        return $this->mapStatus($subscription['resource']['attributes']['state'] ?? '') === Status::FAILED;
    }

    public function deleteCertificate(string $domain): void
    {
        $subscription = $this->findSubscription($domain);

        if ($subscription === null) {
            return;
        }

        $result = $this->request(
            'DELETE',
            '/tls/subscriptions/' . $subscription['resource']['id']
        );

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            throw new \RuntimeException($this->formatError('Failed to delete Fastly TLS subscription', $result));
        }
    }

    /**
     * @return array{resource:array<string, mixed>,included:array<int, array<string, mixed>>}|null
     */
    private function findSubscription(string $domain): ?array
    {
        $query = \http_build_query([
            'filter[tls_domains.id]' => $domain,
            'include' => 'tls_certificates',
            'page[size]' => 1,
        ]);

        $result = $this->request('GET', '/tls/subscriptions?' . $query);

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            throw new \RuntimeException($this->formatError('Failed to fetch Fastly TLS subscriptions', $result));
        }

        if (!\is_array($result['response'])) {
            throw new \RuntimeException('Fastly TLS subscriptions response was not valid JSON.');
        }

        $data = $result['response']['data'] ?? null;
        if (!\is_array($data)) {
            throw new \RuntimeException('Fastly TLS subscriptions response was missing its data list.');
        }

        $resource = $data[0] ?? null;
        if ($resource === null) {
            return null;
        }

        if (!\is_array($resource)) {
            throw new \RuntimeException('Fastly TLS subscription resource was malformed.');
        }

        $included = $result['response']['included'] ?? [];
        if (!\is_array($included)) {
            throw new \RuntimeException('Fastly TLS subscriptions response contained malformed included resources.');
        }

        return ['resource' => $resource, 'included' => \array_values(\array_filter($included, 'is_array'))];
    }

    /**
     * @return array{resource:array<string, mixed>,included:array<int, array<string, mixed>>}
     */
    private function createSubscription(string $domain): array
    {
        $result = $this->request('POST', '/tls/subscriptions', [
            'data' => [
                'type' => 'tls_subscription',
                'attributes' => [
                    'certificate_authority' => $this->certificateAuthority,
                ],
                'relationships' => [
                    'common_name' => [
                        'data' => [
                            'type' => 'tls_domain',
                            'id' => $domain,
                        ],
                    ],
                    'tls_configuration' => [
                        'data' => [
                            'type' => 'tls_configuration',
                            'id' => $this->tlsConfigurationId,
                        ],
                    ],
                    'tls_domains' => [
                        'data' => [[
                            'type' => 'tls_domain',
                            'id' => $domain,
                        ]],
                    ],
                ],
            ],
        ]);

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            throw new \RuntimeException($this->formatError('Failed to create Fastly TLS subscription', $result));
        }

        if (!\is_array($result['response'])) {
            throw new \RuntimeException('Fastly TLS subscription response was not valid JSON.');
        }

        $data = $result['response']['data'] ?? null;

        if (!\is_array($data)) {
            throw new \RuntimeException('Fastly TLS subscription response was missing data.');
        }

        $included = $result['response']['included'] ?? [];

        return ['resource' => $data, 'included' => \is_array($included) ? \array_values(\array_filter($included, 'is_array')) : []];
    }

    /**
     * @return array{resource:array<string, mixed>,included:array<int, array<string, mixed>>}
     */
    private function retrySubscription(string $subscriptionId): array
    {
        $result = $this->request('PATCH', '/tls/subscriptions/' . $subscriptionId, [
            'data' => [
                'id' => $subscriptionId,
                'type' => 'tls_subscription',
                'attributes' => [
                    'state' => 'retry',
                ],
            ],
        ]);

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            throw new \RuntimeException($this->formatError('Failed to retry Fastly TLS subscription', $result));
        }

        if (!\is_array($result['response'])) {
            throw new \RuntimeException('Fastly TLS retry response was not valid JSON.');
        }

        $data = $result['response']['data'] ?? null;

        if (!\is_array($data)) {
            throw new \RuntimeException('Fastly TLS retry response was missing data.');
        }

        $included = $result['response']['included'] ?? [];

        return ['resource' => $data, 'included' => \is_array($included) ? \array_values(\array_filter($included, 'is_array')) : []];
    }

    /**
     * @param array{resource:array<string, mixed>,included:array<int, array<string, mixed>>} $subscription
     */
    private function extractRenewDate(array $subscription): ?string
    {
        $resource = $subscription['resource'];
        $state = $this->mapStatus($resource['attributes']['state'] ?? '');

        if ($state !== Status::ISSUED && $state !== Status::RENEWING) {
            return null;
        }

        $relationship = $resource['relationships']['tls_certificates']['data'] ?? [];
        $certificateIds = [];
        if (\is_array($relationship)) {
            foreach ($relationship as $reference) {
                if (\is_array($reference) && \is_string($reference['id'] ?? null)) {
                    $certificateIds[] = $reference['id'];
                }
            }
        }

        $dates = [];
        foreach ($subscription['included'] as $included) {
            if (($included['type'] ?? null) !== 'tls_certificate' || !\in_array($included['id'] ?? null, $certificateIds, true)) {
                continue;
            }

            $notAfter = $included['attributes']['not_after'] ?? null;
            if (\is_string($notAfter) && $notAfter !== '') {
                $dates[] = $notAfter;
            }
        }

        if ($dates === []) {
            return null;
        }

        \usort($dates, static fn (string $left, string $right): int => \strtotime($right) <=> \strtotime($left));
        $date = new \DateTimeImmutable($dates[0]);

        return $date->modify('-30 days')->format('Y-m-d H:i:s.v');
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{statusCode:int,response:array<string, mixed>|string|null,error:string|null}
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        try {
            $response = $this->client->fetch(url: $this->apiBase . $path, method: $method, body: $body);

            return [
                'statusCode' => $response->getStatusCode(),
                'response' => $this->decodeResponse($response),
                'error' => null,
            ];
        } catch (FetchException $error) {
            return [
                'statusCode' => 0,
                'response' => null,
                'error' => $error->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>|string|null
     */
    private function decodeResponse(\Utopia\Fetch\Response $response): array|string|null
    {
        try {
            return $response->json();
        } catch (\Throwable) {
            return $response->text();
        }
    }

    /**
     * @param array{statusCode:int,response:array<string, mixed>|string|null,error:string|null} $result
     */
    private function formatError(string $prefix, array $result): string
    {
        $message = $result['error'] ?? null;

        if (\is_array($result['response'])) {
            $message ??= $result['response']['errors'][0]['detail']
                ?? $result['response']['errors'][0]['title']
                ?? $result['response']['msg']
                ?? null;
        }

        $message ??= 'Unknown Fastly TLS error';

        return $prefix . ' with status ' . $result['statusCode'] . ': ' . $message;
    }

    private function mapStatus(string $state): string
    {
        return match (\strtolower($state)) {
            'pending' => Status::PENDING,
            'processing' => Status::PROCESSING,
            'issued' => Status::ISSUED,
            'renewing' => Status::RENEWING,
            'failed' => Status::FAILED,
            default => Status::UNKNOWN,
        };
    }
}
