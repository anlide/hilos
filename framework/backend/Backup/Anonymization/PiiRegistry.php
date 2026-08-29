<?php

declare(strict_types=1);

namespace Hilos\Backup\Anonymization;

use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupTableResolver;
use Hilos\Backup\Exception\AnonymizationConfigException;
use Hilos\Backup\Exception\BackupException;
use Hilos\Database\Context\DbContext;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Schema\FrameworkTablesWithoutEntity;
use Hilos\Database\Schema\TablesWithoutEntityProvider;
use Hilos\Hilos;

/**
 * PiiRegistry - what one restore run believes about every table's personal data.
 *
 * Collected rather than listed: each table carries its own verdict where it is declared -
 * an Entity in its `_pii` / `_piiNotPersonal` constants, a table outside the ORM in its
 * {@see TablesWithoutEntityProvider} - and the registry is the walk over those
 * declarations. The walk goes through the collections a {@see DbContext} mounted, which
 * is the list an installation already keeps of the tables that are part of the system;
 * a second hand-written list of tables is exactly the thing this registry stopped being.
 *
 * A table nobody classified is absent from the registry rather than assumed clean, and
 * the coverage gate then names it. Both halves of a verdict are kept: the columns that
 * are personal, and the columns looked at and found not to be. The second half is what
 * lets a question be asked about a column rather than about a table - an empty column map
 * says the table holds nothing personal, and says nothing at all about the column a
 * migration added yesterday.
 */
final class PiiRegistry
{
    /**
     * @param array<int, array<string, array<string, AnonymizationStrategy>|AnonymizationStrategy>> $rowsByConnection
     *     Normalized rows keyed by connection index: table name to its per-column strategies,
     *     or to {@see AnonymizationStrategy::PURGE} for a table dropped whole
     * @param array<int, array<string, list<string>>> $notPersonalByConnection Non-personal columns
     *     of the same tables, keyed the same way
     */
    public function __construct(
        private readonly array $rowsByConnection,
        private readonly array $notPersonalByConnection = [],
    ) {
    }

    /**
     * Collects the registry this installation restores under.
     *
     * Walks the mounted collections first, in the order they were registered - framework
     * collections, then the project's - and the tables outside the ORM after them. That
     * order becomes the order of the anonymization pass.
     *
     * @return self Registry over every verdict this installation declares
     * @throws AnonymizationConfigException When a verdict is malformed, or a mounted collection
     *     resolves to no table class
     */
    public static function collect(): self
    {
        $rows = [];
        $notPersonal = [];
        $index = DatabaseConnectionDefaults::PRIMARY_INDEX;

        foreach (self::classifiedEntities() as $entityClass) {
            $table = $entityClass::_table;
            $row = self::normalizeRow($entityClass, $table, constant("{$entityClass}::" . Entity::META_PII));
            $rows[$index][$table] = $row;
            $notPersonal[$index][$table] = self::normalizeNotPersonal(
                $entityClass,
                $table,
                defined("{$entityClass}::" . Entity::META_PII_NOT_PERSONAL)
                    ? constant("{$entityClass}::" . Entity::META_PII_NOT_PERSONAL)
                    : [],
                $row,
            );
        }

        foreach (self::tablesWithoutEntityProviders() as $provider) {
            $declaredNotPersonal = $provider::piiNotPersonal();
            foreach ($provider::pii() as $table => $declaredRow) {
                $row = self::normalizeRow($provider, (string)$table, $declaredRow);
                $rows[$index][(string)$table] = $row;
                $notPersonal[$index][(string)$table] = self::normalizeNotPersonal(
                    $provider,
                    (string)$table,
                    $declaredNotPersonal[$table] ?? [],
                    $row,
                );
            }
        }

        return new self($rows, $notPersonal);
    }

    /**
     * Builds a registry out of declarations written in full, the entry tests and fixtures take.
     *
     * @param array<int, array<class-string|string, array<string, AnonymizationStrategy>|AnonymizationStrategy>> ...$declarations
     *     Declarations in override order, least significant first
     * @return self Registry over the merged rows
     * @throws AnonymizationConfigException When a declaration is not a registry
     */
    public static function fromDeclarations(array ...$declarations): self
    {
        $rows = [];
        foreach ($declarations as $declaration) {
            foreach (self::normalize($declaration) as $connectionIndex => $tables) {
                // Table keys, so a later declaration replaces a row rather than appending to it.
                $rows[$connectionIndex] = array_merge($rows[$connectionIndex] ?? [], $tables);
            }
        }

        return new self($rows);
    }

