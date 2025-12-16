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
                echo "    ~ [" . implode(', ', $tableFix['reflection']->getConstant(Entity::META_PRIMARY) ?: []) . "] -> [" . implode(', ', $tableFixes['update_primary']) . "]\n";
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

        // Ensure PhpType is imported (always needed when converting types)
        // Check if _types array exists and has string literals that need conversion
        $needsPhpType = preg_match('/public const array _types = \[/', $content) && preg_match('/self::\w+\s*=>\s*\'[^\']+\'/', $content);
        if ($needsPhpType && !preg_match('/use\s+Hilos\\\Database\\\PhpType;/', $content)) {
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

        if (!empty($fixes['add_columns'])) {
            $content = $this->addColumns($content, $fixes['add_columns'], $reflection);
        }

        // Convert all string type literals to PhpType enum (not just updated ones)
        $content = $this->convertAllTypesToPhpType($content);
        
        if (!empty($fixes['update_column_types'])) {
            $content = $this->updateColumnTypes($content, $fixes['update_column_types'], $reflection);
        }

        if (isset($fixes['update_primary'])) {
            $content = $this->updatePrimaryKey($content, $fixes['update_primary'], $reflection);
        }

        if (!empty($fixes['add_indexes']) || !empty($fixes['update_indexes'])) {
            $content = $this->updateIndexes($content, $fixes['add_indexes'] ?? [], $fixes['update_indexes'] ?? [], $reflection);
        }
        
        // Clean up empty _indexes after all operations
        if (preg_match('/(\/\/ Indexes\s*public const array _indexes = \[)\s*(\];)/s', $content, $matches)) {
            // Remove only the comment and declaration lines, preserve surrounding empty lines
            $content = preg_replace('/\s*\/\/ Indexes\s*\n\s*public const array _indexes = \[\s*\];\s*\n/s', "\n", $content);
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
            
            // Clean up any extra spaces that might remain before next section
            $content = preg_replace('/(\n)\s{4,}(\/\/ (?:Indexes|Properties))/', '$1    $2', $content);
        }

        // Write updated content
        file_put_contents($file, $content);
    }

    /**
     * Normalize type to PhpType enum value
     */
    private function normalizeType(string $type): string
    {
        // Try to find matching PhpType enum case
        foreach (PhpType::cases() as $phpType) {
            if ($phpType->value === $type) {
                return $phpType->value;
            }
        }
        
        // If not found, return as-is (fallback for unknown types)
        return $type;
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
     * Add columns to Entity file
     */
    private function addColumns(string $content, array $columns, ReflectionClass $reflection): string
    {
        // Add column constants
        $constants = [];
        foreach ($columns as $col) {
            $constants[] = "    public const string {$col['name']} = '{$col['name']}';";
        }

        // Find position after last column constant
        if (preg_match('/(\/\/ Column name constants.*?\n)((?:    public const string \w+ = \'[^\']+\';\n)+)/s', $content, $matches)) {
            $content = str_replace($matches[2], $matches[2] . implode("\n", $constants) . "\n", $content);
        } else {
            // Insert after class declaration
            if (preg_match('/(final class \w+ extends Entity\n\{)/', $content, $matches)) {
                $content = str_replace($matches[1], $matches[1] . "\n    // Column name constants\n" . implode("\n", $constants) . "\n", $content);
            }
        }

        // Add to _columns array
        $columnRefs = [];
        foreach ($columns as $col) {
            $columnRefs[] = "        self::{$col['name']}";
        }

        if (preg_match('/(public const array _columns = \[)(.*?)(\];)/s', $content, $matches)) {
            $existing = $matches[2];
            $new = $existing . ",\n" . implode(",\n", $columnRefs);
            $content = str_replace($matches[0], $matches[1] . $new . "\n    " . $matches[3], $content);
        }

        // Add to _types array
        $typeEntries = [];
        foreach ($columns as $col) {
            $normalizedType = $this->normalizeType($col['type']);
            $typeValue = $this->formatTypeForArray($normalizedType);
            $typeEntries[] = "        self::{$col['name']} => {$typeValue}";
        }

        if (preg_match('/(public const array _types = \[)(.*?)(\];)/s', $content, $matches)) {
            $existing = $matches[2];
            $new = $existing . ",\n" . implode(",\n", $typeEntries);
            $content = str_replace($matches[0], $matches[1] . $new . "\n    " . $matches[3], $content);
        } else {
            // Add _types section
            if (preg_match('/(public const array _columns = \[.*?\];\n\n)/s', $content, $matches)) {
                $typesSection = "    // Column types\n    public const array _types = [\n" . implode(",\n", $typeEntries) . "\n    ];\n\n";
                $content = str_replace($matches[1], $matches[1] . $typesSection, $content);
            }
        }

        // Add properties
        $properties = [];
        foreach ($columns as $col) {
            $normalizedType = $this->normalizeType($col['type']);
            $phpType = $col['nullable'] ? "?{$normalizedType}" : $normalizedType;
            $default = $col['default'] !== null ? " = " . $this->formatDefaultValue($col['default'], $normalizedType) : ($col['nullable'] ? ' = null' : '');
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
                
                // Format type using PhpType enum
                $formattedType = $this->formatTypeForArray($typeString);
                
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
     * Update primary key in Entity file
     */
    private function updatePrimaryKey(string $content, array $newPrimary, ReflectionClass $reflection): string
    {
        $primaryDef = count($newPrimary) === 1 
            ? "self::{$newPrimary[0]}" 
            : '[' . implode(', ', array_map(fn($col) => "self::{$col}", $newPrimary)) . ']';

        $pattern = '/(public const string\|array _primary = )(.*?)(;)/';
        $replacement = '$1' . $primaryDef . '$3';
        $content = preg_replace($pattern, $replacement, $content);

        return $content;
    }

    /**
     * Update indexes in Entity file
     */
    private function updateIndexes(string $content, array $addIndexes, array $updateIndexes, ReflectionClass $reflection): string
    {
        $allIndexes = array_merge($addIndexes, $updateIndexes);
        $indexEntries = [];

        foreach ($allIndexes as $index) {
            $columns = "'" . implode("', '", $index['columns']) . "'";
            $unique = $index['unique'] ? "'unique' => true, " : "";
            $indexEntries[] = "        '{$index['name']}' => [{$unique}'columns' => [{$columns}]]";
        }

        // Check if _indexes section exists
        if (preg_match('/(public const array _indexes = \[)(.*?)(\];)/s', $content, $matches)) {
            // Get existing indexes to preserve ones not being updated
            $existingIndexes = [];
            // Match index definitions more precisely, including nested arrays
            // Pattern: 'index_name' => [ ... ]
            // We need to match the full structure: 'name' => ['columns' => [...], 'unique' => ...]
            if (preg_match_all("/'([^']+)' => \[(?:[^\[\]]+|\[[^\]]*\])*\]/s", $matches[2], $existingMatches, PREG_SET_ORDER)) {
                foreach ($existingMatches as $match) {
                    $indexName = $match[1];
                    // Skip if it's not a valid index name (like 'columns', 'unique', etc.)
                    // These are keys inside the index definition, not index names
                    if (!in_array($indexName, ['columns', 'unique'], true)) {
                        $existingIndexes[] = $indexName;
                    }
                }
            }
            
            // Also try to extract index names from the raw content more carefully
            // Sometimes the regex might miss indexes due to formatting
            $rawContent = $matches[2];
            // Look for patterns like 'index_name' => [ at the start of lines (with proper indentation)
            // This catches indexes that might be missed by the first regex
            if (preg_match_all("/^\s+'([^']+)' => \[/m", $rawContent, $lineMatches)) {
                foreach ($lineMatches[1] as $indexName) {
                    // Skip keys that are part of index definition structure
                    if (!in_array($indexName, ['columns', 'unique'], true) && !in_array($indexName, $existingIndexes, true)) {
                        $existingIndexes[] = $indexName;
                    }
                }
            }

            // Get names of indexes being updated/added
            $updatedNames = array_column($allIndexes, 'name');

            // Keep existing indexes that are not being updated
            $finalEntries = [];
            foreach ($existingIndexes as $existingName) {
                if (!in_array($existingName, $updatedNames, true)) {
                    // Extract existing index definition - match more precisely
                    $escapedName = preg_quote($existingName, '/');
                    // Match the full index definition including nested arrays
                    // Try multiple patterns to catch different formatting
                    $found = false;
                    
                    // Pattern 1: Standard format 'name' => [...]
                    if (preg_match("/('{$escapedName}' => \[(?:[^\[\]]+|\[[^\]]*\])*\])/s", $matches[2], $existingIndexMatch)) {
                        $entry = trim($existingIndexMatch[1]);
                        // Fix missing closing brackets if needed
                        $openBrackets = substr_count($entry, '[');
                        $closeBrackets = substr_count($entry, ']');
                        while ($openBrackets > $closeBrackets) {
                            $entry .= ']';
                            $closeBrackets++;
                        }
                        // Ensure trailing comma (but not double comma)
                        $entry = rtrim($entry, ',');
                        $entry .= ',';
                        $finalEntries[] = "        " . $entry;
                        $found = true;
                    }
                    
                    // Pattern 2: Line-based extraction if first pattern failed
                    if (!$found) {
                        // Extract by matching lines that start with the index name
                        $lines = explode("\n", $matches[2]);
                        foreach ($lines as $i => $line) {
                            if (preg_match("/^\s*'{$escapedName}' => \[/", $line)) {
                                // Found the start, collect until we find the closing ]
                                $entry = trim($line);
                                $bracketCount = substr_count($entry, '[') - substr_count($entry, ']');
                                $j = $i + 1;
                                while ($bracketCount > 0 && $j < count($lines)) {
                                    $entry .= " " . trim($lines[$j]);
                                    $bracketCount += substr_count($lines[$j], '[') - substr_count($lines[$j], ']');
                                    $j++;
                                }
                                // Ensure trailing comma (but not double comma)
                                $entry = rtrim($entry, ',');
                                $entry .= ',';
                                $finalEntries[] = "        " . $entry;
                                $found = true;
                                break;
                            }
                        }
                    }
                }
            }

            // Add new/updated indexes
            foreach ($allIndexes as $index) {
                $columns = "'" . implode("', '", $index['columns']) . "'";
                $unique = $index['unique'] ? "'unique' => true, " : "";
                // Ensure no double comma - entry should end with single comma
                $entry = "        '{$index['name']}' => [{$unique}'columns' => [{$columns}]],";
                $finalEntries[] = $entry;
            }
            
            // Remove empty _indexes array if no entries
            if (empty($finalEntries)) {
                // Remove the entire _indexes section
                $content = preg_replace('/\s*\/\/ Indexes\s*public const array _indexes = \[\s*\];\s*/s', '', $content);
                return $content;
            }
            
            // Keep trailing comma on all entries (including last one)
            $new = "\n" . implode("\n", $finalEntries) . "\n    ";
            $content = str_replace($matches[0], $matches[1] . $new . $matches[3], $content);
        } else {
            // Don't add _indexes section if empty
            if (empty($indexEntries)) {
                return $content;
            }
            
            // Add _indexes section with trailing comma for all entries (including last one)
            $indexesWithComma = [];
            foreach ($indexEntries as $entry) {
                // Ensure entry has trailing comma
                $entry = rtrim($entry, ',');
                $indexesWithComma[] = $entry . ',';
            }
            $indexesSection = "    // Indexes\n    public const array _indexes = [\n" . implode("\n", $indexesWithComma) . "\n    ];\n\n";
            
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
                $entityNamespace = $this->detectNamespaceFromPath($entityDir);
            }
        }

        $created = 0;
        foreach ($tablesToCreate as $tableName => $dbTable) {
            try {
                $className = $this->tableToPascalCase($tableName);
                $filePath = $entityDir . '/' . $className . '.php';
                
                // Generate Entity code using Generator
                $code = Generator::generateEntity($tableName, $entityNamespace, $className);
                
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
     * Detect namespace from Entity directory path
     */
    private function detectNamespaceFromPath(string $entityDir): string
    {
        // Try to extract namespace by looking at existing Entity files
        $files = glob($entityDir . '/*.php');
        if ($files !== false && !empty($files)) {
            // Read first Entity file to extract namespace
            $content = file_get_contents($files[0]);
            if ($content !== false && preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
                return trim($matches[1]);
            }
        }
        
        // Fallback: try to extract from path structure
        // Look for common patterns like demo/websocket-test/backend/Database/Entity
        $path = str_replace('\\', '/', $entityDir);
        $parts = explode('/', $path);
        
        // Find "Entity" in path
        $entityIndex = -1;
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            if ($parts[$i] === 'Entity') {
                $entityIndex = $i;
                break;
            }
        }
        
        if ($entityIndex > 0) {
            // Find "backend" or similar root directory
            $backendIndex = -1;
            for ($i = $entityIndex - 1; $i >= 0; $i--) {
                if (in_array($parts[$i], ['backend', 'src', 'app'], true)) {
                    $backendIndex = $i;
                    break;
                }
            }
            
            if ($backendIndex >= 0) {
                // Take parts from before backend to Entity (inclusive)
                // Example: demo/websocket-test/backend/Database/Entity
                // We want: Demo\WebSocketTest\Database\Entity
                $namespaceParts = [];
                // Add project name if exists (before backend)
                if ($backendIndex > 0) {
                    $projectParts = array_slice($parts, 0, $backendIndex);
                    foreach ($projectParts as $part) {
                        // Convert to PascalCase
                        $namespaceParts[] = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $part)));
                    }
                }
                // Add parts from backend to Entity
                $pathParts = array_slice($parts, $backendIndex, $entityIndex - $backendIndex + 1);
                foreach ($pathParts as $part) {
                    $namespaceParts[] = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $part)));
                }
                return implode('\\', $namespaceParts);
            } else {
                // No backend found, use all parts up to Entity
                $namespaceParts = array_slice($parts, 0, $entityIndex + 1);
                return implode('\\', array_map(fn($p) => str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $p))), $namespaceParts));
            }
        }
        
        // Fallback
        return 'App\\Entity';
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

