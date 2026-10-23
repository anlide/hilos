<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\InvalidMountedCollectionException;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Hilos;

/** Validates mounted database bindings and their live tables before the daemon loop starts. */
final class EntityDatabaseStartupGuard
{
    /**
     * An unconfigured default primary remains usable by metadata-only processes. Journal DDL
     * still requires the primary physical schema and the primary row connection for its receipt.
     *
     * @throws InvalidMountedCollectionException When a mounted class chain or table cannot be resolved
     * @throws NamedDatabaseException When a database binding is invalid or its journal topology is unsupported
     */
    public static function assertMountedBindings(): void
    {
        foreach (Hilos::$db?->getMountedEntities() ?? [] as $key => $entityClass) {
            $binding = $entityClass::getDatabase();
            if (
                $binding->name === DatabaseNames::PRIMARY
                && !in_array(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getConfiguredIndices(), true)
            ) {
                continue;
            }

            $identity = $binding->getIdentity();
            if (
                $entityClass::_journaled
                && ($binding->connectionIndex !== DatabaseConnectionDefaults::PRIMARY_INDEX
                    || $identity !== Database::getDatabase(DatabaseNames::PRIMARY)->getIdentity())
            ) {
                throw new NamedDatabaseException(
                    "Journaled Entity {$entityClass} [{$key}] uses database '{$binding->name}'; journal triggers support only "
                    . 'the primary physical schema on the primary row connection; remote or secondary journal DDL is not supported',
                );
            }
        }
    }

    /**
     * Audits mounted tables in their declared schemas and probes their row connection's read access.
     * Missing primary framework tables remain optional; an explicit secondary table is required.
     *
     * @throws InvalidMountedCollectionException When a mounted class chain or table cannot be resolved
     * @throws NamedDatabaseException When a mounted database binding cannot be resolved
     * @throws DatabaseException When a live table is inaccessible or diverges from its mounted Entity
     * @throws InvalidArgumentException When an index declaration has an invalid direction or type
     */
    public static function assertMountedSchemasMatch(): void
    {
        /** @var array<int, list<class-string<Entity>>> $bySchema Mounted declarations grouped for this startup check only */
        $bySchema = [];
        foreach (Hilos::$db?->getMountedEntities() ?? [] as $entityClass) {
            $bySchema[$entityClass::getDatabase()->schemaIndex][] = $entityClass;
        }
        if ($bySchema === []) {
            return;
        }

        $frameworkTables = [];
        foreach (EntitySchemaAudit::frameworkEntities() as $entityClass) {
            $frameworkTables[$entityClass::_table] = $entityClass;
        }
        $primaryIdentity = Database::getDatabase(DatabaseNames::PRIMARY)->getIdentity();
        $callerIndex = Database::getCurrentIndex();
        $problems = [];
        try {
            foreach ($bySchema as $schemaIndex => $entityClasses) {
                try {
                    if (!Database::isConnected($schemaIndex)) {
                        Database::connect($schemaIndex);
                    }
                    $mismatches = EntitySchemaAudit::audit($entityClasses, $schemaIndex);
                } catch (DatabaseException) {
                    foreach ($entityClasses as $entityClass) {
                        $problems[] = self::tableAddress($entityClass) . ': cannot read live schema';
                    }
                    continue;
                }

                $missing = [];
                foreach ($mismatches as $mismatch) {
                    if ($mismatch->axis === EntitySchemaAxis::TABLE) {
                        $missing[$mismatch->entityClass] = true;
                        $frameworkClass = $frameworkTables[$mismatch->table] ?? null;
                        if (
                            $frameworkClass !== null
                            && is_a($mismatch->entityClass, $frameworkClass, true)
                            && $mismatch->entityClass::getDatabase()->getIdentity() === $primaryIdentity
                        ) {
                            continue;
                        }
                    }
                    $problems[] = self::tableAddress($mismatch->entityClass) . ': ' . $mismatch->describe();
                }

                foreach ($entityClasses as $entityClass) {
                    if (isset($missing[$entityClass])) {
                        continue;
                    }
                    $binding = $entityClass::getDatabase();
                    try {
                        // LIMIT 0 checks SELECT permission on every column without reading or changing rows.
                        Database::sql(
                            'SELECT * FROM ' . $binding->getSqlTableName($entityClass::_table) . ' LIMIT 0',
                            index: $binding->connectionIndex,
                        );
                    } catch (DatabaseException) {
                        $problems[] = self::tableAddress($entityClass)
                            . ": cannot read table through row connection {$binding->connectionIndex}";
                    }
                }
            }
        } finally {
            Database::useConnection($callerIndex);
        }

        if ($problems !== []) {
            throw new DatabaseException("Mounted Entity schema audit failed:\n- " . implode("\n- ", $problems));
        }
    }

    /**
     * @param class-string<Entity> $entityClass Mounted Entity whose target failed
     * @return string Credential-free logical and physical table address
     * @throws NamedDatabaseException When the declared database cannot be resolved
     */
    private static function tableAddress(string $entityClass): string
    {
        $binding = $entityClass::getDatabase();
        return "database '{$binding->name}' ({$binding->getDatabaseName()}),"
            . " schema connection {$binding->schemaIndex}, table " . $entityClass::_table;
    }
}
