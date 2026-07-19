<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Backup\Exception\BackupException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Objects;
use Hilos\Hilos;

/**
 * BackupReferenceRegistry - resolves the project's reference/seed tables per connection.
 *
 * A project declares which tables hold reference/seed data (rows the schema-seed scope
 * keeps) as a per-connection list of Entity or Object collection classes under the
 * {@see BackupConstants::CATALOG_REFERENCES} key of its backup catalog. Declaring classes
 * rather than raw table names keeps the registry stable across table renames: the table
 * name is derived from the class ({@see tableNameOf()}), and the connection index is the
 * catalog key the class is listed under.
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
            $tables[] = self::tableNameOf($class);
        }

        return $tables;
    }

    /**
     * Derives the table name of a reference class.
     *
     * Accepts an Entity subclass (reads its `_table`) or an Object collection subclass
     * (reads the table off its object's entity class), so a project can list whichever
     * of the two it registers.
     *
     * @param class-string $class Reference Entity or Object collection class
     * @return string Table name
     * @throws BackupException When the class is neither an Entity nor an Object collection
     */
    private static function tableNameOf(string $class): string
    {
        if (is_subclass_of($class, Entity::class)) {
            return $class::_table;
        }

        if (is_subclass_of($class, Objects::class)) {
            $objectClass = $class::OBJECT_CLASS;
            $entityClass = $objectClass::ENTITY_CLASS;

            return $entityClass::_table;
        }

        throw new BackupException(
            "Backup reference class {$class} is neither an Entity nor an Object collection",
        );
    }
}
