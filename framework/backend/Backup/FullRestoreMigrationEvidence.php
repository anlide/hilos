<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\FullRestore\MigrationBinding;
use Hilos\Backup\FullRestore\MigrationFacts;
use Hilos\Backup\FullRestore\MigrationOperationReader;
use Hilos\Backup\FullRestore\MigrationStep;
use Hilos\Backup\FullRestore\MigrationTargetEvidence;
use Hilos\Backup\FullRestore\PreparedTarget;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Database\MigrationUpTrack;

/** Read-only history and UP facts for a future full restore planner, private to one invocation. */
final readonly class FullRestoreMigrationEvidence
{
    /**
     * @param MigrationUpTrack $track Source files and exact SQL execution units
     * @param array<int, MigrationTargetEvidence> $targets Facts keyed by the destination connection index
     */
    private function __construct(public MigrationUpTrack $track, public array $targets)
    {
    }

    /**
     * HilosDbContext models application rows, not the migration journal or physical schema.
     * This infrastructure read uses Migration's shared reader on Database and never initializes it.
     *
     * @param list<PreparedTarget> $targets Inputs already admitted by FullRestorePlan
     * @return self History, binding and ordered operation evidence without authorizing or executing cleanup
     * @throws RestoreFailedException When history, sources or journal placement cannot be established
     */
    public static function prepare(array $targets): self
    {
        try {
            $track = Migration::readUpTrack();
            $histories = [];
            $evidence = [];
            foreach ($targets as $target) {
                $index = $target->connection->index;
                if (isset($evidence[$index])) {
                    throw new RestoreFailedException('Duplicate migration evidence destination');
                }
                $binding = MigrationBinding::resolve($target, $targets);
                $sourceIndex = $binding->source->connection->index;
                if (!array_key_exists($sourceIndex, $histories)) {
                    $histories[$sourceIndex] = Migration::readHistory($sourceIndex);
                }
                $facts = MigrationFacts::read(
                    $binding->source->dump->migrationHistory, $histories[$sourceIndex], $track, $target->connection->migrationIndex,
                    $binding->source->connection->migrationIndex,
                );
                $pending = null;
                if ($facts->archiveLevel !== null) {
                    $pending = [];
                    foreach ($track->after($facts->archiveLevel) as $file) {
                        foreach ($file->statements as $statementIndex => $sql) {
                            foreach (MigrationOperationReader::read($sql, $binding->source->config->database) as $operation) {
                                $pending[] = new MigrationStep($file, $statementIndex, $operation);
                            }
                        }
                    }
                }
                $evidence[$index] = new MigrationTargetEvidence($binding, $facts, $pending);
            }
            return new self($track, $evidence);
        } catch (DatabaseException | RestoreFailedException $failure) {
            throw RestoreFailedException::beforeDestructive('Full restore migration evidence: ' . $failure->getMessage(), $failure);
        }
    }

    /**
     * Complements FullRestorePlan's input, configuration and physical-address recheck.
     *
     * @throws RestoreFailedException When actual rows, source files or journal bindings changed before the first write
     */
    public function assertUnchanged(): void
    {
        try {
            $this->track->assertUnchanged();
            $targets = array_map(static fn (MigrationTargetEvidence $evidence): PreparedTarget => $evidence->binding->target, $this->targets);
            foreach ($this->targets as $evidence) {
                MigrationBinding::resolve($evidence->binding->target, array_values($targets));
                if (Migration::readHistory($evidence->binding->source->connection->index) != $evidence->facts->current) {
                    throw new RestoreFailedException('The live migration history changed after full restore preparation');
                }
            }
        } catch (DatabaseException | RestoreFailedException $failure) {
            throw RestoreFailedException::beforeDestructive('Full restore migration recheck: ' . $failure->getMessage(), $failure);
        }
    }
}
