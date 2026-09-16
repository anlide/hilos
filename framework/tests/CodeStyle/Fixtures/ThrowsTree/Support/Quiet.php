<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

/**
 * The silent source every orphaned tag in the tree is built on: a call that resolves
 * into a declaration documenting nothing.
 */
final class Quiet
{
    /**
     * @return string A literal, which raises nothing
     */
    public function speak(): string
    {
        return 'quiet';
    }
}
