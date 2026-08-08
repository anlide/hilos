<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * RestoreAnonymizer - the seam a restore runs its anonymization pass through.
 *
 * HIL-274 defines only the port and its call point in {@see BackupRestorer}: after
 * every connection is imported and before the restore reports success, the pass runs
 * once per imported connection over the freshly restored data. The implementation -
 * the PII registry, the transforms, and how a concrete anonymizer is resolved for an
 * installation - is HIL-275; until it lands, resolution yields none and a restore
 * that requires anonymization is refused rather than quietly skipped.
 */
interface RestoreAnonymizer
{
    /**
     * Anonymizes the freshly imported data of one connection.
     *
     * @param int $index Connection index the pass runs over
     * @param string $database Database name the connection imported into
     */
    public function anonymizeConnection(int $index, string $database): void;
}
