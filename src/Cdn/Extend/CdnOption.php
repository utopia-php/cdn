<?php

namespace Utopia\Cdn\Extend;

use Utopia\Balancer\Option;
use Utopia\Cdn\Cache\Adapter;
use Utopia\Cdn\Exception\Configuration;
use Utopia\Cdn\Provider;

/**
 * A balancer option that carries a cache adapter.
 *
 * The base option is an untyped state bag, so a filter written against it reads
 * `$option->getState('adapter')` and has to trust the key spelling and the
 * value's type. This subclass fixes both ends: the constructor names what an
 * option needs and the getters return it typed.
 */
class CdnOption extends Option
{
    public const string ADAPTER = 'adapter';

    public const string PROVIDER = 'provider';

    public const string EDGE = 'edge';

    /**
     * @param Adapter $adapter Purges cached content for this option.
     * @param Provider $provider Vendor the adapter talks to.
     * @param bool $edge Whether the option fronts the platform's own edge network rather than customer-owned custom domains.
     */
    public function __construct(Adapter $adapter, Provider $provider, bool $edge = false)
    {
        parent::__construct([
            self::ADAPTER => $adapter,
            self::PROVIDER => $provider,
            self::EDGE => $edge,
        ]);
    }

    public function getAdapter(): Adapter
    {
        $adapter = $this->getState(self::ADAPTER);

        // State stays publicly writable through setState(), so the type the
        // constructor guaranteed is checked again on the way out.
        if (!$adapter instanceof Adapter) {
            throw new Configuration('Option state "' . self::ADAPTER . '" must be a ' . Adapter::class . '.');
        }

        return $adapter;
    }

    public function getProvider(): Provider
    {
        $provider = $this->getState(self::PROVIDER);

        if (!$provider instanceof Provider) {
            throw new Configuration('Option state "' . self::PROVIDER . '" must be a ' . Provider::class . '.');
        }

        return $provider;
    }

    public function isEdge(): bool
    {
        return $this->getState(self::EDGE, false) === true;
    }
}
