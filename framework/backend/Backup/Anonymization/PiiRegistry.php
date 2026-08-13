<?php

declare(strict_types=1);

namespace Hilos\Backup\Anonymization;

use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupTableResolver;
use Hilos\Backup\Exception\AnonymizationConfigException;
use Hilos\Backup\Exception\BackupException;
use Hilos\Hilos;

/**
 * PiiRegistry - what one restore run believes about every table's personal data.
 *
 * Built once per run out of two declarations: the framework's own
 * ({@see FrameworkPiiDeclaration}) and the project's, read from the
 * {@see BackupConstants::CATALOG_PII} key of its backup catalog. A project row for a
 * table replaces the framework row for that table whole rather than merging column by
 * column - a merge would let a framework column the project never wrote about reappear
 * inside a row the project believed it owned.
 *
 * A table key is an Entity or Object collection class wherever one exists, so the
 * registry survives a table rename; a raw table name is accepted only where no class
 * exists at all (`hilos_change_log` and friends). Both resolve to a table name here, so
 * a project overriding a framework row need not use the same spelling of the table the
 * framework used.
 *
 * An empty column map is meaningful and is not the same as an absent row: it is the
 * project saying "this table holds no personal data", which is exactly what the coverage
 * gate demands of every table in the archive.
 */
final class PiiRegistry
{
    /**
     * @param array<int, array<string, array<string, AnonymizationStrategy>|AnonymizationStrategy>> $rowsByConnection
     *     Normalized rows keyed by connection index: table name to its per-column strategies,
     *     or to {@see AnonymizationStrategy::PURGE} for a table dropped whole
     */
    public function __construct(private readonly array $rowsByConnection)
    {
    }

    /**
     * Builds the registry this installation restores under.
     *
     * @return self Registry over the framework declaration with the project's laid over it
     * @throws AnonymizationConfigException When either declaration is not a registry
     */
    public static function fromCatalog(): self
    {
        return self::fromDeclarations(FrameworkPiiDeclaration::rows(), self::catalogRows());
    }

    /**
     * Normalizes declarations and lays each over the ones before it.
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
     * Reads the project's rows off its backup catalog.
     *
     * @return array<int, array<class-string|string, array<string, AnonymizationStrategy>|AnonymizationStrategy>>
     *     Declared rows, empty when the project names no catalog or no PII section
     */
    private static function catalogRows(): array
    {
        $catalogClass = Hilos::getBackupCatalogClass();
        if ($catalogClass === null) {
            return [];
        }

        $rows = $catalogClass::getCatalog()[BackupConstants::CATALOG_PII] ?? [];

        return is_array($rows) ? $rows : [];
    }

    /**
     * Resolves the table keys of one declaration and checks the shape of its values.
     *
     * This is the structural pass: it needs neither a database nor an archive, so a
     * malformed registry is refused before a restore does any work at all.
     *
     * @param array<int, array<class-string|string, array<string, AnonymizationStrategy>|AnonymizationStrategy>> $declaration
     *     One declaration in catalog form
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
                $rows[$connectionIndex][$table] = self::normalizeRow($table, $row);
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
     * @param string $table Table the row was declared for
     * @param mixed $row Declared row
     * @return array<string, AnonymizationStrategy>|AnonymizationStrategy The row as declared
     * @throws AnonymizationConfigException When the row is neither shape
     */
    private static function normalizeRow(string $table, mixed $row): array|AnonymizationStrategy
    {
        if ($row === AnonymizationStrategy::PURGE) {
            return $row;
        }
        if ($row instanceof AnonymizationStrategy) {
            throw new AnonymizationConfigException(
                "PII registry declares [{$row->value}] on table [{$table}] as a whole; "
                . 'only [' . AnonymizationStrategy::PURGE->value . '] is a table-level strategy',
            );
        }
        if (!is_array($row)) {
            throw new AnonymizationConfigException(
                "PII registry row for table [{$table}] is neither a column map nor a table-level strategy",
            );
        }

        foreach ($row as $column => $strategy) {
            if (!$strategy instanceof AnonymizationStrategy) {
                throw new AnonymizationConfigException(
                    "PII registry column [{$table}.{$column}] does not name an anonymization strategy",
                );
            }
            if ($strategy === AnonymizationStrategy::PURGE) {
                throw new AnonymizationConfigException(
                    "PII registry declares [" . AnonymizationStrategy::PURGE->value . "] on column "
                    . "[{$table}.{$column}]; it is a table-level strategy",
                );
            }
        }

        return $row;
    }
}
