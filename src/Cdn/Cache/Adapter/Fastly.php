<?php

namespace Utopia\Cdn\Cache\Adapter;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Utopia\Client;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Cdn\Cache\Adapter;
use Utopia\Cdn\Domain;
use Utopia\Cdn\Exception\UnsupportedOperation;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

class Fastly implements Adapter
{
    private ClientInterface $client;

    public function __construct(
        private string $apiToken,
        private ?string $serviceId = null,
        private bool $softPurge = false,
        ?ClientInterface $client = null,
        private string $apiBase = 'https://api.fastly.com'
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

        foreach ($paths as $path) {
            $cachedUrl = $domain . $this->encodePath($path);
            $result = $this->request(Method::POST, '/purge/' . $cachedUrl);

            if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
                throw new \RuntimeException($this->formatError($result));
            }
        }
    }

    /**
     * Purges the entire configured service. The service is expected to be dedicated to the supplied domain.
     */
    public function purgeDomain(string $domain): void
    {
        Domain::validate($domain);
        $this->requireServiceId('domain purging');

        $result = $this->request(Method::POST, '/service/' . $this->serviceId . '/purge_all');

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            throw new \RuntimeException($this->formatError($result));
        }
    }

    public function purgeKeys(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $this->requireServiceId('cache key purging');

        foreach ($keys as $key) {
            // The key is a path segment of the purge URL, so it is encoded here
            // rather than by every caller.
            $result = $this->request(Method::POST, '/service/' . $this->serviceId . '/purge/' . \rawurlencode($key));

            if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
                throw new \RuntimeException($this->formatError($result));
            }
        }
    }

    /**
     * Reported as an unsupported operation rather than a failure: a token without a service ID can
     * still purge URLs, so a fan-out has to be able to skip this adapter and purge the others.
     */
    private function requireServiceId(string $operation): void
    {
        if ($this->serviceId === null || $this->serviceId === '') {
            throw new UnsupportedOperation('Fastly service ID is required for ' . $operation . '.');
        }
    }

    private function encodePath(string $path): string
    {
        return (string) \preg_replace_callback(
            '/[^A-Za-z0-9\-._~\/%?=&:+]/u',
            static fn (array $match): string => \rawurlencode($match[0]),
            $path,
        );
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
        $request = (new RequestFactory())
            ->createRequest($method, $this->apiBase . $url)
            ->withHeader(Header::USER_AGENT, 'Utopia CDN Fastly Adapter')
            ->withHeader('Fastly-Key', $this->apiToken)
            ->withHeader(Header::ACCEPT, 'application/json');

        if ($this->softPurge) {
            $request = $request->withHeader('Fastly-Soft-Purge', '1');
        }

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
