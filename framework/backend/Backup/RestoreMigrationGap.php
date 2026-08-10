<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * RestoreMigrationGap - one connection whose archived migration level is not the
 * level the code expects.
 *
 * Carried as numbers rather than as a finished sentence because the two callers word
 * the same fact differently: the CLI prints a line per connection before the
 * destructive step, and the engine folds the refusing ones into a
 * {@see RestoreMigrationDecisionResult::$reason} it can throw.
 *
 * Connections whose level matches produce no gap - there is nothing to say about them.
 */
final readonly class RestoreMigrationGap
{
    /**
     * @param int $connectionIndex Connection index as recorded in the sidecar
     * @param ?int $archiveIndex Migration level the archive recorded; null when the
     *     sidecar predates the field
     */
    public function __construct(
        public int $connectionIndex,
        public ?int $archiveIndex,
    ) {
    }
}
