<?php

namespace Utopia\Cdn;

/**
 * Names the vendor behind a cache adapter.
 *
 * Kept separate from the adapter class so filters and error messages can talk
 * about a provider without depending on a concrete adapter implementation.
 */
enum Provider: string
{
    case Fastly = 'fastly';

    case Cloudflare = 'cloudflare';

    case Proxy = 'proxy';
}
