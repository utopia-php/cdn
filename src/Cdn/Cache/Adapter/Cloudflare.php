<?php

namespace Utopia\Cdn\Cache\Adapter;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Utopia\Client;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Cdn\Cache\Adapter;
use Utopia\Cdn\Domain;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

class Cloudflare implements Adapter
{
    private ClientInterface $client;

    public function __construct(
        private string $zoneId,
        private string $apiToken,
        ?ClientInterface $client = null,
        private string $apiBase = 'https://api.cloudflare.com/client/v4'
    ) {
        $this->client = $client ?? new Client(new CurlAdapter());
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

        foreach (\array_chunk($keys, 30) as $chunk) {
            $result = $this->request(
                method: Method::POST,
                url: '/zones/' . $this->zoneId . '/purge_cache',
                body: ['tags' => $chunk],
            );

            if (!$this->isSuccess($result)) {
                throw new \RuntimeException($this->formatError('Cloudflare', $result));
            }
        }
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
        $request = (new RequestFactory())->json($method, $this->apiBase . $url, $body);
        $request = $request
            ->withHeader(Header::USER_AGENT, 'Utopia CDN Cloudflare Adapter')
            ->withHeader(Header::AUTHORIZATION, 'Bearer ' . $this->apiToken)
            ->withHeader(Header::CONTENT_TYPE, ContentType::JSON);

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $error) {
            return ['statusCode' => 0, 'response' => null, 'error' => $error->getMessage()];
        }

        $contents = (string) $response->getBody();

        try {
            $decoded = \json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = $contents;
        }

        return ['statusCode' => $response->getStatusCode(), 'response' => $decoded, 'error' => null];
    }
}
