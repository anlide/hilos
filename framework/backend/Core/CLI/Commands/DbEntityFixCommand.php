<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\DbEntityFixCommand\EntityCollectionFixer;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Generator;
use Hilos\Database\PhpType;
use Hilos\Database\Schema\IndexInfo;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Schema\TableInfo;
use Hilos\Utils\Helpers\StringHelper;
use ReflectionClass;
use RuntimeException;

/**
 * DbEntityFixCommand - Fix Entity and EntityCollection files to match database schema
 *
 * Automatically updates Entity class definitions to match actual database structure.
 * Adds missing columns, indexes, foreign keys, and updates types.
 * Also fixes corresponding EntityCollection files to match Entity classes.
 */
class DbEntityFixCommand implements CommandInterface
{
    use EntityCollectionFixer;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (e.g. db:entity:fix)
     */
    public function getName(): string
    {
        return CliCommands::DB_ENTITY_FIX;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Fix Entity and EntityCollection files to match database schema';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: db:entity:fix

Description:
  Automatically update Entity and EntityCollection class definitions to match database schema.
  Adds missing columns, indexes, foreign keys, and updates types in Entity files.
  Also fixes corresponding EntityCollection files to match Entity classes.

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

    /**
     * Execute the command to fix Entity and EntityCollection files.
     *
     * @param array<string, mixed> $options Parsed options (db-index, table, entity-dir, entity-ns, dry-run)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws DatabaseException If database connection or schema init fails
     * @throws RuntimeException If connection not established or Entity dir not found
     */
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
        Database::useConnection($dbIndex);

        // Check if connected
        if (!Database::isConnected($dbIndex)) {
            throw new RuntimeException("Database connection {$dbIndex} is not established. Please ensure database connection is initialized before running this command.");
        }

        // Initialize schema if not already initialized
        if (!Schema::isInitialized($dbIndex)) {
            echo "Initializing schema for connection {$dbIndex}...\n";
            Schema::initialize($dbIndex);
            echo "Schema initialized successfully.\n\n";
        }

        // Load Entity classes and their file paths
        $syntaxErrors = 0;
        $brokenEntities = [];
        $entities = $this->loadEntities($entityDir, $entityNamespace, $syntaxErrors, $brokenEntities);

        // Get database tables
        $dbTables = Schema::getTables($dbIndex);

        // Find differences and prepare fixes
        $fixes = $this->prepareFixes($entities, $dbTables, $tableName, $brokenEntities);

        // Find tables without Entity files and Entity files without tables
        $tablesToCreate = $this->findTablesToCreate($entities, $dbTables, $tableName, $brokenEntities, $entityDir);
        $filesToDelete = $this->findFilesToDelete($entities, $dbTables, $tableName, $brokenEntities);
        
        // Find EntityCollection files to delete (for deleted Entity files)
        $entityCollectionDir = null;
        $entityCollectionsToDelete = [];
        if (!empty($filesToDelete)) {
            $entityCollectionsToDelete = $this->findEntityCollectionsToDelete($filesToDelete, $entityCollectionDir);
        }

        // Display information about broken Entity files
        if (!empty($brokenEntities)) {
            echo "\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "⚠ Damaged Entity files (will not be modified):\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "\n";
            foreach ($brokenEntities as $file => $reason) {
                echo "  - {$file}\n";
                echo "    Reason: {$reason}\n";
                echo "\n";
            }
        }

        if ($syntaxErrors > 0) {
            echo "\n⚠ {$syntaxErrors} file(s) contain syntax errors and were skipped.\n";
        }

        $hasEntityChanges = !empty($fixes) || !empty($tablesToCreate) || !empty($filesToDelete);

        if (!$hasEntityChanges) {
            if ($syntaxErrors > 0) {
                echo "\n";
            } else {
                echo "✓ No fixes needed! Entity files match database schema.\n\n";
            }
        } else {
            // Display what will be fixed
            $this->displayFixes($fixes, $tablesToCreate, $filesToDelete, $entityCollectionsToDelete, $dryRun);
        }

        // Process EntityCollection files (only if no errors in Entity files)
        echo "\n=== Fix EntityCollection Files ===\n\n";

        $appliedCollections = 0;
        $createdCollections = 0;
        $deletedCollections = 0;
        $brokenEntityCollections = [];
        $collectionSyntaxErrors = 0;

        // Check for errors in Entity files - throw exception if found
        if (!empty($brokenEntities) || $syntaxErrors > 0) {
            throw new RuntimeException(
                "Cannot process EntityCollection files due to errors in Entity files. " .
                "Please fix the errors above before processing EntityCollection files."
            );
        }

        // Load EntityCollection files
        $entityCollections = $this->loadEntityCollections($entityCollectionDir, $collectionSyntaxErrors, $brokenEntityCollections);

        // Display information about broken EntityCollection files
        if (!empty($brokenEntityCollections)) {
            echo "\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "⚠ Damaged EntityCollection files (will not be modified):\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "\n";
            foreach ($brokenEntityCollections as $file => $reason) {
                echo "  - {$file}\n";
                echo "    Reason: {$reason}\n";
                echo "\n";
            }
        }

        if ($collectionSyntaxErrors > 0) {
            echo "\n⚠ {$collectionSyntaxErrors} EntityCollection file(s) contain syntax errors and were skipped.\n";
        }

        // Process EntityCollections (entityCollections is always an array, even if empty)
        // Prepare fixes for EntityCollections
        $entityCollectionFixes = $this->prepareEntityCollectionFixes($entities, $entityCollections, $tableName);

        // Find EntityCollection files to create for existing entities
        $entityCollectionsToCreate = $this->findEntityCollectionsToCreate($entities, $entityCollections, $tableName, $brokenEntities);

        // Also find EntityCollection files to create for new entities (from tablesToCreate)
        if (!empty($tablesToCreate)) {
            $entityCollectionsToCreateForNew = $this->findEntityCollectionsToCreateForNewEntitiesEstimate($tablesToCreate, $entityDir, $entityNamespace, $entityCollections, $brokenEntities);
            // Merge with existing list
            foreach ($entityCollectionsToCreateForNew as $entityClassName => $collectionInfo) {
                if (!isset($entityCollectionsToCreate[$entityClassName])) {
                    $entityCollectionsToCreate[$entityClassName] = $collectionInfo;
                }
            }
        }

        // Check if there are any fixes needed
        if (empty($entityCollectionFixes) && empty($entityCollectionsToCreate)) {
            if ($collectionSyntaxErrors > 0 || !empty($brokenEntityCollections)) {
                echo "\n";
            } else {
                echo "✓ No fixes needed! EntityCollection files match Entity classes.\n\n";
            }
        } else {
            // Display EntityCollection fixes
            $this->displayEntityCollectionFixes($entityCollectionFixes, $entityCollectionsToCreate, $dryRun);
        }

        if ($dryRun) {
            echo "\n[DRY RUN] No files were modified.\n\n";
            return ExitCode::SUCCESS;
        }

        // Apply fixes automatically (no interactive confirmation)

        // Apply fixes to existing files
        $applied = $this->applyFixes($fixes);

        // Create new Entity files
        $created = $this->createEntityFiles($tablesToCreate, $entityDir, $entityNamespace, $dbIndex);

        // After creating new Entity files, find EntityCollections to create for them
        $newlyCreatedEntityCollections = [];
        if ($created > 0 && !empty($tablesToCreate)) {
            // Reload entities to include newly created ones
            $syntaxErrorsAfter = 0;
            $brokenEntitiesAfter = [];
            $entitiesAfter = $this->loadEntities($entityDir, $entityNamespace, $syntaxErrorsAfter, $brokenEntitiesAfter);
            
            // Find EntityCollections for newly created Entity files
            $newlyCreatedEntityCollections = $this->findEntityCollectionsToCreateForNewEntities($tablesToCreate, $entitiesAfter, $entityCollections, $brokenEntitiesAfter);
            
            // Merge with existing list
            foreach ($newlyCreatedEntityCollections as $entityClassName => $collectionInfo) {
                if (!isset($entityCollectionsToCreate[$entityClassName])) {
                    $entityCollectionsToCreate[$entityClassName] = $collectionInfo;
                }
            }
        }

        // Delete Entity files without tables
        $deleted = $this->deleteEntityFiles($filesToDelete);

        // Delete EntityCollection files for deleted Entity files
        if (!empty($entityCollectionsToDelete)) {
            $deletedCollections = $this->deleteEntityCollectionFiles($entityCollectionsToDelete);
        }

        // Apply EntityCollection fixes
        if (!empty($entityCollectionFixes) || !empty($entityCollectionsToCreate)) {
            $appliedCollections = $this->applyEntityCollectionFixes($entityCollectionFixes, $entityCollections, $entities);
            $createdCollections = $this->createEntityCollectionFiles($entityCollectionsToCreate, $entityCollectionDir, $entities);
        }

        // Summary
        $totalChanges = $applied + $created + $deleted + $appliedCollections + $createdCollections + $deletedCollections;
        if ($totalChanges > 0) {
            echo "\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "Summary:\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "\n";

            $entityChanges = $applied + $created + $deleted;
            if ($entityChanges > 0) {
                echo "Entity files:\n";
                if ($applied > 0) {
                    echo "  ✓ Updated {$applied} file(s)\n";
                }
                if ($created > 0) {
                    echo "  ✓ Created {$created} file(s)\n";
                }
                if ($deleted > 0) {
                    echo "  ✓ Deleted {$deleted} file(s)\n";
                }
                echo "\n";
            }

            $collectionChanges = $appliedCollections + $createdCollections + $deletedCollections;
            if ($collectionChanges > 0) {
                echo "EntityCollection files:\n";
                if ($appliedCollections > 0) {
                    echo "  ✓ Updated {$appliedCollections} file(s)\n";
                }
                if ($createdCollections > 0) {
                    echo "  ✓ Created {$createdCollections} file(s)\n";
                }
                if ($deletedCollections > 0) {
                    echo "  ✓ Deleted {$deletedCollections} file(s)\n";
                }
                echo "\n";
            }
        } elseif (empty($brokenEntities) && $syntaxErrors === 0 && empty($brokenEntityCollections) && $collectionSyntaxErrors === 0) {
            // No changes at all and no errors
            echo "\n✓ All files are up to date. No changes needed.\n\n";
        }

        return ExitCode::SUCCESS;
    }

