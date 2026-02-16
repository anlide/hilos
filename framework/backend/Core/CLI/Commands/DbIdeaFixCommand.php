<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\DbIdeaFixCommand\IdeaItemFixer;
use Hilos\Core\CLI\Commands\DbIdeaFixCommand\IdeaCollectionFixer;
use Hilos\Core\CLI\Commands\DbIdeaFixCommand\IdeaMainFixer;
use Hilos\Core\CLI\Commands\DbIdeaFixCommand\IdeaStorageFixer;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Utils\Helpers\StringHelper;
use ReflectionClass;
use RuntimeException;

/**
 * DbIdeaFixCommand - Fix Idea files to match Object files
 *
 * Automatically updates Idea class definitions to match Object structure.
 * Idea is isolated from Entity and works only with Object classes.
 * Adds missing properties, updates types, and maintains user-defined methods.
 */
class DbIdeaFixCommand implements CommandInterface
{
    use IdeaItemFixer;
    use IdeaCollectionFixer;
    use IdeaMainFixer;
    use IdeaStorageFixer;

    public function getName(): string
    {
        return CliCommands::DB_IDEA_FIX;
    }

    public function getDescription(): string
    {
        return 'Fix Idea files to match Object files';
    }

    public function getHelp(): string
    {
        return <<<HELP
Command: db:idea:fix

Description:
  Automatically update Idea class definitions to match Object structure.
  Idea is isolated from Entity and works only with Object classes.
  Adds missing properties, updates types, and preserves user-defined methods.
  Synchronizes Idea objects, IdeaCollections, IdeaStorage, and Idea.php.

Usage:
  php cli.php db:idea:fix [options]

Options:
  --idea-dir=<path>              Idea files directory (default: auto-detect)
  --idea-collection-dir=<path>    IdeaCollection files directory (default: auto-detect)
  --object-dir=<path>             Object files directory (default: auto-detect)
  --table=<name>                  Fix specific table only
  --dry-run                       Show what would be changed without modifying files
  --force-repair                  Attempt to repair broken Idea files

Examples:
  php cli.php db:idea:fix
  php cli.php db:idea:fix --table=user
  php cli.php db:idea:fix --dry-run
  php cli.php db:idea:fix --force-repair

Note:
  This command synchronizes 4 levels of Idea files:
  1. IdeaObject files (Idea/{Name}.php) - individual Idea objects with lazy loading
  2. IdeaCollection files (IdeaCollection/{Name}s.php) - Idea collections with filtering
  3. IdeaStorage.php - central storage for Object collections with lazy loading strategies
  4. Idea.php - access point that connects IdeaStorage with IdeaCollection classes
  
  Processing order: IdeaObject → IdeaCollection → IdeaStorage → Idea.php
  Each level depends on the previous one, so errors in earlier stages skip later stages.
HELP;
    }

