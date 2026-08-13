<?php

namespace Utopia\Cdn\Cache\Adapter;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Utopia\Cdn\Cache\Adapter;
use Utopia\Client;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Psr7\Header;
use Utopia\Psr7\Request\Factory as RequestFactory;

/**
 * Shared plumbing for adapters that purge through a provider's HTTP API.
 *
 * Every provider needs the same four things — authenticate a request, send it, decide whether the
 * answer means success, and turn a failure into a message — and each provider disagrees only about
 * the details. Holding the sequence here is what keeps a new operation on one adapter down to the
 * request it makes: `send()` for a single call, `batch()` when the provider caps how many items one
 * call may carry.
 */
abstract class Api implements Adapter
{
    /**
     * Identifies this adapter to the provider.
     */
    protected const string USER_AGENT = 'Utopia CDN Adapter';

    protected ClientInterface $client;

    public function __construct(?ClientInterface $client, protected string $apiBase)
    {
        $this->client = $client ?? new Client(new CurlAdapter());
    }

    /**
     * Adds whatever the provider accepts as credentials.
     */
    abstract protected function authenticate(RequestInterface $request): RequestInterface;

    /**
     * Whether the provider's answer means the purge happened. A 2xx is not always enough.
     *
     * @param array{statusCode:int,response:array<string, mixed>|string|null,error:string|null} $result
     */
    abstract protected function isSuccess(array $result): bool;

    /**
     * @param array{statusCode:int,response:array<string, mixed>|string|null,error:string|null} $result
     */
    abstract protected function formatError(array $result): string;

    /**
     * Issues one purge request and raises the provider's own error when it did not take.
     *
     * @param array<string, mixed>|null $body
     */
    protected function send(string $method, string $url, ?array $body = null): void
    {
        $result = $this->request($method, $url, $body);

        if (!$this->isSuccess($result)) {
            throw new \RuntimeException($this->formatError($result));
        }
    }

    /**
     * Splits items across as few requests as the provider's per-request ceiling allows.
     *
     * @template T
     * @param array<int, T> $items
     * @param positive-int $perRequest
     * @param callable(array<int, T>): void $purge
     */
    protected function batch(array $items, int $perRequest, callable $purge): void
    {
        foreach (\array_chunk($items, $perRequest) as $chunk) {
            $purge($chunk);
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{statusCode:int,response:array<string, mixed>|string|null,error:string|null}
     */
    protected function request(string $method, string $url, ?array $body = null): array
    {
        $factory = new RequestFactory();
        $request = $body === null
            ? $factory->createRequest($method, $this->apiBase . $url)
            : $factory->json($method, $this->apiBase . $url, $body);

        $request = $this->authenticate($request)
            ->withHeader(Header::USER_AGENT, static::USER_AGENT)
            ->withHeader(Header::ACCEPT, 'application/json');

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

    /**
     * @param array{statusCode:int,response:array<string, mixed>|string|null,error:string|null} $result
     */
    protected function isHttpSuccess(array $result): bool
    {
        return $result['statusCode'] >= 200 && $result['statusCode'] < 300;
    }
}
