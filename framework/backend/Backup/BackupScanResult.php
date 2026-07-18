<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * BackupScanResult - the outcome of scanning the backup storage tree.
 *
 * Carries the indexable backup metadata (valid backups plus recorded failures)
 * and the anomalies to log. It does not touch runtime state; the agent projects
 * the metadata into the runtime history and logs the anomalies.
 */
final class BackupScanResult
{
    /**
     * @param list<BackupMetadata> $metadatas Indexable backups in scan order
     * @param list<BackupScanAnomaly> $anomalies Defects to log, in scan order
     */
    public function __construct(
        public readonly array $metadatas,
        public readonly array $anomalies,
    ) {
    }
}
