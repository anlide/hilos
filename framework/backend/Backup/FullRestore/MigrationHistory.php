<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

final readonly class MigrationHistory
{
    public const string TABLE = 'migration';

    /**
     * Unknown syntax and contradictory histories are refusals, never an empty history.
     * The row list preserves dump order and does not invent a migration level.
     *
     * @param MigrationHistoryState $state Proven table/history presence
     * @param list<MigrationRow> $rows Every admitted index/failed pair
     */
    public function __construct(public MigrationHistoryState $state, public array $rows)
    {
    }
}