    /**
     * Tells whether the registry classifies nothing at all.
     *
     * The condition the CLI preflight and the engine both refuse on: a project that
     * declared no rows has not opted out of anonymization, it has not configured it, and
     * running the pass would silently do nothing to production data.
     *
     * @return bool Whether no table is declared on any connection
     */
    public function isEmpty(): bool
    {
        foreach ($this->rowsByConnection as $tables) {
            if ($tables !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns the tables classified for one connection.
     *
     * @param int $connectionIndex Connection index
     * @return list<string> Table names, in declaration order
     */
    public function declaredTables(int $connectionIndex): array
    {
        return array_keys($this->rowsByConnection[$connectionIndex] ?? []);
    }

    /**
     * Returns the per-column strategies declared for one table.
     *
     * @param int $connectionIndex Connection index
     * @param string $table Table name
     * @return ?array<string, AnonymizationStrategy> Column strategies - empty when the table is
     *     declared to hold no personal data or to be purged whole - or null when the table is
     *     not classified at all
     */
    public function strategiesFor(int $connectionIndex, string $table): ?array
    {
        $row = $this->rowsByConnection[$connectionIndex][$table] ?? null;
        if ($row === null) {
            return null;
        }

        return $row instanceof AnonymizationStrategy ? [] : $row;
    }

    /**
     * Returns the columns of one table looked at and found to hold no personal data.
     *
     * Told apart from {@see self::strategiesFor()} returning an empty map: that says the
     * table holds nothing personal, this says which of its columns that judgement covers.
     *
     * @param int $connectionIndex Connection index
     * @param string $table Table name
     * @return ?list<string> Non-personal columns - empty for a purged table, whose rows do not
     *     survive - or null when the table is not classified at all
     */
    public function notPersonalColumns(int $connectionIndex, string $table): ?array
    {
        if (!isset($this->rowsByConnection[$connectionIndex][$table])) {
            return null;
        }

        return $this->notPersonalByConnection[$connectionIndex][$table] ?? [];
    }

    /**
     * Tells whether a table is declared to be emptied rather than rewritten.
     *
     * @param int $connectionIndex Connection index
     * @param string $table Table name
     * @return bool Whether the table carries {@see AnonymizationStrategy::PURGE}
     */
    public function isPurged(int $connectionIndex, string $table): bool
    {
        return ($this->rowsByConnection[$connectionIndex][$table] ?? null) === AnonymizationStrategy::PURGE;
    }

    /**
     * Names the Entity classes of the mounted collections that carry a verdict.
     *
     * A collection whose Entity declares no `_pii` is not an error here: an unclassified
     * table is refused by the coverage gate, which can name it, rather than by a walk that
     * only knows it found nothing.
     *
     * @return list<class-string<Entity>> Entity classes declaring a verdict, in registration order
     * @throws AnonymizationConfigException When a mounted collection resolves to no table class
     */
    private static function classifiedEntities(): array
    {
        $entityClasses = [];
        foreach (Hilos::$db?->getObjectCollectionClasses() ?? [] as $collectionClass) {
            try {
                $entityClass = BackupTableResolver::entityClassOf($collectionClass);
            } catch (BackupException $failure) {
                throw new AnonymizationConfigException(
                    "Mounted collection {$collectionClass} stands for no table, so its personal data cannot be judged",
                    0,
                    $failure,
                );
            }
            if (defined("{$entityClass}::" . Entity::META_PII)) {
                $entityClasses[] = $entityClass;
            }
        }

        return $entityClasses;
    }

    /**
     * Names the providers of tables that live outside the ORM.
     *
     * The framework's own always, and the project's when its backup catalog names one.
     *
     * @return list<class-string<TablesWithoutEntityProvider>> Framework provider first, then the project's
     * @throws AnonymizationConfigException When the catalog names something that is not a provider
     */
    private static function tablesWithoutEntityProviders(): array
    {
        $providers = [FrameworkTablesWithoutEntity::class];
        $catalogClass = Hilos::getBackupCatalogClass();
        if ($catalogClass === null) {
            return $providers;
        }

        $declared = $catalogClass::getCatalog()[BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY] ?? null;
        if ($declared === null) {
            return $providers;
        }
        if (!is_string($declared) || !is_subclass_of($declared, TablesWithoutEntityProvider::class)) {
            throw new AnonymizationConfigException(
                'Backup catalog key [' . BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY . '] must name a '
                . TablesWithoutEntityProvider::class . ' class',
            );
        }
        $providers[] = $declared;

        return $providers;
    }

    /**
     * Resolves the table keys of one declaration and checks the shape of its values.
     *
     * This is the structural pass: it needs neither a database nor an archive, so a
     * malformed registry is refused before a restore does any work at all.
     *
     * @param array<int, array<class-string|string, array<string, AnonymizationStrategy>|AnonymizationStrategy>> $declaration
     *     One declaration in explicit form
     * @return array<int, array<string, array<string, AnonymizationStrategy>|AnonymizationStrategy>>
     *     The same rows with table names resolved
     * @throws AnonymizationConfigException When a key names no table, a value is not a strategy
     *     map, or a table-level strategy other than purge is declared
     */
    private static function normalize(array $declaration): array
    {
        $rows = [];
        foreach ($declaration as $connectionIndex => $tables) {
            $rows[$connectionIndex] = [];
            foreach ($tables as $key => $row) {
                $table = self::tableNameOf((string)$key);
                $rows[$connectionIndex][$table] = self::normalizeRow((string)$key, $table, $row);
            }
        }

        return $rows;
    }

    /**
     * Resolves one table key to the table it names.
     *
     * A key that names an existing class must be a table class; anything else is taken
     * verbatim, which is how the tables that have no class at all are declared.
     *
     * @param string $key Declared table key
     * @return string Table name
     * @throws AnonymizationConfigException When the key names a class that is not a table
     */
    private static function tableNameOf(string $key): string
    {
        if (!class_exists($key)) {
            return $key;
        }

        try {
            return BackupTableResolver::tableNameOf($key);
        } catch (BackupException $failure) {
            throw new AnonymizationConfigException(
                "PII registry key {$key} names a class that is not a table",
                0,
                $failure,
            );
        }
    }

    /**
     * Checks that one declared row is either a column map or a whole-table purge.
     *
     * @param string $origin Class or key the verdict is written in, for the refusal to point at
     * @param string $table Table the row was declared for
     * @param mixed $row Declared row
     * @return array<string, AnonymizationStrategy>|AnonymizationStrategy The row as declared
     * @throws AnonymizationConfigException When the row is neither shape
     */
    private static function normalizeRow(string $origin, string $table, mixed $row): array|AnonymizationStrategy
    {
        if ($row === AnonymizationStrategy::PURGE) {
            return $row;
        }
        if ($row instanceof AnonymizationStrategy) {
            throw new AnonymizationConfigException(
                "PII verdict in [{$origin}] declares [{$row->value}] on table [{$table}] as a whole; "
                . 'only [' . AnonymizationStrategy::PURGE->value . '] is a table-level strategy',
            );
        }
        if (!is_array($row)) {
            throw new AnonymizationConfigException(
                "PII verdict in [{$origin}] for table [{$table}] is neither a column map nor a table-level strategy",
            );
        }

        foreach ($row as $column => $strategy) {
            if (!$strategy instanceof AnonymizationStrategy) {
                throw new AnonymizationConfigException(
                    "PII verdict in [{$origin}] on column [{$table}.{$column}] does not name an anonymization strategy",
                );
            }
            if ($strategy === AnonymizationStrategy::PURGE) {
                throw new AnonymizationConfigException(
                    "PII verdict in [{$origin}] declares [" . AnonymizationStrategy::PURGE->value . '] on column '
                    . "[{$table}.{$column}]; it is a table-level strategy",
                );
            }
        }

        return $row;
    }

    /**
     * Checks the non-personal half of one verdict against the personal half.
     *
     * A column named by both halves is refused rather than resolved: the two say opposite
     * things about the same data, and either one of them is a mistake nobody would want
     * decided by which list was read first.
     *
     * A purged table keeps none: no row of it survives a restore, so a column of it has
     * nothing left to be personal or not personal about.
     *
     * @param string $origin Class the verdict is written in, for the refusal to point at
     * @param string $table Table the verdict was declared for
     * @param mixed $columns Columns declared to hold nothing personal
     * @param array<string, AnonymizationStrategy>|AnonymizationStrategy $row Personal half of the verdict
     * @return list<string> The columns as declared
     * @throws AnonymizationConfigException When the list is not one of column names, or a column
     *     is named by both halves
     */
    private static function normalizeNotPersonal(
        string $origin,
        string $table,
        mixed $columns,
        array|AnonymizationStrategy $row,
    ): array {
        if ($row instanceof AnonymizationStrategy) {
            return [];
        }
        if (!is_array($columns)) {
            throw new AnonymizationConfigException(
                "PII verdict in [{$origin}] for table [{$table}] does not list its non-personal columns",
            );
        }

        $notPersonal = [];
        foreach ($columns as $column) {
            if (!is_string($column)) {
                throw new AnonymizationConfigException(
                    "PII verdict in [{$origin}] for table [{$table}] names a non-personal column that is not a name",
                );
            }
            if (isset($row[$column])) {
                throw new AnonymizationConfigException(
                    "PII verdict in [{$origin}] names column [{$table}.{$column}] both personal and not personal",
                );
            }
            $notPersonal[] = $column;
        }

        return $notPersonal;
    }
}
