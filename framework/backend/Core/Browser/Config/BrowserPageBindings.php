<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Config;

use IteratorAggregate;
use Traversable;

/**
 * Page table bindings declared in project topology.
 *
 * @implements IteratorAggregate<int, BrowserPageBinding>
 */
final class BrowserPageBindings implements IteratorAggregate
{
    /**
     * @param list<BrowserPageBinding> $bindings Page table bindings
     */
    private function __construct(
        private readonly array $bindings,
    ) {
    }

    /**
     * Builds an empty binding collection.
     *
     * @return self Empty binding collection
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Builds bindings from a PAGE_TABLES page entry.
     *
     * @param array<string, mixed> $configs Table binding configs keyed by table key
     * @return self Page table bindings
     */
    public static function fromArray(array $configs): self
    {
        $bindings = [];
        foreach ($configs as $tableKey => $config) {
            if (!is_string($tableKey)) {
                continue;
            }

            $bindings[] = BrowserPageBinding::fromArray(
                tableKey: $tableKey,
                config: is_array($config) ? $config : [],
            );
        }

        return new self($bindings);
    }

    /**
     * Iterates table bindings in declaration order.
     *
     * @return Traversable<int, BrowserPageBinding> Page table bindings
     */
    public function getIterator(): Traversable
    {
        yield from $this->bindings;
    }
}
