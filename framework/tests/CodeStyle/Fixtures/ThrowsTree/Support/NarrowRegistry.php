<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\NarrowException;

/**
 * The class a child's tag narrows a receiver to. Its method shares a name with
 * {@see Registry::name()} and throws something else, so the report names which of
 * the two tags won.
 */
final class NarrowRegistry
{
    /**
     * @return string Name of the entry this instance stands for
     * @throws NarrowException When the entry has gone
     */
    public function name(): string
    {
        return 'narrow';
    }
}