    /**
     * Load Entity classes from directory
     */
    private function loadEntities(?string $entityDir, ?string $entityNamespace, int &$syntaxErrors = 0, array &$brokenEntities = []): array
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
                throw new RuntimeException("Could not auto-detect Entity directory. Please specify --entity-dir");
            }
        }

        if (!is_dir($entityDir)) {
            throw new RuntimeException("Entity directory does not exist: {$entityDir}");
        }

        $entities = [];
        $files = glob($entityDir . '/*.php');

        if ($files === false) {
            return $entities;
        }

        foreach ($files as $file) {
            $className = $this->extractClassNameFromFile($file, $entityNamespace);
            if ($className === null) {
                // File exists but cannot extract class name - mark as broken
                $brokenEntities[$file] = 'Cannot extract class name from file';
                continue;
            }

            try {
                // Check PHP syntax first
                $content = file_get_contents($file);
                if ($content === false) {
                    $brokenEntities[$file] = 'Cannot read file';
                    continue;
                }

                // Use php -l to check syntax
                $output = [];
                $returnVar = 0;
                exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $returnVar);
                if ($returnVar !== 0) {
                    $errorMessage = implode(' ', $output);
                    $brokenEntities[$file] = "PHP syntax error: {$errorMessage}";
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
                $brokenEntities[$file] = $e->getMessage();
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
    private function prepareFixes(array $entities, array $dbTables, ?string $tableFilter, array $brokenEntities = []): array
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

            // Skip if Entity file is broken
            $entityFile = $entityInfo['file'];
            if (isset($brokenEntities[$entityFile])) {
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
                $indexColumns = $indexDef[Entity::INDEX_COLUMNS] ?? [];
                foreach ($indexColumns as $indexCol) {
                    if (in_array($indexCol, $columnsToRemove, true)) {
                        $fixes['remove_indexes'][] = $indexName;
                        break;
                    }
                }
            }

            // Also remove foreign keys that reference removed columns
            // Need to check both simple and composite foreign keys
            $entityFile = $entity['file'] ?? null;
            if ($entityFile !== null) {
                $content = file_get_contents($entityFile);
                if ($content !== false) {
                    $existingForeignKeys = $this->parseExistingForeignKeys($content);
                    foreach ($existingForeignKeys as $colKey => $tableInfo) {
                        $columns = $this->parseColumnKey($colKey);
                        // If any column in this foreign key is being removed, remove the entire foreign key
                        foreach ($columns as $col) {
                            if (in_array($col, $columnsToRemove, true)) {
                                $fixes['remove_foreign_keys'][] = $colKey;
                                break;
                            }
                        }
                    }
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
                    Entity::INDEX_UNIQUE => $dbIndex->unique,
                    Entity::INDEX_COLUMNS => $dbIndex->columns,
                ];
            } elseif ($this->indexesDiffer($entityIndexes[$indexName], $dbIndex)) {
                $fixes['update_indexes'][] = [
                    'name' => $indexName,
                    Entity::INDEX_UNIQUE => $dbIndex->unique,
                    Entity::INDEX_COLUMNS => $dbIndex->columns,
                ];
            }
        }

        // Missing foreign keys (but not self-references)
        $entityForeign = $entity['foreign'] ?? [];
        $currentTable = $entity['table'];

        // Normalize entity foreign keys: extract table names from Entity constants or strings
        $normalizedEntityForeign = $this->normalizeForeignKeys($entityForeign, $entity['file']);

        foreach ($dbTable->foreignKeys as $colKey => $foreignTable) {
            // Skip if foreign key references the same table (self-reference)
            if ($foreignTable === $currentTable) {
                continue;
            }

            // Check if foreign key exists (support both simple and composite)
            $normalizedColKey = $this->normalizeColumnKey($colKey);
            if (!isset($normalizedEntityForeign[$normalizedColKey])) {
                $fixes['add_foreign_keys'][] = [
                    'columns' => $this->parseColumnKey($colKey),
                    'table' => $foreignTable,
                ];
            } elseif ($normalizedEntityForeign[$normalizedColKey] !== $foreignTable) {
                $fixes['update_foreign_keys'][] = [
                    'columns' => $this->parseColumnKey($colKey),
                    'old_table' => $normalizedEntityForeign[$normalizedColKey],
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
        $entityCols = $entityIndex[Entity::INDEX_COLUMNS] ?? [];
        $entityUnique = $entityIndex[Entity::INDEX_UNIQUE] ?? false;
        sort($entityCols);
        $dbCols = $dbIndex->columns;
        sort($dbCols);

        return $entityCols !== $dbCols || $entityUnique !== $dbIndex->unique;
    }

    /**
     * Find tables in DB without Entity files
     */
    private function findTablesToCreate(array $entities, array $dbTables, ?string $tableFilter, array $brokenEntities = [], ?string $entityDir = null): array
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
                // Check if Entity file exists but is broken
                $entityFile = $this->findEntityFileByTableName($tableName, $entityDir);
                if ($entityFile !== null && isset($brokenEntities[$entityFile])) {
                    // Entity file exists but is broken - don't create new one
                    continue;
                }

                $tablesToCreate[$tableName] = $dbTable;
            }
        }

        return $tablesToCreate;
    }

    /**
     * Find Entity file by table name
     */
    private function findEntityFileByTableName(string $tableName, ?string $entityDir): ?string
    {
        // Auto-detect if not provided
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
        }

        if ($entityDir === null || !is_dir($entityDir)) {
            return null;
        }

        // Try to find Entity file by checking all PHP files
        $files = glob($entityDir . '/*.php');
        if ($files === false) {
            return null;
        }

        foreach ($files as $file) {
            // Try to extract table name from file
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            // Check if file contains table name constant
            if (preg_match("/public const string _table = '([^']+)'/", $content, $matches)) {
                if ($matches[1] === $tableName) {
                    return $file;
                }
            }
        }

        return null;
    }

    /**
     * Find Entity files without tables in DB
     */
    private function findFilesToDelete(array $entities, array $dbTables, ?string $tableFilter, array $brokenEntities = []): array
    {
        $filesToDelete = [];

        foreach ($entities as $tableName => $entityInfo) {
            if ($tableFilter !== null && $tableName !== $tableFilter) {
                continue;
            }

            // Skip if Entity file is broken
            $entityFile = $entityInfo['file'];
            if (isset($brokenEntities[$entityFile])) {
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
    private function displayFixes(array $fixes, array $tablesToCreate, array $filesToDelete, array $entityCollectionsToDelete, bool $dryRun): void
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
                    $unique = ($index[Entity::INDEX_UNIQUE] ?? false) ? 'UNIQUE ' : '';
                    $cols = implode(', ', $index[Entity::INDEX_COLUMNS] ?? []);
                    echo "    + {$unique}{$index['name']}: ({$cols})\n";
                }
                echo "\n";
            }

            if (!empty($tableFixes['update_indexes'])) {
                echo "  Will update indexes:\n";
                foreach ($tableFixes['update_indexes'] as $index) {
                    $unique = ($index[Entity::INDEX_UNIQUE] ?? false) ? 'UNIQUE ' : '';
                    $cols = implode(', ', $index[Entity::INDEX_COLUMNS] ?? []);
                    echo "    ~ {$index['name']}: {$unique}({$cols})\n";
                }
                echo "\n";
            }

            if (!empty($tableFixes['add_foreign_keys'])) {
                echo "  Will add foreign keys:\n";
                foreach ($tableFixes['add_foreign_keys'] as $fk) {
                    $columns = $fk['columns'] ?? [$fk['column'] ?? ''];
                    $colStr = count($columns) === 1 ? $columns[0] : implode(', ', $columns);
                    echo "    + {$colStr} -> {$fk['table']}\n";
                }
                echo "\n";
            }

            if (!empty($tableFixes['update_foreign_keys'])) {
                echo "  Will update foreign keys:\n";
                foreach ($tableFixes['update_foreign_keys'] as $fk) {
                    $columns = $fk['columns'] ?? [$fk['column'] ?? ''];
                    $colStr = count($columns) === 1 ? $columns[0] : implode(', ', $columns);
                    echo "    ~ {$colStr}: {$fk['old_table']} -> {$fk['new_table']}\n";
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

        // Display EntityCollection files to delete
        if (!empty($entityCollectionsToDelete)) {
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo ($dryRun ? "[DRY RUN] " : "") . "EntityCollection files to delete (orphaned):\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            foreach ($entityCollectionsToDelete as $entityClassName => $collectionInfo) {
                echo "  - {$collectionInfo['class']} ({$collectionInfo['file']})\n";
                echo "    (Entity: {$entityClassName})\n";
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
            throw new RuntimeException("Failed to read file: {$file}");
        }

        $fixes = $tableFix['fixes'];
        $reflection = $tableFix['reflection'];

        // Ensure PhpType is imported (always needed)
        if (!preg_match('/use\s+Hilos\\\Database\\\PhpType;/', $content)) {
            // Add use statement after other use statements
            if (preg_match('/(use\s+Hilos\\\Database\\\Entity\\\Entity;)/', $content, $matches)) {
                $content = str_replace($matches[1], $matches[1] . "\nuse Hilos\\Database\\PhpType;", $content);
            } elseif (preg_match('/(namespace\s+[^;]+;\s*\n\n)/', $content, $matches)) {
                $content = str_replace($matches[1], $matches[1] . "use Hilos\\Database\\Entity\\Item\\Entity;\nuse Hilos\\Database\\PhpType;\n\n", $content);
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
        if (preg_match('/(\/\/ Indexes\s*public const array _indexes = \[)\s*(];)/s', $content, $matches)) {
            // Remove only the comment and declaration lines, preserve surrounding empty lines
            // Match: \n    // Indexes\n    public const array _indexes = [\n    ];\n
            // Replace with just newline before to preserve spacing
            $pattern = '/(\n)\s*\/\/ Indexes\s*\n\s*public const array _indexes = \[\s*];\s*\n/';
            $content = preg_replace($pattern, '$1', $content);
        }

        if (!empty($fixes['add_foreign_keys']) || !empty($fixes['update_foreign_keys'])) {
            $content = $this->updateForeignKeys($content, $fixes['add_foreign_keys'] ?? [], $fixes['update_foreign_keys'] ?? [], $reflection);
        }

        // Clean up empty _foreign after all operations
        if (preg_match('/(\/\/ Foreign keys\s*public const array _foreign = \[)\s*(];)/s', $content, $matches)) {
            // Remove only the comment and declaration lines, preserve surrounding empty lines
            // Be very precise to avoid removing extra content or leaving extra spaces
            // Match: \n    // Foreign keys\n    public const array _foreign = [\n    ];\n
            // Replace with just \n (removing the section but keeping one newline)
            $pattern = '/(\n)\s*\/\/ Foreign keys\s*\n\s*public const array _foreign = \[\s*];\s*\n/';
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
        if (preg_match('/(public const array _columns = \[)(.*?)(];)/s', $content, $matches)) {
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

        if (preg_match('/(public const array _columns = \[)(.*?)(];)/s', $content, $matches)) {
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
        if (preg_match('/(public const array _columns = \[)(.*?)(];)/s', $content, $matches)) {
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
        if (preg_match('/(public const array _types = \[)(.*?)(];)/s', $content, $matches)) {
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
        if (preg_match('/(\n)(\s*\/\/ Column types\s*\n\s*public const array _types = \[)(.*?)(];\s*\n)(\s*)/s', $content, $matches)) {
            // Rewrite entire array, preserve newlines before comment and after
            // $matches[1] = newline before, $matches[5] = whitespace/newlines after
            $newContent = $matches[1] . $matches[2] . "\n" . implode(",\n", $typeEntries) . ",\n    " . $matches[4] . $matches[5];
            $content = str_replace($matches[0], $newContent, $content);
        } else {
            // Add _types section
            if (preg_match('/(public const array _columns = \[.*?];\n\n)/s', $content, $matches)) {
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
            if (preg_match('/(\n)}/', $content, $matches)) {
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
            // Remove property-level @object-exclude directive before property
            // Match: // @object-exclude or /** @object-exclude */ followed by property
            $escapedColName = preg_quote($colName, '/');
            // Single-line comment: entire line with // @object-exclude followed by property line
            // Remove entire comment line (including leading spaces) and property line
            $pattern = '/^[ \t]*\/\/[^\n]*@object-exclude[^\n]*\n[ \t]*public\s+(?:\?)?\w+\s+\$' . $escapedColName . '(?:\s*=\s*[^;]+)?;\n?/m';
            $content = preg_replace($pattern, '', $content);
            // Multi-line comment: entire /** @object-exclude */ block followed by property line
            // Remove entire comment block (including leading spaces) and property line
            $pattern = '/^[ \t]*\/\*\*[^\*]*@object-exclude[^\*]*\*\/\s*\n[ \t]*public\s+(?:\?)?\w+\s+\$' . $escapedColName . '(?:\s*=\s*[^;]+)?;\n?/m';
            $content = preg_replace($pattern, '', $content);

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
            // Match both string literals ('string') and PhpType enum (PhpType::STRING->value)
            // Pattern: self::columnName => (string literal OR PhpType enum) with optional comma
            $pattern = '/(?<=\n)\s+self::' . preg_quote($colName, '/') . '\s*=>\s*(?:\'[^\']+\'|PhpType::\w+->value)\s*,?\s*(?=\n)/';
            $content = preg_replace($pattern, '', $content);

            // Also handle inline cases
            $pattern = '/\s+self::' . preg_quote($colName, '/') . '\s*=>\s*(?:\'[^\']+\'|PhpType::\w+->value)\s*,/';
            $content = preg_replace($pattern, '', $content);
            $pattern = '/,\s*self::' . preg_quote($colName, '/') . '\s*=>\s*(?:\'[^\']+\'|PhpType::\w+->value)(?=\s*,|\s*;)/';
            $content = preg_replace($pattern, '', $content);

            // Remove property if it wasn't already removed with @object-exclude directive
            // Match: public type $name = value; or public type $name;
            $pattern = '/    public\s+(?:\?[a-z]+|[a-z]+)\s+\$' . preg_quote($colName, '/') . '(?:\s*=\s*[^;]+)?;\n/';
            $content = preg_replace($pattern, '', $content);

            // Remove from _foreign if exists - already handled separately
        }

        // Remove field from class-level @object-exclude directive and clean up if empty
        $content = $this->removeFromClassLevelObjectExclude($content, $columns);

        // Clean up empty lines in _columns array
        if (preg_match('/(public const array _columns = \[)(.*?)(];)/s', $content, $matches)) {
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
        if (preg_match('/(public const array _types = \[)(.*?)(];)/s', $content, $matches)) {
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
                    $content = str_replace($matches[0], $matches[1] . $typesContent . $matches[3], $content);
                } else {
                    // All entries removed - remove entire _types section
                    // Match: \n    // Column types\n    public const array _types = [\n    ];\n
                    $pattern = '/(\n)\s*\/\/ Column types\s*\n\s*public const array _types = \[\s*];\s*\n/';
                    $content = preg_replace($pattern, '$1', $content);
                }
            } else {
                // All entries removed - remove entire _types section
                // Match: \n    // Column types\n    public const array _types = [\n    ];\n
                $pattern = '/(\n)\s*\/\/ Column types\s*\n\s*public const array _types = \[\s*];\s*\n/';
                $content = preg_replace($pattern, '$1', $content);
            }
        }

        return $content;
    }

    /**
     * Remove fields from class-level @object-exclude directive
     */
    private function removeFromClassLevelObjectExclude(string $content, array $columnsToRemove): string
    {
        if (empty($columnsToRemove)) {
            return $content;
        }

        // Find class-level @object-exclude directive in PHPDoc
        // Pattern: /** ... @object-exclude field1, field2, field3 ... */
        // The directive can be on a single line or span multiple lines
        if (preg_match('/(\/\*\*.*?)(\*\s*@object-exclude\s+)([^\n\*]+)(.*?\*\/)/s', $content, $matches)) {
            $beforeDirective = $matches[1];
            $directiveStart = $matches[2];
            $fieldsString = trim($matches[3]);
            $afterDirective = $matches[4];

            if (empty($fieldsString)) {
                return $content; // No fields to remove
            }

            // Parse fields from directive
            $fields = preg_split('/\s*,\s*/', $fieldsString);
            $fields = array_map('trim', $fields);
            $fields = array_filter($fields, fn($f) => $f !== '');

            // Remove fields that are being deleted
            $remainingFields = array_filter($fields, fn($field) => !in_array($field, $columnsToRemove, true));

            if (empty($remainingFields)) {
                // All fields removed - remove entire @object-exclude line from PHPDoc
                // Remove entire line including leading spaces and * symbol: [spaces]* @object-exclude field1, field2
                $pattern = '/^[ \t]*\*\s*@object-exclude\s+[^\n\*]+\n?/m';
                $content = preg_replace($pattern, '', $content);

                // Clean up multiple consecutive empty lines
                $content = preg_replace('/\n\s*\n\s*\n/', "\n\n", $content);
            } elseif (count($remainingFields) < count($fields)) {
                // Some fields removed - update directive with remaining fields
                $newFieldsString = implode(', ', $remainingFields);
                $oldDirective = $directiveStart . $fieldsString;
                $newDirective = $directiveStart . $newFieldsString;
                $content = str_replace($oldDirective, $newDirective, $content);
            }
        }

        return $content;
    }

    /**
     * Remove indexes from Entity file
     */
    private function removeIndexes(string $content, array $indexNames, ReflectionClass $reflection): string
    {
        // Find _indexes array
        if (preg_match('/(public const array _indexes = \[)(.*?)(];)/s', $content, $matches)) {
            $indexesContent = $matches[2];
            $remainingIndexes = [];

            // Parse existing indexes by finding each index entry with balanced brackets
            // Pattern: 'index_name' => [ ... balanced brackets ... ]
            $offset = 0;
            while (preg_match("/'([^']+)' => \[/", $indexesContent, $nameMatch, PREG_OFFSET_CAPTURE, $offset)) {
                $indexName = $nameMatch[1][0];
                $startPos = $nameMatch[0][1];

                // Find the matching closing bracket for this index definition
                $bracketStart = $nameMatch[0][1] + strlen($nameMatch[0][0]) - 1; // Position of opening [
                $depth = 1;
                $pos = $bracketStart + 1;
                $endPos = $pos;

                while ($depth > 0 && $pos < strlen($indexesContent)) {
                    if ($indexesContent[$pos] === '[') {
                        $depth++;
                    } elseif ($indexesContent[$pos] === ']') {
                        $depth--;
                        if ($depth === 0) {
                            $endPos = $pos + 1;
                            break;
                        }
                    }
                    $pos++;
                }

                if ($depth === 0) {
                    // Extract full index definition
                    $fullEntry = substr($indexesContent, (int)$startPos, $endPos - $startPos);

                    if (!in_array($indexName, $indexNames, true)) {
                        // Keep this index
                        $entry = trim($fullEntry);
                        // Ensure trailing comma (but not double comma)
                        $entry = rtrim($entry, ',');
                        $entry .= ',';
                        $remainingIndexes[] = "        " . $entry;
                    }

                    $offset = $endPos;
                } else {
                    // Unbalanced brackets, skip this match
                    $offset = (int)$startPos + 1;
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
     * Supports both simple and composite foreign keys
     *
     * @param string $content Entity file content
     * @param list<string> $columnKeys Column keys to remove (can be 'column' or 'col1,col2')
     * @param ReflectionClass $reflection Entity reflection class
     * @return string Updated content
     */
    private function removeForeignKeys(string $content, array $columnKeys, ReflectionClass $reflection): string
    {
        // Find _foreign array
        if (preg_match('/(public const array _foreign = \[)(.*?)(];)/s', $content, $matches)) {
            $remainingForeign = [];

            // Parse existing foreign keys (support both simple and composite)
            $existingForeignKeys = $this->parseExistingForeignKeys($content);

            // Normalize keys to remove for comparison
            $normalizedKeysToRemove = [];
            foreach ($columnKeys as $key) {
                $normalizedKeysToRemove[$this->normalizeColumnKey($key)] = true;
            }

            foreach ($existingForeignKeys as $colKey => $tableInfo) {
                $normalizedKey = $this->normalizeColumnKey($colKey);

                // Check if this foreign key should be removed
                if (!isset($normalizedKeysToRemove[$normalizedKey])) {
                    // Keep this foreign key (preserve original format)
                    $remainingForeign[] = $tableInfo['original_line'];
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
        if (preg_match('/(public const array _types = \[)(.*?)(];)/s', $content, $matches)) {
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
                $unique = $indexInfo->unique ? "Entity::INDEX_UNIQUE => true, " : "";
                $indexEntries[] = "        '{$indexName}' => [{$unique}Entity::INDEX_COLUMNS => [{$columns}]],";
            }
        }

        // Check if _indexes section exists - match with comment and preserve surrounding newlines
        if (preg_match('/(\n)(\s*\/\/ Indexes\s*\n\s*public const array _indexes = \[)(.*?)(];\s*\n)(\s*)/s', $content, $matches)) {
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
                if (preg_match('/(\n)}/', $content, $matches)) {
                    $content = str_replace($matches[1] . '}', "\n" . $indexesSection . '}', $content);
                }
            }
        }

        return $content;
    }

    /**
     * Update foreign keys in Entity file
     * Supports both simple and composite foreign keys with Entity class constants
     */
    private function updateForeignKeys(string $content, array $addForeignKeys, array $updateForeignKeys, ReflectionClass $reflection): string
    {
        // Get current table name and namespace to filter out self-references
        $currentTable = null;
        $namespace = null;
        if (preg_match("/public const string _table = '([^']+)'/", $content, $tableMatch)) {
            $currentTable = $tableMatch[1];
        }
        if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatch)) {
            $namespace = trim($nsMatch[1]);
        }

        // Filter out self-references
        $allForeignKeys = array_merge($addForeignKeys, $updateForeignKeys);
        $allForeignKeys = array_filter($allForeignKeys, function($fk) use ($currentTable) {
            $table = $fk['table'] ?? $fk['new_table'] ?? null;
            return $table !== null && $table !== $currentTable;
        });

        if (empty($allForeignKeys)) {
            // No foreign keys to add/update, but check if we need to remove empty section
            if (preg_match('/(public const array _foreign = \[)(.*?)(];)/s', $content, $matches)) {
                $foreignContent = trim($matches[2]);
                if (empty($foreignContent)) {
                    // Remove empty _foreign section
                    $pattern = '/(\n\s*)\/\/ Foreign keys\s*\n\s*public const array _foreign = \[\s*];\s*\n(\s*)/';
                    $content = preg_replace($pattern, '$1$2', $content);
                }
            }
            return $content;
        }

        // Collect referenced tables for use statements
        $referencedTables = [];
        foreach ($allForeignKeys as $fk) {
            $table = $fk['table'] ?? $fk['new_table'] ?? null;
            if ($table !== null) {
                $referencedTables[$table] = true;
            }
        }

        // Generate use statements for Entity classes
        $useStatements = [];
        foreach ($referencedTables as $table => $_) {
            $entityClassName = $this->tableToPascalCase($table);
            $entityAlias = "Entity{$entityClassName}";
            $fullEntityClassName = $namespace !== null ? "{$namespace}\\{$entityClassName}" : $entityClassName;

            // Check if Entity class exists
            if (class_exists($fullEntityClassName)) {
                $useStatements[$entityAlias] = $fullEntityClassName;
            }
        }

        // Add use statements if needed
        $content = $this->addEntityUseStatements($content, $useStatements);

        // Parse existing foreign keys (support both simple and composite, with Entity constants)
        $existingForeignKeys = $this->parseExistingForeignKeys($content);

        // Build normalized keys for comparison
        $updatedKeys = [];
        foreach ($allForeignKeys as $fk) {
            $columns = $fk['columns'] ?? [$fk['column'] ?? ''];
            $normalizedKey = $this->normalizeColumnKey(implode(',', $columns));
            $updatedKeys[$normalizedKey] = true;
        }

        // Check if _foreign section exists
        if (preg_match('/(public const array _foreign = \[)(.*?)(];)/s', $content, $matches)) {
            // Merge: keep existing that are not being updated, add/update new ones
            $finalEntries = [];

            // Keep existing foreign keys that are not being updated
            foreach ($existingForeignKeys as $colKey => $tableInfo) {
                $normalizedKey = $this->normalizeColumnKey($colKey);
                if (!isset($updatedKeys[$normalizedKey])) {
                    // Preserve existing format (may be Entity constant or string)
                    $finalEntries[] = $tableInfo['original_line'];
                }
            }

            // Add new/updated foreign keys
            foreach ($allForeignKeys as $fk) {
                $columns = $fk['columns'] ?? [$fk['column'] ?? ''];
                $table = $fk['table'] ?? $fk['new_table'] ?? null;

                if ($table === null) {
                    continue;
                }

                $entityClassName = $this->tableToPascalCase($table);
                $entityAlias = "Entity{$entityClassName}";

                // Generate foreign key entry
                if (count($columns) === 1) {
                    // Simple foreign key
                    $column = $columns[0];
                    $finalEntries[] = "        self::{$column} => {$entityAlias}::_table,";
                } else {
                    // Composite foreign key
                    $columnRefs = array_map(fn($col) => "self::{$col}", $columns);
                    $finalEntries[] = "        " . implode(' . \',\' . . ', $columnRefs) . " => {$entityAlias}::_table,";
                }
            }

            // Remove empty _foreign array if no entries
            if (empty($finalEntries)) {
                $pattern = '/(\n\s*)\/\/ Foreign keys\s*\n\s*public const array _foreign = \[\s*];\s*\n(\s*)/';
                $content = preg_replace($pattern, '$1$2', $content);
                return $content;
            }

            // Keep trailing comma on all entries (including last one)
            $new = "\n" . implode("\n", $finalEntries) . "\n    ";
            $content = str_replace($matches[0], $matches[1] . $new . $matches[3], $content);
        } else {
            // Add _foreign section
            $foreignEntries = [];
            foreach ($allForeignKeys as $fk) {
                $columns = $fk['columns'] ?? [$fk['column'] ?? ''];
                $table = $fk['table'] ?? $fk['new_table'] ?? null;

                if ($table === null) {
                    continue;
                }

                $entityClassName = $this->tableToPascalCase($table);
                $entityAlias = "Entity{$entityClassName}";

                // Generate foreign key entry
                if (count($columns) === 1) {
                    // Simple foreign key
                    $column = $columns[0];
                    $foreignEntries[] = "        self::{$column} => {$entityAlias}::_table,";
                } else {
                    // Composite foreign key
                    $columnRefs = array_map(fn($col) => "self::{$col}", $columns);
                    $foreignEntries[] = "        " . implode(' . \',\' . . ', $columnRefs) . " => {$entityAlias}::_table,";
                }
            }

            if (empty($foreignEntries)) {
                return $content;
            }

            // Keep trailing comma on all entries (including last one)
            $foreignSection = "    // Foreign keys\n    public const array _foreign = [\n" . implode("\n", $foreignEntries) . "\n    ];\n\n";

            // Insert after _types, before _indexes or properties
            if (preg_match('/(public const array _types = \[.*?];\n\n)/s', $content, $matches)) {
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
     * Parse existing foreign keys from Entity file content
     * Supports both simple and composite, with Entity constants or strings
     *
     * @param string $content Entity file content
     * @return array<string, array{original_line: string, table: string}> Parsed foreign keys
     */
    private function parseExistingForeignKeys(string $content): array
    {
        $foreignKeys = [];

        if (!preg_match('/(public const array _foreign = \[)(.*?)(];)/s', $content, $matches)) {
            return $foreignKeys;
        }

        // Extract namespace for Entity class loading
        $namespace = null;
        if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatch)) {
            $namespace = trim($nsMatch[1]);
        }

        $foreignContent = $matches[2];
        $lines = explode("\n", $foreignContent);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line === ',') {
                continue;
            }

            // Remove trailing comma
            $line = rtrim($line, ',');

            // Match composite first (more specific pattern): self::col1 . ',' . self::col2 => EntityTable::_table
            // Pattern: self::col1 . ',' . self::col2 => ... (or variations with quotes)
            if (preg_match('/(self::\w+(?:\s*\.\s*[\'",]\s*,\s*[\'"]\s*\.\s*\.\s*self::\w+)+)\s*=>\s*(.+)/', $line, $compositeMatch)) {
                $columnExpr = $compositeMatch[1];
                $tableValue = trim($compositeMatch[2]);

                // Extract column names from expression
                if (preg_match_all('/self::(\w+)/', $columnExpr, $colMatches)) {
                    $columns = $colMatches[1];
                    $colKey = implode(',', $columns);
                    $tableName = $this->extractTableNameFromValue($tableValue, $namespace);
                    if ($tableName !== null) {
                        $foreignKeys[$colKey] = [
                            'original_line' => "        {$line},",
                            'table' => $tableName,
                        ];
                    }
                }
                continue;
            }

            // Match simple: self::column => EntityTable::_table or self::column => 'table'
            if (preg_match('/self::(\w+)\s*=>\s*(.+)/', $line, $simpleMatch)) {
                $column = $simpleMatch[1];
                $tableValue = trim($simpleMatch[2]);
                $tableName = $this->extractTableNameFromValue($tableValue, $namespace);
                if ($tableName !== null) {
                    $foreignKeys[$column] = [
                        'original_line' => "        {$line},",
                        'table' => $tableName,
                    ];
                }
            }
        }

        return $foreignKeys;
    }

    /**
     * Extract table name from foreign key value
     * Handles both EntityClass::_table and string 'table'
     *
     * @param string $value Value from _foreign array
     * @param ?string $namespace Entity namespace (for loading Entity class)
     * @return ?string Table name
     */
    private function extractTableNameFromValue(string $value, ?string $namespace = null): ?string
    {
        // Check if it's EntityClass::_table
        if (preg_match('/Entity(\w+)::_table/', $value, $matches)) {
            $entityClassName = $matches[1];

            // Try to load Entity class and get table name
            if ($namespace !== null) {
                $fullClassName = "{$namespace}\\{$entityClassName}";
                if (class_exists($fullClassName)) {
                    $table = $fullClassName::_table ?? null;
                    if ($table !== null) {
                        return $table;
                    }
                }
            }

            // Fallback: convert PascalCase to table name
            return $this->pascalCaseToTableName($entityClassName);
        }

        // Check if it's quoted string 'table'
        if (preg_match("/^'([^']+)'$/", $value, $matches)) {
            return $matches[1];
        }

        // If it's just a string without quotes, use as-is
        return trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Add Entity use statements to file content
     *
     * @param string $content File content
     * @param array<string, string> $useStatements Map of alias => full class name
     * @return string Updated content
     */
    private function addEntityUseStatements(string $content, array $useStatements): string
    {
        if (empty($useStatements)) {
            return $content;
        }

        // Extract namespace
        $namespace = null;
        if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatch)) {
            $namespace = trim($nsMatch[1]);
        }

        // Check existing use statements
        $existingUses = [];
        if (preg_match_all('/use\s+([^;]+);/', $content, $useMatches)) {
            foreach ($useMatches[1] as $useLine) {
                $useLine = trim($useLine);
                // Check for "Class as Alias" format
                if (preg_match('/^(.+?)\s+as\s+(\w+)$/', $useLine, $asMatch)) {
                    $existingUses[trim($asMatch[2])] = trim($asMatch[1]);
                } else {
                    // Extract class name from full namespace
                    $parts = explode('\\', $useLine);
                    $className = end($parts);
                    $existingUses[$className] = $useLine;
                }
            }
        }

        // Add missing use statements
        $newUseStatements = [];
        foreach ($useStatements as $alias => $fullClassName) {
            // Check if already imported
            $alreadyImported = false;
            foreach ($existingUses as $existingAlias => $existingClass) {
                if ($existingAlias === $alias || $existingClass === $fullClassName) {
                    $alreadyImported = true;
                    break;
                }
            }

            if (!$alreadyImported) {
                $newUseStatements[] = "use {$fullClassName} as {$alias};";
            }
        }

        if (empty($newUseStatements)) {
            return $content;
        }

        // Find position to insert use statements (after existing use statements or after namespace)
        if (preg_match('/(use\s+[^;]+;\n)/', $content, $lastUseMatch, PREG_OFFSET_CAPTURE)) {
            // Insert after last use statement
            $insertPos = $lastUseMatch[0][1] + strlen($lastUseMatch[0][0]);
            $before = substr($content, 0, $insertPos);
            $after = substr($content, $insertPos);
            $content = $before . implode("\n", $newUseStatements) . "\n" . $after;
        } elseif (preg_match('/(namespace\s+[^;]+;\n\n)/', $content, $nsMatch)) {
            // Insert after namespace
            $content = str_replace($nsMatch[1], $nsMatch[1] . implode("\n", $newUseStatements) . "\n", $content);
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
     * Find EntityCollection files that reference deleted Entity classes
     *
     * @param array $deletedEntities Array of deleted Entity info (from findFilesToDelete)
     * @param ?string $entityCollectionDir EntityCollection directory (auto-detect if null)
     * @return array<string, array{class: string, file: string, entity_class: string}> EntityCollections to delete
     */
    private function findEntityCollectionsToDelete(array $deletedEntities, ?string &$entityCollectionDir = null): array
    {
        if (empty($deletedEntities)) {
            return [];
        }

        // Auto-detect EntityCollection directory if not provided
        if ($entityCollectionDir === null) {
            $cwd = getcwd();
            $possibleDirs = [
                $cwd . '/backend/Database/EntityCollection',
                $cwd . '/Database/EntityCollection',
                $cwd . '/EntityCollection',
                dirname($cwd) . '/backend/Database/EntityCollection',
                dirname($cwd) . '/Database/EntityCollection',
            ];

            $bootstrapDir = null;
            if (file_exists($cwd . '/backend/Bootstrap/cli.php')) {
                $bootstrapDir = $cwd . '/backend';
            } elseif (file_exists($cwd . '/Bootstrap/cli.php')) {
                $bootstrapDir = $cwd;
            }

            if ($bootstrapDir !== null) {
                $possibleDirs[] = $bootstrapDir . '/Database/EntityCollection';
            }

            foreach ($possibleDirs as $dir) {
                $realPath = realpath($dir);
                if ($realPath !== false && is_dir($realPath)) {
                    $entityCollectionDir = $realPath;
                    break;
                }
            }

            if ($entityCollectionDir === null) {
                return [];
            }
        }

        if (!is_dir($entityCollectionDir)) {
            return [];
        }

        $toDelete = [];
        $files = glob($entityCollectionDir . '/*.php');

        if ($files === false) {
            return $toDelete;
        }

        // Build set of deleted Entity class names for quick lookup
        $deletedEntityClasses = [];
        foreach ($deletedEntities as $tableName => $entityInfo) {
            $deletedEntityClasses[$entityInfo['class']] = true;
        }

        // Check each EntityCollection file
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            // Extract Entity class name from EntityCollection file
            // Look for use statement: use ...\Entity\ClassName as EntityClassName;
            if (preg_match_all('/^use\s+([^\s;]+)(?:\s+as\s+(\w+))?;/m', $content, $useMatches, PREG_SET_ORDER)) {
                foreach ($useMatches as $useMatch) {
                    $fullClassName = trim($useMatch[1]);
                    // Check that this is an Entity class, but not EntityCollection
                    if (strpos($fullClassName, '\\Entity\\') !== false &&
                        strpos($fullClassName, '\\EntityCollection\\') === false) {
                        // Check if this Entity class is in the deleted list
                        if (isset($deletedEntityClasses[$fullClassName])) {
                            // Extract EntityCollection class name
                            $className = $this->extractEntityCollectionClassName($file);
                            if ($className !== null) {
                                $toDelete[$fullClassName] = [
                                    'class' => $className,
                                    'file' => $file,
                                    'entity_class' => $fullClassName,
                                ];
                            }
                            break; // Found matching Entity, no need to check other use statements
                        }
                    }
                }
            }
        }

        return $toDelete;
    }

    /**
     * Delete EntityCollection files
     *
     * @param array $filesToDelete Array of EntityCollection info to delete
     * @return int Number of files deleted
     */
    private function deleteEntityCollectionFiles(array $filesToDelete): int
    {
        if (empty($filesToDelete)) {
            return 0;
        }

        $deleted = 0;
        foreach ($filesToDelete as $entityClassName => $collectionInfo) {
            try {
                $file = $collectionInfo['file'];
                if (file_exists($file)) {
                    unlink($file);
                    $deleted++;
                }
            } catch (\Throwable $e) {
                echo "⚠ Failed to delete EntityCollection file for {$collectionInfo['class']}: {$e->getMessage()}\n";
            }
        }

        return $deleted;
    }

    /**
     * Extract class name from EntityCollection file
     */
    private function extractEntityCollectionClassName(string $file): ?string
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        // Extract namespace
        $namespace = null;
        if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatch)) {
            $namespace = trim($nsMatch[1]);
        } else {
            return null;
        }

        // Remove comments and strings to avoid false matches
        $contentWithoutComments = preg_replace('/\/\/.*$/m', '', $content);
        $contentWithoutComments = preg_replace('/\/\*.*?\*\//s', '', $contentWithoutComments);
        $contentWithoutComments = preg_replace("/'[^']*'/", "''", $contentWithoutComments);
        $contentWithoutComments = preg_replace('/"[^"]*"/', '""', $contentWithoutComments);

        // Extract class name
        if (preg_match('/^\s*(?:final\s+)?class\s+(\w+)/m', $contentWithoutComments, $classMatch)) {
            $className = trim($classMatch[1]);
            return $namespace . '\\' . $className;
        }

        return null;
    }

    /**
     * Display EntityCollection fixes that will be applied
     *
     * @param array $fixes Fixes to apply
     * @param array $toCreate EntityCollections to create
     * @param bool $dryRun Dry run mode
     */
    private function displayEntityCollectionFixes(array $fixes, array $toCreate, bool $dryRun): void
    {
        if ($dryRun) {
            echo "\n[DRY RUN] The following EntityCollection changes would be made:\n\n";
        } else {
            echo "\nThe following EntityCollection changes will be made:\n\n";
        }

        if (!empty($fixes)) {
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "EntityCollection Files to Update:\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

            foreach ($fixes as $entityClassName => $collectionFixes) {
                $entityShortName = (new ReflectionClass($entityClassName))->getShortName();
                $collectionShortName = StringHelper::pluralize($entityShortName);
                echo "  {$collectionShortName}:\n";

                if (isset($collectionFixes['update_imports'])) {
                    echo "    - Update imports (Entity class)\n";
                }

                if (isset($collectionFixes['update_type_hints'])) {
                    $wrong = count($collectionFixes['update_type_hints']['wrong'] ?? []);
                    if ($wrong > 0) {
                        echo "    - Update type hints ({$wrong} method/methods)\n";
                    }
                }

                echo "\n";
            }
        }

        if (!empty($toCreate)) {
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "EntityCollection Files to Create:\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

            foreach ($toCreate as $entityClassName => $info) {
                // Get short name from reflection if available, otherwise extract from class name
                if (isset($info['reflection']) && $info['reflection'] !== null) {
                    $entityShortName = $info['reflection']->getShortName();
                } else {
                    $entityShortName = substr($entityClassName, strrpos($entityClassName, '\\') + 1);
                }
                $collectionShortName = StringHelper::pluralize($entityShortName);
                echo "  + {$collectionShortName}\n";
            }
            echo "\n";
        }
    }

    /**
     * Create EntityCollection files
     *
     * @param array $toCreate EntityCollections to create
     * @param ?string $entityCollectionDir EntityCollection directory
     * @param array $entities Loaded Entity classes
     * @return int Number of files created
     */
    private function createEntityCollectionFiles(array $toCreate, ?string $entityCollectionDir, array $entities): int
    {
        if (empty($toCreate)) {
            return 0;
        }

        // Auto-detect entity collection directory if not provided
        if ($entityCollectionDir === null) {
            $cwd = getcwd();
            $possibleDirs = [
                $cwd . '/backend/Database/EntityCollection',
                $cwd . '/Database/EntityCollection',
                $cwd . '/EntityCollection',
                dirname($cwd) . '/backend/Database/EntityCollection',
                dirname($cwd) . '/Database/EntityCollection',
            ];

            $bootstrapDir = null;
            if (file_exists($cwd . '/backend/Bootstrap/cli.php')) {
                $bootstrapDir = $cwd . '/backend';
            } elseif (file_exists($cwd . '/Bootstrap/cli.php')) {
                $bootstrapDir = $cwd;
            }

            if ($bootstrapDir !== null) {
                $possibleDirs[] = $bootstrapDir . '/Database/EntityCollection';
            }

            foreach ($possibleDirs as $dir) {
                $realPath = realpath($dir);
                if ($realPath !== false && is_dir($realPath)) {
                    $entityCollectionDir = $realPath;
                    break;
                }
            }

            // If directory doesn't exist, try to infer from Entity directory
            if ($entityCollectionDir === null && !empty($entities)) {
                $firstEntity = reset($entities);
                $entityFile = $firstEntity['file'];
                $entityDir = dirname($entityFile);
                $entityCollectionDir = str_replace('/Entity', '/EntityCollection', $entityDir);
            }
        }

        if ($entityCollectionDir === null) {
            echo "Error: Cannot determine EntityCollection directory\n";
            return 0;
        }

        // Extract namespace from first Entity class
        $namespace = null;
        if (!empty($entities)) {
            $firstEntity = reset($entities);
            $entityReflection = $firstEntity['reflection'];
            $entityNamespace = $entityReflection->getNamespaceName();
            $namespace = str_replace('\\Entity', '\\EntityCollection', $entityNamespace);
        }

        if ($namespace === null) {
            echo "Error: Cannot determine EntityCollection namespace\n";
            return 0;
        }

        $created = 0;
        foreach ($toCreate as $entityClassName => $info) {
            // Get reflection from info, or create it from entityClassName if not present
            $entityReflection = $info['reflection'] ?? null;
            if ($entityReflection === null) {
                try {
                    $entityReflection = new ReflectionClass($entityClassName);
                } catch (\Throwable $e) {
                    echo "⚠ Failed to create EntityCollection for {$entityClassName}: Cannot load Entity class\n";
                    continue;
                }
            }
            if ($this->createEntityCollectionFile($entityClassName, $entityCollectionDir, $namespace, $entityReflection)) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Convert table name to PascalCase class name
     */
    private function tableToPascalCase(string $tableName): string
    {
        return str_replace('_', '', ucwords($tableName, '_'));
    }

    /**
     * Normalize foreign keys from Entity file
     * Extracts table names from Entity constants (EntityTable::_table) or strings
     * Parses directly from file content to handle composite keys correctly
     *
     * @param array $entityForeign Foreign keys from Entity constant (for reference)
     * @param string $entityFile Entity file path (for parsing Entity class names)
     * @return array<string, string> Normalized foreign keys: 'column' or 'col1,col2' => 'table'
     */
    private function normalizeForeignKeys(array $entityForeign, string $entityFile): array
    {
        $normalized = [];
        $content = file_get_contents($entityFile);
        if ($content === false) {
            return [];
        }

        // Parse foreign keys directly from file content
        $parsedForeignKeys = $this->parseExistingForeignKeys($content);

        foreach ($parsedForeignKeys as $colKey => $tableInfo) {
            $normalizedKey = $this->normalizeColumnKey($colKey);
            $normalized[$normalizedKey] = $tableInfo['table'];
        }

        return $normalized;
    }

    /**
     * Convert PascalCase class name to table name (snake_case)
     */
    private function pascalCaseToTableName(string $pascalCase): string
    {
        // Insert underscore before uppercase letters (except first)
        $snake = preg_replace('/([a-z])([A-Z])/', '$1_$2', $pascalCase);
        return strtolower($snake);
    }

    /**
     * Parse column key (supports both simple and composite)
     *
     * @param string $colKey Column key: 'column' or 'col1,col2'
     * @return list<string> Array of column names
     */
    private function parseColumnKey(string $colKey): array
    {
        if (strpos($colKey, ',') !== false) {
            return array_map('trim', explode(',', $colKey));
        }
        return [trim($colKey)];
    }

    /**
     * Normalize column key for comparison
     * Sorts columns for composite keys to ensure consistent comparison
     *
     * @param string $colKey Column key: 'column' or 'col1,col2'
     * @return string Normalized key
     */
    private function normalizeColumnKey(string $colKey): string
    {
        $columns = $this->parseColumnKey($colKey);
        sort($columns);
        return implode(',', $columns);
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
