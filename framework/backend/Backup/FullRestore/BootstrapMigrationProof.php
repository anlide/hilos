<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkMigrationOrigin;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\MigrationUpFile;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;

/** Exact immutable parts whose direct compound effects belong to one bootstrap-owned table. */
final class BootstrapMigrationProof
{
    private const string MOMENT_CHANGE = '20261109000848893922_HIL-1918_bootstrap_moments';
    private const array MOMENT_UNITS = ['migration_claim', 'admin_view_mode_latch', 'database_marker'];

    /**
     * A unit name or origin header alone grants nothing. Both generated bodies must agree with
     * the immutable change, and the schema catalog must still assign its one table to bootstrap.
     * These parts inspect physical types and UPDATE their own moment column without granting
     * cleanup lineage. The planner separately requires pristine staging and no automatic trigger
     * effects on bootstrap tables that survive import. Existing staging can carry FK cascades;
     * source identity alone cannot establish that safety. Ordinary migration retries may reuse staging.
     *
     * @param MigrationUpFile $file Exact UP snapshot containing the opaque execution unit
     * @param string $database Physical execution schema
     * @return ?MigrationOperation Proven bootstrap alteration, or absence of this narrow proof
     * @throws RestoreFailedException When an asserted framework pair cannot be verified
     */
    public static function read(MigrationUpFile $file, string $database): ?MigrationOperation
    {
        if (count($file->statements) !== 1 || !str_starts_with($file->content, FrameworkMigrationOrigin::PREFIX)) {
            return null;
        }
        try {
            $schema = new FrameworkSchemaCatalog();
            $origin = new FrameworkMigrationCatalog($schema)->verifyPair(
                $file->content, FsPath::read(substr($file->path, 0, -strlen('.sql')) . '_down.sql'),
            );
            if ($origin->kind !== FrameworkMigrationOrigin::CHANGE
                || $origin->parts[0]->changeId !== self::MOMENT_CHANGE
                || !in_array($origin->parts[0]->unit, self::MOMENT_UNITS, true)) {
                return null;
            }
            $unit = $schema->unit($origin->parts[0]->unit);
            if (!$unit->bootstrapOwned || count($unit->tables) !== 1) {
                return null;
            }
            $table = new TableName($database, $unit->tables[0]);
            if (!$table->isBootstrap()) {
                return null;
            }
            return new MigrationOperation(MigrationOperationKind::ALTER_TABLE, $file->statements[0], $table);
        } catch (DatabaseException | FsException $failure) {
            throw new RestoreFailedException('Bootstrap UP provenance in ' . basename($file->path) . ': ' . $failure->getMessage(),
                previous: $failure);
        }
    }
}
