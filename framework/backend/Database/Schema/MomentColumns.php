<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\InvalidMomentDeclarationException;
use Throwable;

/** Reads infrastructure declaration maps without a database, context or persistent cache. */
final class MomentColumns
{
    /**
     * @param class-string<Entity> $entityClass Entity whose physical moment semantics are requested
     * @return array<string, MomentColumn> Moment descriptors keyed by column name
     * @throws InvalidMomentDeclarationException When declarations lose inherited moments or violate ownership or metadata shape
     */
    public static function forEntity(string $entityClass): array
    {
        if (!is_a($entityClass, Entity::class, true)) {
            throw new InvalidMomentDeclarationException("{$entityClass} is not an Entity moment declaration");
        }

        $frameworkColumns = [];
        $frameworkMoments = [];
        foreach (EntitySchemaAudit::frameworkEntities() as $frameworkClass) {
            if (!is_a($entityClass, $frameworkClass, true)) {
                continue;
            }
            foreach ($frameworkClass::_columns as $column) {
                $frameworkColumns[$column] = true;
            }
            foreach (self::declarationNames($frameworkClass::_moments, $frameworkClass . '::' . Entity::META_MOMENTS) as $column) {
                $frameworkColumns[$column] = true;
                $frameworkMoments[$column] = true;
            }
        }

        $chain = [];
        for ($class = $entityClass; $class !== false && is_a($class, Entity::class, true); $class = get_parent_class($class)) {
            $chain[] = $class;
        }

        $inheritedMoments = [];
        foreach (array_reverse($chain) as $class) {
            $declaration = $class . '::' . Entity::META_MOMENTS;
            $moments = self::declarationNames($class::_moments, $declaration);
            self::keepMoments($moments, $inheritedMoments, $declaration);
            self::validateStorage($class::_momentStorage, $moments, $class . '::' . Entity::META_MOMENT_STORAGE);
            foreach ($moments as $column) {
                if (isset($frameworkColumns[$column]) && !isset($frameworkMoments[$column])) {
                    throw new InvalidMomentDeclarationException(
                        "{$declaration} reclassifies framework column [{$column}] as a moment",
                    );
                }
            }
            foreach (array_keys($class::_momentStorage) as $column) {
                if (isset($frameworkColumns[$column])) {
                    throw new InvalidMomentDeclarationException(
                        "{$class}::" . Entity::META_MOMENT_STORAGE . " overrides framework moment [{$column}]",
                    );
                }
            }
            $inheritedMoments = $moments;
        }

        $mappedColumns = defined("{$entityClass}::" . Entity::META_COLUMNS) ? $entityClass::_columns : [];
        $columns = [];
        foreach ($entityClass::_moments as $column) {
            $columns[$column] = new MomentColumn(
                $column,
                isset($frameworkColumns[$column]),
                in_array($column, $mappedColumns, true),
                $entityClass::_momentStorage[$column] ?? null,
            );
        }
        return $columns;
    }

    /**
     * Reads only the requested provider; connection and schema identity belong to its caller.
     *
     * @param class-string<TablesWithoutEntityProvider> $providerClass Provider whose physical moments are requested
     * @return array<string, array<string, MomentColumn>> Moment descriptors keyed by table and column name
     * @throws InvalidMomentDeclarationException When a provider is invalid, loses inherited moments or overrides framework storage
     */
    public static function forProvider(string $providerClass): array
    {
        if (!class_exists($providerClass) || !is_a($providerClass, TablesWithoutEntityProvider::class, true)) {
            throw new InvalidMomentDeclarationException("{$providerClass} is not a TablesWithoutEntityProvider moment declaration");
        }

        $framework = self::providerColumns(FrameworkTablesWithoutEntity::class);
        $chain = [];
        for ($class = $providerClass; $class !== false; $class = get_parent_class($class)) {
            if (is_a($class, TablesWithoutEntityProvider::class, true)) {
                $chain[] = $class;
            }
        }

        $inherited = [];
        $columns = [];
        foreach (array_reverse($chain) as $class) {
            $columns = self::providerColumns($class);
            foreach ($inherited as $table => $moments) {
                self::keepMoments(
                    self::columnNames($columns[$table] ?? []),
                    self::columnNames($moments),
                    "{$class}::moments()[{$table}]",
                );
            }
            foreach ($columns as $table => $moments) {
                self::keepMoments(
                    self::columnNames($moments),
                    self::columnNames($framework[$table] ?? []),
                    "{$class}::moments()[{$table}]",
                );
                foreach ($moments as $column => $moment) {
                    if (isset($framework[$table][$column]) && $moment->projectStorage !== null) {
                        throw new InvalidMomentDeclarationException(
                            "{$class}::momentStorage()[{$table}] overrides framework moment [{$column}]",
                        );
                    }
                    $columns[$table][$column] = new MomentColumn(
                        $moment->column,
                        isset($framework[$table][$column]),
                        false,
                        $moment->projectStorage,
                    );
                }
            }
            $inherited = $columns;
        }
        return $columns;
    }

