<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * BackupScanAnomaly - one defect found at a storage path during a scan.
 */
final class BackupScanAnomaly
{
    /**
     * @param BackupScanAnomalyType $type What kind of defect was found
     * @param string $path Storage path the defect was found at
     * @param string $detail Optional extra context (e.g. a decode error message)
     */
    public function __construct(
        public readonly BackupScanAnomalyType $type,
        public readonly string $path,
        public readonly string $detail = '',
    ) {
    }
}
