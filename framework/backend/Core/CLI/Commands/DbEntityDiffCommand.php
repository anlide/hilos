<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Database\Database;
use Hilos\Database\Entity\Entity;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Schema\TableInfo;
use Hilos\Exception\DatabaseException;
use ReflectionClass;

/**
 * DbEntityDiffCommand - Compare Entity files with database schema
 *
 * Compares Entity class definitions with actual database structure
 * and displays differences in a structured format.
 */
class DbEntityDiffCommand implements CommandInterface
{
    public function getName(): string
    {
        return CliCommands::DB_ENTITY_DIFF;
    }

    public function getDescription(): string
    {
        return 'Compare Entity files with database schema and show differences';
    }

    public function getHelp(): string
    {
        return <<<HELP
Command: db:entity:diff

Description:
  Compare Entity class definitions with actual database schema structure.
  Shows differences in tables, columns, types, indexes, and foreign keys.

Usage:
  php cli.php db:entity:diff [options]

Options:
  --db-index=<N>     Database connection index (default: 0)
  --entity-dir=<path> Entity files directory (default: auto-detect)
  --entity-ns=<ns>    Entity namespace prefix (default: auto-detect)
  --table=<name>      Show diff for specific table only

Examples:
  php cli.php db:entity:diff
  php cli.php db:entity:diff --db-index=0
  php cli.php db:entity:diff --table=user
  php cli.php db:entity:diff --entity-dir=backend/Database/Entity --entity-ns=App\\Entity
HELP;
    }

    public function execute(array $options, array $args): int
    {
        echo "\n=== Entity vs Database Schema Diff ===\n\n";

        // Parse arguments from options
        $dbIndex = isset($options['db-index']) ? (int)$options['db-index'] : 0;
        $tableName = $options['table'] ?? null;
        $entityDir = $options['entity-dir'] ?? null;
        $entityNamespace = $options['entity-ns'] ?? null;

        // Set database connection index if specified
        try {
            Database::useConnection($dbIndex);
        } catch (DatabaseException $e) {
            echo "Error: Connection {$dbIndex} is not configured\n";
            echo "Message: {$e->getMessage()}\n\n";
            return ExitCode::ERROR;
        }

        // Check if connected
        if (!Database::isConnected($dbIndex)) {
            echo "Error: Database connection {$dbIndex} is not established\n";
            echo "Please ensure database connection is initialized before running this command.\n\n";
            return ExitCode::ERROR;
        }

        // Initialize schema if not already initialized
        try {
            if (!Schema::isInitialized($dbIndex)) {
                echo "Initializing schema for connection {$dbIndex}...\n";
                Schema::initialize($dbIndex);
                echo "Schema initialized successfully.\n\n";
            }
        } catch (DatabaseException $e) {
            echo "Error: Failed to initialize schema\n";
            echo "Message: {$e->getMessage()}\n\n";
            return ExitCode::ERROR;
        }

        // Load Entity classes
        try {
            $entities = $this->loadEntities($entityDir, $entityNamespace);
        } catch (\Throwable $e) {
            echo "Error: Failed to load Entity classes\n";
            echo "Message: {$e->getMessage()}\n\n";
            return ExitCode::ERROR;
        }

        // Get database tables
        $dbTables = Schema::getTables($dbIndex);

        // Compare
        $diff = $this->compareEntitiesWithDatabase($entities, $dbTables, $tableName);

        // Display results
        $this->displayDiff($diff);

        echo "\n";
        return ExitCode::SUCCESS;
    }

