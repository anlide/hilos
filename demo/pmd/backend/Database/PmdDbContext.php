<?php

declare(strict_types=1);

namespace Demo\Pmd\Database;

use Hilos\Database\Context\HilosDbContext;

/**
 * PmdDbContext - Database context for the PMD demo.
 *
 * Adds nothing to the framework collections: people, their sessions and every table of
 * signing in are the framework's, and the demo keeps no rows of its own yet. A visitor
 * without an account stays nameless, so there is no guest table either.
 */
final class PmdDbContext extends HilosDbContext
{
}
