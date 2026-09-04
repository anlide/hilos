<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

use FilesystemIterator;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;
use Hilos\Database\SqlParamCollection;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Audits Entity ORM metadata (_table, _columns, _types, _primary, _indexes,
 * _foreign) and the typed properties hydration writes into against the live
 * database schema, entity by entity.
 *
 * Lives in framework/backend (not framework/tests) so that demos and projects,
 * which do not see framework/tests, can reuse it. It reads the live schema
 * through its own parameterized `information_schema` queries and compares by the
 * raw column type, deliberately bypassing {@see Schema::mysqlTypeToPhp()} — a
 * cross-dictionary comparison was the core lie of the removed `db:entity:diff`
 * command (HIL-478). Both directions are audited, by separate entry points:
 * {@see EntitySchemaAudit::audit()} goes Entity-to-schema, one Entity at a time
 * across every axis, while {@see EntitySchemaAudit::auditTableCoverage()} goes
 * schema-to-Entity and asks of every live table which Entity claims it. Only the
 * second one sees a table nobody declared - the first cannot, having nothing to
 * start from.
 *
 * All divergences for an entity are collected into a list so the caller reports
 * every problem at once instead of failing on the first.
 */
final class EntitySchemaAudit
{
    /** `information_schema.COLUMNS` / `STATISTICS` / `KEY_COLUMN_USAGE` result keys. */
    private const string COL_NAME = 'COLUMN_NAME';
    private const string COL_TYPE = 'COLUMN_TYPE';
    private const string COL_IS_NULLABLE = 'IS_NULLABLE';
    private const string COL_DEFAULT = 'COLUMN_DEFAULT';
    private const string COL_EXTRA = 'EXTRA';
    private const string COL_INDEX_NAME = 'INDEX_NAME';
    private const string COL_NON_UNIQUE = 'NON_UNIQUE';
    private const string COL_CONSTRAINT_NAME = 'CONSTRAINT_NAME';
    private const string COL_REFERENCED_TABLE_NAME = 'REFERENCED_TABLE_NAME';
    private const string COL_TABLE_NAME = 'TABLE_NAME';
    private const string COL_TABLE_COUNT = 'c';

    /** `information_schema.TABLES.TABLE_TYPE` of a real table, as opposed to a view. */
    private const string TABLE_TYPE_BASE = 'BASE TABLE';

    /** PSR-4 location of the Entity classes the framework itself ships. */
    private const string FRAMEWORK_ENTITY_DIR = '/Entity/Item';
    private const string FRAMEWORK_ENTITY_NAMESPACE = 'Hilos\\Database\\Entity\\Item\\';

    /** `IS_NULLABLE` and `EXTRA` markers that make an unmapped column insert-safe. */
    private const string NULLABLE_YES = 'YES';
    private const string EXTRA_AUTO_INCREMENT = 'auto_increment';
    private const string EXTRA_GENERATED = 'GENERATED';

    /** Reserved index name that is compared through the PRIMARY axis, never the INDEX axis. */
    private const string INDEX_PRIMARY = 'PRIMARY';

