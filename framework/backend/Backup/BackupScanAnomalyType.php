<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * BackupScanAnomalyType - a defect found while scanning the backup storage.
 *
 * Anomalies never fail the scan; they are logged. Broken sidecars are errors
 * (a record that should be readable is not); orphaned archives and phantom
 * sidecars are warnings (an artifact pairing is incomplete and rotation cleans
 * it up).
 */
enum BackupScanAnomalyType: string
{
    /** Archive present with no sidecar: an unfinished or corrupt capture. */
    case TAR_WITHOUT_SIDECAR = 'tar_without_sidecar';

    /** Success sidecar present with no archive: a phantom the index must not show. */
    case SIDECAR_WITHOUT_TAR = 'sidecar_without_tar';

    /** Sidecar could not be read or parsed as JSON. */
    case BROKEN_SIDECAR = 'broken_sidecar';

    /**
     * Whether this anomaly is logged at error severity.
     *
     * @return bool True for an unreadable sidecar; false for the warn-level pairing gaps
     */
    public function isError(): bool
    {
        return $this === self::BROKEN_SIDECAR;
    }
}