    /**
     * Execute the command to fix Idea files (IdeaObject, IdeaCollection, IdeaStorage, Idea.php)
     *
     * The command works in 5 stages:
     * 1. Load all data (estimate phase): Loads Object, IdeaObject, ObjectCollection, IdeaCollection,
     *    IdeaStorage, and Idea.php files. Displays broken files and syntax errors.
     * 2. Prepare fixes for all 4 blocks: Compares loaded files and prepares fixes for:
     *    - IdeaObject files: Updates __get(), toArray(), PHPDoc to match Object properties
     *    - IdeaCollection files: Updates objectToIdea() method and imports to match ObjectCollection
     *    - IdeaStorage.php: Updates properties, init(), initAgain(), reloadCollection() methods
     *    - Idea.php: Updates constants and init() method with setRepresent() calls
     * 3. Display planned changes: Shows what will be changed for each block (if dry-run, stops here)
     * 4. Apply fixes: Applies changes to all 4 blocks in order
     * 5. Summary: Displays summary of all changes made
     *
     * Processing order and dependencies:
     * - IdeaObject files are processed first (depends on Object classes)
     * - IdeaCollection files are processed second (depends on ObjectCollection classes)
     * - IdeaStorage.php is processed third (depends on ObjectCollection classes)
     * - Idea.php is processed last (depends on IdeaStorage and IdeaCollection classes)
     *
     * If there are errors in Object/IdeaObject files, IdeaCollection/IdeaStorage/Idea.php
     * processing is skipped to prevent cascading errors.
     *
     * @param array $options Command options:
     *   - 'table' (string|null): Fix specific table only (filters by Entity table name)
     *   - 'idea-dir' (string|null): IdeaObject files directory (auto-detect if null)
     *   - 'idea-collection-dir' (string|null): IdeaCollection files directory (auto-detect if null)
     *   - 'object-dir' (string|null): Object and ObjectCollection files directory (auto-detect if null)
     *   - 'dry-run' (bool): Show what would be changed without modifying files
     *   - 'force-repair' (bool): Attempt to repair broken Idea files (currently not used)
     * @param array $args Command arguments (not used)
     * @return int Exit code (ExitCode::SUCCESS on success)
     * @throws RuntimeException If critical errors occur during processing
     */
    public function execute(array $options, array $args): int
    {
        echo "\n=== Fix Idea Files ===\n\n";

        // Parse arguments from options
        $tableName = $options['table'] ?? null;
        $ideaDir = $options['idea-dir'] ?? null;
        $ideaCollectionDir = $options['idea-collection-dir'] ?? null;
        $objectDir = $options['object-dir'] ?? null;
        $dryRun = isset($options['dry-run']);
        $forceRepair = isset($options['force-repair']);

        // ============================================
        // STEP 1: Load all data (estimate phase)
        // ============================================
        
        $syntaxErrors = 0;
        $brokenObjects = [];
        $objects = $this->loadObjects($objectDir, $syntaxErrors, $brokenObjects);

        if (empty($objects)) {
            echo "⚠ No Object classes found. Please run 'db:object:fix' first to create Object classes.\n\n";
            return ExitCode::SUCCESS;
        }

        // Load IdeaItem files
        $brokenIdeaItems = [];
        $ideaItems = $this->loadIdeaItems($ideaDir, $syntaxErrors, $brokenIdeaItems);

        // Load ObjectCollection classes
        $brokenObjectCollections = [];
        $objectCollections = [];
        if (empty($brokenObjects) && empty($brokenIdeaItems) && $syntaxErrors === 0) {
            $objectCollections = $this->loadObjectCollections($objectDir, $syntaxErrors, $brokenObjectCollections);
        }

        // Load IdeaCollection files
        $brokenIdeaCollections = [];
        $ideaCollections = [];
        if (empty($brokenObjects) && empty($brokenIdeaItems) && $syntaxErrors === 0) {
            $ideaCollections = $this->loadIdeaCollections($ideaCollectionDir, $syntaxErrors, $brokenIdeaCollections);
        }

        // Load IdeaStorage file
        $ideaStorageFile = null;
        $ideaStorage = null;
        $brokenIdeaStorage = null;
        if (empty($brokenObjects) && empty($brokenIdeaItems) && $syntaxErrors === 0) {
            // Auto-detect IdeaStorage file
            $cwd = getcwd();
            $possibleFiles = [
                $cwd . '/backend/Database/IdeaStorage.php',
                $cwd . '/Database/IdeaStorage.php',
                $cwd . '/IdeaStorage.php',
            ];
            foreach ($possibleFiles as $file) {
                if (file_exists($file)) {
                    $ideaStorageFile = $file;
                    break;
                }
            }
            if ($ideaStorageFile !== null) {
                try {
                    $ideaStorage = $this->loadIdeaStorage($ideaStorageFile);
                } catch (\Throwable $e) {
                    $brokenIdeaStorage = [
                        'file' => $ideaStorageFile,
                        'reason' => $e->getMessage(),
                    ];
                }
            }
        }

        // Load Idea.php file
        $ideaFile = null;
        $ideaMain = null;
        $brokenIdeaMain = null;
        if (empty($brokenObjects) && empty($brokenIdeaItems) && $syntaxErrors === 0) {
            // Auto-detect Idea.php file
            $cwd = getcwd();
            $possibleFiles = [
                $cwd . '/backend/Database/Idea.php',
                $cwd . '/Database/Idea.php',
                $cwd . '/Idea.php',
            ];
            foreach ($possibleFiles as $file) {
                if (file_exists($file)) {
                    $ideaFile = $file;
                    break;
                }
            }
            if ($ideaFile !== null) {
                try {
                    $ideaMain = $this->loadIdeaMain($ideaFile);
                } catch (\Throwable $e) {
                    $brokenIdeaMain = [
                        'file' => $ideaFile,
                        'reason' => $e->getMessage(),
                    ];
                }
            }
        }

        // Display information about broken files
        if (!empty($brokenObjects)) {
            echo "\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "⚠ Damaged Object files (Idea files will not be modified):\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "\n";
            foreach ($brokenObjects as $file => $reason) {
                echo "  - {$file}\n";
                echo "    Reason: {$reason}\n";
                echo "\n";
            }
        }

        if (!empty($brokenIdeaItems)) {
            echo "\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "⚠ Damaged IdeaItem files:\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "\n";
            foreach ($brokenIdeaItems as $file => $reason) {
                echo "  - {$file}\n";
                echo "    Reason: {$reason}\n";
                echo "\n";
            }
        }

        if ($brokenIdeaStorage !== null) {
            echo "\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "⚠ Damaged IdeaStorage file:\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "\n";
            echo "  - {$brokenIdeaStorage['file']}\n";
            echo "    Reason: {$brokenIdeaStorage['reason']}\n";
            echo "\n";
        }

        if ($brokenIdeaMain !== null) {
            echo "\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "⚠ Damaged Idea.php file:\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "\n";
            echo "  - {$brokenIdeaMain['file']}\n";
            echo "    Reason: {$brokenIdeaMain['reason']}\n";
            echo "\n";
        }

        if ($syntaxErrors > 0) {
            echo "\n⚠ {$syntaxErrors} file(s) contain syntax errors and were skipped.\n";
        }

        // If IdeaStorage or Idea.php are broken, terminate with error
        if ($brokenIdeaStorage !== null || $brokenIdeaMain !== null) {
            throw new RuntimeException('Cannot proceed: IdeaStorage or Idea.php files are damaged. Please fix them first.');
        }

        // ============================================
        // STEP 2: Prepare fixes for all 4 blocks (estimate)
        // ============================================

        // Prepare fixes for IdeaItems
        $ideaItemFixes = $this->prepareIdeaItemFixes($objects, $ideaItems, $tableName, $brokenIdeaItems);
        $ideaItemsToCreate = $this->findIdeaItemsToCreate($objects, $ideaItems, $tableName, $brokenObjects);
        $ideaItemsToDelete = $this->findIdeaItemsToDelete($objects, $ideaItems, $ideaDir);

        // Prepare fixes for IdeaCollections (only if no errors)
        $ideaCollectionFixes = [];
        $ideaCollectionsToCreate = [];
        $ideaCollectionsToDelete = [];
        if (empty($brokenObjects) && empty($brokenIdeaItems) && $syntaxErrors === 0) {
            $ideaCollectionFixes = $this->prepareIdeaCollectionFixes($objectCollections, $ideaCollections, $tableName);
            $ideaCollectionsToCreate = $this->findIdeaCollectionsToCreate($objectCollections, $ideaCollections, $tableName, $brokenObjectCollections);
            
            // Also find IdeaCollection files to create for new idea items (from ideaItemsToCreate)
            if (!empty($ideaItemsToCreate)) {
                $ideaCollectionsToCreateForNew = $this->findIdeaCollectionsToCreateForNewIdeaItemsEstimate($ideaItemsToCreate, $objectCollections, $ideaCollections, $brokenObjectCollections);
                // Merge with existing list
                foreach ($ideaCollectionsToCreateForNew as $objectCollectionClassName => $collectionInfo) {
                    if (!isset($ideaCollectionsToCreate[$objectCollectionClassName])) {
                        $ideaCollectionsToCreate[$objectCollectionClassName] = $collectionInfo;
                    }
                }
            }
            
            $ideaCollectionsToDelete = $this->findIdeaCollectionsToDelete($objectCollections, $ideaCollections);
        }

        // Prepare fixes for IdeaStorage (only if no errors)
        $ideaStorageFixes = [];
        if (empty($brokenObjects) && empty($brokenIdeaItems) && $syntaxErrors === 0) {
            $ideaStorageFixes = $this->prepareIdeaStorageFixes($objectCollections, $ideaStorage);
        }

        // Prepare fixes for Idea.php (only if no errors)
        $ideaMainFixes = [];
        if (empty($brokenObjects) && empty($brokenIdeaItems) && $syntaxErrors === 0) {
            $ideaMainFixes = $this->prepareIdeaMainFixes($objectCollections, $ideaMain);
        }

        // ============================================
        // STEP 3: Display planned changes (estimate)
        // ============================================

        $hasAnyChanges = !empty($ideaItemFixes) || !empty($ideaItemsToCreate) || !empty($ideaItemsToDelete) ||
                         !empty($ideaCollectionFixes) || !empty($ideaCollectionsToCreate) || !empty($ideaCollectionsToDelete) ||
                         !empty($ideaStorageFixes) || !empty($ideaMainFixes);

        if (!$hasAnyChanges) {
            if ($syntaxErrors > 0 || !empty($brokenObjects) || !empty($brokenIdeaItems)) {
                echo "\n";
            } else {
                echo "✓ No fixes needed! All Idea files match Object classes.\n\n";
            }
        } else {
            // Display planned changes (estimate)
            if (!empty($ideaItemFixes) || !empty($ideaItemsToCreate) || !empty($ideaItemsToDelete)) {
                $this->displayIdeaItemFixes($ideaItemFixes, $ideaItemsToCreate, $ideaItemsToDelete, $dryRun);
            }

            if (!empty($ideaCollectionFixes) || !empty($ideaCollectionsToCreate) || !empty($ideaCollectionsToDelete)) {
                $this->displayIdeaCollectionFixes($ideaCollectionFixes, $ideaCollectionsToCreate, $ideaCollectionsToDelete, $dryRun);
            }

            if (!empty($ideaStorageFixes)) {
                $this->displayIdeaStorageFixes($ideaStorageFixes, $dryRun);
            }

            if (!empty($ideaMainFixes)) {
                $this->displayIdeaMainFixes($ideaMainFixes, $dryRun);
            }
        }

        if ($dryRun) {
            echo "\n[DRY RUN] No files were modified.\n\n";
            return ExitCode::SUCCESS;
        }

        // ============================================
        // STEP 4: Apply fixes (implementation)
        // ============================================

        $appliedObjects = 0;
        $createdObjects = 0;
        $deletedObjects = 0;
        $appliedCollections = 0;
        $createdCollections = 0;
        $appliedStorage = 0;
        $appliedMain = 0;

        // Apply IdeaItem fixes
        if (!empty($ideaItemFixes) || !empty($ideaItemsToCreate) || !empty($ideaItemsToDelete)) {
            $appliedObjects = $this->applyIdeaItemFixes($ideaItemFixes, $ideaItems, $objects);
            $createdObjects = $this->createIdeaItemFiles($ideaItemsToCreate, $ideaDir, $objects);
            $deletedObjects = $this->deleteIdeaItemFiles($ideaItemsToDelete);
        }

        // After creating new IdeaItem files, find IdeaCollections to create for them
        if ($createdObjects > 0 && !empty($ideaItemsToCreate) && empty($brokenObjects) && empty($brokenIdeaItems) && $syntaxErrors === 0) {
            // Reload ideaItems to include newly created ones
            $syntaxErrorsAfter = 0;
            $brokenIdeaItemsAfter = [];
            $ideaItemsAfter = $this->loadIdeaItems($ideaDir, $syntaxErrorsAfter, $brokenIdeaItemsAfter);
            
            // Reload objectCollections to ensure we have latest data
            $brokenObjectCollectionsAfter = [];
            $objectCollectionsAfter = [];
            if (empty($brokenObjects) && empty($brokenIdeaItemsAfter) && $syntaxErrorsAfter === 0) {
                $objectCollectionsAfter = $this->loadObjectCollections($objectDir, $syntaxErrorsAfter, $brokenObjectCollectionsAfter);
            }
            
            // Find IdeaCollections for newly created IdeaItem files
            $newlyCreatedIdeaCollections = $this->findIdeaCollectionsToCreateForNewIdeaItems($ideaItemsToCreate, $ideaItemsAfter, $objectCollectionsAfter, $ideaCollections, $brokenIdeaItemsAfter, $brokenObjectCollectionsAfter);
            
            // Merge with existing list
            foreach ($newlyCreatedIdeaCollections as $ideaItemClassName => $collectionInfo) {
                if (!isset($ideaCollectionsToCreate[$ideaItemClassName])) {
                    $ideaCollectionsToCreate[$ideaItemClassName] = $collectionInfo;
                }
            }
        }

        // Apply IdeaCollection fixes (only if no errors)
        if (empty($brokenObjects) && empty($brokenIdeaItems) && $syntaxErrors === 0) {
            if (!empty($ideaCollectionFixes) || !empty($ideaCollectionsToCreate)) {
                $appliedCollections = $this->applyIdeaCollectionFixes($ideaCollectionFixes, $ideaCollections, $objectCollections);
                $createdCollections = $this->createIdeaCollectionFiles($ideaCollectionsToCreate, $ideaCollectionDir, $objectCollections);
            }

            // Apply IdeaStorage fixes
            if (!empty($ideaStorageFixes) && $ideaStorageFile !== null) {
                if ($this->applyIdeaStorageFixes($ideaStorageFixes, $ideaStorageFile)) {
                    $appliedStorage = 1;
                }
            }

            // Apply Idea.php fixes
            if (!empty($ideaMainFixes) && $ideaFile !== null) {
                if ($this->applyIdeaMainFixes($ideaMainFixes, $ideaFile)) {
                    $appliedMain = 1;
                }
            }
        }

        // ============================================
        // STEP 5: Summary
        // ============================================

        $totalChanges = $appliedObjects + $createdObjects + $deletedObjects + $appliedCollections + $createdCollections + $appliedStorage + $appliedMain;
        if ($totalChanges > 0) {
            echo "\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "Summary:\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "\n";

            $objectChanges = $appliedObjects + $createdObjects + $deletedObjects;
            if ($objectChanges > 0) {
                echo "IdeaItem files:\n";
                if ($appliedObjects > 0) {
                    echo "  ✓ Updated {$appliedObjects} file(s)\n";
                }
                if ($createdObjects > 0) {
                    echo "  ✓ Created {$createdObjects} file(s)\n";
                }
                if ($deletedObjects > 0) {
                    echo "  ✓ Deleted {$deletedObjects} file(s)\n";
                }
                echo "\n";
            }

            $collectionChanges = $appliedCollections + $createdCollections;
            if ($collectionChanges > 0) {
                echo "IdeaCollection files:\n";
                if ($appliedCollections > 0) {
                    echo "  ✓ Updated {$appliedCollections} file(s)\n";
                }
                if ($createdCollections > 0) {
                    echo "  ✓ Created {$createdCollections} file(s)\n";
                }
                echo "\n";
            }

            if ($appliedStorage > 0) {
                echo "IdeaStorage file:\n";
                echo "  ✓ Updated 1 file\n";
                echo "\n";
            }

            if ($appliedMain > 0) {
                echo "Idea.php file:\n";
                echo "  ✓ Updated 1 file\n";
                echo "\n";
            }
        } elseif (empty($brokenObjects) && $syntaxErrors === 0 && empty($brokenIdeaItems)) {
            // No changes at all and no errors
            echo "\n✓ All files are up to date. No changes needed.\n\n";
        }

        return ExitCode::SUCCESS;
    }

