<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Database\Database;
use Hilos\Database\Entity\Entity;
use Hilos\Database\Generator;
use Hilos\Database\PhpType;
use Hilos\Database\Schema\IndexInfo;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Schema\TableInfo;
use Hilos\Exception\DatabaseException;
use ReflectionClass;

/**
 * DbEntityFixCommand - Fix Entity files to match database schema
 *
 * Automatically updates Entity class definitions to match actual database structure.
 * Adds missing columns, indexes, foreign keys, and updates types.
 */
class DbEntityFixCommand implements CommandInterface
{
    public function getName(): string
    {
        return CliCommands::DB_ENTITY_FIX;
    }

    public function getDescription(): string
    {
        return 'Fix Entity files to match database schema';
    }

    public function getHelp(): string
    {
        return <<<HELP
Command: db:entity:fix

Description:
  Automatically update Entity class definitions to match database schema.
  Adds missing columns, indexes, foreign keys, and updates types.

Usage:
  php cli.php db:entity:fix [options]

Options:
  --db-index=<N>     Database connection index (default: 0)
  --entity-dir=<path> Entity files directory (default: auto-detect)
  --entity-ns=<ns>    Entity namespace prefix (default: auto-detect)
  --table=<name>      Fix specific table only
  --dry-run           Show what would be changed without modifying files

Examples:
  php cli.php db:entity:fix
  php cli.php db:entity:fix --db-index=0
  php cli.php db:entity:fix --table=user
  php cli.php db:entity:fix --dry-run
HELP;
    }

    public function execute(array $options, array $args): int
    {
        echo "\n=== Fix Entity Files ===\n\n";

        // Parse arguments from options
        $dbIndex = isset($options['db-index']) ? (int)$options['db-index'] : 0;
        $tableName = $options['table'] ?? null;
        $entityDir = $options['entity-dir'] ?? null;
        $entityNamespace = $options['entity-ns'] ?? null;
        $dryRun = isset($options['dry-run']);

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

        // Load Entity classes and their file paths
        $syntaxErrors = 0;
        try {
            $entities = $this->loadEntities($entityDir, $entityNamespace, $syntaxErrors);
        } catch (\Throwable $e) {
            echo "Error: Failed to load Entity classes\n";
            echo "Message: {$e->getMessage()}\n\n";
            return ExitCode::ERROR;
        }

        // Get database tables
        $dbTables = Schema::getTables($dbIndex);

        // Find differences and prepare fixes
        $fixes = $this->prepareFixes($entities, $dbTables, $tableName);

        // Find tables without Entity files and Entity files without tables
        $tablesToCreate = $this->findTablesToCreate($entities, $dbTables, $tableName);
        $filesToDelete = $this->findFilesToDelete($entities, $dbTables, $tableName);

        if ($syntaxErrors > 0) {
            echo "\n⚠ {$syntaxErrors} file(s) contain syntax errors and were skipped.\n";
        }

        if (empty($fixes) && empty($tablesToCreate) && empty($filesToDelete)) {
            if ($syntaxErrors > 0) {
                echo "\n";
            } else {
                echo "✓ No fixes needed! Entity files match database schema.\n\n";
            }
            return ExitCode::SUCCESS;
        }

        // Display what will be fixed
        $this->displayFixes($fixes, $tablesToCreate, $filesToDelete, $dryRun);

        if ($dryRun) {
            echo "\n[DRY RUN] No files were modified.\n\n";
            return ExitCode::SUCCESS;
        }

        // Apply fixes automatically (no interactive confirmation)

        // Apply fixes to existing files
        $applied = $this->applyFixes($fixes);

        // Create new Entity files
        $created = $this->createEntityFiles($tablesToCreate, $entityDir, $entityNamespace, $dbIndex);

        // Delete Entity files without tables
        $deleted = $this->deleteEntityFiles($filesToDelete);

        // Summary
        $totalChanges = $applied + $created + $deleted;
        if ($totalChanges > 0) {
            echo "\n";
            if ($applied > 0) {
                echo "✓ Updated {$applied} file(s).\n";
            }
            if ($created > 0) {
                echo "✓ Created {$created} file(s).\n";
            }
            if ($deleted > 0) {
                echo "✓ Deleted {$deleted} file(s).\n";
            }
            echo "\n";
        }

        return ExitCode::SUCCESS;
    }