    /**
     * Load Entity classes from directory
     */
    private function loadEntities(?string $entityDir, ?string $entityNamespace): array
    {
        // Auto-detect if not provided
        if ($entityDir === null) {
            // Try to find Entity directory relative to current working directory
            $cwd = getcwd();
            $possibleDirs = [
                $cwd . '/backend/Database/Entity',
                $cwd . '/Database/Entity',
                $cwd . '/Entity',
                dirname($cwd) . '/backend/Database/Entity',
                dirname($cwd) . '/Database/Entity',
            ];

            // Also try relative to Bootstrap directory (common in projects)
            $bootstrapDir = null;
            if (file_exists($cwd . '/backend/Bootstrap/cli.php')) {
                $bootstrapDir = $cwd . '/backend';
            } elseif (file_exists($cwd . '/Bootstrap/cli.php')) {
                $bootstrapDir = $cwd;
            }

            if ($bootstrapDir !== null) {
                $possibleDirs[] = $bootstrapDir . '/Database/Entity';
            }

            foreach ($possibleDirs as $dir) {
                $realPath = realpath($dir);
                if ($realPath !== false && is_dir($realPath)) {
                    $entityDir = $realPath;
                    break;
                }
            }

            if ($entityDir === null) {
                throw new \RuntimeException("Could not auto-detect Entity directory. Please specify --entity-dir");
            }
        }

        if (!is_dir($entityDir)) {
            throw new \RuntimeException("Entity directory does not exist: {$entityDir}");
        }

        $entities = [];
        $files = glob($entityDir . '/*.php');

        if ($files === false) {
            return $entities;
        }

        foreach ($files as $file) {
            $className = $this->extractClassNameFromFile($file, $entityNamespace);
            if ($className === null) {
                continue;
            }

            try {
                if (!class_exists($className)) {
                    require_once $file;
                }

                $reflection = new ReflectionClass($className);
                if (!$reflection->isSubclassOf(Entity::class)) {
                    continue;
                }

                $entityInfo = $this->extractEntityInfo($reflection);
                if ($entityInfo !== null) {
                    $entities[$entityInfo['table']] = $entityInfo;
                }
            } catch (\Throwable $e) {
                // Skip invalid classes
                continue;
            }
        }

        return $entities;
    }