    /**
     * Load Object classes from directory
     *
     * @param ?string $objectDir Object directory
     * @param int $syntaxErrors Reference to syntax error counter
     * @param array $brokenObjects Reference to broken files array
     * @return array<string, array{class: string, file: string, reflection: ReflectionClass}> Loaded Object classes
     */
    private function loadObjects(?string $objectDir, int &$syntaxErrors = 0, array &$brokenObjects = []): array
    {
        // Auto-detect if not provided
        if ($objectDir === null) {
            $cwd = getcwd();
            $possibleDirs = [
                $cwd . '/backend/Database/Object',
                $cwd . '/Database/Object',
                $cwd . '/Object',
                dirname($cwd) . '/backend/Database/Object',
                dirname($cwd) . '/Database/Object',
            ];

            $bootstrapDir = null;
            if (file_exists($cwd . '/backend/Bootstrap/cli.php')) {
                $bootstrapDir = $cwd . '/backend';
            } elseif (file_exists($cwd . '/Bootstrap/cli.php')) {
                $bootstrapDir = $cwd;
            }

            if ($bootstrapDir !== null) {
                $possibleDirs[] = $bootstrapDir . '/Database/Object';
            }

            foreach ($possibleDirs as $dir) {
                $realPath = realpath($dir);
                if ($realPath !== false && is_dir($realPath)) {
                    $objectDir = $realPath;
                    break;
                }
            }

            if ($objectDir === null) {
                return [];
            }
        }

        if (!is_dir($objectDir)) {
            return [];
        }

        $objects = [];
        $files = glob($objectDir . '/*.php');

        if ($files === false) {
            return $objects;
        }

        foreach ($files as $file) {
            $className = $this->extractClassNameFromObjectFile($file);
            if ($className === null) {
                $brokenObjects[$file] = 'Cannot extract class name from file';
                continue;
            }

            try {
                // Check PHP syntax
                $output = [];
                $returnVar = 0;
                exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $returnVar);
                if ($returnVar !== 0) {
                    $errorMessage = implode(' ', $output);
                    $brokenObjects[$file] = "PHP syntax error: {$errorMessage}";
                    $syntaxErrors++;
                    continue;
                }

                if (!class_exists($className)) {
                    require_once $file;
                }

                $reflection = new ReflectionClass($className);
                if (!$reflection->isSubclassOf(Object_::class)) {
                    continue;
                }

                $objects[$className] = [
                    'class' => $className,
                    'file' => $file,
                    'reflection' => $reflection,
                ];
            } catch (\Throwable $e) {
                $brokenObjects[$file] = $e->getMessage();
                continue;
            }
        }

