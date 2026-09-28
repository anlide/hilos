<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;

/**
 * The next migration to apply is already marked failed by an earlier run (HIL-1228).
 *
 * Raised by {@see Migration::migrateUp()} under the rollout claim: a holder that failed on the
 * migration's SQL leaves its row at `failed = 1` and gives the claim up, and whoever takes the
 * claim next refuses here with the number and the command that retries it, instead of stumbling
 * over the duplicate key of that row. A node's watchdog reads it as any other migration failure.
 */
final class MigrationMarkedFailedException extends DatabaseException
{
    /**
     * @param int $index Migration marked failed
     * @return self Exception instance
     */
    public static function forIndex(int $index): self
    {
        return new self(
            "Migration {$index} is marked failed by an earlier run and its SQL may be half applied:"
            . " fix the schema by hand, then run db:migration:retry {$index}",
        );
    }
}
