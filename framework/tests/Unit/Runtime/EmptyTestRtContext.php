<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Runtime\View\Context\RtContext;

/**
 * Project runtime context that mounts nothing at all.
 *
 * The other half of the alias contract: a project that never mounts a framework singleton
 * must read null through the alias rather than meet an exception, because that null is what
 * every optional-subsystem caller is written against.
 */
final class EmptyTestRtContext extends RtContext
{
    /**
     * Registers no runtime state whatsoever.
     */
    public function configure(): void
    {
    }
}
