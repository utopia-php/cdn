<?php

namespace Utopia\Tests\Cdn;

use Utopia\Fetch\Client;
use Utopia\Fetch\Response;

class TestClient extends Client
{
    /**
     * @var array<int, array{url:string,method:string,body:mixed}>
     */
    public array $calls = [];

    /**
     * @param array<int, Response> $responses
     */
    public function __construct(private array $responses)
    {
    }

    public function fetch(
        string $url,
        string $method = self::METHOD_GET,
        array|string|null $body = [],
        ?array $query = [],
        ?callable $chunks = null,
        ?int $timeoutMs = null,
        ?int $connectTimeoutMs = null,
    ): Response {
        if ($query) {
            $url = \rtrim($url, '?') . '?' . \http_build_query($query);
        }

        $this->calls[] = [
            'url' => $url,
            'method' => $method,
            'body' => $body,
        ];

        return \array_shift($this->responses) ?? new Response(500, '', []);
    }
}
