<?php

declare(strict_types=1);

namespace Hilos\Database\Migration;

use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\Hilos;

/** Source and applied gates for one process's startup, before application persistence reads. */
class FrameworkMigrationStartupGuard
{
    private static ?self $starting = null;

    /**
     * Immutable address and source snapshot belong to this startup only, with no shared RT reader or writer.
     *
     * @param FrameworkMigrationProject $project Prepared facade and Schema track
     * @param array<int, string> $sources Verified origin up basenames indexed by exact primary migration number
     */
    private function __construct(private readonly FrameworkMigrationProject $project, private readonly array $sources)
    {
    }

    /**
     * Arms the applied gate only after the actual sources satisfy the current selected catalog.
     * Does not connect, copy sources, repair pairs, initialize tables or apply SQL.
     *
     * @param class-string<Hilos> $hilosClass Facade whose metadata selects schema units
     * @param string $projectRoot Project root containing backend/Database/Migration/Schema
     * @throws DatabaseException When source origin, coverage, hashes or pair integrity are invalid
     * @throws IncompleteFeatureActivationException When a declared feature has no definition
     * @throws FsException When the track cannot be locked or its sources cannot be read
     */
    public static function prepare(string $hilosClass, string $projectRoot): void
    {
        self::$starting = null;
        $project = new FrameworkMigrationProject($projectRoot . '/backend/Database/Migration/Schema', $hilosClass);
        $sources = FsPath::withLock($project->root . '/' . FrameworkMigrationInstaller::LOCK_FILE, false, static function () use ($project): array {
            $schema = static::createSchemaCatalog();
            $catalog = static::createMigrationCatalog($schema);
            $track = (new FrameworkMigrationInstaller($schema, $catalog))->readTrack($project, requireComplete: true);
            $origins = [];
            $installed = [];
            $sources = [];
            foreach ($track as $entry) {
                if ($entry->origin === null) {
                    continue;
                }
                $origins[] = $entry->origin;
                $sources[$entry->index] = $entry->upName;
                if ($entry->origin->kind !== FrameworkMigrationOrigin::CHANGE) {
                    foreach ($entry->origin->units as $unit) {
                        $installed[$unit] = true;
                    }
                }
            }
            if ($origins === []) {
                throw new DatabaseException(
                    'This project has no framework catalog origin; only projects initialized for this catalog are supported',
                );
            }
            $units = $schema->selectedUnits($project->facade);
            $keys = array_column($units, 'key');
            $catalog->verifyCurrentStubs($keys);
            foreach ($units as $unit) {
                if (!$unit->bootstrapOwned && !isset($installed[$unit->key])) {
                    throw new DatabaseException("Framework migration {$unit->key} is missing from this project; run db:framework:pull");
                }
            }
            foreach ($catalog->missingParts($keys, $origins) as $part) {
                throw new DatabaseException("Framework migration {$part->key()} is missing from this project; run db:framework:pull");
            }

            return $sources;
        });
        self::$starting = new static($project, $sources);
    }

    /**
     * CLI initEnvironment() never arms this gate; Docker may migrate between prepare() and this check.
     *
     * @throws DatabaseException When the prepared track changed or an origin's exact primary index is not successful
     */
    public static function assertAppliedIfStarting(): void
    {
        if (self::$starting === null) {
            return;
        }
        $starting = self::$starting;
        $path = realpath(Migration::trackPath());
        if (($path === false ? Migration::trackPath() : $path) !== $starting->project->root) {
            throw new DatabaseException('Configured migration track differs from the prepared framework startup track');
        }
        $applied = array_fill_keys($starting->readAppliedIndices(), true);
        foreach ($starting->sources as $index => $file) {
            if (!isset($applied[$index])) {
                throw new DatabaseException("Framework migration {$file} is not applied; run db:migration:up");
            }
        }
    }

    /** Clears only the private startup descriptor so independent unit scenarios do not inherit a gate. */
    public static function resetForTests(): void
    {
        self::$starting = null;
    }

    /**
     * @return FrameworkSchemaCatalog Schema metadata, replaceable with synthetic source fixtures in unit tests
     * @throws DatabaseException When schema unit declarations are invalid
     */
    protected static function createSchemaCatalog(): FrameworkSchemaCatalog
    {
        return new FrameworkSchemaCatalog();
    }

    /**
     * @param FrameworkSchemaCatalog $schema Selected schema metadata
     * @return FrameworkMigrationCatalog Immutable source catalog, replaceable with a temporary tree in unit tests
     * @throws DatabaseException When source metadata or catalog files are invalid
     */
    protected static function createMigrationCatalog(FrameworkSchemaCatalog $schema): FrameworkMigrationCatalog
    {
        return new FrameworkMigrationCatalog($schema);
    }

    /**
     * @return list<int> Exact successful primary indices, replaceable without a DB in unit tests
     * @throws DatabaseException When the primary migration rows cannot be read
     */
    protected function readAppliedIndices(): array
    {
        return Migration::appliedIndices();
    }
}
