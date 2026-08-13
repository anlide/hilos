<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\NarrowException;

/**
 * A parent whose helper is private. PHP does not inherit it, so a child reusing the
 * name overrides nothing and its own contract widens nothing.
 */
class PrivateParent
{
    /**
     * @return string A constant
     * @throws NarrowException Never, in a fixture
     */
    private function hidden(): string
    {
        throw new NarrowException('hidden');
    }
}
