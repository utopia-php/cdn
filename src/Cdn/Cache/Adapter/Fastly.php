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
    /**
     * Fastly's documented ceiling for a single batch surrogate key purge.
     */
    public const int KEYS_PER_PURGE = 256;

    private ClientInterface $client;

    /**
     * Fastly cannot purge by host: its purge API offers URL, surrogate key and whole-service purges
     * and nothing in between. A domain is therefore addressed by the surrogate key the origin
     * attaches to every response it serves for that domain, and this adapter has to be told how
     * those keys are named — hence a required prefix rather than an optional one.
     *
     * @param string $domainKeyPrefix Prefix of the per-domain surrogate key. Pass '' when the key is the bare hostname.
     */
    public function __construct(
        private string $apiToken,
        private string $domainKeyPrefix,
        private ?string $serviceId = null,
        private bool $softPurge = false,
        ?ClientInterface $client = null,
        private string $apiBase = 'https://api.fastly.com',
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
     * Purges the domain's surrogate key, leaving every other domain on the service cached.
     */
    public function purgeDomain(string $domain): void
    {
        $this->purgeKeys([$this->domainKeyPrefix . Domain::validate($domain)]);
    }

    public function purgeKeys(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $this->requireServiceId('cache key purging');

        // Keys travel in the request body, so they are sent as given: no
        // encoding, and up to 256 of them in one request instead of one each.
        foreach (\array_chunk($keys, self::KEYS_PER_PURGE) as $chunk) {
            $result = $this->request(
                method: Method::POST,
                url: '/service/' . $this->serviceId . '/purge',
                body: ['surrogate_keys' => $chunk],
            );

            if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
                throw new \RuntimeException($this->formatError($result));
            }
        }
    }

    /**
     * Purges every object on the service, whatever domain it belongs to.
     *
     * Deliberately absent from the Adapter interface: it is not what any caller asking to purge a
     * domain, a path or a key means, and Fastly documents it as taking up to two minutes, being
     * incompatible with soft purge, and likely to spike origin traffic on a busy service. Reach for
     * a surrogate key purge first.
     */
    public function purgeService(): void
    {
        $this->requireServiceId('service purging');

        $result = $this->request(Method::POST, '/service/' . $this->serviceId . '/purge_all');

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            throw new \RuntimeException($this->formatError($result));
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
     * @param array<string, mixed>|null $body
     * @return array{statusCode:int,response:array<string, mixed>|string|null,error:string|null}
     */
    private function request(string $method, string $url, ?array $body = null): array
    {
        $factory = new RequestFactory();
        $request = $body === null
            ? $factory->createRequest($method, $this->apiBase . $url)
            : $factory->json($method, $this->apiBase . $url, $body);

        $request = $request
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
