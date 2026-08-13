<?php

namespace Utopia\Tests\Cdn\Extend;

use PHPUnit\Framework\TestCase;
use Utopia\Balancer\Algorithm\Last;
use Utopia\Balancer\Option;
use Utopia\Cdn\Cache\Adapter;
use Utopia\Cdn\Exception\Configuration;
use Utopia\Cdn\Extend\CdnBalancer;
use Utopia\Cdn\Extend\CdnOption;
use Utopia\Cdn\Provider;

class CdnBalancerTest extends TestCase
{
    public function testOptionExposesItsStateTyped(): void
    {
        $adapter = $this->adapter();
        $option = new CdnOption($adapter, Provider::Fastly, true);

        $this->assertSame($adapter, $option->getAdapter());
        $this->assertSame(Provider::Fastly, $option->getProvider());
        $this->assertTrue($option->isEdge());
        $this->assertFalse((new CdnOption($adapter, Provider::Cloudflare))->isEdge());
    }

    public function testOptionRejectsStateOverwrittenWithTheWrongType(): void
    {
        $option = new CdnOption($this->adapter(), Provider::Fastly);
        $option->setState(CdnOption::ADAPTER, 'fastly');

        $this->expectException(Configuration::class);
        $option->getAdapter();
    }

    public function testFilteredOptionsAreTheSurvivorsInOrder(): void
    {
        $edge = new CdnOption($this->adapter(), Provider::Fastly, true);
        $run = new CdnOption($this->adapter(), Provider::Fastly);
        $cloudflare = new CdnOption($this->adapter(), Provider::Cloudflare);

        $balancer = (new CdnBalancer())
            ->addOption($edge)
            ->addOption($run)
            ->addOption($cloudflare);

        $this->assertSame([$edge, $run, $cloudflare], $balancer->getFilteredOptions());

        $balancer->addFilter(fn (CdnOption $option): bool => !$option->isEdge());

        $this->assertSame([$run, $cloudflare], $balancer->getFilteredOptions());

        $balancer->addFilter(fn (CdnOption $option): bool => $option->getProvider() === Provider::Cloudflare);

        $this->assertSame([$cloudflare], $balancer->getFilteredOptions());
        $this->assertSame([], (new CdnBalancer())->getFilteredOptions());
    }

    public function testRunStillPicksASingleOptionThroughTheAlgorithm(): void
    {
        $edge = new CdnOption($this->adapter(), Provider::Fastly, true);
        $run = new CdnOption($this->adapter(), Provider::Fastly);

        // Default algorithm is First, so filters and algorithm both still apply.
        $this->assertSame($edge, (new CdnBalancer())->addOption($edge)->addOption($run)->run());
        $this->assertSame($run, (new CdnBalancer(new Last()))->addOption($edge)->addOption($run)->run());

        $balancer = (new CdnBalancer())->addOption($edge)->addOption($run);
        $balancer->addFilter(fn (CdnOption $option): bool => !$option->isEdge());

        $this->assertSame($run, $balancer->run());
    }

    public function testRejectsUntypedOptions(): void
    {
        $this->expectException(Configuration::class);
        (new CdnBalancer())->addOption(new Option(['adapter' => $this->adapter()]));
    }

    private function adapter(): Adapter
    {
        return new class () implements Adapter {
            public function purgePaths(string $domain, array $paths): void
            {
            }

            public function purgeDomain(string $domain): void
            {
            }

            public function purgeKeys(array $keys): void
            {
            }
        };
    }
}