    /**
     * Extract class name from PHP file
     */
    private function extractClassNameFromFile(string $file, ?string $namespacePrefix): ?string
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        // Extract namespace
        if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatch)) {
            $namespace = trim($nsMatch[1]);
        } else {
            return null;
        }

        // Extract class name
        if (preg_match('/\b(?:final\s+)?class\s+(\w+)/', $content, $classMatch)) {
            $className = trim($classMatch[1]);
            return $namespace . '\\' . $className;
        }

        return null;
    }

    /**
     * Extract Entity information from ReflectionClass
     */
    private function extractEntityInfo(ReflectionClass $reflection): ?array
    {
        try {
            $table = $reflection->getConstant(Entity::META_TABLE);
            if ($table === false) {
                return null;
            }

            $primary = $reflection->getConstant(Entity::META_PRIMARY);
            $columns = $reflection->getConstant(Entity::META_COLUMNS) ?: [];
            $types = $reflection->getConstant(Entity::META_TYPES) ?: [];
            $foreign = $reflection->getConstant(Entity::META_FOREIGN) ?: [];
            $indexes = $reflection->getConstant(Entity::META_INDEXES) ?: [];

            // Normalize primary key to array
            if (is_string($primary)) {
                $primary = [$primary];
            } elseif (!is_array($primary)) {
                $primary = [];
            }

            return [
                'class' => $reflection->getName(),
                'table' => $table,
                'primary' => $primary,
                'columns' => $columns,
                'types' => $types,
                'foreign' => $foreign,
                'indexes' => $indexes,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Compare Entity definitions with database schema
     */
    private function compareEntitiesWithDatabase(array $entities, array $dbTables, ?string $tableFilter): array
    {
        $diff = [
            'tables_missing_in_entity' => [],
            'tables_missing_in_db' => [],
            'table_diffs' => [],
        ];

        // Find tables in DB but not in Entity
        foreach ($dbTables as $tableName => $tableInfo) {
            if ($tableFilter !== null && $tableName !== $tableFilter) {
                continue;
            }

            if (!isset($entities[$tableName])) {
                $diff['tables_missing_in_entity'][] = $tableName;
            }
        }

        // Find tables in Entity but not in DB, and compare existing tables
        foreach ($entities as $tableName => $entityInfo) {
            if ($tableFilter !== null && $tableName !== $tableFilter) {
                continue;
            }

            if (!isset($dbTables[$tableName])) {
                $diff['tables_missing_in_db'][] = $tableName;
                continue;
            }

            // Compare table structure
            $tableDiff = $this->compareTable($entityInfo, $dbTables[$tableName]);
            if (!empty($tableDiff)) {
                $diff['table_diffs'][$tableName] = $tableDiff;
            }
        }

        return $diff;
    }

    /**
     * Compare single table Entity with database schema
     */
    private function compareTable(array $entity, TableInfo $dbTable): array
    {
        $diff = [
            'columns_missing_in_entity' => [],
            'columns_missing_in_db' => [],
            'column_type_mismatches' => [],
            'primary_key_diff' => null,
            'indexes_missing_in_entity' => [],
            'indexes_missing_in_db' => [],
            'indexes_different' => [],
            'foreign_keys_missing_in_entity' => [],
            'foreign_keys_missing_in_db' => [],
            'foreign_keys_different' => [],
        ];

        // Compare columns
        $entityColumns = array_flip($entity['columns']);
        $dbColumns = array_keys($dbTable->columns);

        foreach ($dbColumns as $colName) {
            if (!isset($entityColumns[$colName])) {
                $diff['columns_missing_in_entity'][] = $colName;
            } else {
                // Check type
                $entityType = $entity['types'][$colName] ?? null;
                $dbType = $dbTable->columns[$colName]->phpType;
                if ($entityType !== null && $entityType !== $dbType) {
                    $diff['column_type_mismatches'][] = [
                        'column' => $colName,
                        'entity_type' => $entityType,
                        'db_type' => $dbType,
                    ];
                }
            }
        }

        foreach ($entity['columns'] as $colName) {
            if (!isset($dbTable->columns[$colName])) {
                $diff['columns_missing_in_db'][] = $colName;
            }
        }

        // Compare primary keys
        $entityPrimary = $entity['primary'];
        $dbPrimary = $dbTable->primaryKeys;
        sort($entityPrimary);
        sort($dbPrimary);
        if ($entityPrimary !== $dbPrimary) {
            $diff['primary_key_diff'] = [
                'entity' => $entityPrimary,
                'db' => $dbPrimary,
            ];
        }

        // Compare indexes
        $entityIndexes = $entity['indexes'] ?? [];
        $dbIndexes = $dbTable->indexes;

        foreach ($dbIndexes as $indexName => $dbIndex) {
            if (!isset($entityIndexes[$indexName])) {
                $diff['indexes_missing_in_entity'][] = [
                    'name' => $indexName,
                    'unique' => $dbIndex->unique,
                    'columns' => $dbIndex->columns,
                ];
            } else {
                $entityIndex = $entityIndexes[$indexName];
                $entityCols = $entityIndex['columns'] ?? [];
                $entityUnique = $entityIndex['unique'] ?? false;
                sort($entityCols);
                $dbCols = $dbIndex->columns;
                sort($dbCols);

                if ($entityCols !== $dbCols || $entityUnique !== $dbIndex->unique) {
                    $diff['indexes_different'][] = [
                        'name' => $indexName,
                        'entity' => [
                            'unique' => $entityUnique,
                            'columns' => $entityCols,
                        ],
                        'db' => [
                            'unique' => $dbIndex->unique,
                            'columns' => $dbCols,
                        ],
                    ];
                }
            }
        }

        foreach ($entityIndexes as $indexName => $entityIndex) {
            if (!isset($dbIndexes[$indexName])) {
                $diff['indexes_missing_in_db'][] = [
                    'name' => $indexName,
                    'unique' => $entityIndex['unique'] ?? false,
                    'columns' => $entityIndex['columns'] ?? [],
                ];
            }
        }

        // Compare foreign keys
        $entityForeign = $entity['foreign'] ?? [];
        $dbForeign = $dbTable->foreignKeys;

        foreach ($dbForeign as $colName => $foreignTable) {
            if (!isset($entityForeign[$colName])) {
                $diff['foreign_keys_missing_in_entity'][] = [
                    'column' => $colName,
                    'table' => $foreignTable,
                ];
            } elseif ($entityForeign[$colName] !== $foreignTable) {
                $diff['foreign_keys_different'][] = [
                    'column' => $colName,
                    'entity_table' => $entityForeign[$colName],
                    'db_table' => $foreignTable,
                ];
            }
        }

        foreach ($entityForeign as $colName => $foreignTable) {
            if (!isset($dbForeign[$colName])) {
                $diff['foreign_keys_missing_in_db'][] = [
                    'column' => $colName,
                    'table' => $foreignTable,
                ];
            }
        }

        // Remove empty sections
        return array_filter($diff, fn($value) => !empty($value));
    }

    /**
     * Display diff results
     */
    private function displayDiff(array $diff): void
    {
        $hasDifferences = false;

        // Tables missing in Entity
        if (!empty($diff['tables_missing_in_entity'])) {
            $hasDifferences = true;
            echo "⚠ Tables in database but missing in Entity:\n";
            foreach ($diff['tables_missing_in_entity'] as $table) {
                echo "  - {$table}\n";
            }
            echo "\n";
        }

        // Tables missing in DB
        if (!empty($diff['tables_missing_in_db'])) {
            $hasDifferences = true;
            echo "⚠ Tables in Entity but missing in database:\n";
            foreach ($diff['tables_missing_in_db'] as $table) {
                echo "  - {$table}\n";
            }
            echo "\n";
        }

        // Table differences
        if (!empty($diff['table_diffs'])) {
            $hasDifferences = true;
            foreach ($diff['table_diffs'] as $tableName => $tableDiff) {
                echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
                echo "Table: {$tableName}\n";
                echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

                // Columns
                if (!empty($tableDiff['columns_missing_in_entity'])) {
                    echo "  Columns missing in Entity:\n";
                    foreach ($tableDiff['columns_missing_in_entity'] as $col) {
                        echo "    - {$col}\n";
                    }
                    echo "\n";
                }

                if (!empty($tableDiff['columns_missing_in_db'])) {
                    echo "  Columns missing in database:\n";
                    foreach ($tableDiff['columns_missing_in_db'] as $col) {
                        echo "    - {$col}\n";
                    }
                    echo "\n";
                }

                if (!empty($tableDiff['column_type_mismatches'])) {
                    echo "  Column type mismatches:\n";
                    foreach ($tableDiff['column_type_mismatches'] as $mismatch) {
                        echo "    - {$mismatch['column']}: Entity={$mismatch['entity_type']}, DB={$mismatch['db_type']}\n";
                    }
                    echo "\n";
                }

                // Primary key
                if (isset($tableDiff['primary_key_diff'])) {
                    echo "  Primary key difference:\n";
                    $pk = $tableDiff['primary_key_diff'];
                    echo "    Entity: [" . implode(', ', $pk['entity']) . "]\n";
                    echo "    DB:     [" . implode(', ', $pk['db']) . "]\n";
                    echo "\n";
                }

                // Indexes
                if (!empty($tableDiff['indexes_missing_in_entity'])) {
                    echo "  Indexes missing in Entity:\n";
                    foreach ($tableDiff['indexes_missing_in_entity'] as $index) {
                        $unique = $index['unique'] ? 'UNIQUE ' : '';
                        $cols = implode(', ', $index['columns']);
                        echo "    - {$unique}{$index['name']}: ({$cols})\n";
                    }
                    echo "\n";
                }

                if (!empty($tableDiff['indexes_missing_in_db'])) {
                    echo "  Indexes missing in database:\n";
                    foreach ($tableDiff['indexes_missing_in_db'] as $index) {
                        $unique = $index['unique'] ? 'UNIQUE ' : '';
                        $cols = implode(', ', $index['columns']);
                        echo "    - {$unique}{$index['name']}: ({$cols})\n";
                    }
                    echo "\n";
                }

                if (!empty($tableDiff['indexes_different'])) {
                    echo "  Indexes with differences:\n";
                    foreach ($tableDiff['indexes_different'] as $index) {
                        echo "    - {$index['name']}:\n";
                        $eUnique = $index['entity']['unique'] ? 'UNIQUE ' : '';
                        $dUnique = $index['db']['unique'] ? 'UNIQUE ' : '';
                        echo "      Entity: {$eUnique}(" . implode(', ', $index['entity']['columns']) . ")\n";
                        echo "      DB:     {$dUnique}(" . implode(', ', $index['db']['columns']) . ")\n";
                    }
                    echo "\n";
                }

                // Foreign keys
                if (!empty($tableDiff['foreign_keys_missing_in_entity'])) {
                    echo "  Foreign keys missing in Entity:\n";
                    foreach ($tableDiff['foreign_keys_missing_in_entity'] as $fk) {
                        echo "    - {$fk['column']} -> {$fk['table']}\n";
                    }
                    echo "\n";
                }

                if (!empty($tableDiff['foreign_keys_missing_in_db'])) {
                    echo "  Foreign keys missing in database:\n";
                    foreach ($tableDiff['foreign_keys_missing_in_db'] as $fk) {
                        echo "    - {$fk['column']} -> {$fk['table']}\n";
                    }
                    echo "\n";
                }

                if (!empty($tableDiff['foreign_keys_different'])) {
                    echo "  Foreign keys with differences:\n";
                    foreach ($tableDiff['foreign_keys_different'] as $fk) {
                        echo "    - {$fk['column']}: Entity={$fk['entity_table']}, DB={$fk['db_table']}\n";
                    }
                    echo "\n";
                }

                echo "\n";
            }
        }

        if (!$hasDifferences) {
            echo "✓ No differences found! Entity files match database schema.\n\n";
        }
    }
}

