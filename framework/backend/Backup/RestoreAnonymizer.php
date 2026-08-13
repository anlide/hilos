<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Backup\Anonymization\ArchiveTableSchema;
use Hilos\Backup\Anonymization\CatalogRestoreAnonymizer;
use Hilos\Backup\Exception\AnonymizationConfigException;
use Hilos\Backup\Exception\RestoreFailedException;

/**
 * RestoreAnonymizer - the seam a restore runs its anonymization pass through.
 *
 * HIL-274 defined the port and its call point in {@see BackupRestorer}: after every
 * connection is imported and before the restore reports success, the pass runs once per
 * imported connection over the freshly restored data. HIL-275 added the second method and
 * the implementation behind both ({@see CatalogRestoreAnonymizer}).
 *
 * The two methods are the contract's two halves, and the order between them is the point:
 * an anonymizer must be able to REFUSE an archive before a byte of it is imported, so
 * whatever it cannot handle is said while the target database is still untouched. An
 * implementation that only knew how to rewrite data would leave the restore with one
 * choice at the end - fail over a database that already holds production rows.
 */
interface RestoreAnonymizer
{
    /**
     * Judges the archive before any of it is imported.
     *
     * Called once per restore, after the archive is unpacked and before the first import,
     * with every connection's tables as the dump files declare them. Returning is the
     * anonymizer's promise that it can anonymize this archive.
     *
     * @param array<int, list<ArchiveTableSchema>> $schemasByConnection Archive tables per connection index
     * @throws AnonymizationConfigException When the archive carries anything the anonymizer
     *     cannot account for
     */
    public function validateArchive(array $schemasByConnection): void;

    /**
     * Anonymizes the freshly imported data of one connection.
     *
     * @param int $index Connection index the pass runs over
     * @param string $database Database name the connection imported into
     * @throws RestoreFailedException When the pass fails; the data is already imported, so
     *     the run ends with a restored database that still holds personal data
     */
    public function anonymizeConnection(int $index, string $database): void;
}
