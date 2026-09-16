<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\TreeException;

/**
 * An extension point whose body does work, so it is not an empty stub: its wide tag
 * is backed by what {@see ExtensionChild} raises, not by anything the base calls.
 */
abstract class ExtensionBase
{
    /**
     * @param Quiet $quiet Source the default hook speaks through
     */
    public function __construct(private readonly Quiet $quiet)
    {
    }

    /**
     * @throws TreeException When an override refuses the hook
     */
    public function hook(): void
    {
        $this->quiet->speak();
    }
}