    /**
     * Load Entity classes from directory
     */
    private function loadEntities(?string $entityDir, ?string $entityNamespace, int &$syntaxErrors = 0): array
    {
        // Auto-detect if not provided (same logic as DbEntityDiffCommand)
        if ($entityDir === null) {
            $cwd = getcwd();
            $possibleDirs = [
                $cwd . '/backend/Database/Entity',
                $cwd . '/Database/Entity',
                $cwd . '/Entity',
                dirname($cwd) . '/backend/Database/Entity',
                dirname($cwd) . '/Database/Entity',
            ];

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
                // Check PHP syntax first
                $content = file_get_contents($file);
                if ($content === false) {
                    continue;
                }

                // Use php -l to check syntax
                $output = [];
                $returnVar = 0;
                exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $returnVar);
                if ($returnVar !== 0) {
                    echo "⚠ Skipping {$file}: PHP syntax error detected\n";
                    echo "  " . implode("\n  ", $output) . "\n";
                    $syntaxErrors++;
                    continue;
                }

                if (!class_exists($className)) {
                    require_once $file;
                }

                $reflection = new ReflectionClass($className);
                if (!$reflection->isSubclassOf(Entity::class)) {
                    continue;
                }

                $entityInfo = $this->extractEntityInfo($reflection);
                if ($entityInfo !== null) {
                    $entityInfo['file'] = $file;
                    $entityInfo['reflection'] = $reflection;
                    $entities[$entityInfo['table']] = $entityInfo;
                }
            } catch (\Throwable $e) {
                echo "⚠ Skipping {$file}: {$e->getMessage()}\n";
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

        if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatch)) {
            $namespace = trim($nsMatch[1]);
        } else {
            return null;
        }

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
     * Prepare fixes for Entity files
     */
    private function prepareFixes(array $entities, array $dbTables, ?string $tableFilter): array
    {
        $fixes = [];

        foreach ($entities as $tableName => $entityInfo) {
            // Skip migration table (hardcoded exclusion)
            if ($tableName === 'migration') {
                continue;
            }

            if ($tableFilter !== null && $tableName !== $tableFilter) {
                continue;
            }

            if (!isset($dbTables[$tableName])) {
                continue; // Skip tables that don't exist in DB
            }

            $dbTable = $dbTables[$tableName];
            $tableFixes = $this->prepareTableFixes($entityInfo, $dbTable);

            if (!empty($tableFixes)) {
                $fixes[$tableName] = [
                    'file' => $entityInfo['file'],
                    'class' => $entityInfo['class'],
                    'reflection' => $entityInfo['reflection'],
                    'fixes' => $tableFixes,
                    'db_table' => $dbTable, // Store table info for column order
                ];
            }
        }

        return $fixes;
    }

    /**
     * Prepare fixes for single table
     */
    private function prepareTableFixes(array $entity, TableInfo $dbTable): array
    {
        $fixes = [];

        // Missing columns (in DB but not in Entity)
        $entityColumns = array_flip($entity['columns']);
        foreach ($dbTable->columns as $colName => $colInfo) {
            $normalizedType = $this->normalizeType($colInfo->phpType);
            if (!isset($entityColumns[$colName])) {
                $fixes['add_columns'][] = [
                    'name' => $colName,
                    'type' => $normalizedType,
                    'nullable' => $colInfo->nullable,
                    'default' => $colInfo->default,
                    'is_primary' => $colInfo->isPrimary,
                ];
            } else {
                $entityType = $entity['types'][$colName] ?? null;
                $normalizedEntityType = $entityType !== null ? $this->normalizeType($entityType) : null;
                if ($normalizedEntityType !== $normalizedType) {
                    $fixes['update_column_types'][] = [
                        'name' => $colName,
                        'old_type' => $entityType ?? 'unknown',
                        'new_type' => $normalizedType,
                    ];
                }

                // Update properties (exact names as in database) - sync nullable and default values
                // This ensures properties match database schema exactly
                // Parse current property from Entity file to compare
                $currentProperty = $this->parsePropertyFromEntity($entity['file'], $colName);

                // Determine what property should be
                $isPrimary = $colInfo->isPrimary;
                $shouldBeNullable = $isPrimary || $colInfo->nullable;
                $shouldBeDefault = $isPrimary ? null : $colInfo->default;

                // Normalize should-be default for comparison
                $shouldBeDefaultStr = $isPrimary
                    ? 'null'
                    : ($shouldBeDefault !== null
                        ? $this->normalizeDefaultForComparison($this->formatDefaultValue($shouldBeDefault, $normalizedType), $normalizedType)
                        : ($shouldBeNullable ? 'null' : null));

                // Compare current vs should be
                $needsUpdate = false;
                if ($currentProperty === null) {
                    $needsUpdate = true; // Property doesn't exist
                } else {
                    // Check nullable
                    // For primary key columns, always require nullable (even if DB says not nullable)
                    // So if it's primary key and current is not nullable, we need to update
                    // But if it's primary key and DB says not nullable, we still want nullable in Entity
                    if ($isPrimary) {
                        // For primary keys, always require nullable
                        if (!$currentProperty['nullable']) {
                            $needsUpdate = true;
                        }
                    } else {
                        // For non-primary keys, compare normally
                        if ($currentProperty['nullable'] !== $shouldBeNullable) {
                            $needsUpdate = true;
                        }
                    }
                    // Check default value (normalize for comparison)
                    $currentDefaultStr = $currentProperty['default'] !== null
                        ? $this->normalizeDefaultForComparison($currentProperty['default'], $normalizedType)
                        : ($currentProperty['nullable'] ? 'null' : null);
                    if ($currentDefaultStr !== $shouldBeDefaultStr) {
                        $needsUpdate = true;
                    }
                }

                if ($needsUpdate) {
                    if (!isset($fixes['update_properties'])) {
                        $fixes['update_properties'] = [];
                    }
                    $fixes['update_properties'][] = [
                        'name' => $colName,
                        'type' => $normalizedType,
                        'nullable' => $shouldBeNullable,
                        'default' => $shouldBeDefault,
                        'is_primary' => $isPrimary,
                    ];
                }
            }
        }

        // Extra columns (in Entity but not in DB) - need to be removed
        $dbColumns = array_keys($dbTable->columns);
        $columnsToRemove = [];
        foreach ($entity['columns'] as $colName) {
            if (!in_array($colName, $dbColumns, true)) {
                $columnsToRemove[] = $colName;
            }
        }

        if (!empty($columnsToRemove)) {
            $fixes['remove_columns'] = $columnsToRemove;

            // Also remove indexes that reference removed columns
            $entityIndexes = $entity['indexes'] ?? [];
            foreach ($entityIndexes as $indexName => $indexDef) {
                $indexColumns = $indexDef['columns'] ?? [];
                foreach ($indexColumns as $indexCol) {
                    if (in_array($indexCol, $columnsToRemove, true)) {
                        $fixes['remove_indexes'][] = $indexName;
                        break;
                    }
                }
            }

            // Also remove foreign keys that reference removed columns
            $entityForeign = $entity['foreign'] ?? [];
            foreach ($entityForeign as $colName => $foreignTable) {
                if (in_array($colName, $columnsToRemove, true)) {
                    $fixes['remove_foreign_keys'][] = $colName;
                }
            }
        }

        // Primary key differences
        $entityPrimary = $entity['primary'];
        $dbPrimary = $dbTable->primaryKeys;
        sort($entityPrimary);
        sort($dbPrimary);
        if ($entityPrimary !== $dbPrimary) {
            $fixes['update_primary'] = $dbPrimary;
        }

        // Missing indexes
        $entityIndexes = $entity['indexes'] ?? [];
        foreach ($dbTable->indexes as $indexName => $dbIndex) {
            if (!isset($entityIndexes[$indexName])) {
                $fixes['add_indexes'][] = [
                    'name' => $indexName,
                    'unique' => $dbIndex->unique,
                    'columns' => $dbIndex->columns,
                ];
            } elseif ($this->indexesDiffer($entityIndexes[$indexName], $dbIndex)) {
                $fixes['update_indexes'][] = [
                    'name' => $indexName,
                    'unique' => $dbIndex->unique,
                    'columns' => $dbIndex->columns,
                ];
            }
        }

        // Missing foreign keys (but not self-references)
        $entityForeign = $entity['foreign'] ?? [];
        $currentTable = $entity['table'];
        foreach ($dbTable->foreignKeys as $colName => $foreignTable) {
            // Skip if foreign key references the same table (self-reference)
            if ($foreignTable === $currentTable) {
                continue;
            }

            if (!isset($entityForeign[$colName])) {
                $fixes['add_foreign_keys'][] = [
                    'column' => $colName,
                    'table' => $foreignTable,
                ];
            } elseif ($entityForeign[$colName] !== $foreignTable) {
                $fixes['update_foreign_keys'][] = [
                    'column' => $colName,
                    'old_table' => $entityForeign[$colName],
                    'new_table' => $foreignTable,
                ];
            }
        }

        return $fixes;
    }

    /**
     * Check if indexes differ
     */
    private function indexesDiffer(array $entityIndex, IndexInfo $dbIndex): bool
    {
        $entityCols = $entityIndex['columns'] ?? [];
        $entityUnique = $entityIndex['unique'] ?? false;
        sort($entityCols);
        $dbCols = $dbIndex->columns;
        sort($dbCols);

        return $entityCols !== $dbCols || $entityUnique !== $dbIndex->unique;
    }

    /**
     * Find tables in DB without Entity files
     */
    private function findTablesToCreate(array $entities, array $dbTables, ?string $tableFilter): array
    {
        $tablesToCreate = [];

        foreach ($dbTables as $tableName => $dbTable) {
            // Skip migration table (hardcoded exclusion)
            if ($tableName === 'migration') {
                continue;
            }

            if ($tableFilter !== null && $tableName !== $tableFilter) {
                continue;
            }

            if (!isset($entities[$tableName])) {
                $tablesToCreate[$tableName] = $dbTable;
            }
        }

        return $tablesToCreate;
    }

    /**
     * Find Entity files without tables in DB
     */
    private function findFilesToDelete(array $entities, array $dbTables, ?string $tableFilter): array
    {
        $filesToDelete = [];

        foreach ($entities as $tableName => $entityInfo) {
            if ($tableFilter !== null && $tableName !== $tableFilter) {
                continue;
            }

            if (!isset($dbTables[$tableName])) {
                $filesToDelete[$tableName] = $entityInfo;
            }
        }

        return $filesToDelete;
    }

    /**
     * Display fixes that will be applied
     */
    private function displayFixes(array $fixes, array $tablesToCreate, array $filesToDelete, bool $dryRun): void
    {
        $prefix = $dryRun ? "[DRY RUN] " : "";

        foreach ($fixes as $tableName => $tableFix) {
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "{$prefix}Table: {$tableName}\n";
            echo "File: {$tableFix['file']}\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

            $tableFixes = $tableFix['fixes'];

            if (!empty($tableFixes['add_columns'])) {
                echo "  Will add columns:\n";
                foreach ($tableFixes['add_columns'] as $col) {
                    $nullable = $col['nullable'] ? 'nullable ' : '';
                    echo "    + {$col['name']} ({$nullable}{$col['type']})\n";
                }
                echo "\n";
            }

            if (!empty($tableFixes['update_column_types'])) {
                echo "  Will update column types:\n";
                foreach ($tableFixes['update_column_types'] as $col) {
                    echo "    ~ {$col['name']}: {$col['old_type']} -> {$col['new_type']}\n";
                }
                echo "\n";
            }

            if (!empty($tableFixes['update_properties'])) {
                echo "  Will update properties (nullable/default values):\n";
                foreach ($tableFixes['update_properties'] as $col) {
                    $nullable = $col['nullable'] ? 'nullable' : 'not null';
                    $default = $col['default'] !== null ? " (default: {$col['default']})" : '';
                    echo "    ~ {$col['name']}: {$nullable}{$default}\n";
                }
                echo "\n";
            }

            if (!empty($tableFixes['remove_columns'])) {
                echo "  Will remove columns:\n";
                foreach ($tableFixes['remove_columns'] as $col) {
                    echo "    - {$col}\n";
                }
                echo "\n";
            }

            if (!empty($tableFixes['remove_indexes'])) {
                echo "  Will remove indexes:\n";
                foreach ($tableFixes['remove_indexes'] as $indexName) {
                    echo "    - {$indexName}\n";
                }
                echo "\n";
            }

            if (!empty($tableFixes['remove_foreign_keys'])) {
                echo "  Will remove foreign keys:\n";
                foreach ($tableFixes['remove_foreign_keys'] as $col) {
                    echo "    - {$col}\n";
                }
                echo "\n";
            }

            if (isset($tableFixes['update_primary'])) {
                echo "  Will update primary key:\n";
                $oldPrimary = $tableFix['reflection']->getConstant(Entity::META_PRIMARY);
                if (is_string($oldPrimary)) {
                    $oldPrimary = [$oldPrimary];
                } elseif (!is_array($oldPrimary)) {
                    $oldPrimary = [];
                }
                $newPrimary = $tableFixes['update_primary'];
                if (is_string($newPrimary)) {
                    $newPrimary = [$newPrimary];
                } elseif (!is_array($newPrimary)) {
                    $newPrimary = [];
                }
                echo "    ~ [" . implode(', ', $oldPrimary) . "] -> [" . implode(', ', $newPrimary) . "]\n";
                echo "\n";
            }

            if (!empty($tableFixes['add_indexes'])) {
                echo "  Will add indexes:\n";
                foreach ($tableFixes['add_indexes'] as $index) {
                    $unique = $index['unique'] ? 'UNIQUE ' : '';
                    $cols = implode(', ', $index['columns']);
                    echo "    + {$unique}{$index['name']}: ({$cols})\n";
                }
                echo "\n";
            }

            if (!empty($tableFixes['update_indexes'])) {
                echo "  Will update indexes:\n";
                foreach ($tableFixes['update_indexes'] as $index) {
                    $unique = $index['unique'] ? 'UNIQUE ' : '';
                    $cols = implode(', ', $index['columns']);
                    echo "    ~ {$index['name']}: {$unique}({$cols})\n";
                }
                echo "\n";
            }

            if (!empty($tableFixes['add_foreign_keys'])) {
                echo "  Will add foreign keys:\n";
                foreach ($tableFixes['add_foreign_keys'] as $fk) {
                    echo "    + {$fk['column']} -> {$fk['table']}\n";
                }
                echo "\n";
            }

            if (!empty($tableFixes['update_foreign_keys'])) {
                echo "  Will update foreign keys:\n";
                foreach ($tableFixes['update_foreign_keys'] as $fk) {
                    echo "    ~ {$fk['column']}: {$fk['old_table']} -> {$fk['new_table']}\n";
                }
                echo "\n";
            }

            echo "\n";
        }

        // Display files to create
        if (!empty($tablesToCreate)) {
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo ($dryRun ? "[DRY RUN] " : "") . "Files to create:\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            foreach ($tablesToCreate as $tableName => $dbTable) {
                echo "  + {$tableName} (Entity file will be created)\n";
            }
            echo "\n";
        }

        // Display files to delete
        if (!empty($filesToDelete)) {
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo ($dryRun ? "[DRY RUN] " : "") . "Files to delete:\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            foreach ($filesToDelete as $tableName => $entityInfo) {
                echo "  - {$tableName} ({$entityInfo['file']})\n";
            }
            echo "\n";
        }
    }

    /**
     * Apply fixes to Entity files
     */
    private function applyFixes(array $fixes): int
    {
        $applied = 0;

        foreach ($fixes as $tableName => $tableFix) {
            try {
                $this->applyTableFixes($tableFix);
                $applied++;
            } catch (\Throwable $e) {
                echo "✗ Failed to fix {$tableName}: {$e->getMessage()}\n";
            }
        }

        return $applied;
    }

    /**
     * Apply fixes to single Entity file
     */
    private function applyTableFixes(array $tableFix): void
    {
        $file = $tableFix['file'];
        $content = file_get_contents($file);
        if ($content === false) {
            throw new \RuntimeException("Failed to read file: {$file}");
        }

        $fixes = $tableFix['fixes'];
        $reflection = $tableFix['reflection'];

        // Ensure PhpType is imported (always needed)
        if (!preg_match('/use\s+Hilos\\\Database\\\PhpType;/', $content)) {
            // Add use statement after other use statements
            if (preg_match('/(use\s+Hilos\\\Database\\\Entity\\\Entity;)/', $content, $matches)) {
                $content = str_replace($matches[1], $matches[1] . "\nuse Hilos\\Database\\PhpType;", $content);
            } elseif (preg_match('/(namespace\s+[^;]+;\s*\n\n)/', $content, $matches)) {
                $content = str_replace($matches[1], $matches[1] . "use Hilos\\Database\\Entity\\Entity;\nuse Hilos\\Database\\PhpType;\n\n", $content);
            }
        }

        // Apply fixes in order
        // Remove columns first (before adding new ones)
        if (!empty($fixes['remove_columns'])) {
            $content = $this->removeColumns($content, $fixes['remove_columns'], $reflection);
        }

        // Remove indexes that reference removed columns
        if (!empty($fixes['remove_indexes'])) {
            $content = $this->removeIndexes($content, $fixes['remove_indexes'], $reflection);
        }

        // Remove foreign keys that reference removed columns
        if (!empty($fixes['remove_foreign_keys'])) {
            $content = $this->removeForeignKeys($content, $fixes['remove_foreign_keys'], $reflection);
        }

        // Fix missing trailing comma in _columns array (even if no new columns added)
        $content = $this->fixTrailingCommaInColumnsArray($content);

        if (!empty($fixes['add_columns'])) {
            $dbTable = $tableFix['db_table'] ?? null;
            $content = $this->addColumnConstants($content, $fixes['add_columns'], $dbTable, $reflection);
            $content = $this->addColumnsToArray($content, $fixes['add_columns'], $dbTable, $reflection);
            $content = $this->addTypesToArray($content, $fixes['add_columns'], $dbTable, $reflection);
            $content = $this->addProperties($content, $fixes['add_columns'], $reflection);
        }

        // Convert all string type literals to PhpType enum (not just updated ones)
        $content = $this->convertAllTypesToPhpType($content);

        if (!empty($fixes['update_column_types'])) {
            $content = $this->updateColumnTypes($content, $fixes['update_column_types'], $reflection);
        }

        if (!empty($fixes['update_properties'])) {
            $content = $this->updateProperties($content, $fixes['update_properties'], $reflection);
        }

        if (isset($fixes['update_primary'])) {
            $content = $this->updatePrimaryKey($content, $fixes['update_primary'], $reflection);
        }

        if (!empty($fixes['add_indexes']) || !empty($fixes['update_indexes'])) {
            $dbTable = $tableFix['db_table'] ?? null;
            $content = $this->updateIndexes($content, $dbTable, $reflection);
        }

        // Clean up empty _indexes after all operations
        if (preg_match('/(\/\/ Indexes\s*public const array _indexes = \[)\s*(\];)/s', $content, $matches)) {
            // Remove only the comment and declaration lines, preserve surrounding empty lines
            // Match: \n    // Indexes\n    public const array _indexes = [\n    ];\n
            // Replace with just newline before to preserve spacing
            $pattern = '/(\n)\s*\/\/ Indexes\s*\n\s*public const array _indexes = \[\s*\];\s*\n/';
            $content = preg_replace($pattern, '$1', $content);
        }

        if (!empty($fixes['add_foreign_keys']) || !empty($fixes['update_foreign_keys'])) {
            $content = $this->updateForeignKeys($content, $fixes['add_foreign_keys'] ?? [], $fixes['update_foreign_keys'] ?? [], $reflection);
        }

        // Clean up empty _foreign after all operations
        if (preg_match('/(\/\/ Foreign keys\s*public const array _foreign = \[)\s*(\];)/s', $content, $matches)) {
            // Remove only the comment and declaration lines, preserve surrounding empty lines
            // Be very precise to avoid removing extra content or leaving extra spaces
            // Match: \n    // Foreign keys\n    public const array _foreign = [\n    ];\n
            // Replace with just \n (removing the section but keeping one newline)
            $pattern = '/(\n)\s*\/\/ Foreign keys\s*\n\s*public const array _foreign = \[\s*\];\s*\n/';
            $content = preg_replace($pattern, '$1', $content);

            // Clean up any extra spaces that might remain before next section (but preserve empty lines)
            // Only fix excessive indentation, don't remove empty lines
            $content = preg_replace('/(\n)\s{5,}(\/\/ (?:Indexes|Properties))/', '$1    $2', $content);
        }

        // Write updated content
        file_put_contents($file, $content);
    }

    /**
     * Normalize type to PhpType enum value
     * Uses common method from Generator
     */
    private function normalizeType(string $type): string
    {
        return Generator::normalizeType($type);
    }

    /**
     * Convert PhpType enum value to PHP type hint for properties
     * Converts 'integer' -> 'int', 'boolean' -> 'bool', 'datetime' -> 'string'
     * Uses common method from Generator to ensure consistency
     */
    private function phpTypeToPropertyType(string $type): string
    {
        return Generator::phpTypeToPropertyType($type);
    }

    /**
     * Format type for use in _types array (use PhpType enum if available, otherwise string)
     */
    private function formatTypeForArray(string $type): string
    {
        // Try to find matching PhpType enum case
        foreach (PhpType::cases() as $phpType) {
            if ($phpType->value === $type) {
                // Use PhpType enum directly: PhpType::INTEGER->value
                return "PhpType::{$phpType->name}->value";
            }
        }

        // Fallback for unknown types
        return "'{$type}'";
    }

    /**
     * Add column constants in correct order according to database schema
     */
    private function addColumnConstants(string $content, array $newColumns, ?TableInfo $dbTable, ReflectionClass $reflection): string
    {
        // Get all column names from DB in order
        $dbColumnOrder = [];
        if ($dbTable !== null) {
            $dbColumnOrder = array_keys($dbTable->columns);
        }

        // Extract existing column constants from file
        $existingConstants = [];
        if (preg_match_all('/    public const string (\w+) = \'[^\']+\';\n/', $content, $matches)) {
            $existingConstants = $matches[1];
        }

        // Create map of new columns by name
        $newColumnsMap = [];
        foreach ($newColumns as $col) {
            $newColumnsMap[$col['name']] = $col;
        }

        // Build ordered list: existing columns + new columns in DB order
        $allColumns = [];
        $processedNew = [];

        // First, add all existing columns
        foreach ($existingConstants as $colName) {
            $allColumns[] = $colName;
        }

        // Then, insert new columns in their DB order position
        foreach ($dbColumnOrder as $dbColName) {
            if (isset($newColumnsMap[$dbColName]) && !in_array($dbColName, $allColumns, true)) {
                // Find position in DB order relative to existing columns
                $insertPos = count($allColumns);
                for ($i = 0; $i < count($allColumns); $i++) {
                    $existingCol = $allColumns[$i];
                    $existingPos = array_search($existingCol, $dbColumnOrder, true);
                    $newPos = array_search($dbColName, $dbColumnOrder, true);
                    if ($existingPos !== false && $newPos !== false && $newPos < $existingPos) {
                        $insertPos = $i;
                        break;
                    }
                }
                array_splice($allColumns, $insertPos, 0, $dbColName);
                $processedNew[] = $dbColName;
            }
        }

        // Add any remaining new columns that weren't in DB order (shouldn't happen, but safety)
        foreach ($newColumns as $col) {
            if (!in_array($col['name'], $allColumns, true)) {
                $allColumns[] = $col['name'];
                $processedNew[] = $col['name'];
            }
        }

        // Generate constants for new columns only
        $newConstants = [];
        foreach ($processedNew as $colName) {
            $newConstants[] = "    public const string {$colName} = '{$colName}';";
        }

        if (empty($newConstants)) {
            return $content;
        }

        // Find position to insert new constants
        if (preg_match('/(\/\/ Column name constants.*?\n)((?:    public const string \w+ = \'[^\']+\';\n)+)/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $constantsBlock = $matches[2][0];
            $constantsBlockStart = $matches[2][1];

            // Get existing constants with their DB positions
            $existingWithPositions = [];
            foreach ($existingConstants as $colName) {
                $dbPos = array_search($colName, $dbColumnOrder, true);
                $existingWithPositions[] = [
                    'name' => $colName,
                    'db_position' => $dbPos !== false ? $dbPos : 9999,
                ];
            }

            // For each new constant, find where to insert it
            $insertions = [];
            foreach ($processedNew as $newColName) {
                $newDbPos = array_search($newColName, $dbColumnOrder, true);
                if ($newDbPos === false) {
                    $newDbPos = 9999;
                }

                // Find the last existing constant that comes before this new one in DB order
                $insertAfter = null;
                foreach ($existingWithPositions as $existing) {
                    if ($existing['db_position'] < $newDbPos) {
                        $insertAfter = $existing['name'];
                    }
                }

                // Find the actual position in the constants block
                if ($insertAfter !== null) {
                    $pattern = '/(    public const string ' . preg_quote($insertAfter, '/') . ' = \'[^\']+\';\n)/';
                    if (preg_match($pattern, $constantsBlock, $found, PREG_OFFSET_CAPTURE)) {
                        $insertions[] = [
                            'constant' => "    public const string {$newColName} = '{$newColName}';",
                            'position' => $found[0][1] + strlen($found[0][0]),
                        ];
                    } else {
                        // Fallback: append
                        $insertions[] = [
                            'constant' => "    public const string {$newColName} = '{$newColName}';",
                            'position' => strlen($constantsBlock),
                        ];
                    }
                } else {
                    // No existing constant before this one, insert at start
                    $insertions[] = [
                        'constant' => "    public const string {$newColName} = '{$newColName}';",
                        'position' => 0,
                    ];
                }
            }

            // Sort insertions by position (descending) to insert from end to start
            usort($insertions, fn($a, $b) => $b['position'] <=> $a['position']);

            // Insert constants from end to start to preserve positions
            $newConstantsBlock = $constantsBlock;
            foreach ($insertions as $insertion) {
                $before = substr($newConstantsBlock, 0, $insertion['position']);
                $after = substr($newConstantsBlock, $insertion['position']);
                $newConstantsBlock = $before . $insertion['constant'] . "\n" . $after;
            }

            $content = str_replace($constantsBlock, $newConstantsBlock, $content);
        } else {
            // Insert after class declaration if no constants section exists
            if (preg_match('/(final class \w+ extends Entity\n\{)/', $content, $matches)) {
                $content = str_replace($matches[1], $matches[1] . "\n    // Column name constants\n" . implode("\n", $newConstants) . "\n", $content);
            }
        }

        return $content;
    }

    /**
     * Add columns to _columns array in correct order
     */
    private function addColumnsToArray(string $content, array $columns, ?TableInfo $dbTable, ReflectionClass $reflection): string
    {
        // Get all column names from DB in order
        $dbColumnOrder = [];
        if ($dbTable !== null) {
            $dbColumnOrder = array_keys($dbTable->columns);
        }

        // Extract existing columns from _columns array
        $existingColumns = [];
        $existingContent = '';
        if (preg_match('/(public const array _columns = \[)(.*?)(\];)/s', $content, $matches)) {
            $existingContent = $matches[2];
            if (preg_match_all('/self::(\w+)/', $existingContent, $colMatches)) {
                $existingColumns = $colMatches[1];
            }
        }

        // Build ordered list: existing + new columns in DB order
        $allColumns = [];
        $newColumnNames = array_column($columns, 'name');

        // Start with existing columns
        foreach ($existingColumns as $colName) {
            $allColumns[] = $colName;
        }

        // Insert new columns in their DB order position
        foreach ($dbColumnOrder as $dbColName) {
            if (in_array($dbColName, $newColumnNames, true) && !in_array($dbColName, $allColumns, true)) {
                // Find position in DB order relative to existing columns
                $insertPos = count($allColumns);
                for ($i = 0; $i < count($allColumns); $i++) {
                    $existingCol = $allColumns[$i];
                    $existingPos = array_search($existingCol, $dbColumnOrder, true);
                    $newPos = array_search($dbColName, $dbColumnOrder, true);
                    if ($existingPos !== false && $newPos !== false && $newPos < $existingPos) {
                        $insertPos = $i;
                        break;
                    }
                }
                array_splice($allColumns, $insertPos, 0, $dbColName);
            }
        }

        // Add any remaining new columns
        foreach ($newColumnNames as $colName) {
            if (!in_array($colName, $allColumns, true)) {
                $allColumns[] = $colName;
            }
        }

        // Generate column references
        $columnRefs = array_map(fn($col) => "        self::{$col}", $allColumns);

        if (preg_match('/(public const array _columns = \[)(.*?)(\];)/s', $content, $matches)) {
            // Always ensure trailing comma after last element
            $newContent = "\n" . implode(",\n", $columnRefs) . ",\n    ";
            $content = str_replace($matches[0], $matches[1] . $newContent . $matches[3], $content);
        }

        return $content;
    }

    /**
     * Fix missing trailing comma in _columns array
     */
    private function fixTrailingCommaInColumnsArray(string $content): string
    {
        // Check if _columns array exists and if last element has trailing comma
        if (preg_match('/(public const array _columns = \[)(.*?)(\];)/s', $content, $matches)) {
            $arrayContent = $matches[2];

            // Check if there's at least one column
            if (preg_match('/self::\w+/', $arrayContent)) {
                // Check if last non-whitespace character before closing bracket is not a comma
                $trimmed = rtrim($arrayContent);
                $lastChar = !empty($trimmed) ? substr($trimmed, -1) : '';

                if (!empty($trimmed) && $lastChar !== ',') {
                    // Find the last column reference
                    if (preg_match_all('/(self::\w+)(\s*,?\s*)/', $arrayContent, $colMatches, PREG_OFFSET_CAPTURE)) {
                        $lastColRef = end($colMatches[1]);
                        $lastPos = $lastColRef[1] + strlen($lastColRef[0]);

                        // Check if there's a comma after this column
                        $afterCol = substr($arrayContent, $lastPos);
                        $afterColTrimmed = ltrim($afterCol);

                        // If no comma found, add it
                        if (!empty($afterColTrimmed) && $afterColTrimmed[0] !== ',') {
                            $before = substr($arrayContent, 0, $lastPos);
                            $after = substr($arrayContent, $lastPos);
                            // Add comma after the column reference
                            $newArrayContent = $before . ',' . $after;
                            $content = str_replace($matches[0], $matches[1] . $newArrayContent . $matches[3], $content);
                        } elseif (empty($afterColTrimmed)) {
                            // Column is at the end, add comma and newline
                            $newArrayContent = rtrim($arrayContent) . ",\n    ";
                            $content = str_replace($matches[0], $matches[1] . $newArrayContent . $matches[3], $content);
                        }
                    }
                }
            }
        }

        return $content;
    }

    /**
     * Add types to _types array in correct order
     */
    private function addTypesToArray(string $content, array $columns, ?TableInfo $dbTable, ReflectionClass $reflection): string
    {
        // Get all column names from DB in order
        $dbColumnOrder = [];
        if ($dbTable !== null) {
            $dbColumnOrder = array_keys($dbTable->columns);
        }

        // Extract existing types from _types array
        $existingTypes = [];
        if (preg_match('/(public const array _types = \[)(.*?)(\];)/s', $content, $matches)) {
            if (preg_match_all('/self::(\w+)\s*=>\s*([^,\n]+)/', $matches[2], $typeMatches)) {
                foreach ($typeMatches[1] as $i => $colName) {
                    $existingTypes[$colName] = trim($typeMatches[2][$i]);
                }
            }
        }

        // Create map of new types by column name
        $newTypesMap = [];
        foreach ($columns as $col) {
            $normalizedType = $this->normalizeType($col['type']);
            $typeValue = $this->formatTypeForArray($normalizedType);
            $newTypesMap[$col['name']] = $typeValue;
        }

        // Build ordered list: existing + new types in DB order
        // First, get all existing column names in their current order
        $existingColNames = array_keys($existingTypes);

        // Build ordered list similar to addColumnsToArray
        $allColNames = [];
        $newColumnNames = array_column($columns, 'name');

        // Start with existing columns
        foreach ($existingColNames as $colName) {
            $allColNames[] = $colName;
        }

        // Insert new columns in their DB order position
        foreach ($dbColumnOrder as $dbColName) {
            if (in_array($dbColName, $newColumnNames, true) && !in_array($dbColName, $allColNames, true)) {
                // Find position in DB order relative to existing columns
                $insertPos = count($allColNames);
                for ($i = 0; $i < count($allColNames); $i++) {
                    $existingCol = $allColNames[$i];
                    $existingPos = array_search($existingCol, $dbColumnOrder, true);
                    $newPos = array_search($dbColName, $dbColumnOrder, true);
                    if ($existingPos !== false && $newPos !== false && $newPos < $existingPos) {
                        $insertPos = $i;
                        break;
                    }
                }
                array_splice($allColNames, $insertPos, 0, $dbColName);
            }
        }

        // Add any remaining new columns
        foreach ($newColumnNames as $colName) {
            if (!in_array($colName, $allColNames, true)) {
                $allColNames[] = $colName;
            }
        }

        // Generate type entries in correct order
        $typeEntries = [];
        foreach ($allColNames as $colName) {
            $typeValue = $existingTypes[$colName] ?? $newTypesMap[$colName] ?? "PhpType::STRING->value";
            $typeEntries[] = "        self::{$colName} => {$typeValue}";
        }

        // Check if _types section exists - match with comment and preserve surrounding newlines
        if (preg_match('/(\n)(\s*\/\/ Column types\s*\n\s*public const array _types = \[)(.*?)(\];\s*\n)(\s*)/s', $content, $matches)) {
            // Rewrite entire array, preserve newlines before comment and after
            // $matches[1] = newline before, $matches[5] = whitespace/newlines after
            $newContent = $matches[1] . $matches[2] . "\n" . implode(",\n", $typeEntries) . ",\n    " . $matches[4] . $matches[5];
            $content = str_replace($matches[0], $newContent, $content);
        } else {
            // Add _types section
            if (preg_match('/(public const array _columns = \[.*?\];\n\n)/s', $content, $matches)) {
                $typesSection = "    // Column types\n    public const array _types = [\n" . implode(",\n", $typeEntries) . ",\n    ];\n\n";
                $content = str_replace($matches[1], $matches[1] . $typesSection, $content);
            }
        }

        return $content;
    }

    /**
     * Add properties (exact names as in database)
     */
    private function addProperties(string $content, array $columns, ReflectionClass $reflection): string
    {
        $properties = [];
        foreach ($columns as $col) {
            $normalizedType = $this->normalizeType($col['type']);
            $propertyType = $this->phpTypeToPropertyType($normalizedType);

            // Primary key columns should be nullable with default null
            $isPrimary = $col['is_primary'] ?? false;
            $shouldBeNullable = $isPrimary || $col['nullable'];

            $phpType = $shouldBeNullable ? "?{$propertyType}" : $propertyType;

            // For primary keys, always set default to null
            // For other columns, use database default or null if nullable
            if ($isPrimary) {
                $default = ' = null';
            } elseif ($col['default'] !== null) {
                $default = " = " . $this->formatDefaultValue($col['default'], $normalizedType);
            } elseif ($col['nullable']) {
                $default = ' = null';
            } else {
                $default = '';
            }

            $properties[] = "    public {$phpType} \${$col['name']}{$default};";
        }

        // Find properties section
        if (preg_match('/(\/\/ Properties.*?\n)/', $content, $matches)) {
            $content = str_replace($matches[1], $matches[1] . implode("\n", $properties) . "\n", $content);
        } else {
            // Add before closing brace
            if (preg_match('/(\n)\}/', $content, $matches)) {
                $propsSection = "\n    // Properties\n" . implode("\n", $properties) . "\n";
                $content = str_replace($matches[1] . '}', $propsSection . '}', $content);
            }
        }

        return $content;
    }

    /**
     * Remove columns from Entity file
     */
    private function removeColumns(string $content, array $columns, ReflectionClass $reflection): string
    {
        foreach ($columns as $colName) {
            // Remove column constant (only the line itself, not extra newlines)
            $pattern = '/    public const string ' . preg_quote($colName, '/') . ' = \'[^\']+\';\n/';
            $content = preg_replace($pattern, '', $content);

            // Remove from _columns array - be more precise to avoid removing extra lines
            // Match: whitespace + self::colName + optional comma + optional whitespace + newline
            // But don't match if it's part of a longer line with other columns
            $pattern = '/(?<=\n)\s+self::' . preg_quote($colName, '/') . '\s*,?\s*(?=\n)/';
            $content = preg_replace($pattern, '', $content);

            // Also handle inline cases (multiple columns on one line)
            $pattern = '/\s+self::' . preg_quote($colName, '/') . '\s*,/';
            $content = preg_replace($pattern, '', $content);
            $pattern = '/,\s*self::' . preg_quote($colName, '/') . '(?=\s*,|\s*;)/';
            $content = preg_replace($pattern, '', $content);

            // Remove from _types array - match entry with optional comma, be precise
            $pattern = '/(?<=\n)\s+self::' . preg_quote($colName, '/') . '\s*=>\s*\'[^\']+\'\s*,?\s*(?=\n)/';
            $content = preg_replace($pattern, '', $content);

            // Also handle inline cases
            $pattern = '/\s+self::' . preg_quote($colName, '/') . '\s*=>\s*\'[^\']+\'\s*,/';
            $content = preg_replace($pattern, '', $content);
            $pattern = '/,\s*self::' . preg_quote($colName, '/') . '\s*=>\s*\'[^\']+\'(?=\s*,|\s*;)/';
            $content = preg_replace($pattern, '', $content);

            // Remove property - match property line only, preserve surrounding newlines
            // Match: public type $name = value; or public type $name;
            $pattern = '/    public\s+(?:\?[a-z]+|[a-z]+)\s+\$' . preg_quote($colName, '/') . '(?:\s*=\s*[^;]+)?;\n/';
            $content = preg_replace($pattern, '', $content);

            // Remove from _foreign if exists - already handled separately
        }

        // Clean up empty lines in _columns array
        if (preg_match('/(public const array _columns = \[)(.*?)(\];)/s', $content, $matches)) {
            $columnsContent = $matches[2];
            // Remove lines that are only whitespace
            $columnsContent = preg_replace('/^\s+$/m', '', $columnsContent);
            // Split by newlines and clean each line
            $lines = explode("\n", $columnsContent);
            $cleanedLines = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    $cleanedLines[] = $trimmed;
                }
            }
            // Rejoin with proper formatting - remove trailing commas from each line first
            if (!empty($cleanedLines)) {
                $normalizedLines = [];
                foreach ($cleanedLines as $line) {
                    // Remove trailing commas and whitespace
                    $line = rtrim($line, ',');
                    $line = trim($line);
                    if ($line !== '') {
                        $normalizedLines[] = $line;
                    }
                }
                if (!empty($normalizedLines)) {
                    $columnsContent = "\n        " . implode(",\n        ", $normalizedLines) . ",\n    ";
                } else {
                    $columnsContent = "\n    ";
                }
            } else {
                $columnsContent = "\n    ";
            }
            $content = str_replace($matches[0], $matches[1] . $columnsContent . $matches[3], $content);
        }

        // Clean up empty lines in _types array
        if (preg_match('/(public const array _types = \[)(.*?)(\];)/s', $content, $matches)) {
            $typesContent = $matches[2];
            // Remove lines that are only whitespace
            $typesContent = preg_replace('/^\s+$/m', '', $typesContent);
            // Split by newlines and clean each line
            $lines = explode("\n", $typesContent);
            $cleanedLines = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    $cleanedLines[] = $trimmed;
                }
            }
            // Rejoin with proper formatting - remove trailing commas from each line first
            if (!empty($cleanedLines)) {
                $normalizedLines = [];
                foreach ($cleanedLines as $line) {
                    // Remove trailing commas and whitespace
                    $line = rtrim($line, ',');
                    $line = trim($line);
                    if ($line !== '') {
                        $normalizedLines[] = $line;
                    }
                }
                if (!empty($normalizedLines)) {
                    $typesContent = "\n        " . implode(",\n        ", $normalizedLines) . ",\n    ";
                } else {
                    $typesContent = "\n    ";
                }
            } else {
                $typesContent = "\n    ";
            }
            $content = str_replace($matches[0], $matches[1] . $typesContent . $matches[3], $content);
        }

        return $content;
    }

    /**
     * Remove indexes from Entity file
     */
    private function removeIndexes(string $content, array $indexNames, ReflectionClass $reflection): string
    {
        // Find _indexes array
        if (preg_match('/(public const array _indexes = \[)(.*?)(\];)/s', $content, $matches)) {
            $indexesContent = $matches[2];
            $remainingIndexes = [];

            // Parse existing indexes
            if (preg_match_all("/'([^']+)' => \[.*?\]/s", $indexesContent, $existingMatches, PREG_SET_ORDER)) {
                foreach ($existingMatches as $match) {
                    $indexName = $match[1];
                    if (!in_array($indexName, $indexNames, true)) {
                        // Extract full index definition
                        $escapedName = preg_quote($indexName, '/');
                        if (preg_match("/('{$escapedName}' => \[.*?\])/s", $indexesContent, $indexMatch)) {
                            $entry = trim($indexMatch[1]);
                            // Ensure trailing comma (but not double comma)
                            $entry = rtrim($entry, ',');
                            $entry .= ',';
                            $remainingIndexes[] = "        " . $entry;
                        }
                    }
                }
            }

            // Keep trailing comma on all entries (including last one)
            $new = !empty($remainingIndexes) ? "\n" . implode("\n", $remainingIndexes) . "\n    " : "\n    ";
            $content = str_replace($matches[0], $matches[1] . $new . $matches[3], $content);
        }

        return $content;
    }

    /**
     * Remove foreign keys from Entity file
     */
    private function removeForeignKeys(string $content, array $columnNames, ReflectionClass $reflection): string
    {
        // Find _foreign array
        if (preg_match('/(public const array _foreign = \[)(.*?)(\];)/s', $content, $matches)) {
            $foreignContent = $matches[2];
            $remainingForeign = [];

            // Parse existing foreign keys
            if (preg_match_all("/self::(\w+)\s*=>\s*'([^']+)'/", $foreignContent, $existingMatches, PREG_SET_ORDER)) {
                foreach ($existingMatches as $match) {
                    $colName = $match[1];
                    if (!in_array($colName, $columnNames, true)) {
                        $table = $match[2];
                        $remainingForeign[] = "        self::{$colName} => '{$table}',";
                    }
                }
            }

            // Keep trailing comma on all entries (including last one)
            $new = !empty($remainingForeign) ? "\n" . implode("\n", $remainingForeign) . "\n    " : "\n    ";
            $content = str_replace($matches[0], $matches[1] . $new . $matches[3], $content);
        }

        return $content;
    }

    /**
     * Convert all string type literals in _types array to PhpType enum
     */
    private function convertAllTypesToPhpType(string $content): string
    {
        // Find _types array
        if (preg_match('/(public const array _types = \[)(.*?)(\];)/s', $content, $matches)) {
            $typesContent = $matches[2];

            // Replace all string literals like 'integer', 'string' with PhpType::INTEGER->value, etc.
            // Pattern: self::columnName => 'type' (with optional whitespace)
            $pattern = '/(self::\w+\s*=>\s*)\'([^\']+)\'/';
            $convertedContent = preg_replace_callback($pattern, function($matches) {
                $before = $matches[1];
                $typeString = $matches[2];

                // Normalize type first (convert 'text' to 'string', etc.)
                $normalizedType = Generator::normalizeType($typeString);

                // Format type using PhpType enum
                $formattedType = $this->formatTypeForArray($normalizedType);

                return $before . $formattedType;
            }, $typesContent);

            // Replace the original _types content with converted version
            $content = str_replace($matches[0], $matches[1] . $convertedContent . $matches[3], $content);
        }

        return $content;
    }

    /**
     * Update column types in Entity file
     */
    private function updateColumnTypes(string $content, array $updates, ReflectionClass $reflection): string
    {
        foreach ($updates as $update) {
            $normalizedType = $this->normalizeType($update['new_type']);
            $typeValue = $this->formatTypeForArray($normalizedType);

            $escapedName = preg_quote($update['name'], '/');

            // Match any value after => until comma or newline
            // This will match: 'string', PhpType::INTEGER->value, self::TYPE_INTEGER, etc.
            // Pattern: self::columnName => (any value until comma or newline)
            $pattern = '/(self::' . $escapedName . '\s*=>\s*)[^,\n]+/';
            $replacement = '$1' . $typeValue;
            $content = preg_replace($pattern, $replacement, $content);
        }

        return $content;
    }

    /**
     * Update properties (exact names as in database) with nullable and default values
     */
    private function updateProperties(string $content, array $updates, ReflectionClass $reflection): string
    {
        foreach ($updates as $update) {
            $colName = $update['name'];
            $normalizedType = $this->normalizeType($update['type']);
            $propertyType = $this->phpTypeToPropertyType($normalizedType);

            // Primary key columns should be nullable with default null
            $isPrimary = $update['is_primary'] ?? false;
            $shouldBeNullable = $isPrimary || $update['nullable'];
            $phpType = $shouldBeNullable ? "?{$propertyType}" : $propertyType;

            // For primary keys, always set default to null
            // For other columns, use database default or null if nullable
            if ($isPrimary) {
                $default = ' = null';
            } elseif ($update['default'] !== null) {
                $default = " = " . $this->formatDefaultValue($update['default'], $normalizedType);
            } elseif ($update['nullable']) {
                $default = ' = null';
            } else {
                $default = '';
            }

            $escapedName = preg_quote($colName, '/');

            // Match property declaration: public [type] $name [= value];
            // Pattern: public (nullable type or type) $name (optional = value);
            // This matches: public ?string $name = null; or public string $name; or public int $id = 0;
            // More precise pattern to match the entire property line
            $pattern = '/    public\s+(?:\?[a-zA-Z_]+|[a-zA-Z_]+)\s+\$' . $escapedName . '(?:\s*=\s*[^;]+)?;/';
            $replacement = "    public {$phpType} \${$colName}{$default};";
            $content = preg_replace($pattern, $replacement, $content);
        }

        return $content;
    }

    /**
     * Parse property from Entity file to get nullable and default values
     */
    private function parsePropertyFromEntity(string $file, string $colName): ?array
    {
        $content = file_get_contents($file);
        $escapedName = preg_quote($colName, '/');

        // Match property declaration: public [type] $name [= value];
        if (preg_match('/    public\s+(\??)([a-zA-Z_]+)\s+\$' . $escapedName . '(?:\s*=\s*([^;]+))?;/', $content, $matches)) {
            $nullable = !empty($matches[1]); // ? prefix means nullable
            $type = $matches[2];
            $default = isset($matches[3]) ? trim($matches[3]) : null;

            return [
                'nullable' => $nullable,
                'type' => $type,
                'default' => $default,
            ];
        }

        return null;
    }

    /**
     * Normalize default value for comparison (handles 0 vs false for boolean, etc.)
     * Removes quotes from string values for comparison
     */
    private function normalizeDefaultForComparison(?string $default, string $type): ?string
    {
        if ($default === null) {
            return null;
        }

        $default = trim($default);

        // Remove quotes from string values (formatDefaultValue adds quotes)
        if (preg_match("/^'(.*)'$/", $default, $matches)) {
            $default = $matches[1];
        }

        // For boolean type, normalize 0/false and 1/true
        if ($type === PhpType::BOOLEAN->value) {
            if ($default === '0' || $default === 'false') {
                return 'false';
            }
            if ($default === '1' || $default === 'true') {
                return 'true';
            }
        }

        // For integer type, normalize numeric strings
        if ($type === PhpType::INTEGER->value) {
            if (is_numeric($default)) {
                return (string)(int)$default;
            }
        }

        return $default;
    }

    /**
     * Update primary key in Entity file
     */
    private function updatePrimaryKey(string $content, array $newPrimary, ReflectionClass $reflection): string
    {
        $isSingle = count($newPrimary) === 1;
        $primaryDef = $isSingle
            ? "self::{$newPrimary[0]}"
            : '[' . implode(', ', array_map(fn($col) => "self::{$col}", $newPrimary)) . ']';

        $type = $isSingle ? 'string' : 'array';

        // Update _primary constant - replace any existing type (string|array, string, or array) with correct type
        $pattern = '/(public const )(?:string\|array|string|array)( _primary = )(.*?)(;)/';
        $replacement = '$1' . $type . '$2' . $primaryDef . '$4';
        $content = preg_replace($pattern, $replacement, $content);

        return $content;
    }

    /**
     * Update indexes in Entity file - rewrite entire array from database
     */
    private function updateIndexes(string $content, ?TableInfo $dbTable, ReflectionClass $reflection): string
    {
        // Get all indexes from database
        $indexEntries = [];
        if ($dbTable !== null && !empty($dbTable->indexes)) {
            foreach ($dbTable->indexes as $indexName => $indexInfo) {
                // Use column constants instead of strings
                $columnRefs = array_map(fn($col) => "self::{$col}", $indexInfo->columns);
                $columns = implode(", ", $columnRefs);
                $unique = $indexInfo->unique ? "'unique' => true, " : "";
                $indexEntries[] = "        '{$indexName}' => [{$unique}'columns' => [{$columns}]],";
            }
        }

        // Check if _indexes section exists - match with comment and preserve surrounding newlines
        if (preg_match('/(\n)(\s*\/\/ Indexes\s*\n\s*public const array _indexes = \[)(.*?)(\];\s*\n)(\s*)/s', $content, $matches)) {
            // Remove empty _indexes array if no entries
            if (empty($indexEntries)) {
                // Remove the entire _indexes section, but preserve newlines before and after
                // $matches[1] = newline before, $matches[5] = whitespace/newlines after
                $content = str_replace($matches[0], $matches[1] . $matches[5], $content);
                return $content;
            }

            // Rewrite entire array with all indexes from database
            // Preserve newline before comment and whitespace after
            // $matches[2] already contains "    // Indexes\n    public const array _indexes = ["
            // $matches[4] contains "];\n"
            // Note: Each $indexEntries element already ends with a comma, so use "\n" not ",\n"
            $new = $matches[1] . $matches[2] . "\n" . implode("\n", $indexEntries) . "\n    " . $matches[4] . $matches[5];
            $content = str_replace($matches[0], $new, $content);
        } else {
            // Don't add _indexes section if empty
            if (empty($indexEntries)) {
                return $content;
            }

            // Add _indexes section
            $indexesSection = "    // Indexes\n    public const array _indexes = [\n" . implode("\n", $indexEntries) . "\n    ];\n\n";

            // Insert before properties or at end
            if (preg_match('/(\/\/ Properties)/', $content, $matches)) {
                $content = str_replace($matches[1], $indexesSection . $matches[1], $content);
            } elseif (preg_match('/(\n    \/\/ Properties)/', $content, $matches)) {
                $content = str_replace($matches[1], "\n" . $indexesSection . $matches[1], $content);
            } else {
                // Add before closing brace
                if (preg_match('/(\n)\}/', $content, $matches)) {
                    $content = str_replace($matches[1] . '}', "\n" . $indexesSection . '}', $content);
                }
            }
        }

        return $content;
    }

    /**
     * Update foreign keys in Entity file
     */
    private function updateForeignKeys(string $content, array $addForeignKeys, array $updateForeignKeys, ReflectionClass $reflection): string
    {
        // Get current table name to filter out self-references
        $currentTable = null;
        if (preg_match("/public const string _table = '([^']+)'/", $content, $tableMatch)) {
            $currentTable = $tableMatch[1];
        }

        // Filter out self-references
        $allForeignKeys = array_merge($addForeignKeys, $updateForeignKeys);
        $allForeignKeys = array_filter($allForeignKeys, function($fk) use ($currentTable) {
            $table = $fk['table'] ?? $fk['new_table'] ?? null;
            return $table !== null && $table !== $currentTable;
        });

        $updatedColumns = array_column($allForeignKeys, 'column');

        // Check if _foreign section exists
        if (preg_match('/(public const array _foreign = \[)(.*?)(\];)/s', $content, $matches)) {
            // Get existing foreign keys to preserve ones not being updated
            $existingForeignKeys = [];
            if (preg_match_all("/self::(\w+)\s*=>\s*'([^']+)'/", $matches[2], $existingMatches, PREG_SET_ORDER)) {
                foreach ($existingMatches as $match) {
                    $existingForeignKeys[$match[1]] = $match[2];
                }
            }

            // Merge: keep existing that are not being updated, add/update new ones
            $finalEntries = [];
            foreach ($existingForeignKeys as $col => $table) {
                if (!in_array($col, $updatedColumns, true)) {
                    $finalEntries[] = "        self::{$col} => '{$table}',";
                }
            }

            // Add new/updated foreign keys
            foreach ($allForeignKeys as $fk) {
                $column = $fk['column'];
                $table = $fk['table'] ?? $fk['new_table'];
                $finalEntries[] = "        self::{$column} => '{$table}',";
            }

            // Remove empty _foreign array if no entries
            if (empty($finalEntries)) {
                // Remove only the comment and declaration lines, preserve surrounding empty lines
                // Match exactly: // Foreign keys\n    public const array _foreign = [\n    ];\n
                // Be very precise to avoid removing extra content
                $pattern = '/(\n\s*)\/\/ Foreign keys\s*\n\s*public const array _foreign = \[\s*\];\s*\n(\s*)/';
                $content = preg_replace($pattern, '$1$2', $content);
                return $content;
            }

            // Keep trailing comma on all entries (including last one)
            $new = "\n" . implode("\n", $finalEntries) . "\n    ";
            $content = str_replace($matches[0], $matches[1] . $new . $matches[3], $content);
        } else {
            // Don't add _foreign section if empty
            if (empty($allForeignKeys)) {
                return $content;
            }

            // Add _foreign section with trailing comma for all entries
            $foreignEntries = [];
            foreach ($allForeignKeys as $fk) {
                $column = $fk['column'];
                $table = $fk['table'] ?? $fk['new_table'];
                $foreignEntries[] = "        self::{$column} => '{$table}',";
            }
            // Keep trailing comma on all entries (including last one)
            $foreignSection = "    // Foreign keys\n    public const array _foreign = [\n" . implode("\n", $foreignEntries) . "\n    ];\n\n";

            // Insert after _types, before _indexes or properties
            if (preg_match('/(public const array _types = \[.*?\];\n\n)/s', $content, $matches)) {
                $content = str_replace($matches[1], $matches[1] . $foreignSection, $content);
            } elseif (preg_match('/(\/\/ Indexes)/', $content, $matches)) {
                $content = str_replace($matches[1], $foreignSection . $matches[1], $content);
            } elseif (preg_match('/(\/\/ Properties)/', $content, $matches)) {
                $content = str_replace($matches[1], $foreignSection . $matches[1], $content);
            }
        }

        return $content;
    }

    /**
     * Create Entity files for tables without Entity files
     */
    private function createEntityFiles(array $tablesToCreate, ?string $entityDir, ?string $entityNamespace, int $dbIndex): int
    {
        if (empty($tablesToCreate)) {
            return 0;
        }

        // Auto-detect entity directory and namespace if not provided
        if ($entityDir === null || $entityNamespace === null) {
            $cwd = getcwd();
            $possibleDirs = [
                $cwd . '/backend/Database/Entity',
                $cwd . '/Database/Entity',
                $cwd . '/Entity',
            ];

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
                echo "⚠ Could not auto-detect Entity directory. Skipping file creation.\n";
                return 0;
            }

            // Auto-detect namespace from directory structure
            if ($entityNamespace === null) {
                $entityNamespace = Generator::detectNamespaceFromPath($entityDir);
            }
        }

        $created = 0;
        foreach ($tablesToCreate as $tableName => $dbTable) {
            // Skip migration table (hardcoded exclusion)
            if ($tableName === 'migration') {
                continue;
            }

            try {
                $className = $this->tableToPascalCase($tableName);
                $filePath = $entityDir . '/' . $className . '.php';

                // Generate Entity code using Generator (pass entityDir for namespace auto-detection if needed)
                $code = Generator::generateEntity($tableName, $entityNamespace, $className, $entityDir);

                // Write file
                file_put_contents($filePath, $code);
                $created++;
            } catch (\Throwable $e) {
                echo "⚠ Failed to create Entity file for {$tableName}: {$e->getMessage()}\n";
            }
        }

        return $created;
    }

    /**
     * Delete Entity files for tables that don't exist in DB
     */
    private function deleteEntityFiles(array $filesToDelete): int
    {
        if (empty($filesToDelete)) {
            return 0;
        }

        $deleted = 0;
        foreach ($filesToDelete as $tableName => $entityInfo) {
            try {
                $file = $entityInfo['file'];
                if (file_exists($file)) {
                    unlink($file);
                    $deleted++;
                }
            } catch (\Throwable $e) {
                echo "⚠ Failed to delete Entity file for {$tableName}: {$e->getMessage()}\n";
            }
        }

        return $deleted;
    }

    /**
     * Convert table name to PascalCase class name
     */
    private function tableToPascalCase(string $tableName): string
    {
        return str_replace('_', '', ucwords($tableName, '_'));
    }

    /**
     * Format default value for code generation
     */
    private function formatDefaultValue(mixed $default, string $type): string
    {
        if ($default === null) {
            return 'null';
        }

        return match ($type) {
            PhpType::INTEGER->value => (string)(int)$default,
            PhpType::FLOAT->value, PhpType::DECIMAL->value, PhpType::DOUBLE->value => (string)(float)$default,
            PhpType::BOOLEAN->value => $default ? 'true' : 'false',
            default => "'" . addslashes((string)$default) . "'",
        };
    }
}
