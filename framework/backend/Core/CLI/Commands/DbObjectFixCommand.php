<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Database\Database;
use Hilos\Database\Entity\Entity;
use Hilos\Database\PhpType;
use Hilos\Exception\DatabaseException;
use ReflectionClass;

/**
 * DbObjectFixCommand - Fix Object files to match Entity files
 *
 * Automatically updates Object class definitions to match Entity structure.
 * Adds missing properties, updates types, and maintains user-defined methods.
 */
class DbObjectFixCommand implements CommandInterface
{
    public function getName(): string
    {
        return CliCommands::DB_OBJECT_FIX;
    }

    public function getDescription(): string
    {
        return 'Fix Object files to match Entity files';
    }

    public function getHelp(): string
    {
        return <<<HELP
Command: db:object:fix

Description:
  Automatically update Object class definitions to match Entity structure.
  Adds missing properties, updates types, and preserves user-defined methods.
  Uses @object-exclude directives in Entity files to determine excluded fields.

Usage:
  php cli.php db:object:fix [options]

Options:
  --object-dir=<path>  Object files directory (default: auto-detect)
  --entity-dir=<path>  Entity files directory (default: auto-detect)
  --table=<name>       Fix specific table only
  --dry-run            Show what would be changed without modifying files
  --force-repair       Attempt to repair broken Object files

Examples:
  php cli.php db:object:fix
  php cli.php db:object:fix --table=user
  php cli.php db:object:fix --dry-run
  php cli.php db:object:fix --force-repair
HELP;
    }

    public function execute(array $options, array $args): int
    {
        echo "\n=== Fix Object Files ===\n\n";

        // Parse arguments from options
        $tableName = $options['table'] ?? null;
        $objectDir = $options['object-dir'] ?? null;
        $entityDir = $options['entity-dir'] ?? null;
        $dryRun = isset($options['dry-run']);
        $forceRepair = isset($options['force-repair']);

        // Load Entity classes
        $syntaxErrors = 0;
        try {
            $entities = $this->loadEntities($entityDir, $syntaxErrors);
        } catch (\Throwable $e) {
            echo "Error: Failed to load Entity classes\n";
            echo "Message: {$e->getMessage()}\n\n";
            return ExitCode::ERROR;
        }

        // Load Object classes
        try {
            $objects = $this->loadObjects($objectDir, $syntaxErrors);
        } catch (\Throwable $e) {
            echo "Error: Failed to load Object classes\n";
            echo "Message: {$e->getMessage()}\n\n";
            return ExitCode::ERROR;
        }

        // Find differences and prepare fixes
        $fixes = $this->prepareFixes($entities, $objects, $tableName, $forceRepair);
        $objectsToCreate = $this->findObjectsToCreate($entities, $objects, $tableName);
        $filesToDelete = $this->findFilesToDelete($entities, $objects, $tableName);

        if ($syntaxErrors > 0) {
            echo "\n⚠ {$syntaxErrors} file(s) contain syntax errors and were skipped.\n";
        }

        if (empty($fixes) && empty($objectsToCreate) && empty($filesToDelete)) {
            if ($syntaxErrors > 0) {
                echo "\n";
            } else {
                echo "✓ No fixes needed! Object files match Entity files.\n\n";
            }
            return ExitCode::SUCCESS;
        }

        // Display what will be fixed
        $this->displayFixes($fixes, $objectsToCreate, $filesToDelete, $dryRun);

        if ($dryRun) {
            echo "\n[DRY RUN] No files were modified.\n\n";
            return ExitCode::SUCCESS;
        }

        // Apply fixes
        $applied = $this->applyFixes($fixes, $forceRepair);
        $created = $this->createObjectFiles($objectsToCreate, $objectDir, $entityDir);
        $deleted = $this->deleteObjectFiles($filesToDelete);

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
    private function loadEntities(?string $entityDir, int &$syntaxErrors = 0): array
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
            $className = $this->extractClassNameFromFile($file);
            if ($className === null) {
                continue;
            }

            try {
                // Check PHP syntax
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

                $table = $reflection->getConstant(Entity::META_TABLE);
                if ($table === false) {
                    continue;
                }

                $entities[$table] = [
                    'class' => $className,
                    'file' => $file,
                    'reflection' => $reflection,
                ];
            } catch (\Throwable $e) {
                echo "⚠ Skipping {$file}: {$e->getMessage()}\n";
                continue;
            }
        }