        return $objects;
    }

    /**
     * Extract class name from Object file
     */
    private function extractClassNameFromObjectFile(string $file): ?string
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

        // Extract class name - look for class declaration
        // Match: "final class Name" or "class Name" followed by extends/implements or {
        // This avoids matching "class" in comments or strings
        // Use multiline mode and look for class declaration that's not in a comment
        if (preg_match('/^\s*(?:final\s+)?class\s+(\w+)(?:\s+(?:extends|implements)|\s*\{)/m', $content, $classMatch)) {
            $className = trim($classMatch[1]);
            return $namespace . '\\' . $className;
        }

        return null;
    }

    /**
     * Find IdeaItem files that need to be created
     *
     * @param array $objects Loaded Object classes
     * @param array $ideaItems Loaded IdeaItem files
     * @param ?string $tableFilter Table name filter
     * @param array $brokenObjects Broken Object files
     * @return array<string, array{object_class: string, reflection: ReflectionClass}> IdeaItems to create
     */
    private function findIdeaItemsToCreate(array $objects, array $ideaItems, ?string $tableFilter, array $brokenObjects): array
    {
        $toCreate = [];

        foreach ($objects as $objectClassName => $objectInfo) {
            // Skip broken objects
            if (isset($brokenObjects[$objectInfo['file']])) {
                continue;
            }

            // Check if IdeaItem already exists
            if (isset($ideaItems[$objectClassName])) {
                continue;
            }

            // Get table name from Entity to apply filter
            $objectReflection = $objectInfo['reflection'];
            $entityProperty = $objectReflection->getProperty('entity');
            $entityType = $entityProperty->getType();
            if ($entityType === null || !method_exists($entityType, 'getName')) {
                continue;
            }

            $entityClassName = $entityType->getName();
            if (!class_exists($entityClassName)) {
                continue;
            }

            $entityReflection = new ReflectionClass($entityClassName);
            $table = $entityReflection->getConstant('_table');
            if ($table === false) {
                continue;
            }

            // Apply table filter if specified
            if ($tableFilter !== null && $table !== $tableFilter) {
                continue;
            }

            $toCreate[$objectClassName] = [
                'object_class' => $objectClassName,
                'reflection' => $objectReflection,
            ];
        }

        return $toCreate;
    }

    /**
     * Display IdeaItem fixes that will be applied
     *
     * @param array $fixes Fixes to apply
     * @param array $toCreate IdeaItems to create
     * @param bool $dryRun Dry run mode
     */
    private function displayIdeaItemFixes(array $fixes, array $toCreate, array $toDelete, bool $dryRun): void
    {
        if (!empty($fixes)) {
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo ($dryRun ? "[DRY RUN] " : "") . "Idea Files to Update:\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

            foreach ($fixes as $objectClassName => $ideaFixes) {
                $objectShortName = (new ReflectionClass($objectClassName))->getShortName();
                echo "  {$objectShortName}:\n";

                if (isset($ideaFixes['update_getter'])) {
                    $missing = count($ideaFixes['update_getter']['missing'] ?? []);
                    $extra = count($ideaFixes['update_getter']['extra'] ?? []);
                    if ($missing > 0 || $extra > 0) {
                        echo "    - Update __get() method";
                        if ($missing > 0) {
                            $word = $missing === 1 ? 'property' : 'properties';
                            echo " (add {$missing} {$word})";
                        }
                        if ($extra > 0) {
                            $word = $extra === 1 ? 'property' : 'properties';
                            echo " (remove {$extra} {$word})";
                        }
                        echo "\n";
                    }
                }

                if (isset($ideaFixes['update_toarray'])) {
                    $missing = count($ideaFixes['update_toarray']['missing'] ?? []);
                    $extra = count($ideaFixes['update_toarray']['extra'] ?? []);
                    if ($missing > 0 || $extra > 0) {
                        echo "    - Update toArray() method";
                        if ($missing > 0) {
                            $word = $missing === 1 ? 'property' : 'properties';
                            echo " (add {$missing} {$word})";
                        }
                        if ($extra > 0) {
                            $word = $extra === 1 ? 'property' : 'properties';
                            echo " (remove {$extra} {$word})";
                        }
                        echo "\n";
                    }
                }

                if (isset($ideaFixes['update_phpdoc'])) {
                    $missing = count($ideaFixes['update_phpdoc']['missing'] ?? []);
                    $extra = count($ideaFixes['update_phpdoc']['extra'] ?? []);
                    $typeChanged = count($ideaFixes['update_phpdoc']['type_changed'] ?? []);
                    if ($missing > 0 || $extra > 0 || $typeChanged > 0) {
                        echo "    - Update PHPDoc @property-read";
                        if ($missing > 0) {
                            $word = $missing === 1 ? 'property' : 'properties';
                            echo " (add {$missing} {$word})";
                        }
                        if ($extra > 0) {
                            $word = $extra === 1 ? 'property' : 'properties';
                            echo " (remove {$extra} {$word})";
                        }
                        if ($typeChanged > 0) {
                            $word = $typeChanged === 1 ? 'property' : 'properties';
                            echo " (update type for {$typeChanged} {$word})";
                        }
                        echo "\n";
                    }
                }

                if (isset($ideaFixes['update_use_statements'])) {
                    echo "    - Update use statements\n";
                }

                echo "\n";
            }
        }

        if (!empty($toCreate)) {
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo ($dryRun ? "[DRY RUN] " : "") . "Idea Files to Create:\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

            foreach ($toCreate as $objectClassName => $info) {
                $objectShortName = (new ReflectionClass($objectClassName))->getShortName();
                echo "  + {$objectShortName}\n";
            }
            echo "\n";
        }

        if (!empty($toDelete)) {
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo ($dryRun ? "[DRY RUN] " : "") . "Idea Files to Delete:\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

            foreach ($toDelete as $objectClassName => $ideaItemInfo) {
                $ideaItemShortName = (new \ReflectionClass($ideaItemInfo['class']))->getShortName();
                echo "  - {$ideaItemShortName} (Object class {$objectClassName} no longer exists)\n";
            }
            echo "\n";
        }
    }

    /**
     * Create IdeaItem files
     *
     * @param array $toCreate IdeaItems to create
     * @param ?string $ideaDir Idea directory
     * @param array $objects Loaded Object classes
     * @return int Number of files created
     */
    private function createIdeaItemFiles(array $toCreate, ?string $ideaDir, array $objects): int
    {
        if (empty($toCreate)) {
            return 0;
        }

        // Auto-detect idea directory if not provided
        if ($ideaDir === null) {
            $cwd = getcwd();
            $possibleDirs = [
                $cwd . '/backend/Database/Idea',
                $cwd . '/Database/Idea',
                $cwd . '/Idea',
                dirname($cwd) . '/backend/Database/Idea',
                dirname($cwd) . '/Database/Idea',
            ];

            $bootstrapDir = null;
            if (file_exists($cwd . '/backend/Bootstrap/cli.php')) {
                $bootstrapDir = $cwd . '/backend';
            } elseif (file_exists($cwd . '/Bootstrap/cli.php')) {
                $bootstrapDir = $cwd;
            }

            if ($bootstrapDir !== null) {
                $possibleDirs[] = $bootstrapDir . '/Database/Idea';
            }

            foreach ($possibleDirs as $dir) {
                $realPath = realpath($dir);
                if ($realPath !== false && is_dir($realPath)) {
                    $ideaDir = $realPath;
                    break;
                }
            }

            // If directory doesn't exist, try to infer from Object directory
            if ($ideaDir === null && !empty($objects)) {
                $firstObject = reset($objects);
                $objectFile = $firstObject['file'];
                $objectDir = dirname($objectFile);
                $ideaDir = str_replace('/Object', '/Idea', $objectDir);
            }
        }

        if ($ideaDir === null) {
            echo "Error: Cannot determine Idea directory\n";
            return 0;
        }

        // Extract namespace from first Object class
        $namespace = null;
        if (!empty($objects)) {
            $firstObject = reset($objects);
            $objectReflection = $firstObject['reflection'];
            $objectNamespace = $objectReflection->getNamespaceName();
            $namespace = str_replace('\\Object', '\\Idea', $objectNamespace);
        }

        if ($namespace === null) {
            echo "Error: Cannot determine Idea namespace\n";
            return 0;
        }

        $created = 0;
        foreach ($toCreate as $objectClassName => $info) {
            $objectReflection = $info['reflection'];
            if ($this->createIdeaItemFile($objectClassName, $ideaDir, $namespace, $objectReflection)) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Load ObjectCollection classes from directory
     *
     * @param ?string $objectDir Object directory (ObjectCollection is usually in ObjectCollection subdirectory)
     * @param int $syntaxErrors Reference to syntax error counter
     * @param array $brokenObjectCollections Reference to broken files array
     * @return array<string, array{class: string, file: string, reflection: ReflectionClass}> Loaded ObjectCollection classes
     */
    private function loadObjectCollections(?string $objectDir, int &$syntaxErrors = 0, array &$brokenObjectCollections = []): array
    {
        // Auto-detect ObjectCollection directory
        if ($objectDir === null) {
            $cwd = getcwd();
            $possibleDirs = [
                $cwd . '/backend/Database/ObjectCollection',
                $cwd . '/Database/ObjectCollection',
                $cwd . '/ObjectCollection',
                dirname($cwd) . '/backend/Database/ObjectCollection',
                dirname($cwd) . '/Database/ObjectCollection',
            ];

            $bootstrapDir = null;
            if (file_exists($cwd . '/backend/Bootstrap/cli.php')) {
                $bootstrapDir = $cwd . '/backend';
            } elseif (file_exists($cwd . '/Bootstrap/cli.php')) {
                $bootstrapDir = $cwd;
            }

            if ($bootstrapDir !== null) {
                $possibleDirs[] = $bootstrapDir . '/Database/ObjectCollection';
            }

            foreach ($possibleDirs as $dir) {
                $realPath = realpath($dir);
                if ($realPath !== false && is_dir($realPath)) {
                    $objectDir = $realPath;
                    break;
                }
            }

            if ($objectDir === null) {
                return [];
            }
        } else {
            // If objectDir is provided, try ObjectCollection subdirectory
            $objectCollectionDir = $objectDir . '/../ObjectCollection';
            $realPath = realpath($objectCollectionDir);
            if ($realPath !== false && is_dir($realPath)) {
                $objectDir = $realPath;
            } else {
                return [];
            }
        }

        if (!is_dir($objectDir)) {
            return [];
        }

        $objectCollections = [];
        $files = glob($objectDir . '/*.php');

        if ($files === false) {
            return $objectCollections;
        }

        foreach ($files as $file) {
            $className = $this->extractClassNameFromObjectFile($file);
            if ($className === null) {
                $brokenObjectCollections[$file] = 'Cannot extract class name from file';
                continue;
            }

            try {
                // Check PHP syntax
                $output = [];
                $returnVar = 0;
                exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $returnVar);
                if ($returnVar !== 0) {
                    $errorMessage = implode(' ', $output);
                    $brokenObjectCollections[$file] = "PHP syntax error: {$errorMessage}";
                    $syntaxErrors++;
                    continue;
                }

                if (!class_exists($className)) {
                    require_once $file;
                }

                $reflection = new ReflectionClass($className);
                if (!$reflection->isSubclassOf(Objects::class)) {
                    continue;
                }

                $objectCollections[$className] = [
                    'class' => $className,
                    'file' => $file,
                    'reflection' => $reflection,
                ];
            } catch (\Throwable $e) {
                $brokenObjectCollections[$file] = $e->getMessage();
                continue;
            }
        }

        return $objectCollections;
    }

    /**
     * Find IdeaCollection files that need to be created
     *
     * @param array $objectCollections Loaded ObjectCollection classes
     * @param array $ideaCollections Loaded IdeaCollection files
     * @param ?string $tableFilter Table name filter
     * @param array $brokenObjectCollections Broken ObjectCollection files
     * @return array<string, array{object_collection_class: string, reflection: ReflectionClass}> IdeaCollections to create
     */
    private function findIdeaCollectionsToCreate(array $objectCollections, array $ideaCollections, ?string $tableFilter, array $brokenObjectCollections): array
    {
        $toCreate = [];

        foreach ($objectCollections as $objectCollectionClassName => $objectCollectionInfo) {
            // Skip broken object collections
            if (isset($brokenObjectCollections[$objectCollectionInfo['file']])) {
                continue;
            }

            // Check if IdeaCollection already exists
            if (isset($ideaCollections[$objectCollectionClassName])) {
                continue;
            }

            // Get table name from Entity to apply filter
            $objectCollectionReflection = $objectCollectionInfo['reflection'];
            $entityClassName = $this->extractEntityClassNameFromObjectCollection($objectCollectionReflection);
            if ($entityClassName === null || !class_exists($entityClassName)) {
                continue;
            }

            $entityReflection = new ReflectionClass($entityClassName);
            $table = $entityReflection->getConstant('_table');
            if ($table === false) {
                continue;
            }

            // Apply table filter if specified
            if ($tableFilter !== null && $table !== $tableFilter) {
                continue;
            }

            $toCreate[$objectCollectionClassName] = [
                'object_collection_class' => $objectCollectionClassName,
                'reflection' => $objectCollectionReflection,
            ];
        }

        return $toCreate;
    }

    /**
     * Find IdeaCollection files to create for newly created IdeaItem files
     *
     * @param array $ideaItemsToCreate Array of object class names => object info for newly created idea items
     * @param array $ideaItemsAfter Loaded IdeaItem classes after creation (includes new ones)
     * @param array $objectCollectionsAfter Loaded ObjectCollection classes after creation
     * @param array $ideaCollections Loaded IdeaCollection files
     * @param array $brokenIdeaItems Broken IdeaItem files
     * @param array $brokenObjectCollections Broken ObjectCollection files
     * @return array<string, array{object_collection_class: string, reflection: ReflectionClass}> IdeaCollections to create
     */
    private function findIdeaCollectionsToCreateForNewIdeaItems(array $ideaItemsToCreate, array $ideaItemsAfter, array $objectCollectionsAfter, array $ideaCollections, array $brokenIdeaItems, array $brokenObjectCollections): array
    {
        $toCreate = [];

        foreach ($ideaItemsToCreate as $objectClassName => $objectInfo) {
            // Find corresponding IdeaItem in ideaItemsAfter
            if (!isset($ideaItemsAfter[$objectClassName])) {
                continue;
            }

            $ideaItemInfo = $ideaItemsAfter[$objectClassName];
            $ideaItemFile = $ideaItemInfo['file'];

            // Skip broken idea items
            if (isset($brokenIdeaItems[$ideaItemFile])) {
                continue;
            }

            // Find corresponding ObjectCollection
            $objectCollectionClassName = null;
            foreach ($objectCollectionsAfter as $loadedObjectCollectionClassName => $objectCollectionInfo) {
                $objectCollectionFile = $objectCollectionInfo['file'];
                
                // Skip broken object collections
                if (isset($brokenObjectCollections[$objectCollectionFile])) {
                    continue;
                }

                // Extract Entity class name from ObjectCollection
                $entityClassName = $this->extractEntityClassNameFromObjectCollection($objectCollectionInfo['reflection']);
                if ($entityClassName === null) {
                    continue;
                }

                // Find Object class name from Entity class name
                $objectClassNameFromEntity = str_replace('\\Entity\\', '\\Object\\', $entityClassName);
                if ($objectClassNameFromEntity === $objectClassName) {
                    $objectCollectionClassName = $loadedObjectCollectionClassName;
                    break;
                }
            }

            if ($objectCollectionClassName === null) {
                continue;
            }

            // Check if IdeaCollection already exists
            if (isset($ideaCollections[$objectCollectionClassName])) {
                continue;
            }

            $objectCollectionInfo = $objectCollectionsAfter[$objectCollectionClassName];
            // Reflection should exist in objectCollectionInfo after file creation
            $reflection = $objectCollectionInfo['reflection'] ?? null;
            if ($reflection === null) {
                try {
                    $reflection = new ReflectionClass($objectCollectionClassName);
                } catch (\Throwable $e) {
                    continue; // Skip if reflection cannot be created
                }
            }
            $toCreate[$objectCollectionClassName] = [
                'object_collection_class' => $objectCollectionClassName,
                'reflection' => $reflection,
            ];
        }

        return $toCreate;
    }

    /**
     * Find IdeaCollection files to create for new idea items (estimate phase)
     * Uses object class names to predict object collection class names
     *
     * @param array $ideaItemsToCreate Array of object class names => object info for newly created idea items
     * @param array $objectCollections Loaded ObjectCollection classes
     * @param array $ideaCollections Loaded IdeaCollection files
     * @param array $brokenObjectCollections Broken ObjectCollection files
     * @return array<string, array{object_collection_class: string, reflection: ReflectionClass}> IdeaCollections to create
     */
    private function findIdeaCollectionsToCreateForNewIdeaItemsEstimate(array $ideaItemsToCreate, array $objectCollections, array $ideaCollections, array $brokenObjectCollections): array
    {
        $toCreate = [];

        foreach ($ideaItemsToCreate as $objectClassName => $objectInfo) {
            // Predict ObjectCollection class name from Object class name
            $objectCollectionClassName = str_replace('\\Object\\', '\\ObjectCollection\\', $objectClassName);
            $objectCollectionShortName = substr($objectCollectionClassName, strrpos($objectCollectionClassName, '\\') + 1);
            $objectCollectionShortName = StringHelper::pluralize($objectCollectionShortName);
            $objectCollectionNamespace = substr($objectCollectionClassName, 0, strrpos($objectCollectionClassName, '\\'));
            $objectCollectionClassName = $objectCollectionNamespace . '\\' . $objectCollectionShortName;
            
            // Check if ObjectCollection exists
            if (!isset($objectCollections[$objectCollectionClassName])) {
                // Try to find by short name
                $found = false;
                foreach ($objectCollections as $loadedObjectCollectionClassName => $objectCollectionInfo) {
                    $collectionReflection = $objectCollectionInfo['reflection'];
                    $collectionShortName = $collectionReflection->getShortName();
                    if ($collectionShortName === $objectCollectionShortName) {
                        $objectCollectionClassName = $loadedObjectCollectionClassName;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    continue; // ObjectCollection doesn't exist yet, skip
                }
            }

            $objectCollectionInfo = $objectCollections[$objectCollectionClassName];
            $objectCollectionFile = $objectCollectionInfo['file'];
            
            // Skip broken object collections
            if (isset($brokenObjectCollections[$objectCollectionFile])) {
                continue;
            }

            // Check if IdeaCollection already exists
            if (isset($ideaCollections[$objectCollectionClassName])) {
                continue;
            }

            // For estimate, reflection should exist in objectCollectionInfo
            // If not, we'll create it when needed
            $reflection = $objectCollectionInfo['reflection'] ?? null;
            if ($reflection === null) {
                try {
                    $reflection = new ReflectionClass($objectCollectionClassName);
                } catch (\Throwable $e) {
                    continue; // Skip if reflection cannot be created
                }
            }
            $toCreate[$objectCollectionClassName] = [
                'object_collection_class' => $objectCollectionClassName,
                'reflection' => $reflection,
            ];
        }

        return $toCreate;
    }

    /**
     * Extract Entity class name from ObjectCollection (helper method)
     */
    private function extractEntityClassNameFromObjectCollection(ReflectionClass $objectCollectionReflection): ?string
    {
        $file = $objectCollectionReflection->getFileName();
        if ($file === false) {
            return null;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        // Look for use statement: use ...\Entity\ClassName as EntityClassName;
        if (preg_match('/use\s+([^;]+\\\Entity\\\[^;]+)(?:\s+as\s+(\w+))?;/', $content, $useMatch)) {
            return trim($useMatch[1]);
        }

        return null;
    }

    /**
     * Display IdeaCollection fixes that will be applied
     *
     * @param array $fixes Fixes to apply
     * @param array $toCreate IdeaCollections to create
     * @param array $toDelete IdeaCollections to delete
     * @param bool $dryRun Dry run mode
     */
    private function displayIdeaCollectionFixes(array $fixes, array $toCreate, array $toDelete, bool $dryRun): void
    {
        if (!empty($fixes)) {
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo ($dryRun ? "[DRY RUN] " : "") . "IdeaCollection Files to Update:\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

            foreach ($fixes as $objectCollectionClassName => $ideaFixes) {
                $objectCollectionShortName = (new ReflectionClass($objectCollectionClassName))->getShortName();
                echo "  {$objectCollectionShortName}:\n";

                if (isset($ideaFixes['update_objectToIdea'])) {
                    echo "    - Update objectToIdea() method\n";
                }

                if (isset($ideaFixes['update_imports'])) {
                    $missing = count($ideaFixes['update_imports']['missing'] ?? []);
                    $wrong = count($ideaFixes['update_imports']['wrong'] ?? []);
                    if ($missing > 0 || $wrong > 0) {
                        echo "    - Update imports";
                        if ($missing > 0) echo " (add {$missing} import/imports)";
                        if ($wrong > 0) echo " (fix {$wrong} import/imports)";
                        echo "\n";
                    }
                }

                echo "\n";
            }
        }

        if (!empty($toCreate)) {
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo ($dryRun ? "[DRY RUN] " : "") . "IdeaCollection Files to Create:\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

            foreach ($toCreate as $objectCollectionClassName => $info) {
                // Get short name from reflection if available, otherwise extract from class name
                if (isset($info['reflection']) && $info['reflection'] !== null) {
                    $objectCollectionShortName = $info['reflection']->getShortName();
                } else {
                    $objectCollectionShortName = substr($objectCollectionClassName, strrpos($objectCollectionClassName, '\\') + 1);
                }
                echo "  + {$objectCollectionShortName}\n";
            }
            echo "\n";
        }

        if (!empty($toDelete)) {
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo ($dryRun ? "[DRY RUN] " : "") . "IdeaCollection Files to Delete:\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

            foreach ($toDelete as $objectCollectionClassName => $ideaCollectionInfo) {
                $ideaCollectionShortName = (new \ReflectionClass($ideaCollectionInfo['class']))->getShortName();
                echo "  - {$ideaCollectionShortName} (ObjectCollection class {$objectCollectionClassName} no longer exists)\n";
            }
            echo "\n";
        }
    }

    /**
     * Create IdeaCollection files
     *
     * @param array $toCreate IdeaCollections to create
     * @param ?string $ideaCollectionDir IdeaCollection directory
     * @param array $objectCollections Loaded ObjectCollection classes
     * @return int Number of files created
     */
    private function createIdeaCollectionFiles(array $toCreate, ?string $ideaCollectionDir, array $objectCollections): int
    {
        if (empty($toCreate)) {
            return 0;
        }

        // Auto-detect idea collection directory if not provided
        if ($ideaCollectionDir === null) {
            $cwd = getcwd();
            $possibleDirs = [
                $cwd . '/backend/Database/IdeaCollection',
                $cwd . '/Database/IdeaCollection',
                $cwd . '/IdeaCollection',
                dirname($cwd) . '/backend/Database/IdeaCollection',
                dirname($cwd) . '/Database/IdeaCollection',
            ];

            $bootstrapDir = null;
            if (file_exists($cwd . '/backend/Bootstrap/cli.php')) {
                $bootstrapDir = $cwd . '/backend';
            } elseif (file_exists($cwd . '/Bootstrap/cli.php')) {
                $bootstrapDir = $cwd;
            }

            if ($bootstrapDir !== null) {
                $possibleDirs[] = $bootstrapDir . '/Database/IdeaCollection';
            }

            foreach ($possibleDirs as $dir) {
                $realPath = realpath($dir);
                if ($realPath !== false && is_dir($realPath)) {
                    $ideaCollectionDir = $realPath;
                    break;
                }
            }

            // If directory doesn't exist, try to infer from ObjectCollection directory
            if ($ideaCollectionDir === null && !empty($objectCollections)) {
                $firstObjectCollection = reset($objectCollections);
                $objectCollectionFile = $firstObjectCollection['file'];
                $objectCollectionDir = dirname($objectCollectionFile);
                $ideaCollectionDir = str_replace('/ObjectCollection', '/IdeaCollection', $objectCollectionDir);
            }
        }

        if ($ideaCollectionDir === null) {
            echo "Error: Cannot determine IdeaCollection directory\n";
            return 0;
        }

        // Extract namespace from first ObjectCollection class
        $namespace = null;
        if (!empty($objectCollections)) {
            $firstObjectCollection = reset($objectCollections);
            $objectCollectionReflection = $firstObjectCollection['reflection'];
            $objectCollectionNamespace = $objectCollectionReflection->getNamespaceName();
            $namespace = str_replace('\\ObjectCollection', '\\IdeaCollection', $objectCollectionNamespace);
        }

        if ($namespace === null) {
            echo "Error: Cannot determine IdeaCollection namespace\n";
            return 0;
        }

        $created = 0;
        foreach ($toCreate as $objectCollectionClassName => $info) {
            // Get reflection from info, or create it from objectCollectionClassName if not present
            $objectCollectionReflection = $info['reflection'] ?? null;
            if ($objectCollectionReflection === null) {
                try {
                    $objectCollectionReflection = new ReflectionClass($objectCollectionClassName);
                } catch (\Throwable $e) {
                    echo "⚠ Failed to create IdeaCollection for {$objectCollectionClassName}: Cannot load ObjectCollection class\n";
                    continue;
                }
            }
            if ($this->createIdeaCollectionFile($objectCollectionClassName, $ideaCollectionDir, $namespace, $objectCollectionReflection)) {
                $created++;
            }
        }

        return $created;
    }
}