    /**
     * Discover concrete Entity classes under a project directory via PSR-4.
     *
     * Recurses `$dir`, maps each `*.php` file to a class name by prefixing the
     * path-relative namespace, and keeps only autoloadable, non-abstract Entity
     * subclasses. No text parsing of file bodies.
     *
     * A project's own catalog is what this door is for. The framework's own list has
     * exactly one source, {@see EntitySchemaAudit::frameworkEntities()}, so the
     * framework namespace is refused here rather than served: a second hand-kept pair
     * of directory and namespace is precisely what drifts apart in silence. The
     * refusal reads the namespace and never the directory, because the directory
     * legitimately arrives through a demo's `vendor/anlide/hilos` symlink, as a
     * relative path, and with a trailing separator - a false refusal there would cost
     * more than the miss it prevents. It reads the namespace the way PHP resolves a
     * class name - case-insensitively - because a miscased prefix reaches the very same
     * classes and would otherwise walk past the lock.
     *
     * @param string $dir Directory to scan (absolute path recommended)
     * @param string $namespacePrefix PSR-4 namespace of `$dir`, e.g. `App\Database\Entity\Item\`
     * @return list<class-string<Entity>> Discovered concrete Entity classes, sorted by class name
     * @throws LogicException When the framework Entity namespace is passed instead of a project one
     */
    public static function discoverEntities(string $dir, string $namespacePrefix): array
    {
        if (strcasecmp(rtrim($namespacePrefix, '\\') . '\\', self::FRAMEWORK_ENTITY_NAMESPACE) === 0) {
            throw new LogicException(
                'The framework Entity namespace has a single source: call '
                . 'EntitySchemaAudit::frameworkEntities() instead of discovering it by hand',
            );
        }

        return self::scanEntities($dir, $namespacePrefix);
    }

    /**
     * Audit each given Entity class against the live schema on `$index`.
     *
     * @param list<class-string<Entity>> $entityClasses Entity classes to audit
     * @param ?int $index Connection index (default: current)
     * @return list<EntitySchemaMismatch> Every divergence found, across all classes
     * @throws DatabaseException When an introspection query fails
     */
    public static function audit(array $entityClasses, ?int $index = null): array
    {
        $index = $index ?? Database::getCurrentIndex();

        $originalIndex = Database::getCurrentIndex();
        Database::useConnection($index);

        try {
            $mismatches = [];
            foreach ($entityClasses as $entityClass) {
                array_push($mismatches, ...self::auditEntity($entityClass));
            }
            return $mismatches;
        } finally {
            Database::useConnection($originalIndex);
        }
    }

    /**
     * Every base table of the live schema on `$index`.
     *
     * Views are excluded by TABLE_TYPE: an Entity maps a table, and a view has no
     * schema of its own to diverge from. Callers use the list two ways - as the set
     * {@see EntitySchemaAudit::auditTableCoverage()} must account for, and as the
     * answer to "does this installation create that table at all", which is how a
     * demo skips a framework Entity whose subsystem it never activated.
     *
     * @param ?int $index Connection index (default: current)
     * @return list<string> Table names, sorted by name
     * @throws DatabaseException When the introspection query fails
     */
    public static function liveTables(?int $index = null): array
    {
        $index = $index ?? Database::getCurrentIndex();

        $originalIndex = Database::getCurrentIndex();
        Database::useConnection($index);

        try {
            Database::sql(
                'SELECT ' . self::COL_TABLE_NAME
                . ' FROM information_schema.TABLES'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?'
                . ' ORDER BY ' . self::COL_TABLE_NAME,
                SqlParamCollection::fromArray([self::TABLE_TYPE_BASE]),
            );

            $tables = [];
            foreach (Database::rows() as $row) {
                $tables[] = (string) $row[self::COL_TABLE_NAME];
            }
            return $tables;
        } finally {
            Database::useConnection($originalIndex);
        }
    }

    /**
     * Every concrete Entity class the framework itself ships.
     *
     * Discovered rather than listed, so a demo auditing the framework's entities
     * cannot fall behind a newly shipped one by saying nothing. The directory
     * resolves through a demo's `vendor/anlide/hilos` symlink as well as from the
     * framework tree itself.
     *
     * @return list<class-string<Entity>> Framework Entity classes, sorted by class name
     */
    public static function frameworkEntities(): array
    {
        return self::scanEntities(
            dirname(__DIR__) . self::FRAMEWORK_ENTITY_DIR,
            self::FRAMEWORK_ENTITY_NAMESPACE,
        );
    }

