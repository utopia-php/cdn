<?php

namespace Utopia\Cdn\Cache\Adapter;

use Utopia\Cdn\Cache\Adapter;
use Utopia\Cdn\Domain;
use Utopia\Fetch\Client;
use Utopia\Fetch\Exception as FetchException;

class Cloudflare implements Adapter
{
    public function __construct(
        private string $zoneId,
        private string $apiToken,
        private ?Client $client = null,
        private string $apiBase = 'https://api.cloudflare.com/client/v4'
    ) {
        $this->client ??= new Client();
        $this->client
            ->setUserAgent('Utopia CDN Cloudflare Adapter')
            ->addHeader('Authorization', 'Bearer ' . $this->apiToken)
            ->addHeader('Content-Type', Client::CONTENT_TYPE_APPLICATION_JSON);
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
                method: Client::METHOD_POST,
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
            method: Client::METHOD_POST,
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

        throw new \RuntimeException('Cloudflare cache key purging is not supported by this adapter.');
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
        try {
            $response = $this->client->fetch(url: $this->apiBase . $url, method: $method, body: $body);

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

}
