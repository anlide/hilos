<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\StepUp\Fixtures;

use Hilos\Database\Context\HilosDbContext;

/**
 * No-op database context for the step-up facade fixture.
 */
final class StepUpTestDbContext extends HilosDbContext
{
    /**
     * Leaves database collections unmounted for pure setting and directory tests.
     */
    public function configure(): void
    {
    }
}
