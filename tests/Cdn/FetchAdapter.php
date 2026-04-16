<?php

namespace Utopia\Tests\Cdn;

use Utopia\Fetch\Adapter;
use Utopia\Fetch\Options\Request as RequestOptions;
use Utopia\Fetch\Response;

class FetchAdapter implements Adapter
{
    /**
     * @var array<int, array{url:string,method:string,body:mixed,headers:array<string, string>}>
     */
    public array $calls = [];

    /**
     * @param array<int, Response> $responses
     */
    public function __construct(private array $responses)
    {
    }

    public function send(
        string $url,
        string $method,
        mixed $body,
        array $headers,
        RequestOptions $options,
        ?callable $chunkCallback = null
    ): Response {
        $this->calls[] = [
            'url' => $url,
            'method' => $method,
            'body' => $body,
            'headers' => $headers,
        ];

        return \array_shift($this->responses) ?? new Response(500, '', []);
    }
}
