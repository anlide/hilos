<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Database\MigrationHistory as DatabaseMigrationHistory;
use Hilos\Database\MigrationRecord;
use Hilos\Database\MigrationTrackCheck;
use Hilos\Database\MigrationUpTrack;
use Hilos\Database\Exception\MigrationNumberTakenTwiceException;
use Hilos\Database\Exception\MigrationSkippedBelowLevelException;

/** History facts are available even when they cannot authorize cleanup of a legacy FULL. */
final readonly class MigrationFacts
{
    /**
     * @param MigrationHistory $archive History actually carried by the dump
     * @param ?DatabaseMigrationHistory $current Every live row, or absence of the live table
     * @param ?int $archiveLevel Highest successful archive row; never inferred from the sidecar
     * @param ?int $sidecarLevel Recorded sidecar level, when present
     * @param ?int $sourceSidecarLevel Primary sidecar level when a journal uses primary history
     * @param list<int> $archiveSuccessful Exact successful archive rows
     * @param list<int> $archiveFailed Every failed archive row
     */
    private function __construct(
        public MigrationHistory $archive,
        public ?DatabaseMigrationHistory $current,
        public ?int $archiveLevel,
        public ?int $sidecarLevel,
        public ?int $sourceSidecarLevel,
        public array $archiveSuccessful,
        public array $archiveFailed,
    ) {
    }

    /**
     * @param MigrationHistory $archive Actual dump history
     * @param ?DatabaseMigrationHistory $current Actual live history
     * @param MigrationUpTrack $track Read-only UP source snapshot
     * @param ?int $sidecarLevel Optional sidecar level
     * @param ?int $sourceSidecarLevel Optional sidecar level of the history source
     * @return self Facts that retain unknown levels and all failures explicitly
     * @throws RestoreFailedException When the archive rows contradict one another
     * @throws MigrationNumberTakenTwiceException When track numbers are duplicated
     * @throws MigrationSkippedBelowLevelException When an interior UP file is missing from history
     */
    public static function read(
        MigrationHistory $archive,
        ?DatabaseMigrationHistory $current,
        MigrationUpTrack $track,
        ?int $sidecarLevel,
        ?int $sourceSidecarLevel = null,
    ): self {
        $rows = [];
        $seen = [];
        foreach ($archive->rows as $row) {
            if ($row->index < 0 || isset($seen[$row->index])) {
                throw new RestoreFailedException('Contradictory migration rows in the full archive');
            }
            $seen[$row->index] = true;
            $rows[] = new MigrationRecord($row->index, $row->failed);
        }
        if (($archive->state === MigrationHistoryState::RECORDED) !== ($rows !== [])) {
            throw new RestoreFailedException('Contradictory migration history presence in the full archive');
        }
        $history = new DatabaseMigrationHistory($rows);
        MigrationTrackCheck::assertConsistent($track->fileNames, $history, $track->name);
        MigrationTrackCheck::assertConsistent($track->fileNames, $current, $track->name);
        return new self(
            $archive, $current, $history->level(), $sidecarLevel, $sourceSidecarLevel,
            $history->successfulIndices(), $history->failedIndices(),
        );
    }

    /**
     * Called only when cleanup is needed. Unknown legacy histories remain available to the safe path.
     * A successful row at a higher level never substitutes for the exact CREATE migration's row.
     *
     * @return int Proven archive level, including an explicitly recorded zero
     * @throws RestoreFailedException When history cannot establish cleanup provenance
     */
    public function requireCleanupLevel(): int
    {
        if ($this->archiveLevel === null) {
            throw new RestoreFailedException('The full archive has no provable successful migration level for cleanup');
        }
        if (($this->sidecarLevel !== null && $this->sidecarLevel !== $this->archiveLevel)
            || ($this->sourceSidecarLevel !== null && $this->sourceSidecarLevel !== $this->archiveLevel)) {
            throw new RestoreFailedException('The full archive migration history contradicts its sidecar');
        }
        if ($this->archiveFailed !== [] || ($this->current?->failedIndices() ?? []) !== []) {
            throw new RestoreFailedException('Failed migration rows cannot establish full restore cleanup provenance');
        }
        if ($this->current === null || $this->current->level() === null) {
            throw new RestoreFailedException('The target has no provable successful migration history for cleanup');
        }
        return $this->archiveLevel;
    }
}
