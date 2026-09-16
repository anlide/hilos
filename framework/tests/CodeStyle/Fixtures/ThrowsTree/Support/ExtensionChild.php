<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\NarrowException;

/**
 * The override that keeps the base's tag alive, and whose own tag its body backs.
 */
final class ExtensionChild extends ExtensionBase
{
    /**
     * @throws NarrowException Always, refusing the hook
     */
    public function hook(): void
    {
        throw new NarrowException('no');
    }
}
