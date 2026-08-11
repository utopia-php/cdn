<?php

namespace Utopia\Cdn\Cache\Adapter;

use Psr\Http\Client\ClientInterface;
use Utopia\Cdn\Cache\Adapter;
use Utopia\Cdn\Domain;
use Utopia\Cdn\Exception\UnsupportedOperation;
use Utopia\Cdn\HttpClient;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;

class Cloudflare implements Adapter
{
    private HttpClient $client;

    public function __construct(
        private string $zoneId,
        private string $apiToken,
        ?ClientInterface $client = null,
        private string $apiBase = 'https://api.cloudflare.com/client/v4'
    ) {
        $this->client = new HttpClient($client, [
            Header::USER_AGENT => 'Utopia CDN Cloudflare Adapter',
            Header::AUTHORIZATION => 'Bearer ' . $this->apiToken,
            Header::CONTENT_TYPE => ContentType::JSON,
        ]);
    }

    public function purgePaths(string $domain, array $paths): void
    {
        $domain = Domain::validate($domain);
        $paths = Domain::validatePaths($paths);

        if ($paths === []) {
            return;
        }

        foreach (\array_chunk($paths, 30) as $chunk) {
            $urls = \array_map(fn (string $path): string => 'https://' . $domain . $path, $chunk);
            $result = $this->request(
                method: Method::POST,
                url: '/zones/' . $this->zoneId . '/purge_cache',
                body: ['files' => $urls],
            );

            if (!$this->isSuccess($result)) {
                throw new \RuntimeException($this->formatError('Cloudflare', $result));
            }
        }
    }

    public function purgeDomain(string $domain): void
    {
        $result = $this->request(
            method: Method::POST,
            url: '/zones/' . $this->zoneId . '/purge_cache',
            body: ['hosts' => [Domain::validate($domain)]],
        );

        if (!$this->isSuccess($result)) {
            throw new \RuntimeException($this->formatError('Cloudflare', $result));
        }
    }

    /**
     * @param array{statusCode:int,response:array<string, mixed>|string|null,error:string|null} $result
     */
    private function isSuccess(array $result): bool
    {
        return $result['statusCode'] >= 200
            && $result['statusCode'] < 300
            && \is_array($result['response'])
            && ($result['response']['success'] ?? false) === true;
    }

    public function purgeKeys(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        throw new UnsupportedOperation('Cloudflare cache key purging is not supported by this adapter.');
    }

    /**
     * @param array{statusCode:int,response:array<string, mixed>|string|null,error:string|null} $result
     */
    private function formatError(string $provider, array $result): string
    {
        $message = $result['error'] ?? null;

        if (\is_array($result['response'])) {
            $message ??= $result['response']['errors'][0]['message'] ?? null;
        }

        $message ??= 'Unknown purge error';

        return $provider . ' purge failed with status ' . $result['statusCode'] . ': ' . $message;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{statusCode:int,response:array<string, mixed>|string|null,error:string|null}
     */
    private function request(string $method, string $url, ?array $body = null): array
    {
        return $this->client->request($method, $this->apiBase . $url, $body);
    }

}
