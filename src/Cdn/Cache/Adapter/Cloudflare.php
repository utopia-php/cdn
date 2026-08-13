<?php

namespace Utopia\Cdn\Cache\Adapter;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Utopia\Cdn\Domain;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;

class Cloudflare extends Api
{
    /**
     * Items per purge request, kept to the lowest figure Cloudflare documents.
     *
     * Their pages disagree: the purge overview tables say 100 operations per request for tags,
     * hostnames and prefixes and 100 URLs for single-file purge (500 on Enterprise), while the
     * purge-by-hostname page says 30 hostnames at a time. Defaulting to the smaller number is
     * within both; raise it through the constructor if your plan and reading say otherwise.
     */
    public const int ITEMS_PER_PURGE = 30;

    protected const string USER_AGENT = 'Utopia CDN Cloudflare Adapter';

    /**
     * @param positive-int $itemsPerPurge How many URLs, hostnames or cache tags one purge request may carry.
     */
    public function __construct(
        private string $zoneId,
        private string $apiToken,
        ?ClientInterface $client = null,
        string $apiBase = 'https://api.cloudflare.com/client/v4',
        private int $itemsPerPurge = self::ITEMS_PER_PURGE,
    ) {
        parent::__construct($client, $apiBase);
    }

    public function purgePaths(string $domain, array $paths): void
    {
        $domain = Domain::validate($domain);
        $paths = Domain::validatePaths($paths);

        if ($paths === []) {
            return;
        }

        $this->batch($paths, $this->itemsPerPurge, function (array $chunk) use ($domain): void {
            $this->purge(['files' => \array_map(static fn (string $path): string => 'https://' . $domain . $path, $chunk)]);
        });
    }

    /**
     * Purges every cached response served for the hostname, and nothing served for another.
     */
    public function purgeDomain(string $domain): void
    {
        $this->purge(['hosts' => [Domain::validate($domain)]]);
    }

    public function purgeKeys(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        // Cache tags only match responses the origin tagged with a Cache-Tag header.
        $this->batch($keys, $this->itemsPerPurge, function (array $chunk): void {
            $this->purge(['tags' => $chunk]);
        });
    }

    /**
     * Purges every cached response in the zone, whatever hostname it was served for.
     *
     * Deliberately absent from the Adapter interface, for the same reason as its Fastly
     * counterpart: no caller asking to purge a domain, a path or a key means this.
     */
    public function purgeZone(): void
    {
        $this->purge(['purge_everything' => true]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function purge(array $body): void
    {
        $this->send(Method::POST, '/zones/' . $this->zoneId . '/purge_cache', $body);
    }

    protected function authenticate(RequestInterface $request): RequestInterface
    {
        return $request->withHeader(Header::AUTHORIZATION, 'Bearer ' . $this->apiToken);
    }

    /**
     * A 2xx is not enough: Cloudflare reports a rejected purge in the body.
     */
    protected function isSuccess(array $result): bool
    {
        return $this->isHttpSuccess($result)
            && \is_array($result['response'])
            && ($result['response']['success'] ?? false) === true;
    }

    protected function formatError(array $result): string
    {
        $message = $result['error'] ?? null;

        if (\is_array($result['response'])) {
            $message ??= $result['response']['errors'][0]['message'] ?? null;
        }

        $message ??= 'Unknown purge error';

        return 'Cloudflare purge failed with status ' . $result['statusCode'] . ': ' . $message;
    }
}
