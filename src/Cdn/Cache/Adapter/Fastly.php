<?php

namespace Utopia\Cdn\Cache\Adapter;

use Utopia\Cdn\Cache\Adapter;
use Utopia\Fetch\Client;
use Utopia\Fetch\Exception as FetchException;

class Fastly implements Adapter
{
    public function __construct(
        private string $apiToken,
        private ?string $serviceId = null,
        private bool $softPurge = false,
        private ?Client $client = null,
        private string $apiBase = 'https://api.fastly.com'
    ) {
        $this->client ??= new Client();
        $this->client
            ->setUserAgent('Utopia CDN Fastly Adapter')
            ->addHeader('Fastly-Key', $this->apiToken)
            ->addHeader('Accept', 'application/json');

        if ($this->softPurge) {
            $this->client->addHeader('Fastly-Soft-Purge', '1');
        }
    }

    public function purgeUrls(array $urls): void
    {
        if ($urls === []) {
            return;
        }

        foreach ($urls as $url) {
            $result = $this->request(Client::METHOD_POST, '/purge/' . $url);

            if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
                throw new \RuntimeException($this->formatError($result));
            }
        }
    }

    public function purgeKeys(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        if ($this->serviceId === null || $this->serviceId === '') {
            throw new \RuntimeException('Fastly service ID is required for cache key purging.');
        }

        foreach ($keys as $key) {
            $result = $this->request(Client::METHOD_POST, '/service/' . $this->serviceId . '/purge/' . $key);

            if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
                throw new \RuntimeException($this->formatError($result));
            }
        }
    }

    /**
     * @param array{statusCode:int,response:array<string, mixed>|string|null,error:string|null} $result
     */
    private function formatError(array $result): string
    {
        $message = $result['error'] ?? null;

        if (\is_array($result['response'])) {
            $message ??= $result['response']['msg'] ?? $result['response']['detail'] ?? null;
        }

        $message ??= 'Unknown purge error';

        return 'Fastly purge failed with status ' . $result['statusCode'] . ': ' . $message;
    }

    /**
     * @return array{statusCode:int,response:array<string, mixed>|string|null,error:string|null}
     */
    private function request(string $method, string $url): array
    {
        try {
            $response = $this->client->fetch(url: $this->apiBase . $url, method: $method);

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
