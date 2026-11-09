<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\FullRestore\BootstrapMigrationProof;
use Hilos\Backup\FullRestore\MigrationBinding;
use Hilos\Backup\FullRestore\MigrationFacts;
use Hilos\Backup\FullRestore\MigrationOperationKind;
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
                $pending = self::readSteps($track, $facts->archiveLevel, $binding->source->config->database);
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
                if (self::readSteps($this->track, $evidence->facts->archiveLevel, $evidence->binding->source->config->database)
                    != $evidence->pending) {
                    throw new RestoreFailedException('The UP effect proof changed after full restore preparation');
                }
            }
        } catch (DatabaseException | RestoreFailedException $failure) {
            throw RestoreFailedException::beforeDestructive('Full restore migration recheck: ' . $failure->getMessage(), $failure);
        }
    }

    /**
     * Proof and its source reads belong to preparation; the planner's compiler stays pure.
     * Rebuilding also rechecks the DOWN body and immutable source immediately before import.
     *
     * @param MigrationUpTrack $track Exact UP source snapshot
     * @param ?int $level Proven archive level, or absence requiring the complete track
     * @param string $database Physical execution schema
     * @return list<MigrationStep> Ordered effects, retaining opaque operations without a proof as UNKNOWN
     * @throws RestoreFailedException When a claimed immutable bootstrap pair fails verification
     */
    private static function readSteps(MigrationUpTrack $track, ?int $level, string $database): array
    {
        $steps = [];
        foreach ($level === null ? $track->files : $track->after($level) as $file) {
            foreach ($file->statements as $statementIndex => $sql) {
                foreach (MigrationOperationReader::read($sql, $database) as $operation) {
                    if ($operation->kind === MigrationOperationKind::UNKNOWN) {
                        $operation = BootstrapMigrationProof::read($file, $database) ?? $operation;
                    }
                    $steps[] = new MigrationStep($file, $statementIndex, $operation);
                }
            }
        }
        return $steps;
    }
}