    /**
     * Audit the live schema on `$index` the other way round: every base table must
     * be claimed, either by an audited Entity or by a declaration that it has none.
     *
     * The framework's own unmapped tables ({@see FrameworkTablesWithoutEntity}) are
     * subtracted for the caller; a project names its own through
     * `$allowedTablesWithoutEntity` and by that admits it looked at them.
     *
     * @param list<class-string<Entity>> $entityClasses Entities whose _table counts as claimed
     * @param list<string> $allowedTablesWithoutEntity Project tables that live outside the ORM on purpose
     * @param ?int $index Connection index (default: current)
     * @return list<EntitySchemaMismatch> One TABLE_UNMAPPED per unclaimed table, ordered by table name
     * @throws DatabaseException When the introspection query fails
     */
    public static function auditTableCoverage(
        array $entityClasses,
        array $allowedTablesWithoutEntity = [],
        ?int $index = null,
    ): array {
        $claimed = array_fill_keys(
            [...FrameworkTablesWithoutEntity::tables(), ...$allowedTablesWithoutEntity],
            true,
        );
        foreach ($entityClasses as $entityClass) {
            $claimed[constant("{$entityClass}::" . Entity::META_TABLE)] = true;
        }

        $mismatches = [];
        foreach (self::liveTables($index) as $table) {
            if (isset($claimed[$table])) {
                continue;
            }

            $mismatches[] = new EntitySchemaMismatch(
                EntitySchemaAxis::TABLE_UNMAPPED,
                null,
                $table,
                null,
                'an Entity mapped to this table or a declared table without one',
                'no Entity maps it',
            );
        }
        return $mismatches;
    }

    /**
     * Walk a PSR-4 directory and collect the concrete Entity classes under it.
     *
     * The body of {@see EntitySchemaAudit::discoverEntities()} with no refusal in
     * front of it, so {@see EntitySchemaAudit::frameworkEntities()} reaches the walk
     * without tripping over the lock that exists to keep everyone else out of it.
     *
     * @param string $dir Directory to scan (absolute path recommended)
     * @param string $namespacePrefix PSR-4 namespace of `$dir`, e.g. `Hilos\Database\Entity\Item\`
     * @return list<class-string<Entity>> Discovered concrete Entity classes, sorted by class name
     */
    private static function scanEntities(string $dir, string $namespacePrefix): array
    {
        $prefix = rtrim($namespacePrefix, '\\') . '\\';
        $baseDir = rtrim($dir, DIRECTORY_SEPARATOR);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
        );