        return $entities;
    }

    /**
     * Load Object classes from directory
     */
    private function loadObjects(?string $objectDir, int &$syntaxErrors = 0): array
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
                // Object directory doesn't exist yet, that's OK
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
            $className = $this->extractClassNameFromFile($file);
            if ($className === null) {
                continue;
            }

            try {
                // Check PHP syntax
                $output = [];
                $returnVar = 0;
                exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $returnVar);
                if ($returnVar !== 0) {
                    echo "⚠ Skipping {$file}: PHP syntax error detected\n";
                    echo "  " . implode("\n  ", $output) . "\n";
                    $syntaxErrors++;
                    continue;
                }

                $objects[$className] = [
                    'class' => $className,
                    'file' => $file,
                ];
            } catch (\Throwable $e) {
                echo "⚠ Skipping {$file}: {$e->getMessage()}\n";
                continue;
            }
        }

        return $objects;
    }

    /**
     * Extract class name from PHP file
     */
    private function extractClassNameFromFile(string $file): ?string
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
     * Prepare fixes for Object files
     */
    private function prepareFixes(array $entities, array $objects, ?string $tableFilter, bool $forceRepair): array
    {
        $fixes = [];

        foreach ($entities as $tableName => $entityInfo) {
            if ($tableFilter !== null && $tableName !== $tableFilter) {
                continue;
            }

            // Find corresponding Object by Entity class name
            $entityClassName = $entityInfo['class'];
            $objectClassName = $this->findObjectClassName($entityClassName, $objects);

            if ($objectClassName === null) {
                continue; // Will be created separately
            }

            $objectInfo = $objects[$objectClassName];
            $objectFile = $objectInfo['file'];

            // Validate Object file
            $validation = $this->validateObjectFile($objectFile, $entityInfo);
            if (!$validation['valid']) {
                if ($forceRepair) {
                    // Mark for repair
                    $fixes[$tableName] = [
                        'file' => $objectFile,
                        'entity' => $entityInfo,
                        'type' => 'repair',
                        'errors' => $validation['errors'],
                    ];
                } else {
                    echo "⚠ Skipping {$objectFile}: Validation failed\n";
                    foreach ($validation['errors'] as $error) {
                        echo "  - {$error}\n";
                    }
                    echo "  Use --force-repair to attempt repair\n";
                }
                continue;
            }

            // Compare Object with Entity
            $objectFixes = $this->compareObjectWithEntity($objectFile, $entityInfo);
            if (!empty($objectFixes)) {
                $fixes[$tableName] = [
                    'file' => $objectFile,
                    'entity' => $entityInfo,
                    'type' => 'update',
                    'fixes' => $objectFixes,
                ];
            }
        }

        return $fixes;
    }

    /**
     * Find Object class name from Entity class name
     */
    private function findObjectClassName(string $entityClassName, array $objects): ?string
    {
        // Object class name should match Entity class name
        // But namespace might be different (Entity vs Object)
        $entityShortName = substr($entityClassName, strrpos($entityClassName, '\\') + 1);

        foreach ($objects as $objectClassName => $objectInfo) {
            $objectShortName = substr($objectClassName, strrpos($objectClassName, '\\') + 1);
            if ($objectShortName === $entityShortName) {
                return $objectClassName;
            }
        }

        return null;
    }

    /**
     * Validate Object file structure
     */
    private function validateObjectFile(string $file, array $entityInfo): array
    {
        $errors = [];
        $content = file_get_contents($file);
        if ($content === false) {
            return ['valid' => false, 'errors' => ['Cannot read file']];
        }

        // Check for required elements
        if (!preg_match('/extends\s+Object_/', $content)) {
            $errors[] = 'Class does not extend Object_';
        }

        if (!preg_match('/protected\s+\w+\s+\$entity;/', $content)) {
            $errors[] = 'Missing $entity property';
        }

        if (!preg_match('/protected\s+\w+\s+\$entitySync;/', $content)) {
            $errors[] = 'Missing $entitySync property';
        }

        // Check for __get method
        if (!preg_match('/public\s+function\s+__get\s*\(/', $content)) {
            $errors[] = 'Missing __get() method';
        }

        // Check for __set method (optional but recommended)
        // Not required as per user's request

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Compare Object file with Entity and determine fixes needed
     */
    private function compareObjectWithEntity(string $objectFile, array $entityInfo): array
    {
        $fixes = [];
        $content = file_get_contents($objectFile);
        if ($content === false) {
            return $fixes;
        }

        $entityReflection = $entityInfo['reflection'];
        $entityFile = $entityInfo['file'];
        $entityContent = file_get_contents($entityFile);
        if ($entityContent === false) {
            return $fixes;
        }

        // Parse Entity to get included columns (excluding @object-exclude fields)
        $excludedFields = $this->parseExcludedFields($entityContent);
        $entityColumns = $entityReflection->getConstant(Entity::META_COLUMNS) ?: [];
        $entityTypes = $entityReflection->getConstant(Entity::META_TYPES) ?: [];
        $entityPrimary = $entityReflection->getConstant(Entity::META_PRIMARY);
        if (is_string($entityPrimary)) {
            $entityPrimary = [$entityPrimary];
        } elseif (!is_array($entityPrimary)) {
            $entityPrimary = [];
        }

        // Get included columns (not excluded)
        $includedColumns = [];
        foreach ($entityColumns as $colName) {
            if (!in_array($colName, $excludedFields, true)) {
                // Determine nullable from Entity property
                $nullable = false; // Default
                if (preg_match('/public\s+(\??\w+)\s+\$' . preg_quote($colName, '/') . '/', $entityContent, $matches)) {
                    $nullable = strpos($matches[1], '?') === 0;
                }
                
                $includedColumns[$colName] = [
                    'type' => $entityTypes[$colName] ?? 'string',
                    'is_primary' => in_array($colName, $entityPrimary, true),
                    'nullable' => $nullable,
                ];
            }
        }

        // Parse Object file to get current constants and cases
        $objectConstants = $this->parseObjectConstants($content);
        $objectGetterCases = $this->parseGetterCases($content);
        $objectSetterCases = $this->parseSetterCases($content);
        $objectPhpDoc = $this->parsePhpDoc($content);

        // Build reverse mapping: find which Object constants map to which Entity fields
        // This handles cases where Object uses different names (e.g., idUser instead of id)
        $entityFieldToObjectConstant = [];
        foreach ($objectGetterCases as $objectConstant => $case) {
            // Try to find matching Entity field by checking the getter case content
            // Pattern: self::constant => $this->entity->entity_field
            // Need to search in the actual content, not in the parsed cases
            if (preg_match('/self::' . preg_quote($objectConstant, '/') . '\s*=>\s*\$this->entity->(\w+)/', $content, $matches)) {
                $entityField = $matches[1];
                if (in_array($entityField, $entityColumns, true)) {
                    $entityFieldToObjectConstant[$entityField] = $objectConstant;
                }
            }
        }

        // Compare: find missing, extra, and changed properties
        foreach ($includedColumns as $colName => $colInfo) {
            $camelCase = $this->snakeToCamelCase($colName);
            $normalizedType = \Hilos\Database\Generator::normalizeType($colInfo['type']);

            // Check if there's a mapping for this Entity field
            $objectConstantName = $entityFieldToObjectConstant[$colName] ?? $camelCase;

            // Check if constant exists (either by standard name or mapped name)
            if (!isset($objectConstants[$objectConstantName]) && !isset($objectConstants[$camelCase])) {
                $fixes['add_constants'][] = [
                    'name' => $camelCase,
                    'entity_field' => $colName,
                    'type' => $normalizedType,
                    'is_primary' => $colInfo['is_primary'],
                ];
            }

            // Check if getter case exists
            if (!isset($objectGetterCases[$objectConstantName]) && !isset($objectGetterCases[$camelCase])) {
                $fixes['add_getter_cases'][] = [
                    'name' => $camelCase,
                    'entity_field' => $colName,
                ];
            }

            // Check if setter case exists (only for non-primary keys)
            if (!$colInfo['is_primary'] && !isset($objectSetterCases[$objectConstantName]) && !isset($objectSetterCases[$camelCase])) {
                $fixes['add_setter_cases'][] = [
                    'name' => $camelCase,
                    'entity_field' => $colName,
                    'type' => $normalizedType,
                ];
            }

            // Check PHPDoc (use the actual constant name if mapped)
            $phpDocName = $objectConstantName !== $camelCase ? $objectConstantName : $camelCase;
            $expectedPhpDocType = $this->getPhpDocType($normalizedType, $colInfo['is_primary'], $colInfo['nullable'] ?? false);
            
            // Check if PHPDoc exists and if type matches
            $existingPhpDoc = $objectPhpDoc[$phpDocName] ?? $objectPhpDoc[$camelCase] ?? null;
            if ($existingPhpDoc === null) {
                // PHPDoc doesn't exist, add it
                $fixes['update_phpdoc'][] = [
                    'name' => $phpDocName,
                    'type' => $expectedPhpDocType,
                    'is_primary' => $colInfo['is_primary'],
                ];
            } elseif ($existingPhpDoc['type'] !== $expectedPhpDocType) {
                // PHPDoc exists but type doesn't match (including nullable), update it
                $fixes['update_phpdoc'][] = [
                    'name' => $phpDocName,
                    'type' => $expectedPhpDocType,
                    'is_primary' => $colInfo['is_primary'],
                ];
            }
        }

        // Find constants/cases that exist in Object but not in Entity (should be removed)
        // Build set of expected constant names (including mapped names)
        $expectedConstantNames = [];
        foreach ($includedColumns as $colName => $colInfo) {
            $camelCase = $this->snakeToCamelCase($colName);
            $objectConstantName = $entityFieldToObjectConstant[$colName] ?? $camelCase;
            $expectedConstantNames[$objectConstantName] = true;
            $expectedConstantNames[$camelCase] = true;
        }
        
        // Find constants to remove
        foreach ($objectConstants as $constName => $exists) {
            if (!isset($expectedConstantNames[$constName])) {
                $fixes['remove_constants'][] = $constName;
            }
        }
        
        // Find getter cases to remove
        foreach ($objectGetterCases as $caseName => $exists) {
            if (!isset($expectedConstantNames[$caseName])) {
                $fixes['remove_getter_cases'][] = $caseName;
            }
        }
        
        // Find setter cases to remove
        foreach ($objectSetterCases as $caseName => $exists) {
            if (!isset($expectedConstantNames[$caseName])) {
                $fixes['remove_setter_cases'][] = $caseName;
            }
        }

        return $fixes;
    }

    /**
     * Parse excluded fields from Entity file
     * Uses same algorithm as ObjectGenerator::parseExcludedFields
     */
    private function parseExcludedFields(string $entityContent): array
    {
        $excluded = [];

        // Parse class-level @object-exclude directive in PHPDoc
        if (preg_match('/\/\*\*.*?@object-exclude\s+([^\n\*]+).*?\*\//s', $entityContent, $matches)) {
            $fields = preg_split('/\s*,\s*/', trim($matches[1]));
            foreach ($fields as $field) {
                $field = trim($field);
                if ($field !== '') {
                    $excluded[] = $field;
                }
            }
        }

        // Parse property-level @object-exclude directives
        // Look for @object-exclude in comments before property declarations
        if (preg_match_all('/(?:\/\/[^\n]*@object-exclude[^\n]*\n|\/\*\*[^\*]*@object-exclude[^\*]*\*\/\s*\n).*?public\s+(?:\?)?\w+\s+\$(\w+)/s', $entityContent, $matches)) {
            foreach ($matches[1] as $field) {
                if (!in_array($field, $excluded, true)) {
                    $excluded[] = $field;
                }
            }
        }

        return $excluded;
    }

    /**
     * Parse Object constants from file
     */
    private function parseObjectConstants(string $content): array
    {
        $constants = [];
        if (preg_match_all('/public\s+const\s+string\s+(\w+)\s*=\s*\'[^\']+\';/', $content, $matches)) {
            foreach ($matches[1] as $name) {
                $constants[$name] = true;
            }
        }
        return $constants;
    }

    /**
     * Parse getter cases from __get method
     */
    private function parseGetterCases(string $content): array
    {
        $cases = [];
        if (preg_match('/public\s+function\s+__get.*?match\s*\([^)]+\)\s*\{([^}]+)\}/s', $content, $match)) {
            $matchBody = $match[1];
            if (preg_match_all('/self::(\w+)\s*=>/', $matchBody, $matches)) {
                foreach ($matches[1] as $name) {
                    $cases[$name] = true;
                }
            }
        }
        return $cases;
    }

    /**
     * Parse setter cases from __set method
     */
    private function parseSetterCases(string $content): array
    {
        $cases = [];
        if (preg_match('/public\s+function\s+__set.*?match\s*\([^)]+\)\s*\{([^}]+)\}/s', $content, $match)) {
            $matchBody = $match[1];
            if (preg_match_all('/self::(\w+)\s*=>/', $matchBody, $matches)) {
                foreach ($matches[1] as $name) {
                    $cases[$name] = true;
                }
            }
        }
        return $cases;
    }

    /**
     * Parse PHPDoc properties
     */
    private function parsePhpDoc(string $content): array
    {
        $properties = [];
        if (preg_match_all('/\*\s*@property(?:-read)?\s+(\S+)\s+\$(\w+)/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $properties[$match[2]] = [
                    'type' => $match[1],
                    'readonly' => strpos($match[0], '@property-read') !== false,
                ];
            }
        }
        return $properties;
    }

    /**
     * Get PHPDoc type string for property
     */
    private function getPhpDocType(string $type, bool $isPrimary, bool $nullable = false): string
    {
        $phpType = match ($type) {
            PhpType::INTEGER->value => 'int',
            PhpType::BOOLEAN->value => 'bool',
            PhpType::DATETIME->value => 'string',
            default => $type,
        };

        // Primary keys are always nullable, or if explicitly nullable
        if ($isPrimary || $nullable) {
            return "?{$phpType}";
        }

        return $phpType;
    }

    /**
     * Convert snake_case to camelCase
     */
    private function snakeToCamelCase(string $snake): string
    {
        return lcfirst(str_replace('_', '', ucwords($snake, '_')));
    }

    /**
     * Find Object files to create
     */
    private function findObjectsToCreate(array $entities, array $objects, ?string $tableFilter): array
    {
        $toCreate = [];

        foreach ($entities as $tableName => $entityInfo) {
            if ($tableFilter !== null && $tableName !== $tableFilter) {
                continue;
            }

            $entityClassName = $entityInfo['class'];
            $objectClassName = $this->findObjectClassName($entityClassName, $objects);

            if ($objectClassName === null) {
                $toCreate[$tableName] = $entityInfo;
            }
        }

        return $toCreate;
    }

    /**
     * Find Object files to delete (no corresponding Entity)
     */
    private function findFilesToDelete(array $entities, array $objects, ?string $tableFilter): array
    {
        $toDelete = [];

        foreach ($objects as $objectClassName => $objectInfo) {
            $objectShortName = substr($objectClassName, strrpos($objectClassName, '\\') + 1);

            // Try to find corresponding Entity
            $found = false;
            foreach ($entities as $tableName => $entityInfo) {
                if ($tableFilter !== null && $tableName !== $tableFilter) {
                    continue;
                }

                $entityShortName = substr($entityInfo['class'], strrpos($entityInfo['class'], '\\') + 1);
                if ($entityShortName === $objectShortName) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $toDelete[$objectClassName] = $objectInfo;
            }
        }

        return $toDelete;
    }

    /**
     * Display fixes that will be applied
     */
    private function displayFixes(array $fixes, array $objectsToCreate, array $filesToDelete, bool $dryRun): void
    {
        $prefix = $dryRun ? "[DRY RUN] " : "";

        foreach ($fixes as $tableName => $fix) {
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "{$prefix}Table: {$tableName}\n";
            echo "File: {$fix['file']}\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

            if ($fix['type'] === 'repair') {
                echo "  Will repair broken file:\n";
                foreach ($fix['errors'] as $error) {
                    echo "    - {$error}\n";
                }
                echo "\n";
                continue;
            }

            $objectFixes = $fix['fixes'] ?? [];

            if (!empty($objectFixes['add_constants'])) {
                echo "  Will add constants:\n";
                foreach ($objectFixes['add_constants'] as $const) {
                    echo "    + {$const['name']}\n";
                }
                echo "\n";
            }

            if (!empty($objectFixes['add_getter_cases'])) {
                echo "  Will add getter cases:\n";
                foreach ($objectFixes['add_getter_cases'] as $case) {
                    echo "    + {$case['name']}\n";
                }
                echo "\n";
            }

            if (!empty($objectFixes['add_setter_cases'])) {
                echo "  Will add setter cases:\n";
                foreach ($objectFixes['add_setter_cases'] as $case) {
                    echo "    + {$case['name']}\n";
                }
                echo "\n";
            }

            if (!empty($objectFixes['remove_constants'])) {
                echo "  Will remove constants:\n";
                foreach ($objectFixes['remove_constants'] as $name) {
                    echo "    - {$name}\n";
                }
                echo "\n";
            }

            if (!empty($objectFixes['remove_getter_cases'])) {
                echo "  Will remove getter cases:\n";
                foreach ($objectFixes['remove_getter_cases'] as $name) {
                    echo "    - {$name}\n";
                }
                echo "\n";
            }

            if (!empty($objectFixes['remove_setter_cases'])) {
                echo "  Will remove setter cases:\n";
                foreach ($objectFixes['remove_setter_cases'] as $name) {
                    echo "    - {$name}\n";
                }
                echo "\n";
            }

            if (!empty($objectFixes['update_phpdoc'])) {
                echo "  Will update PHPDoc:\n";
                foreach ($objectFixes['update_phpdoc'] as $prop) {
                    echo "    ~ {$prop['name']}: {$prop['type']}\n";
                }
                echo "\n";
            }

            echo "\n";
        }

        // Display files to create
        if (!empty($objectsToCreate)) {
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo ($dryRun ? "[DRY RUN] " : "") . "Files to create:\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            foreach ($objectsToCreate as $tableName => $entityInfo) {
                echo "  + {$tableName} (Object file will be created)\n";
            }
            echo "\n";
        }

        // Display files to delete
        if (!empty($filesToDelete)) {
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo ($dryRun ? "[DRY RUN] " : "") . "Files to delete:\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            foreach ($filesToDelete as $objectClassName => $objectInfo) {
                echo "  - {$objectClassName} ({$objectInfo['file']})\n";
            }
            echo "\n";
        }
    }

    /**
     * Apply fixes to Object files
     */
    private function applyFixes(array $fixes, bool $forceRepair): int
    {
        $applied = 0;

        foreach ($fixes as $tableName => $fix) {
            try {
                if ($fix['type'] === 'repair') {
                    // Regenerate entire file
                    $this->repairObjectFile($fix);
                } else {
                    $this->applyObjectFixes($fix);
                }
                $applied++;
            } catch (\Throwable $e) {
                echo "✗ Failed to fix {$tableName}: {$e->getMessage()}\n";
            }
        }

        return $applied;
    }

    /**
     * Repair broken Object file by regenerating it
     */
    private function repairObjectFile(array $fix): void
    {
        $entityInfo = $fix['entity'];
        $entityClassName = $entityInfo['class'];
        $objectFile = $fix['file'];

        // Extract namespace and class name from existing file
        $content = file_get_contents($objectFile);
        if ($content === false) {
            throw new \RuntimeException("Cannot read file: {$objectFile}");
        }

        // Try to extract namespace and class name
        $namespace = null;
        $className = null;
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            $namespace = trim($matches[1]);
        }
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            $className = trim($matches[1]);
        }

        if ($namespace === null || $className === null) {
            throw new \RuntimeException("Cannot extract namespace or class name from file");
        }

        // Extract user-defined methods (preserve them)
        $userMethods = $this->extractUserMethods($content);

        // Regenerate Object file
        $newContent = $this->generateObjectFromEntity($entityClassName, $namespace, $className);

        // Insert user-defined methods before closing brace
        if (!empty($userMethods)) {
            $newContent = preg_replace('/(\n)\}/', '$1' . implode("\n\n", $userMethods) . "\n}", $newContent);
        }

        file_put_contents($objectFile, $newContent);
    }

    /**
     * Apply fixes to Object file - Full rebuild approach
     */
    private function applyObjectFixes(array $fix): void
    {
        $objectFile = $fix['file'];
        $content = file_get_contents($objectFile);
        if ($content === false) {
            throw new \RuntimeException("Cannot read file: {$objectFile}");
        }

        $entityInfo = $fix['entity'];
        $entityReflection = $entityInfo['reflection'];
        $entityFile = $entityInfo['file'];
        $entityContent = file_get_contents($entityFile);
        
        // Parse excluded fields
        $excludedFields = $this->parseExcludedFields($entityContent);
        $entityColumns = $entityReflection->getConstant(Entity::META_COLUMNS) ?: [];
        $entityTypes = $entityReflection->getConstant(Entity::META_TYPES) ?: [];
        $entityPrimary = $entityReflection->getConstant(Entity::META_PRIMARY);
        if (is_string($entityPrimary)) {
            $entityPrimary = [$entityPrimary];
        } elseif (!is_array($entityPrimary)) {
            $entityPrimary = [];
        }

        // Get included columns in Entity order
        $includedColumns = [];
        foreach ($entityColumns as $colName) {
            if (!in_array($colName, $excludedFields, true)) {
                // Determine nullable from Entity property
                $nullable = false; // Default
                if (preg_match('/public\s+(\??\w+)\s+\$' . preg_quote($colName, '/') . '/', $entityContent, $matches)) {
                    $nullable = strpos($matches[1], '?') === 0;
                }
                
                $includedColumns[$colName] = [
                    'type' => $entityTypes[$colName] ?? 'string',
                    'is_primary' => in_array($colName, $entityPrimary, true),
                    'nullable' => $nullable,
                ];
            }
        }

        // Extract existing comments and mappings from Object file
        $existingComments = $this->extractComments($content);
        $entityFieldToObjectConstant = $this->buildEntityToObjectMapping($content, $entityColumns);

        // Extract user-defined methods (preserve them)
        $userMethods = $this->extractUserMethods($content);

        // Full rebuild: Constants
        $content = $this->rebuildConstants($content, $includedColumns, $entityFieldToObjectConstant, $existingComments);

        // Full rebuild: __get() method
        $content = $this->rebuildGetterMethod($content, $includedColumns, $entityFieldToObjectConstant, $existingComments);

        // Full rebuild: __set() method
        $content = $this->rebuildSetterMethod($content, $includedColumns, $entityFieldToObjectConstant, $existingComments);

        // Full rebuild: PHPDoc (preserve existing descriptions)
        $content = $this->rebuildPhpDoc($content, $includedColumns, $entityFieldToObjectConstant, $entityReflection);

        // Generate/update: toArray() method
        $content = $this->rebuildToArrayMethod($content, $includedColumns, $entityFieldToObjectConstant);

        // Preserve user-defined methods
        if (!empty($userMethods)) {
            // Remove old user methods if they exist, then add them back
            $content = $this->removeUserMethods($content);
            // Insert before closing brace
            $content = preg_replace('/(\n)\}/', '$1' . implode("\n\n", $userMethods) . "\n}", $content);
        }

        file_put_contents($objectFile, $content);
    }

    /**
     * Extract comments from Object file (end-of-line comments)
     */
    private function extractComments(string $content): array
    {
        $comments = [];
        
        // Extract comments from constants: public const string name = 'name'; // comment
        if (preg_match_all('/public\s+const\s+string\s+(\w+)\s*=\s*\'[^\']+\';\s*\/\/\s*(.+)/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $comments['constant'][$match[1]] = trim($match[2]);
            }
        }

        // Extract comments from getter cases: self::name => $this->entity->name, // comment
        if (preg_match_all('/self::(\w+)\s*=>\s*\$this->entity->\w+[^,]*,\s*\/\/\s*(.+)/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $comments['getter'][$match[1]] = trim($match[2]);
            }
        }

        // Extract comments from setter cases
        if (preg_match_all('/self::(\w+)\s*=>\s*\$this->entity->\w+[^,]*,\s*\/\/\s*(.+)/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (!isset($comments['setter'][$match[1]])) {
                    $comments['setter'][$match[1]] = trim($match[2]);
                }
            }
        }

        return $comments;
    }

    /**
     * Build mapping from Entity field names to Object constant names
     */
    private function buildEntityToObjectMapping(string $content, array $entityColumns): array
    {
        $mapping = [];
        
        // Parse getter cases to find mappings
        if (preg_match_all('/self::(\w+)\s*=>\s*\$this->entity->(\w+)/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $objectConstant = $match[1];
                $entityField = $match[2];
                if (in_array($entityField, $entityColumns, true)) {
                    $mapping[$entityField] = $objectConstant;
                }
            }
        }

        return $mapping;
    }

    /**
     * Rebuild constants section - full rebuild in Entity order
     */
    private function rebuildConstants(string $content, array $includedColumns, array $entityFieldToObjectConstant, array $existingComments): string
    {
        // Extract indent from existing constants section
        $indent = '    '; // Default 4 spaces
        if (preg_match('/(\n)([ \t]*)\/\/ Property name constants/', $content, $indentMatch)) {
            $indent = $indentMatch[2];
        }

        $constants = [];
        
        foreach ($includedColumns as $colName => $colInfo) {
            $camelCase = $this->snakeToCamelCase($colName);
            $objectConstantName = $entityFieldToObjectConstant[$colName] ?? $camelCase;
            
            $comment = $existingComments['constant'][$objectConstantName] ?? '';
            $commentStr = $comment !== '' ? ' // ' . $comment : '';
            
            $constants[] = "{$indent}public const string {$objectConstantName} = '{$objectConstantName}';{$commentStr}";
        }

        $constantsSection = "{$indent}// Property name constants (camelCase for PHP)\n" . implode("\n", $constants) . "\n";

        // Replace constants section - match from start of line including newline
        if (preg_match('/(\n)([ \t]*)\/\/ Property name constants.*?\n((?:[ \t]*public const string \w+[^\n]*\n)+)/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
            // Check what comes after constants section to preserve spacing
            $matchEnd = $matches[0][1] + strlen($matches[0][0]);
            $afterConstants = substr($content, $matchEnd);
            $hasNewlineAfter = preg_match('/^\s*\n/', $afterConstants);
            $replacement = $matches[1][0] . $constantsSection . ($hasNewlineAfter ? '' : "\n");
            $content = substr_replace($content, $replacement, $matches[0][1], strlen($matches[0][0]));
        } else {
            // Add constants section before $entity property
            if (preg_match('/(protected Entity\w+ \$entity;)/', $content, $matches)) {
                $content = str_replace($matches[1], $constantsSection . $matches[1], $content);
            }
        }

        return $content;
    }

    /**
     * Rebuild __get() method - full rebuild in Entity order
     */
    private function rebuildGetterMethod(string $content, array $includedColumns, array $entityFieldToObjectConstant, array $existingComments): string
    {
        $cases = [];
        
        foreach ($includedColumns as $colName => $colInfo) {
            $camelCase = $this->snakeToCamelCase($colName);
            $objectConstantName = $entityFieldToObjectConstant[$colName] ?? $camelCase;
            
            $comment = $existingComments['getter'][$objectConstantName] ?? '';
            $commentStr = $comment !== '' ? ' // ' . $comment : '';
            
            $cases[] = "self::{$objectConstantName} => \$this->entity->{$colName}{$commentStr}";
        }

        // Replace __get method - find method with balanced braces
        // Match indent at start of line (spaces/tabs, but not newlines)
        $methodPattern = '/(\n)([ \t]*)public\s+function\s+__get\s*\([^)]*\)\s*:\s*mixed\s*\{/';
        if (preg_match($methodPattern, $content, $methodStart, PREG_OFFSET_CAPTURE)) {
            $startPos = $methodStart[0][1];
            $indent = $methodStart[2][0]; // Extract indent (spaces/tabs only, no newlines)
            
            // Find matching closing brace
            $pos = $startPos + strlen($methodStart[0][0]);
            $braceCount = 1;
            $inString = false;
            $stringChar = '';
            
            while ($braceCount > 0 && $pos < strlen($content)) {
                $char = $content[$pos];
                $nextChar = $pos + 1 < strlen($content) ? $content[$pos + 1] : '';
                
                // Handle strings
                if (!$inString && ($char === '"' || $char === "'")) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($inString && $char === $stringChar && $content[$pos - 1] !== '\\') {
                    $inString = false;
                }
                
                if (!$inString) {
                    if ($char === '{') {
                        $braceCount++;
                    } elseif ($char === '}') {
                        $braceCount--;
                    }
                }
                
                $pos++;
            }
            
            if ($braceCount === 0) {
                // Replace entire method including PHPDoc if present
                $methodEnd = $pos;
                
                // Check for PHPDoc before method
                $docStart = $startPos;
                $beforeMethod = substr($content, max(0, $startPos - 200), $startPos - max(0, $startPos - 200));
                if (preg_match('/(\/\*\*.*?\*\/)\s*(?=public\s+function\s+__get)/s', $beforeMethod, $docMatch, PREG_OFFSET_CAPTURE)) {
                    $docStart = $startPos - (strlen($beforeMethod) - $docMatch[1][1]);
                }
                
                $before = substr($content, 0, $docStart);
                $after = substr($content, $methodEnd);
                
                // Check if there's a newline before method, preserve it
                $beforeEndsWithNewline = preg_match('/\n\s*$/', $before);
                
                // Generate method with extracted indent
                $getterMethod = ($beforeEndsWithNewline ? '' : "\n") . "{$indent}public function __get(string \$property): mixed\n";
                $getterMethod .= "{$indent}{\n";
                $getterMethod .= "{$indent}    return match (\$property) {\n";
                if (!empty($cases)) {
                    // Update cases with correct indent (same as default: indent + 8 spaces)
                    $indentedCases = array_map(fn($case) => $indent . '        ' . $case, $cases);
                    $getterMethod .= implode(",\n", $indentedCases) . ",\n";
                }
                $getterMethod .= "{$indent}        default => parent::__get(\$property),\n";
                $getterMethod .= "{$indent}    };\n";
                $getterMethod .= "{$indent}}";
                
                // Ensure proper spacing: check if there's a newline after method, preserve it
                $afterStartsWithNewline = preg_match('/^\s*\n/', $after);
                $content = $before . $getterMethod . ($afterStartsWithNewline ? '' : "\n") . $after;
            }
        } else {
            // Add __get method before __set or before closing brace
            // Extract indent from context (default 4 spaces)
            $indent = '    ';
            if (preg_match('/(\n)([ \t]*)(?:public|protected|private)\s+function/', $content, $indentMatch, PREG_OFFSET_CAPTURE)) {
                $indent = $indentMatch[2][0];
            }
            
            // Generate method with extracted indent
            $getterMethod = "{$indent}public function __get(string \$property): mixed\n";
            $getterMethod .= "{$indent}{\n";
            $getterMethod .= "{$indent}    return match (\$property) {\n";
            if (!empty($cases)) {
                $indentedCases = array_map(fn($case) => $indent . '    ' . ltrim($case), $cases);
                $getterMethod .= implode(",\n", $indentedCases) . ",\n";
            }
            $getterMethod .= "{$indent}        default => parent::__get(\$property),\n";
            $getterMethod .= "{$indent}    };\n";
            $getterMethod .= "{$indent}}";
            
            if (preg_match('/(\n)([ \t]*)public\s+function\s+__set/', $content, $matches, PREG_OFFSET_CAPTURE)) {
                $insertPos = $matches[0][1];
                $before = substr($content, 0, $insertPos);
                $after = substr($content, $insertPos);
                // Check if there's already a newline before __set
                $needsNewline = !preg_match('/\n\s*$/', $before);
                $content = $before . ($needsNewline ? "\n" : '') . $getterMethod . "\n" . $after;
            } elseif (preg_match('/(\n)\}/', $content, $matches, PREG_OFFSET_CAPTURE)) {
                $insertPos = $matches[0][1];
                $before = substr($content, 0, $insertPos);
                $after = substr($content, $insertPos);
                $content = $before . $getterMethod . "\n" . $after;
            }
        }

        return $content;
    }

    /**
     * Rebuild __set() method - full rebuild in Entity order (only non-primary keys)
     */
    private function rebuildSetterMethod(string $content, array $includedColumns, array $entityFieldToObjectConstant, array $existingComments): string
    {
        $cases = [];
        
        foreach ($includedColumns as $colName => $colInfo) {
            // Skip primary keys in setter
            if ($colInfo['is_primary']) {
                continue;
            }

            $camelCase = $this->snakeToCamelCase($colName);
            $objectConstantName = $entityFieldToObjectConstant[$colName] ?? $camelCase;
            $castType = $this->getCastTypeForSetter($colInfo['type']);
            $nullable = $colInfo['nullable'] ?? false;
            
            // Generate setter case with null handling for nullable fields
            $comment = $existingComments['setter'][$objectConstantName] ?? '';
            $commentStr = $comment !== '' ? ' // ' . $comment : '';
            
            if ($nullable) {
                // For nullable fields, check if value is valid before casting, otherwise set to null
                if ($castType === 'string') {
                    $cases[] = "self::{$objectConstantName} => \$this->entity->{$colName} = is_scalar(\$value) ? ({$castType})\$value : null{$commentStr}";
                } elseif ($castType === 'int') {
                    $cases[] = "self::{$objectConstantName} => \$this->entity->{$colName} = is_numeric(\$value) ? ({$castType})\$value : null{$commentStr}";
                } elseif ($castType === 'float') {
                    $cases[] = "self::{$objectConstantName} => \$this->entity->{$colName} = is_numeric(\$value) ? ({$castType})\$value : null{$commentStr}";
                } elseif ($castType === 'bool') {
                    $cases[] = "self::{$objectConstantName} => \$this->entity->{$colName} = is_bool(\$value) ? ({$castType})\$value : (is_scalar(\$value) ? (bool)\$value : null){$commentStr}";
                } else {
                    // For other types, just cast or set to null
                    $cases[] = "self::{$objectConstantName} => \$this->entity->{$colName} = \$value !== null ? ({$castType})\$value : null{$commentStr}";
                }
            } else {
                // For non-nullable fields, simple cast
                $cases[] = "self::{$objectConstantName} => \$this->entity->{$colName} = ({$castType})\$value{$commentStr}";
            }
        }

        if (empty($cases)) {
            // No settable fields, remove __set method if exists
            // Match indent at start of line (spaces/tabs, but not newlines)
            $methodPattern = '/(\n)([ \t]*)public\s+function\s+__set\s*\([^)]*\)\s*:\s*void\s*\{/';
            if (preg_match($methodPattern, $content, $methodStart, PREG_OFFSET_CAPTURE)) {
                $startPos = $methodStart[0][1];
                // Find matching closing brace
                $pos = $startPos + strlen($methodStart[0][0]);
                $braceCount = 1;
                $inString = false;
                $stringChar = '';
                
                while ($braceCount > 0 && $pos < strlen($content)) {
                    $char = $content[$pos];
                    if (!$inString && ($char === '"' || $char === "'")) {
                        $inString = true;
                        $stringChar = $char;
                    } elseif ($inString && $char === $stringChar && $content[$pos - 1] !== '\\') {
                        $inString = false;
                    }
                    if (!$inString) {
                        if ($char === '{') $braceCount++;
                        elseif ($char === '}') $braceCount--;
                    }
                    $pos++;
                }
                
                if ($braceCount === 0) {
                    $methodEnd = $pos;
                    // Check for PHPDoc before method
                    $docStart = $startPos;
                    $beforeMethod = substr($content, max(0, $startPos - 200), $startPos - max(0, $startPos - 200));
                    if (preg_match('/(\/\*\*.*?\*\/)\s*(?=public\s+function\s+__set)/s', $beforeMethod, $docMatch, PREG_OFFSET_CAPTURE)) {
                        $docStart = $startPos - (strlen($beforeMethod) - $docMatch[1][1]);
                    }
                    $before = substr($content, 0, $docStart);
                    $after = substr($content, $methodEnd);
                    // Remove trailing newline if present
                    $after = preg_replace('/^\s*\n/', '', $after);
                    $content = $before . $after;
                }
            }
            return $content;
        }

        // Replace __set method - find method with balanced braces
        // Match indent at start of line (spaces/tabs, but not newlines)
        $methodPattern = '/(\n)([ \t]*)public\s+function\s+__set\s*\([^)]*\)\s*:\s*void\s*\{/';
        if (preg_match($methodPattern, $content, $methodStart, PREG_OFFSET_CAPTURE)) {
            $startPos = $methodStart[0][1];
            $indent = $methodStart[2][0]; // Extract indent (spaces/tabs only, no newlines)
            
            // Find matching closing brace
            $pos = $startPos + strlen($methodStart[0][0]);
            $braceCount = 1;
            $inString = false;
            $stringChar = '';
            
            while ($braceCount > 0 && $pos < strlen($content)) {
                $char = $content[$pos];
                $nextChar = $pos + 1 < strlen($content) ? $content[$pos + 1] : '';
                
                // Handle strings
                if (!$inString && ($char === '"' || $char === "'")) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($inString && $char === $stringChar && $content[$pos - 1] !== '\\') {
                    $inString = false;
                }
                
                if (!$inString) {
                    if ($char === '{') {
                        $braceCount++;
                    } elseif ($char === '}') {
                        $braceCount--;
                    }
                }
                
                $pos++;
            }
            
            if ($braceCount === 0) {
                // Replace entire method including PHPDoc if present
                $methodEnd = $pos;
                
                // Check for PHPDoc before method
                $docStart = $startPos;
                $beforeMethod = substr($content, max(0, $startPos - 200), $startPos - max(0, $startPos - 200));
                if (preg_match('/(\/\*\*.*?\*\/)\s*(?=public\s+function\s+__set)/s', $beforeMethod, $docMatch, PREG_OFFSET_CAPTURE)) {
                    $docStart = $startPos - (strlen($beforeMethod) - $docMatch[1][1]);
                }
                
                $before = substr($content, 0, $docStart);
                $after = substr($content, $methodEnd);
                
                // Check if there's a newline before method, preserve it
                $beforeEndsWithNewline = preg_match('/\n\s*$/', $before);
                
                // Generate method with extracted indent
                $setterMethod = ($beforeEndsWithNewline ? '' : "\n") . "{$indent}public function __set(string \$property, mixed \$value): void\n";
                $setterMethod .= "{$indent}{\n";
                $setterMethod .= "{$indent}    match (\$property) {\n";
                // Update cases with correct indent (same as default: indent + 8 spaces)
                $indentedCases = array_map(fn($case) => $indent . '        ' . $case, $cases);
                $setterMethod .= implode(",\n", $indentedCases) . ",\n";
                $setterMethod .= "{$indent}        default => parent::__set(\$property, \$value),\n";
                $setterMethod .= "{$indent}    };\n";
                $setterMethod .= "{$indent}}";
                
                // Ensure proper spacing: check if there's a newline after method, preserve it
                $afterStartsWithNewline = preg_match('/^\s*\n/', $after);
                $content = $before . $setterMethod . ($afterStartsWithNewline ? '' : "\n") . $after;
            }
        } else {
            // Add __set method after __get or before closing brace
            // Extract indent from context (default 4 spaces)
            $indent = '    ';
            if (preg_match('/(\n)([ \t]*)(?:public|protected|private)\s+function/', $content, $indentMatch, PREG_OFFSET_CAPTURE)) {
                $indent = $indentMatch[2][0];
            }
            
            // Generate method with extracted indent
            $setterMethod = "{$indent}public function __set(string \$property, mixed \$value): void\n";
            $setterMethod .= "{$indent}{\n";
            $setterMethod .= "{$indent}    match (\$property) {\n";
            $indentedCases = array_map(fn($case) => $indent . '        ' . $case, $cases);
            $setterMethod .= implode(",\n", $indentedCases) . ",\n";
            $setterMethod .= "{$indent}        default => parent::__set(\$property, \$value),\n";
            $setterMethod .= "{$indent}    };\n";
            $setterMethod .= "{$indent}}";
            
            if (preg_match('/(\n)([ \t]*)public\s+function\s+__get\s*\([^)]*\)\s*:\s*mixed\s*\{/', $content, $matches, PREG_OFFSET_CAPTURE)) {
                // Find end of __get method
                $getStartPos = $matches[0][1] + strlen($matches[0][0]);
                $pos = $getStartPos;
                $braceCount = 1;
                $inString = false;
                $stringChar = '';
                
                while ($braceCount > 0 && $pos < strlen($content)) {
                    $char = $content[$pos];
                    if (!$inString && ($char === '"' || $char === "'")) {
                        $inString = true;
                        $stringChar = $char;
                    } elseif ($inString && $char === $stringChar && $content[$pos - 1] !== '\\') {
                        $inString = false;
                    }
                    if (!$inString) {
                        if ($char === '{') $braceCount++;
                        elseif ($char === '}') $braceCount--;
                    }
                    $pos++;
                }
                
                $before = substr($content, 0, $pos);
                $after = substr($content, $pos);
                // Check if there's already a newline after __get
                $needsNewline = !preg_match('/\n\s*$/', $before);
                $afterStartsWithNewline = preg_match('/^\s*\n/', $after);
                $content = $before . ($needsNewline ? "\n" : '') . $setterMethod . ($afterStartsWithNewline ? '' : "\n") . $after;
            } elseif (preg_match('/(\n)\}/', $content, $matches, PREG_OFFSET_CAPTURE)) {
                $insertPos = $matches[0][1];
                $before = substr($content, 0, $insertPos);
                $after = substr($content, $insertPos);
                $content = $before . $setterMethod . "\n" . $after;
            }
        }

        return $content;
    }

    /**
     * Rebuild PHPDoc - full rebuild preserving existing descriptions
     */
    private function rebuildPhpDoc(string $content, array $includedColumns, array $entityFieldToObjectConstant, \ReflectionClass $entityReflection): string
    {
        // Extract existing PHPDoc descriptions and custom class description
        $existingDescriptions = [];
        $customClassDescription = [];
        
        if (preg_match('/\/\*\*(.*?)\*\//s', $content, $docMatch)) {
            $docContent = $docMatch[1];
            
            // Split PHPDoc into lines
            $lines = explode("\n", $docContent);
            
            // Find first @property or @property-read line
            $firstPropertyLine = -1;
            foreach ($lines as $index => $line) {
                if (preg_match('/^\s*\*\s*@property(?:-read)?\s+/', $line)) {
                    $firstPropertyLine = $index;
                    break;
                }
            }
            
            // Extract custom description (lines before first @property, excluding standard lines)
            if ($firstPropertyLine > 0) {
                $standardPattern1 = '/^\s*\*\s*' . preg_quote($entityReflection->getShortName(), '/') . '\s+Object\s*$/';
                $standardPattern2 = '/^\s*\*\s*Auto-generated from Entity:\s*' . preg_quote($entityReflection->getName(), '/') . '\s*$/';
                $standardPattern3 = '/^\s*\*\s*$/'; // Empty line
                
                foreach (array_slice($lines, 0, $firstPropertyLine) as $line) {
                    // Skip standard lines
                    if (preg_match($standardPattern1, $line) || 
                        preg_match($standardPattern2, $line) || 
                        preg_match($standardPattern3, $line)) {
                        continue;
                    }
                    // Keep custom description lines
                    $trimmedLine = trim($line);
                    if ($trimmedLine !== '' && $trimmedLine !== '*') {
                        // Remove leading " * " if present, keep the rest
                        $customLine = preg_replace('/^\s*\*\s*/', '', $line);
                        if ($customLine !== '') {
                            $customClassDescription[] = $customLine;
                        }
                    }
                }
            }
            
            // Extract descriptions: @property type $name Description text
            if (preg_match_all('/@property(?:-read)?\s+\S+\s+\$(\w+)(?:\s+(.+))?/s', $docContent, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (isset($match[2]) && trim($match[2]) !== '') {
                        $existingDescriptions[$match[1]] = trim($match[2]);
                    }
                }
            }
        }

        // Build new PHPDoc
        $phpDocLines = [];
        $phpDocLines[] = " * {$entityReflection->getShortName()} Object";
        $phpDocLines[] = " * Auto-generated from Entity: {$entityReflection->getName()}";
        
        // Add custom class description if exists
        if (!empty($customClassDescription)) {
            $phpDocLines[] = " *";
            foreach ($customClassDescription as $customLine) {
                $phpDocLines[] = " * {$customLine}";
            }
        }
        
        $phpDocLines[] = " *";

        // Add properties in Entity order
        foreach ($includedColumns as $colName => $colInfo) {
            $camelCase = $this->snakeToCamelCase($colName);
            $objectConstantName = $entityFieldToObjectConstant[$colName] ?? $camelCase;
            $normalizedType = \Hilos\Database\Generator::normalizeType($colInfo['type']);
            $phpType = $this->getPhpDocType($normalizedType, $colInfo['is_primary'], $colInfo['nullable'] ?? false);
            $docType = $colInfo['is_primary'] ? '@property-read' : '@property';
            
            $description = $existingDescriptions[$objectConstantName] ?? '';
            $descriptionStr = $description !== '' ? ' ' . $description : '';
            
            $phpDocLines[] = " * {$docType} {$phpType} \${$objectConstantName}{$descriptionStr}";
        }

        $newPhpDoc = "/**\n" . implode("\n", $phpDocLines) . "\n */";

        // Replace PHPDoc
        if (preg_match('/\/\*\*.*?\*\//s', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $docEnd = $matches[0][1] + strlen($matches[0][0]);
            $afterDoc = substr($content, $docEnd);
            // Check if there's already a newline after PHPDoc
            $hasNewlineAfter = preg_match('/^\s*\n/', $afterDoc);
            $replacement = $newPhpDoc . ($hasNewlineAfter ? '' : "\n");
            $content = substr_replace($content, $replacement, $matches[0][1], strlen($matches[0][0]));
        } else {
            // Add PHPDoc before class declaration
            if (preg_match('/(final\s+class\s+\w+)/', $content, $matches)) {
                $content = str_replace($matches[1], $newPhpDoc . "\n" . $matches[1], $content);
            }
        }

        return $content;
    }

    /**
     * Rebuild toArray() method - full rebuild
     */
    private function rebuildToArrayMethod(string $content, array $includedColumns, array $entityFieldToObjectConstant): string
    {
        $arrayEntries = [];
        
        foreach ($includedColumns as $colName => $colInfo) {
            $camelCase = $this->snakeToCamelCase($colName);
            $objectConstantName = $entityFieldToObjectConstant[$colName] ?? $camelCase;
            
            $arrayEntries[] = "self::{$objectConstantName} => \$this->entity->{$colName}";
        }

        // Replace toArray method - find method with balanced braces
        // Match indent at start of line (spaces/tabs, but not newlines)
        $methodPattern = '/(\n)([ \t]*)public\s+function\s+toArray\s*\([^)]*\)\s*:\s*array\s*\{/';
        if (preg_match($methodPattern, $content, $methodStart, PREG_OFFSET_CAPTURE)) {
            $startPos = $methodStart[0][1];
            $indent = $methodStart[2][0]; // Extract indent (spaces/tabs only, no newlines)
            
            // Find matching closing brace
            $pos = $startPos + strlen($methodStart[0][0]);
            $braceCount = 1;
            $inString = false;
            $stringChar = '';
            
            while ($braceCount > 0 && $pos < strlen($content)) {
                $char = $content[$pos];
                
                // Handle strings
                if (!$inString && ($char === '"' || $char === "'")) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($inString && $char === $stringChar && $content[$pos - 1] !== '\\') {
                    $inString = false;
                }
                
                if (!$inString) {
                    if ($char === '{') {
                        $braceCount++;
                    } elseif ($char === '}') {
                        $braceCount--;
                    }
                }
                
                $pos++;
            }
            
            if ($braceCount === 0) {
                // Replace entire method including PHPDoc if present
                $methodEnd = $pos;
                
                // Check for PHPDoc before method
                $docStart = $startPos;
                $beforeMethod = substr($content, max(0, $startPos - 200), $startPos - max(0, $startPos - 200));
                if (preg_match('/(\/\*\*.*?\*\/)\s*(?=public\s+function\s+toArray)/s', $beforeMethod, $docMatch, PREG_OFFSET_CAPTURE)) {
                    $docStart = $startPos - (strlen($beforeMethod) - $docMatch[1][1]);
                }
                
                $before = substr($content, 0, $docStart);
                $after = substr($content, $methodEnd);
                
                // Check if there's a newline before method, preserve it
                $beforeEndsWithNewline = preg_match('/\n\s*$/', $before);
                
                // Generate method with extracted indent
                $toArrayMethod = ($beforeEndsWithNewline ? '' : "\n") . "{$indent}public function toArray(): array\n";
                $toArrayMethod .= "{$indent}{\n";
                $toArrayMethod .= "{$indent}    return [\n";
                // Update array entries with correct indent (same as return: indent + 4 spaces)
                $indentedEntries = array_map(fn($entry) => $indent . '        ' . $entry, $arrayEntries);
                $toArrayMethod .= implode(",\n", $indentedEntries) . ",\n";
                $toArrayMethod .= "{$indent}    ];\n";
                $toArrayMethod .= "{$indent}}";
                
                // Ensure proper spacing: check if there's a newline after method, preserve it
                $afterStartsWithNewline = preg_match('/^\s*\n/', $after);
                $content = $before . $toArrayMethod . ($afterStartsWithNewline ? '' : "\n") . $after;
            }
        } else {
            // Add toArray method before closing brace
            // Extract indent from context (default 4 spaces)
            $indent = '    ';
            if (preg_match('/(\n)([ \t]*)(?:public|protected|private)\s+function/', $content, $indentMatch, PREG_OFFSET_CAPTURE)) {
                $indent = $indentMatch[2][0];
            }
            
            // Generate method with extracted indent
            $toArrayMethod = "{$indent}public function toArray(): array\n";
            $toArrayMethod .= "{$indent}{\n";
            $toArrayMethod .= "{$indent}    return [\n";
            $indentedEntries = array_map(fn($entry) => $indent . '        ' . $entry, $arrayEntries);
            $toArrayMethod .= implode(",\n", $indentedEntries) . ",\n";
            $toArrayMethod .= "{$indent}    ];\n";
            $toArrayMethod .= "{$indent}}";
            
            if (preg_match('/(\n)\}/', $content, $matches, PREG_OFFSET_CAPTURE)) {
                $insertPos = $matches[0][1];
                $before = substr($content, 0, $insertPos);
                $after = substr($content, $insertPos);
                $content = $before . $toArrayMethod . "\n" . $after;
            }
        }

        return $content;
    }

    /**
     * Remove user-defined methods from content (to re-add them later)
     * Uses same logic as extractUserMethods to find methods with balanced braces
     */
    private function removeUserMethods(string $content): string
    {
        $excludedMethods = ['__get', '__set', 'create', 'fromEntity', 'sync', 'toArray'];
        
        // Find all method declarations
        $pattern = '/(?:\/\*\*.*?\*\/\s*)?(?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\([^)]*\)\s*\{/s';
        
        $offset = 0;
        $removals = [];
        
        while (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $methodName = $matches[1][0];
            $methodStart = $matches[0][1];
            
            if (!in_array($methodName, $excludedMethods, true)) {
                // Find the matching closing brace
                $braceStart = $methodStart + strlen($matches[0][0]) - 1;
                $braceCount = 1;
                $pos = $braceStart + 1;
                
                while ($braceCount > 0 && $pos < strlen($content)) {
                    if ($content[$pos] === '{') {
                        $braceCount++;
                    } elseif ($content[$pos] === '}') {
                        $braceCount--;
                    }
                    $pos++;
                }
                
                if ($braceCount === 0) {
                    // Find PHPDoc before method
                    $docStart = $methodStart;
                    if (preg_match('/(\/\*\*.*?\*\/)\s*(?=public|protected|private)/s', substr($content, 0, $methodStart), $docMatch, PREG_OFFSET_CAPTURE)) {
                        $docStart = $methodStart - (strlen(substr($content, 0, $methodStart)) - $docMatch[1][1]);
                    }
                    $methodEnd = $pos;
                    $removals[] = ['start' => $docStart, 'end' => $methodEnd];
                }
            }
            
            $offset = $methodStart + strlen($matches[0][0]);
        }
        
        // Remove methods from end to start to preserve positions
        rsort($removals);
        foreach ($removals as $removal) {
            $content = substr_replace($content, '', $removal['start'], $removal['end'] - $removal['start']);
        }

        return $content;
    }

    /**
     * Get cast type for setter
     */
    private function getCastTypeForSetter(string $type): string
    {
        return match ($type) {
            PhpType::INTEGER->value => 'int',
            PhpType::BOOLEAN->value => 'bool',
            PhpType::FLOAT->value, PhpType::DECIMAL->value, PhpType::DOUBLE->value => 'float',
            default => 'string',
        };
    }

    /**
     * Extract user-defined methods from Object file
     * Handles multi-line method bodies with balanced braces
     */
    private function extractUserMethods(string $content): array
    {
        $methods = [];
        $excludedMethods = ['__get', '__set', 'create', 'fromEntity', 'sync', 'toArray'];
        
        // Find all method declarations
        $pattern = '/(?:\/\*\*.*?\*\/\s*)?(?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\([^)]*\)\s*\{/s';
        
        $offset = 0;
        while (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $methodName = $matches[1][0];
            $methodStart = $matches[0][1];
            
            if (!in_array($methodName, $excludedMethods, true)) {
                // Find the matching closing brace
                $braceStart = $methodStart + strlen($matches[0][0]) - 1; // Position of opening {
                $braceCount = 1;
                $pos = $braceStart + 1;
                
                while ($braceCount > 0 && $pos < strlen($content)) {
                    if ($content[$pos] === '{') {
                        $braceCount++;
                    } elseif ($content[$pos] === '}') {
                        $braceCount--;
                    }
                    $pos++;
                }
                
                if ($braceCount === 0) {
                    // Extract method with its PHPDoc if present
                    $methodEnd = $pos;
                    // Try to find PHPDoc before method
                    $docStart = $methodStart;
                    if (preg_match('/(\/\*\*.*?\*\/)\s*(?=public|protected|private)/s', substr($content, 0, $methodStart), $docMatch, PREG_OFFSET_CAPTURE)) {
                        $docStart = $docMatch[1][1];
                    }
                    $fullMethod = substr($content, $docStart, $methodEnd - $docStart);
                    $methods[] = $fullMethod;
                }
            }
            
            $offset = $methodStart + strlen($matches[0][0]);
        }

        return $methods;
    }

    /**
     * Create Object files for entities without Object files
     */
    private function createObjectFiles(array $objectsToCreate, ?string $objectDir, ?string $entityDir): int
    {
        if (empty($objectsToCreate)) {
            return 0;
        }

        // Auto-detect directories if not provided
        if ($objectDir === null) {
            $cwd = getcwd();
            $possibleDirs = [
                $cwd . '/backend/Database/Object',
                $cwd . '/Database/Object',
                $cwd . '/Object',
            ];

            foreach ($possibleDirs as $dir) {
                $realPath = realpath($dir);
                if ($realPath !== false && is_dir($realPath)) {
                    $objectDir = $realPath;
                    break;
                }
            }

            // Create directory if it doesn't exist
            if ($objectDir === null) {
                $objectDir = $cwd . '/Database/Object';
                if (!is_dir($objectDir)) {
                    mkdir($objectDir, 0755, true);
                }
            }
        }

        $created = 0;
        foreach ($objectsToCreate as $tableName => $entityInfo) {
            try {
                $entityClassName = $entityInfo['class'];
                $className = substr($entityClassName, strrpos($entityClassName, '\\') + 1);
                $filePath = $objectDir . '/' . $className . '.php';

                // Detect namespace from directory
                $namespace = $this->detectNamespaceFromPath($objectDir);

                // Generate Object code
                $code = $this->generateObjectFromEntity($entityClassName, $namespace, $className);

                // Write file
                file_put_contents($filePath, $code);
                $created++;
            } catch (\Throwable $e) {
                echo "⚠ Failed to create Object file for {$tableName}: {$e->getMessage()}\n";
            }
        }

        return $created;
    }

    /**
     * Delete Object files without corresponding Entity files
     */
    private function deleteObjectFiles(array $filesToDelete): int
    {
        if (empty($filesToDelete)) {
            return 0;
        }

        $deleted = 0;
        foreach ($filesToDelete as $objectClassName => $objectInfo) {
            try {
                $file = $objectInfo['file'];
                if (file_exists($file)) {
                    unlink($file);
                    $deleted++;
                }
            } catch (\Throwable $e) {
                echo "⚠ Failed to delete Object file for {$objectClassName}: {$e->getMessage()}\n";
            }
        }

        return $deleted;
    }

    /**
     * Generate Object file from Entity class
     */
    private function generateObjectFromEntity(string $entityClassName, string $namespace, string $className): string
    {
        if (!class_exists($entityClassName)) {
            throw new \RuntimeException("Entity class not found: {$entityClassName}");
        }

        $reflection = new ReflectionClass($entityClassName);
        if (!$reflection->isSubclassOf(Entity::class)) {
            throw new \RuntimeException("Class {$entityClassName} is not an Entity");
        }

        // Get Entity file path to parse directives
        $entityFile = $reflection->getFileName();
        if ($entityFile === false) {
            throw new \RuntimeException("Cannot get Entity file path");
        }

        $entityContent = file_get_contents($entityFile);
        if ($entityContent === false) {
            throw new \RuntimeException("Cannot read Entity file");
        }

        // Parse excluded fields
        $excludedFields = $this->parseExcludedFields($entityContent);
        $entityColumns = $reflection->getConstant(Entity::META_COLUMNS) ?: [];
        $entityTypes = $reflection->getConstant(Entity::META_TYPES) ?: [];
        $entityPrimary = $reflection->getConstant(Entity::META_PRIMARY);
        if (is_string($entityPrimary)) {
            $entityPrimary = [$entityPrimary];
        } elseif (!is_array($entityPrimary)) {
            $entityPrimary = [];
        }

        // Get included columns
        $includedColumns = [];
        foreach ($entityColumns as $colName) {
            if (!in_array($colName, $excludedFields, true)) {
                $includedColumns[$colName] = [
                    'type' => $entityTypes[$colName] ?? 'string',
                    'is_primary' => in_array($colName, $entityPrimary, true),
                ];
            }
        }

        // Generate Object code
        $camelCaseConstants = [];
        $getterCases = [];
        $setterCases = [];
        $readOnlyProps = [];
        $writableProps = [];
        $toArrayEntries = [];

        foreach ($includedColumns as $colName => $colInfo) {
            $camelCase = $this->snakeToCamelCase($colName);
            $normalizedType = \Hilos\Database\Generator::normalizeType($colInfo['type']);
            $propertyType = $this->phpTypeToPropertyType($normalizedType);
            $isPrimary = $colInfo['is_primary'];

            // Determine nullable from Entity property
            $nullable = true; // Default
            if (preg_match('/public\s+(\??\w+)\s+\$' . preg_quote($colName, '/') . '/', $entityContent, $matches)) {
                $nullable = strpos($matches[1], '?') === 0;
            }
            $phpType = $nullable ? "?{$propertyType}" : $propertyType;

            $camelCaseConstants[] = "    public const string {$camelCase} = '{$camelCase}';";
            $getterCases[] = "            self::{$camelCase} => \$this->entity->{$colName}";
            $toArrayEntries[] = "            self::{$camelCase} => \$this->entity->{$colName}";

            if (!$isPrimary) {
                $castType = $this->getCastTypeForSetter($normalizedType);
                $setterCases[] = "            self::{$camelCase} => \$this->entity->{$colName} = ({$castType})\$value";
                $writableProps[] = " * @property {$phpType} \${$camelCase}";
            } else {
                $readOnlyProps[] = " * @property-read {$phpType} \${$camelCase}";
            }
        }

        $code = "<?php\n\n";
        $code .= "namespace {$namespace};\n\n";
        $code .= "use Hilos\\Database\\Object\\Object_;\n";
        $code .= "use {$entityClassName} as Entity{$className};\n\n";
        $code .= "/**\n";
        $code .= " * {$className} Object\n";
        $code .= " * Auto-generated from Entity: {$entityClassName}\n";
        $code .= " *\n";
        if (!empty($readOnlyProps) || !empty($writableProps)) {
            $code .= implode("\n", array_merge($readOnlyProps, $writableProps)) . "\n";
        }
        $code .= " */\n";
        $code .= "final class {$className} extends Object_\n";
        $code .= "{\n";
        
        if (!empty($camelCaseConstants)) {
            $code .= "    // Property name constants (camelCase for PHP)\n";
            $code .= implode("\n", $camelCaseConstants) . "\n\n";
        }
        
        $code .= "    protected Entity{$className} \$entity;\n";
        $code .= "    protected Entity{$className} \$entitySync;\n\n";

        $code .= "    public static function create(): self\n";
        $code .= "    {\n";
        $code .= "        \$obj = new self();\n";
        $code .= "        \$obj->entity = Entity{$className}::getEmpty();\n";
        $code .= "        \$obj->entitySync = clone \$obj->entity;\n";
        $code .= "        return \$obj;\n";
        $code .= "    }\n\n";
        $code .= "    public static function fromEntity(Entity{$className} \$entity): self\n";
        $code .= "    {\n";
        $code .= "        \$obj = new self();\n";
        $code .= "        \$obj->entity = \$entity;\n";
        $code .= "        \$obj->entitySync = clone \$entity;\n";
        $code .= "        return \$obj;\n";
        $code .= "    }\n\n";

        if (!empty($getterCases)) {
            $code .= "    public function __get(string \$property): mixed\n";
            $code .= "    {\n";
            $code .= "        return match (\$property) {\n";
            $code .= implode(",\n", $getterCases) . ",\n";
            $code .= "            default => parent::__get(\$property),\n";
            $code .= "        };\n";
            $code .= "    }\n\n";
        }

        if (!empty($setterCases)) {
            $code .= "    public function __set(string \$property, mixed \$value): void\n";
            $code .= "    {\n";
            $code .= "        match (\$property) {\n";
            $code .= implode(",\n", $setterCases) . ",\n";
            $code .= "            default => parent::__set(\$property, \$value),\n";
            $code .= "        };\n";
            $code .= "    }\n\n";
        }

        if (!empty($toArrayEntries)) {
            $code .= "    public function toArray(): array\n";
            $code .= "    {\n";
            $code .= "        return [\n";
            $code .= implode(",\n", $toArrayEntries) . ",\n";
            $code .= "        ];\n";
            $code .= "    }\n";
        }
        
        $code .= "}\n";

        return $code;
    }

    /**
     * Convert PhpType enum value to PHP type hint for properties
     */
    private function phpTypeToPropertyType(string $type): string
    {
        return match ($type) {
            PhpType::INTEGER->value => 'int',
            PhpType::BOOLEAN->value => 'bool',
            PhpType::DATETIME->value => 'string',
            default => $type,
        };
    }

    /**
     * Detect namespace from Object directory path
     */
    private function detectNamespaceFromPath(string $objectDir): string
    {
        // Try to extract namespace by looking at existing Object files
        $files = glob($objectDir . '/*.php');
        if ($files !== false && !empty($files)) {
            $content = file_get_contents($files[0]);
            if ($content !== false && preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
                $detectedNamespace = trim($matches[1]);
                $detectedNamespace = ltrim($detectedNamespace, '\\');
                if (!str_starts_with($detectedNamespace, 'App\\')) {
                    return $detectedNamespace;
                }
            }
        }

        // Normalize paths
        $objectDir = str_replace('\\', '/', $objectDir);
        $objectDir = rtrim($objectDir, '/');

        // Try to find composer.json
        $currentDir = $objectDir;
        $composerJsonPath = null;

        while ($currentDir !== '' && $currentDir !== '/') {
            $composerJsonPath = $currentDir . '/composer.json';
            if (file_exists($composerJsonPath)) {
                break;
            }
            $parentDir = dirname($currentDir);
            if ($parentDir === $currentDir) {
                break;
            }
            $currentDir = $parentDir;
        }

        // If composer.json found, use it to determine namespace
        if ($composerJsonPath !== null && file_exists($composerJsonPath)) {
            $composerContent = file_get_contents($composerJsonPath);
            if ($composerContent !== false) {
                $composer = json_decode($composerContent, true);
                if (isset($composer['autoload']['psr-4']) && is_array($composer['autoload']['psr-4'])) {
                    $composerDir = dirname($composerJsonPath);
                    $composerDir = str_replace('\\', '/', $composerDir);
                    $composerDir = rtrim($composerDir, '/');

                    $relativePath = str_replace($composerDir . '/', '', $objectDir);

                    foreach ($composer['autoload']['psr-4'] as $namespacePrefix => $pathPrefix) {
                        $pathPrefix = rtrim(str_replace('\\', '/', $pathPrefix), '/');

                        if (str_starts_with($relativePath, $pathPrefix . '/') || $relativePath === $pathPrefix) {
                            $remainder = substr($relativePath, strlen($pathPrefix));
                            $remainder = ltrim($remainder, '/');

                            $remainderParts = explode('/', $remainder);
                            $namespaceParts = [];
                            foreach ($remainderParts as $part) {
                                if ($part !== '') {
                                    $namespaceParts[] = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $part)));
                                }
                            }

                            $namespacePrefix = rtrim($namespacePrefix, '\\');
                            if (!empty($namespaceParts)) {
                                return $namespacePrefix . '\\' . implode('\\', $namespaceParts);
                            } else {
                                return $namespacePrefix;
                            }
                        }
                    }
                }
            }
        }

        // Fallback: try to extract from path structure
        $path = str_replace('\\', '/', $objectDir);
        $parts = explode('/', $path);

        $objectIndex = -1;
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            if ($parts[$i] === 'Object') {
                $objectIndex = $i;
                break;
            }
        }

        if ($objectIndex > 0) {
            $backendIndex = -1;
            for ($i = $objectIndex - 1; $i >= 0; $i--) {
                if (in_array($parts[$i], ['backend', 'src', 'app'], true)) {
                    $backendIndex = $i;
                    break;
                }
            }

            if ($backendIndex >= 0) {
                $namespaceParts = [];
                if ($backendIndex > 0) {
                    $projectParts = array_slice($parts, 0, $backendIndex);
                    foreach ($projectParts as $part) {
                        $namespaceParts[] = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $part)));
                    }
                }
                $pathParts = array_slice($parts, $backendIndex, $objectIndex - $backendIndex + 1);
                foreach ($pathParts as $part) {
                    $namespaceParts[] = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $part)));
                }
                return implode('\\', $namespaceParts);
            } else {
                $namespaceParts = array_slice($parts, 0, $objectIndex + 1);
                return implode('\\', array_map(fn($p) => str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $p))), $namespaceParts));
            }
        }

        return 'App\\Object';
    }
}

