<?php

namespace Utopia\Cdn\Extend;

use Utopia\Balancer\Algorithm;
use Utopia\Balancer\Algorithm\First;
use Utopia\Balancer\Balancer;
use Utopia\Balancer\Option;
use Utopia\Cdn\Exception\Configuration;

/**
 * A balancer that keeps its filters reachable.
 *
 * Utopia's balancer is built to pick a winner: `run()` returns one option and
 * `$filters` is private, so there is no way to ask which options a filter set
 * leaves standing. A cache purge has to reach every provider that may hold a
 * response for the domain, so this subclass records filters as they are
 * registered and exposes the whole surviving set through
 * `getFilteredOptions()`. `run()` keeps working exactly as before, for callers
 * that do want a single option.
 *
 * The algorithm is optional here because fan-out does not need one; it defaults
 * to `First` so `run()` stays deterministic.
 */
class CdnBalancer extends Balancer
{
    /**
     * @var array<int, callable(CdnOption): bool>
     */
    private array $filters = [];

    public function __construct(?Algorithm $algo = null)
    {
        parent::__construct($algo ?? new First());
    }

    public function addOption(Option $option): self
    {
        // Rejected here rather than in getFilteredOptions() so a mistyped option
        // fails where it was written, not on the next purge.
        if (!$option instanceof CdnOption) {
            throw new Configuration('Cache options must be instances of ' . CdnOption::class . '.');
        }

        parent::addOption($option);

        return $this;
    }

    /**
     * @param callable(CdnOption): bool $filter
     */
    public function addFilter(callable $filter): self
    {
        $this->filters[] = $filter;

        parent::addFilter($filter);

        return $this;
    }

    /**
     * @return array<int, CdnOption>
     */
    public function getOptions(): array
    {
        $options = [];

        foreach (parent::getOptions() as $option) {
            // addOption() is the only way in, so this only trips if a caller
            // reached past it through the parent class.
            if (!$option instanceof CdnOption) {
                throw new Configuration('Cache options must be instances of ' . CdnOption::class . '.');
            }

            $options[] = $option;
        }

        return $options;
    }

    /**
     * Every option that passed all registered filters, in registration order.
     *
     * @return array<int, CdnOption>
     */
    public function getFilteredOptions(): array
    {
        $options = $this->getOptions();

        foreach ($this->filters as $filter) {
            $options = \array_filter($options, $filter);
        }

        return \array_values($options);
    }
}
