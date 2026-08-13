<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Backup\Exception\BackupException;
use Hilos\Hilos;

/**
 * BackupReferenceRegistry - resolves the project's reference/seed tables per connection.
 *
 * A project declares which tables hold reference/seed data (rows the schema-seed scope
 * keeps) as a per-connection list of Entity or Object collection classes under the
 * {@see BackupConstants::CATALOG_REFERENCES} key of its backup catalog. Declaring classes
 * rather than raw table names keeps the registry stable across table renames: the table
 * name is derived from the class ({@see BackupTableResolver::tableNameOf()}), and the
 * connection index is the catalog key the class is listed under.
 *
 * The registry is read by {@see BackupCreator} to append the reference-table data pass for
 * schema-seed dumps. An empty or unconfigured registry is valid — schema-seed then captures
 * schema only, and the creator records a warning rather than failing.
 */
final class BackupReferenceRegistry
{
    /**
     * @param array<int, list<class-string>> $classesByConnection Reference classes keyed by connection index
     */
    public function __construct(private readonly array $classesByConnection)
    {
    }

    /**
     * Builds the registry from the active project's backup catalog.
     *
     * Reads {@see BackupConstants::CATALOG_REFERENCES} from the catalog named by
     * {@see Hilos::getBackupCatalogClass()}. An unconfigured catalog or a missing/malformed
     * references section yields an empty registry.
     *
     * @return self Registry over the declared reference classes
     */
    public static function fromCatalog(): self
    {
        $catalogClass = Hilos::getBackupCatalogClass();
        if ($catalogClass === null) {
            return new self([]);
        }

        $references = $catalogClass::getCatalog()[BackupConstants::CATALOG_REFERENCES] ?? [];

        return new self(is_array($references) ? $references : []);
    }

    /**
     * Returns the reference table names declared for one connection index.
     *
     * @param int $connectionIndex Connection index
     * @return list<string> Reference table names, in declaration order
     * @throws BackupException When a declared class is neither an Entity nor an Object collection
     */
    public function tablesForConnection(int $connectionIndex): array
    {
        $tables = [];
        foreach ($this->classesByConnection[$connectionIndex] ?? [] as $class) {
            $tables[] = BackupTableResolver::tableNameOf($class);
        }

        return $tables;
    }
}
