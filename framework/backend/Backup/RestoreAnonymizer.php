<?php

declare(strict_types=1);

namespace Hilos\Backup;

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
 * Three methods, and the order between them is the point:
 *
 * - {@see validateArchive()} refuses an archive before a byte of it is imported, so
 *   whatever the anonymizer cannot account for is said while the target database is still
 *   untouched. An implementation that only knew how to rewrite data would leave the
 *   restore with one choice at the end - fail over a database that already holds
 *   production rows.
 * - {@see validateTargetSchema()} asks the second half of the same question of the schema
 *   that will actually be written into, which exists only once the archive is imported and
 *   migrated forward. It is late by necessity, so it runs for every connection before the
 *   first one is rewritten: a refusal must not leave one database anonymized and the next
 *   holding personal data.
 * - {@see anonymizeConnection()} performs the pass those two have cleared.
 */
interface RestoreAnonymizer
{
    /**
     * Judges the archive before any of it is imported.
     *
     * Called once per restore, after the archive is unpacked and before the first import,
     * with the tables every connection's dump file declares. Returning is the anonymizer's
     * promise that the archive carries nothing it would fail to classify.
     *
     * @param array<int, list<string>> $tablesByConnection Archive table names per connection index
     * @throws AnonymizationConfigException When the archive carries anything the anonymizer
     *     cannot account for
     */
    public function validateArchive(array $tablesByConnection): void;

    /**
     * Judges the live schema of one connection after it is imported and migrated.
     *
     * Called once per connection, after every connection's forward migrations and before
     * the first connection is rewritten. Returning is the anonymizer's promise that every
     * column it declared on this connection can carry what its strategy produces.
     *
     * @param int $index Connection index whose schema is judged
     * @param string $database Database name the connection imported into, for the refusal
     * @throws AnonymizationConfigException When a declared column is absent or cannot carry
     *     its strategy; the data is already imported, so the refusal says so
     */
    public function validateTargetSchema(int $index, string $database): void;

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