        $classes = [];
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($fileInfo->getPathname(), strlen($baseDir) + 1, -strlen('.php'));
            $class = $prefix . str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);

            if (!class_exists($class) || !is_subclass_of($class, Entity::class)) {
                continue;
            }
            // Reflection asks whether the class is declared abstract. Plain PHP has no answer:
            // `class_exists()` is true for an abstract class, and nothing else in the language
            // reports the declaration - only instantiating it, which is what must be avoided.
            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);
        return $classes;
    }

    /**
     * Audit one Entity class across every axis.
     *
     * @param class-string<Entity> $entityClass Entity to audit
     * @return list<EntitySchemaMismatch> Divergences for this entity
     * @throws DatabaseException When an introspection query fails
     */
    private static function auditEntity(string $entityClass): array
    {
        $table = constant("{$entityClass}::" . Entity::META_TABLE);
        /** @var list<string> $columns */
        $columns = constant("{$entityClass}::" . Entity::META_COLUMNS);
        /** @var array<string, string> $types */
        $types = constant("{$entityClass}::" . Entity::META_TYPES);
        $primary = constant("{$entityClass}::" . Entity::META_PRIMARY);
        /** @var array<string, string> $foreign */
        $foreign = defined("{$entityClass}::" . Entity::META_FOREIGN)
            ? constant("{$entityClass}::" . Entity::META_FOREIGN)
            : [];
        /** @var array<string, array<string, mixed>> $indexes */
        $indexes = defined("{$entityClass}::" . Entity::META_INDEXES)
            ? constant("{$entityClass}::" . Entity::META_INDEXES)
            : [];

        if (!self::tableExists($table)) {
            return [new EntitySchemaMismatch(
                EntitySchemaAxis::TABLE,
                $entityClass,
                $table,
                null,
                'table exists',
                'missing',
            )];
        }

        $mismatches = [];
        self::auditMeta($mismatches, $entityClass, $table, $columns, $types);

        $liveColumns = self::liveColumns($table);
        self::auditColumns($mismatches, $entityClass, $table, $columns, $types, $liveColumns);

        $statistics = self::liveStatistics($table);
        $liveForeignKeys = self::liveForeignKeys($table);
        self::auditPrimary($mismatches, $entityClass, $table, $columns, $primary, $statistics);
        self::auditIndexes($mismatches, $entityClass, $table, $indexes, $statistics, $liveForeignKeys);
        self::auditForeign($mismatches, $entityClass, $table, $foreign, $liveForeignKeys);

        return $mismatches;
    }

    /**
     * Axis META: keys(_types) and _columns must match as sets, both directions.
     *
     * @param list<EntitySchemaMismatch> $mismatches Accumulator, appended in place
     * @param class-string<Entity> $entityClass Entity being audited
     * @param string $table The Entity's _table
     * @param list<string> $columns The Entity's _columns
     * @param array<string, string> $types The Entity's _types
     */
    private static function auditMeta(
        array &$mismatches,
        string $entityClass,
        string $table,
        array $columns,
        array $types,
    ): void {
        $typeKeys = array_keys($types);
        if (array_diff($columns, $typeKeys) === [] && array_diff($typeKeys, $columns) === []) {
            return;
        }

        $mismatches[] = new EntitySchemaMismatch(
            EntitySchemaAxis::META,
            $entityClass,
            $table,
            null,
            '_columns = keys(_types): ' . implode(',', $columns),
            'keys(_types): ' . implode(',', $typeKeys),
        );
    }

    /**
     * Axes COLUMN_MISSING, COLUMN_NOT_INSERTABLE, PROPERTY_MISSING,
     * PROPERTY_NULLABLE, TYPE, TYPE_UNSUPPORTED.
     *
     * @param list<EntitySchemaMismatch> $mismatches Accumulator, appended in place
     * @param class-string<Entity> $entityClass Entity being audited
     * @param string $table The Entity's _table
     * @param list<string> $columns The Entity's _columns
     * @param array<string, string> $types The Entity's _types
     * @param array<string, array<string, mixed>> $liveColumns Live columns keyed by name
     */
    private static function auditColumns(
        array &$mismatches,
        string $entityClass,
        string $table,
        array $columns,
        array $types,
        array $liveColumns,
    ): void {
        // Reflection asks what the Entity *declares* about a property: that it exists, whether
        // it is static, and its declared type with nullability. Plain PHP answers none of it -
        // `property_exists()` sees the name but not the type, and a typed property left
        // uninitialized is invisible to `get_object_vars()` on an instance. One object is built
        // here and handed down: auditProperty() asks all three questions of it.
        $reflection = new ReflectionClass($entityClass);

        foreach ($columns as $column) {
            if (!isset($liveColumns[$column])) {
                $mismatches[] = new EntitySchemaMismatch(
                    EntitySchemaAxis::COLUMN_MISSING,
                    $entityClass,
                    $table,
                    $column,
                    'column present',
                    'missing',
                );
                continue;
            }

            // Before the type axes, because an unmapped MySQL type skips the rest
            // of this iteration and would hide a nullability divergence with it.
            self::auditProperty($mismatches, $entityClass, $table, $column, $liveColumns[$column], $reflection);

            $mysqlType = (string) $liveColumns[$column][self::COL_TYPE];
            $accepted = PhpType::forMysqlType($mysqlType);
            if ($accepted === []) {
                $mismatches[] = new EntitySchemaMismatch(
                    EntitySchemaAxis::TYPE_UNSUPPORTED,
                    $entityClass,
                    $table,
                    $column,
                    'a mapped PhpType',
                    "unmapped MySQL type '{$mysqlType}'",
                );
                continue;
            }

            $acceptedValues = array_map(static fn(PhpType $type): string => $type->value, $accepted);
            if (!in_array($types[$column], $acceptedValues, true)) {
                $mismatches[] = new EntitySchemaMismatch(
                    EntitySchemaAxis::TYPE,
                    $entityClass,
                    $table,
                    $column,
                    "one of [" . implode('|', $acceptedValues) . "] for '{$mysqlType}'",
                    $types[$column],
                );
            }
        }

        foreach ($liveColumns as $name => $info) {
            if (in_array($name, $columns, true) || self::isInsertSafe($info)) {
                continue;
            }
            $mismatches[] = new EntitySchemaMismatch(
                EntitySchemaAxis::COLUMN_NOT_INSERTABLE,
                $entityClass,
                $table,
                $name,
                'insert-safe (nullable / DEFAULT / auto_increment / GENERATED) or mapped in _columns',
                'NOT NULL without default',
            );
        }
    }

    /**
     * Axes PROPERTY_MISSING and PROPERTY_NULLABLE for one mapped column.
     *
     * The typed property is the shape hydration writes into, so its nullability is
     * a claim about the column and is checked in both directions: nullable over a
     * NOT NULL column means `saveInsert()` may send NULL where the column refuses
     * it, non-nullable over a NULL-able column means hydrating a stored NULL is a
     * TypeError.
     *
     * auto_increment is the one exemption, because `saveInsert()` omits a null
     * primary key from the statement. A DEFAULT is NOT one: `saveInsert()` lists
     * every mapped column, so the null leaves as an explicit NULL and the DEFAULT
     * never applies — a db-defaulted column is either owned by the application
     * through a non-nullable property or left out of `_columns` entirely (see
     * docs/agents/orm/entity.md, "Who owns the value of a column with a DB-level
     * DEFAULT"). That is why this reads EXTRA directly instead of asking the wider
     * {@see self::isInsertSafe()}, which answers a different question: whether an
     * UNMAPPED column can be left out of the insert.
     *
     * GENERATED needs no arm of its own: MariaDB refuses NULL / NOT NULL on a
     * generated column outright, so such a column always reports IS_NULLABLE=YES
     * and is judged above, by the NULL-able rule.
     *
     * @param list<EntitySchemaMismatch> $mismatches Accumulator, appended in place
     * @param class-string<Entity> $entityClass Entity being audited
     * @param string $table The Entity's _table
     * @param string $column Column from _columns, known to exist in the live table
     * @param array<string, mixed> $columnInfo Live column row (IS_NULLABLE, COLUMN_DEFAULT, EXTRA)
     * @param ReflectionClass<Entity> $reflection Reflection of the audited Entity
     */
    private static function auditProperty(
        array &$mismatches,
        string $entityClass,
        string $table,
        string $column,
        array $columnInfo,
        ReflectionClass $reflection,
    ): void {
        if (!$reflection->hasProperty($column) || $reflection->getProperty($column)->isStatic()) {
            $mismatches[] = new EntitySchemaMismatch(
                EntitySchemaAxis::PROPERTY_MISSING,
                $entityClass,
                $table,
                $column,
                'instance property $' . $column,
                $reflection->hasProperty($column) ? 'declared static' : 'no property',
            );
            return;
        }

        // A property with no type at all, and `mixed`, both hold null — and
        // allowsNull() already answers true for `mixed`, so one call covers all three.
        $type = $reflection->getProperty($column)->getType();
        $propertyNullable = $type === null || $type->allowsNull();
        $columnNullable = $columnInfo[self::COL_IS_NULLABLE] === self::NULLABLE_YES;

        if ($columnNullable) {
            if (!$propertyNullable) {
                $mismatches[] = new EntitySchemaMismatch(
                    EntitySchemaAxis::PROPERTY_NULLABLE,
                    $entityClass,
                    $table,
                    $column,
                    'nullable property',
                    'NULL-able column',
                );
            }
            return;
        }

        if (!$propertyNullable) {
            return;
        }

        if (stripos((string) $columnInfo[self::COL_EXTRA], self::EXTRA_AUTO_INCREMENT) !== false) {
            return;
        }

        $mismatches[] = new EntitySchemaMismatch(
            EntitySchemaAxis::PROPERTY_NULLABLE,
            $entityClass,
            $table,
            $column,
            'non-nullable property',
            $columnInfo[self::COL_DEFAULT] !== null
                ? 'NOT NULL with a DEFAULT an explicit NULL bypasses'
                : 'NOT NULL without default',
        );
    }

    /**
     * Axis PRIMARY: _primary equals the ordered PRIMARY KEY, which must exist and
     * whose columns must all be in _columns.
     *
     * @param list<EntitySchemaMismatch> $mismatches Accumulator, appended in place
     * @param class-string<Entity> $entityClass Entity being audited
     * @param string $table The Entity's _table
     * @param list<string> $columns The Entity's _columns
     * @param string|list<string> $primary The Entity's _primary
     * @param list<array<string, mixed>> $statistics Live `information_schema.STATISTICS` rows
     */
    private static function auditPrimary(
        array &$mismatches,
        string $entityClass,
        string $table,
        array $columns,
        string|array $primary,
        array $statistics,
    ): void {
        $declared = is_array($primary) ? array_values($primary) : [$primary];

        $livePrimary = [];
        foreach ($statistics as $row) {
            if ($row[self::COL_INDEX_NAME] === self::INDEX_PRIMARY) {
                $livePrimary[] = (string) $row[self::COL_NAME];
            }
        }

        if ($livePrimary === []) {
            $mismatches[] = new EntitySchemaMismatch(
                EntitySchemaAxis::PRIMARY,
                $entityClass,
                $table,
                null,
                'PRIMARY KEY (' . implode(',', $declared) . ')',
                'no PRIMARY KEY',
            );
        } elseif ($livePrimary !== $declared) {
            $mismatches[] = new EntitySchemaMismatch(
                EntitySchemaAxis::PRIMARY,
                $entityClass,
                $table,
                null,
                implode(',', $declared),
                implode(',', $livePrimary),
            );
        }

        foreach ($declared as $primaryColumn) {
            if (!in_array($primaryColumn, $columns, true)) {
                $mismatches[] = new EntitySchemaMismatch(
                    EntitySchemaAxis::PRIMARY,
                    $entityClass,
                    $table,
                    $primaryColumn,
                    'primary column listed in _columns',
                    'absent from _columns',
                );
            }
        }
    }

    /**
     * Axis INDEX: _indexes matches the live secondary indexes both directions, by
     * ordered columns and uniqueness. PRIMARY and FK-backing indexes are excluded.
     *
     * @param list<EntitySchemaMismatch> $mismatches Accumulator, appended in place
     * @param class-string<Entity> $entityClass Entity being audited
     * @param string $table The Entity's _table
     * @param array<string, array<string, mixed>> $indexes The Entity's _indexes
     * @param list<array<string, mixed>> $statistics Live `information_schema.STATISTICS` rows
     * @param array<string, array{columns: list<string>, table: string}> $liveForeignKeys Live FKs by constraint name
     */
    private static function auditIndexes(
        array &$mismatches,
        string $entityClass,
        string $table,
        array $indexes,
        array $statistics,
        array $liveForeignKeys,
    ): void {
        $liveIndexes = self::groupLiveIndexes($statistics, array_keys($liveForeignKeys));

        foreach ($indexes as $name => $definition) {
            $declaredColumns = $definition[Entity::INDEX_COLUMNS];
            $declaredUnique = $definition[Entity::INDEX_UNIQUE] ?? false;

            if (!isset($liveIndexes[$name])) {
                $mismatches[] = new EntitySchemaMismatch(
                    EntitySchemaAxis::INDEX,
                    $entityClass,
                    $table,
                    $name,
                    'index (' . implode(',', $declaredColumns) . ')',
                    'missing',
                );
                continue;
            }

            $live = $liveIndexes[$name];
            if ($live['columns'] !== array_values($declaredColumns) || $live['unique'] !== $declaredUnique) {
                $mismatches[] = new EntitySchemaMismatch(
                    EntitySchemaAxis::INDEX,
                    $entityClass,
                    $table,
                    $name,
                    self::describeIndex(array_values($declaredColumns), (bool) $declaredUnique),
                    self::describeIndex($live['columns'], $live['unique']),
                );
            }
        }

        foreach ($liveIndexes as $name => $live) {
            if (!isset($indexes[$name])) {
                $mismatches[] = new EntitySchemaMismatch(
                    EntitySchemaAxis::INDEX,
                    $entityClass,
                    $table,
                    $name,
                    'declared in _indexes',
                    self::describeIndex($live['columns'], $live['unique']),
                );
            }
        }
    }

    /**
     * Axis FOREIGN: forward, each _foreign pair has a real single-column FK;
     * backward, an undeclared single-column FK is a mismatch. Composite FKs are
     * skipped as inexpressible in _foreign.
     *
     * @param list<EntitySchemaMismatch> $mismatches Accumulator, appended in place
     * @param class-string<Entity> $entityClass Entity being audited
     * @param string $table The Entity's _table
     * @param array<string, string> $foreign The Entity's _foreign (column => referenced table)
     * @param array<string, array{columns: list<string>, table: string}> $liveForeignKeys Live FKs by constraint name
     */
    private static function auditForeign(
        array &$mismatches,
        string $entityClass,
        string $table,
        array $foreign,
        array $liveForeignKeys,
    ): void {
        $singleColumnForeignKeys = [];
        foreach ($liveForeignKeys as $foreignKey) {
            if (count($foreignKey['columns']) === 1) {
                $singleColumnForeignKeys[$foreignKey['columns'][0]] = $foreignKey['table'];
            }
        }

        foreach ($foreign as $column => $referencedTable) {
            if (($singleColumnForeignKeys[$column] ?? null) !== $referencedTable) {
                $mismatches[] = new EntitySchemaMismatch(
                    EntitySchemaAxis::FOREIGN,
                    $entityClass,
                    $table,
                    $column,
                    "foreign key -> {$referencedTable}",
                    $singleColumnForeignKeys[$column] ?? 'no foreign key',
                );
            }
        }

        foreach ($singleColumnForeignKeys as $column => $referencedTable) {
            if (!isset($foreign[$column])) {
                $mismatches[] = new EntitySchemaMismatch(
                    EntitySchemaAxis::FOREIGN,
                    $entityClass,
                    $table,
                    $column,
                    'declared in _foreign',
                    "foreign key -> {$referencedTable}",
                );
            }
        }
    }

    /**
     * @param string $table Table name
     * @return bool Whether the table exists in the current schema
     * @throws DatabaseException When the query fails
     */
    private static function tableExists(string $table): bool
    {
        Database::sql(
            'SELECT COUNT(*) AS ' . self::COL_TABLE_COUNT
            . ' FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            SqlParamCollection::fromArray([$table]),
        );
        $row = Database::row();
        return $row !== null && (int) $row[self::COL_TABLE_COUNT] > 0;
    }

    /**
     * @param string $table Table name
     * @return array<string, array<string, mixed>> Live columns keyed by column name, in ordinal order
     * @throws DatabaseException When the query fails
     */
    private static function liveColumns(string $table): array
    {
        Database::sql(
            'SELECT ' . self::COL_NAME . ', ' . self::COL_TYPE . ', ' . self::COL_IS_NULLABLE
            . ', ' . self::COL_DEFAULT . ', ' . self::COL_EXTRA
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            . ' ORDER BY ORDINAL_POSITION',
            SqlParamCollection::fromArray([$table]),
        );

        $columns = [];
        foreach (Database::rows() as $row) {
            $columns[(string) $row[self::COL_NAME]] = $row;
        }
        return $columns;
    }

    /**
     * @param string $table Table name
     * @return list<array<string, mixed>> `information_schema.STATISTICS` rows, ordered by index then sequence
     * @throws DatabaseException When the query fails
     */
    private static function liveStatistics(string $table): array
    {
        Database::sql(
            'SELECT ' . self::COL_INDEX_NAME . ', SEQ_IN_INDEX, ' . self::COL_NAME . ', ' . self::COL_NON_UNIQUE
            . ' FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            . ' ORDER BY ' . self::COL_INDEX_NAME . ', SEQ_IN_INDEX',
            SqlParamCollection::fromArray([$table]),
        );
        return Database::rows();
    }

    /**
     * @param string $table Table name
     * @return array<string, array{columns: list<string>, table: string}> Live FKs by constraint name, columns ordered
     * @throws DatabaseException When the query fails
     */
    private static function liveForeignKeys(string $table): array
    {
        Database::sql(
            'SELECT ' . self::COL_CONSTRAINT_NAME . ', ' . self::COL_NAME . ', ORDINAL_POSITION, '
            . self::COL_REFERENCED_TABLE_NAME
            . ' FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            . ' AND ' . self::COL_REFERENCED_TABLE_NAME . ' IS NOT NULL'
            . ' ORDER BY ' . self::COL_CONSTRAINT_NAME . ', ORDINAL_POSITION',
            SqlParamCollection::fromArray([$table]),
        );

        $foreignKeys = [];
        foreach (Database::rows() as $row) {
            $name = (string) $row[self::COL_CONSTRAINT_NAME];
            $foreignKeys[$name] ??= ['columns' => [], 'table' => (string) $row[self::COL_REFERENCED_TABLE_NAME]];
            $foreignKeys[$name]['columns'][] = (string) $row[self::COL_NAME];
        }
        return $foreignKeys;
    }

    /**
     * Group STATISTICS rows into secondary indexes, dropping PRIMARY and any
     * index that backs a foreign key.
     *
     * @param list<array<string, mixed>> $statistics Live STATISTICS rows
     * @param list<string> $foreignKeyNames Constraint names of the table's foreign keys
     * @return array<string, array{columns: list<string>, unique: bool}> Secondary indexes by name
     */
    private static function groupLiveIndexes(array $statistics, array $foreignKeyNames): array
    {
        $indexes = [];
        foreach ($statistics as $row) {
            $name = (string) $row[self::COL_INDEX_NAME];
            if ($name === self::INDEX_PRIMARY || in_array($name, $foreignKeyNames, true)) {
                continue;
            }
            $indexes[$name] ??= ['columns' => [], 'unique' => (int) $row[self::COL_NON_UNIQUE] === 0];
            $indexes[$name]['columns'][] = (string) $row[self::COL_NAME];
        }
        return $indexes;
    }

    /**
     * @param array<string, mixed> $columnInfo Live column row (IS_NULLABLE, COLUMN_DEFAULT, EXTRA)
     * @return bool Whether saveInsert() may legally omit this column
     */
    private static function isInsertSafe(array $columnInfo): bool
    {
        if ($columnInfo[self::COL_IS_NULLABLE] === self::NULLABLE_YES) {
            return true;
        }
        if ($columnInfo[self::COL_DEFAULT] !== null) {
            return true;
        }
        $extra = (string) $columnInfo[self::COL_EXTRA];
        return stripos($extra, self::EXTRA_AUTO_INCREMENT) !== false
            || stripos($extra, self::EXTRA_GENERATED) !== false;
    }

    /**
     * @param list<string> $columns Index columns in order
     * @param bool $unique Whether the index is unique
     * @return string Human description, e.g. `unique(a,b)` or `(a)`
     */
    private static function describeIndex(array $columns, bool $unique): string
    {
        // external-boundary: the neutral element of the signature — a plain index is spelled without the word
        return ($unique ? 'unique' : '') . '(' . implode(',', $columns) . ')';
    }
}
