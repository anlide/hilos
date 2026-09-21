<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Method\Fixtures;

use Hilos\Database\Context\HilosDbContext;

/**
 * No-op DB context so the facade fixture is instantiable.
 */
final class AuthMethodTestDbContext extends HilosDbContext
{
    /**
     * No-op DB configuration for the method-set fixture.
     */
    public function configure(): void
    {
    }
}
