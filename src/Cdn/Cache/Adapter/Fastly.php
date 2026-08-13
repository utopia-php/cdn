<?php

namespace Utopia\Cdn\Cache\Adapter;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Utopia\Cdn\Domain;
use Utopia\Cdn\Exception\UnsupportedOperation;
use Utopia\Psr7\Method;

class Fastly extends Api
{
    /**
     * Fastly's documented ceiling for one batch surrogate key purge.
     */
    public const int KEYS_PER_PURGE = 256;

    protected const string USER_AGENT = 'Utopia CDN Fastly Adapter';

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
        string $apiBase = 'https://api.fastly.com',
    ) {
        parent::__construct($client, $apiBase);
    }

    public function purgePaths(string $domain, array $paths): void
    {
        $domain = Domain::validate($domain);
        $paths = Domain::validatePaths($paths);

        // A URL purge addresses exactly one cached URL, so there is nothing to batch.
        foreach ($paths as $path) {
            $this->send(Method::POST, '/purge/' . $domain . $this->encodePath($path));
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
        // encoding, and up to 256 of them per request instead of one each.
        $this->batch($keys, self::KEYS_PER_PURGE, function (array $chunk): void {
            $this->send(Method::POST, '/service/' . $this->serviceId . '/purge', ['surrogate_keys' => $chunk]);
        });
    }

    /**
     * Purges every object on the service, whatever domain it belongs to.
     *
     * Deliberately absent from the Adapter interface: it is not what any caller asking to purge a
     * domain, a path or a key means, and Fastly documents it as taking up to two minutes, being
     * incompatible with soft purge, and likely to spike origin traffic on a busy service. Reach for
     * a surrogate key purge first.
     */
    public function purgeZone(): void
    {
        $this->requireServiceId('zone purging');

        $this->send(Method::POST, '/service/' . $this->serviceId . '/purge_all');
    }

    protected function authenticate(RequestInterface $request): RequestInterface
    {
        $request = $request->withHeader('Fastly-Key', $this->apiToken);

        return $this->softPurge ? $request->withHeader('Fastly-Soft-Purge', '1') : $request;
    }

    protected function isSuccess(array $result): bool
    {
        return $this->isHttpSuccess($result);
    }

    protected function formatError(array $result): string
    {
        $message = $result['error'] ?? null;

        if (\is_array($result['response'])) {
            $message ??= $result['response']['msg'] ?? $result['response']['detail'] ?? null;
        }

        $message ??= 'Unknown purge error';

        return 'Fastly purge failed with status ' . $result['statusCode'] . ': ' . $message;
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
}