    /**
     * @param array<array-key, MomentColumn> $columns Normalized infrastructure map
     * @return list<string> Original string names, including names PHP casts to integer array keys
     */
    private static function columnNames(array $columns): array
    {
        return array_values(array_map(static fn (MomentColumn $moment): string => $moment->column, $columns));
    }

    /**
     * @param class-string<TablesWithoutEntityProvider> $providerClass Provider to validate independently of ownership
     * @return array<string, array<string, MomentColumn>> Infrastructure map of the provider's declared moments
     * @throws InvalidMomentDeclarationException When provider calls fail or their maps have invalid shape
     */
    private static function providerColumns(string $providerClass): array
    {
        try {
            $tables = $providerClass::tables();
            $moments = $providerClass::moments();
            $storage = $providerClass::momentStorage();
        } catch (Throwable $failure) {
            throw new InvalidMomentDeclarationException("{$providerClass} moment declaration could not be read", previous: $failure);
        }

        $tables = self::declarationNames($tables, "{$providerClass}::tables()");
        foreach ($tables as $table) {
            if (!array_key_exists($table, $moments)) {
                throw new InvalidMomentDeclarationException("{$providerClass}::moments() omits table [{$table}]");
            }
        }
        foreach (array_keys($moments) as $table) {
            if (!in_array((string) $table, $tables, true)) {
                throw new InvalidMomentDeclarationException("{$providerClass}::moments() names undeclared table [{$table}]");
            }
        }
        foreach ($storage as $table => $exceptions) {
            if (!in_array((string) $table, $tables, true)) {
                throw new InvalidMomentDeclarationException("{$providerClass}::momentStorage() names undeclared table [{$table}]");
            }
            if (!is_array($exceptions)) {
                throw new InvalidMomentDeclarationException("{$providerClass}::momentStorage()[{$table}] must be a column map");
            }
        }

        $columns = [];
        foreach ($tables as $table) {
            $names = self::declarationNames($moments[$table], "{$providerClass}::moments()[{$table}]");
            $exceptions = $storage[$table] ?? [];
            self::validateStorage($exceptions, $names, "{$providerClass}::momentStorage()[{$table}]");
            $columns[$table] = [];
            foreach ($names as $column) {
                $columns[$table][$column] = new MomentColumn($column, false, false, $exceptions[$column] ?? null);
            }
        }
        return $columns;
    }

    /**
     * @param mixed $names Incoming infrastructure list
     * @param string $declaration Diagnostic location
     * @return list<string> Unique non-empty names, without coercion or trimming
     * @throws InvalidMomentDeclarationException When the declaration is not a list of unique non-empty strings
     */
    private static function declarationNames(mixed $names, string $declaration): array
    {
        if (!is_array($names) || !array_is_list($names)) {
            throw new InvalidMomentDeclarationException("{$declaration} must be a list of names");
        }
        $seen = [];
        foreach ($names as $name) {
            if (!is_string($name) || trim($name) === '') {
                throw new InvalidMomentDeclarationException("{$declaration} contains an empty or non-string name");
            }
            if (isset($seen[$name])) {
                throw new InvalidMomentDeclarationException("{$declaration} repeats [{$name}]");
            }
            $seen[$name] = true;
        }
        return $names;
    }

    /**
     * @param array<array-key, mixed> $storage Incoming storage exception map with PHP's integer keys for numeric string names
     * @param list<string> $moments Declared physical moment names
     * @param string $declaration Diagnostic location
     * @throws InvalidMomentDeclarationException When an exception has no moment or no non-empty explanation
     */
    private static function validateStorage(array $storage, array $moments, string $declaration): void
    {
        foreach ($storage as $column => $reason) {
            if (!in_array((string) $column, $moments, true)) {
                throw new InvalidMomentDeclarationException("{$declaration} names undeclared moment [{$column}]");
            }
            if (!is_string($reason) || trim($reason) === '') {
                throw new InvalidMomentDeclarationException("{$declaration}[{$column}] needs a non-empty storage explanation");
            }
        }
    }

    /**
     * @param list<string> $moments Descendant declaration
     * @param list<string> $inherited Ancestor's moment semantics
     * @param string $declaration Diagnostic location
     * @throws InvalidMomentDeclarationException When any inherited moment disappears
     */
    private static function keepMoments(array $moments, array $inherited, string $declaration): void
    {
        foreach ($inherited as $column) {
            if (!in_array($column, $moments, true)) {
                throw new InvalidMomentDeclarationException("{$declaration} loses inherited moment [{$column}]");
            }
        }
    }
}
