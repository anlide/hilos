<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands\DbEntityFixCommand;

use Hilos\Database\Entity\Item\Entity;
use Hilos\Utils\Helpers\StringHelper;
use ReflectionClass;

/**
 * EntityCollectionFixer trait.
 *
 * Handles synchronization of EntityCollection files (EntityCollection/{Name}s.php) with Entity classes.
 *
 * Responsibilities:
 * - Compare EntityCollection imports with Entity class
 * - Update method type hints to match Entity class
 * - Preserve user-defined methods
 */
trait EntityCollectionFixer
{
    /**
     * Load EntityCollection files from directory.
     *
     * @param ?string $entityCollectionDir EntityCollection files directory
     * @param int $syntaxErrors Reference to syntax error counter
     * @param array<string, string> $brokenFiles Reference to broken files (file path => error message)
     * @return array<string, array{class: string, file: string, reflection: ReflectionClass, entity_class: string}> Loaded EntityCollection files info
     */
    protected function loadEntityCollections(?string $entityCollectionDir, int &$syntaxErrors = 0, array &$brokenFiles = []): array
    {
        // Auto-detect if not provided
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

        $entityCollections = [];
        $files = glob($entityCollectionDir . '/*.php');

        if ($files === false) {
            return $entityCollections;
        }

        foreach ($files as $file) {
            $className = $this->extractClassNameFromEntityCollectionFile($file);
            if ($className === null) {
                $brokenFiles[$file] = 'Cannot extract class name from file';
                continue;
            }

            try {
                // Check PHP syntax
                $output = [];
                $returnVar = 0;
                exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $returnVar);
                if ($returnVar !== 0) {
                    $errorMessage = implode(' ', $output);
                    $brokenFiles[$file] = "PHP syntax error: {$errorMessage}";
                    $syntaxErrors++;
                    continue;
                }

                if (!class_exists($className)) {
                    require_once $file;
                }

                $reflection = new ReflectionClass($className);

                // Extract Entity class name from EntityCollection
                $entityClassName = $this->extractEntityClassNameFromEntityCollection($reflection, $file);
                if ($entityClassName === null) {
                    $brokenFiles[$file] = 'Cannot determine Entity class name';
                    continue;
                }

                $entityCollections[$entityClassName] = [
                    'class' => $className,
                    'file' => $file,
                    'reflection' => $reflection,
                    'entity_class' => $entityClassName,
                ];
            } catch (\Throwable $e) {
                $brokenFiles[$file] = $e->getMessage();
                continue;
            }
        }

        return $entityCollections;
    }

    /**
     * Extract class name from EntityCollection file.
     *
     * @param string $file EntityCollection file path
     * @return ?string Fully qualified class name or null if not found
     */
    private function extractClassNameFromEntityCollectionFile(string $file): ?string
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
        // Remove single-line comments
        $contentWithoutComments = preg_replace('/\/\/.*$/m', '', $content);
        // Remove multi-line comments
        $contentWithoutComments = preg_replace('/\/\*.*?\*\//s', '', $contentWithoutComments);
        // Remove single-quoted strings
        $contentWithoutComments = preg_replace("/'[^']*'/", "''", $contentWithoutComments);
        // Remove double-quoted strings
        $contentWithoutComments = preg_replace('/"[^"]*"/', '""', $contentWithoutComments);

        // Extract class name - look for class definition at the start of a line
        // (after optional whitespace) to avoid matching "class" in remaining text
        // Pattern: start of line, optional whitespace, optional "final", "class", whitespace, class name
        if (preg_match('/^\s*(?:final\s+)?class\s+(\w+)/m', $contentWithoutComments, $classMatch)) {
            $className = trim($classMatch[1]);
            return $namespace . '\\' . $className;
        }

        return null;
    }

    /**
     * Extract Entity class name from EntityCollection reflection or file.
     *
     * @param ReflectionClass $entityCollectionReflection EntityCollection class reflection
     * @param string $entityCollectionFile EntityCollection file path
     * @return ?string Entity class name or null if not found
     */
    private function extractEntityClassNameFromEntityCollection(ReflectionClass $entityCollectionReflection, string $entityCollectionFile): ?string
    {
        $content = file_get_contents($entityCollectionFile);
        if ($content === false) {
            return null;
        }

        // Look for ENTITY_CLASS constant first
        if (preg_match('/ENTITY_CLASS\s*=\s*(\w+)::class/', $content, $constMatch)) {
            $entityAlias = $constMatch[1];
            // Resolve alias from use statements
            if (preg_match_all('/^use\s+([^\s;]+)(?:\s+as\s+(\w+))?;/m', $content, $useMatches, PREG_SET_ORDER)) {
                foreach ($useMatches as $useMatch) {
                    $fullClassName = trim($useMatch[1]);
                    $alias = $useMatch[2] ?? $this->getShortClassName($fullClassName);
                    if ($alias === $entityAlias && str_contains($fullClassName, '\\Entity\\') && !str_contains($fullClassName, '\\EntityCollection\\')) {
                        return $fullClassName;
                    }
                }
            }
        }

        // Fallback: look for use statement with Entity class
        if (preg_match_all('/^use\s+([^\s;]+)(?:\s+as\s+(\w+))?;/m', $content, $useMatches, PREG_SET_ORDER)) {
            foreach ($useMatches as $useMatch) {
                $fullClassName = trim($useMatch[1]);
                if (str_contains($fullClassName, '\\Entity\\') && !str_contains($fullClassName, '\\EntityCollection\\')) {
                    return $fullClassName;
                }
            }
        }

        // Fallback: infer from EntityCollection class name
        $entityCollectionNamespace = $entityCollectionReflection->getNamespaceName();
        $entityCollectionShortName = $entityCollectionReflection->getShortName();
        $entityShortName = StringHelper::singularize($entityCollectionShortName);
        $entityNamespace = str_replace('\\Entity\\Collection\\' . $entityCollectionShortName, '\\Entity\\Item\\' . $entityShortName, $entityCollectionReflection->getName());

        return class_exists($entityNamespace) ? $entityNamespace : null;
    }

    /**
     * Prepare fixes for EntityCollection files.
     *
     * @param array $entities Loaded Entity classes
     * @param array $entityCollections Loaded EntityCollection files
     * @param ?string $tableFilter Table name filter
     * @return array Fixes to apply
     */
    protected function prepareEntityCollectionFixes(array $entities, array $entityCollections, ?string $tableFilter): array
    {
        $fixes = [];

        foreach ($entities as $tableName => $entityInfo) {
            $entityClassName = $entityInfo['class'];

            // Apply table filter if specified
            if ($tableFilter !== null && $tableName !== $tableFilter) {
                continue;
            }

            // Check if EntityCollection exists for this Entity
            // Try direct lookup first
            $entityCollectionInfo = $entityCollections[$entityClassName] ?? null;

            // If not found, try to find by file name (handle pluralization mismatch)
            if ($entityCollectionInfo === null) {
                $entityReflection = $entityInfo['reflection'];
                $entityShortName = $entityReflection->getShortName();
                $entityCollectionShortName = StringHelper::pluralize($entityShortName);

                // Try to find EntityCollection by checking all loaded collections
                // and comparing their file names or class names
                foreach ($entityCollections as $loadedEntityClassName => $collectionInfo) {
                    $collectionReflection = $collectionInfo['reflection'];
                    $collectionShortName = $collectionReflection->getShortName();

                    // Check if collection name matches expected pluralized name
                    if ($collectionShortName === $entityCollectionShortName) {
                        // Verify that the loaded Entity class matches our Entity class
                        if ($loadedEntityClassName === $entityClassName) {
                            $entityCollectionInfo = $collectionInfo;
                            break;
                        }
                    }
                }
            }

            if ($entityCollectionInfo === null) {
                // EntityCollection doesn't exist - will be created
                continue;
            }

            // Compare EntityCollection with Entity
            $collectionFixes = $this->compareEntityCollectionWithEntity($entityCollectionInfo, $entityInfo);
            if (!empty($collectionFixes)) {
                $fixes[$entityClassName] = $collectionFixes;
            }
        }

        return $fixes;
    }

    /**
     * Compare EntityCollection with Entity and prepare fixes.
     *
     * @param array $entityCollectionInfo EntityCollection info
     * @param array $entityInfo Entity info
     * @return array Fixes to apply
     */
    private function compareEntityCollectionWithEntity(array $entityCollectionInfo, array $entityInfo): array
    {
        $fixes = [];
        $entityCollectionFile = $entityCollectionInfo['file'];
        $content = file_get_contents($entityCollectionFile);
        if ($content === false) {
            return $fixes;
        }

        $entityClassName = $entityInfo['class'];
        $entityReflection = $entityInfo['reflection'];
        $entityShortName = $entityReflection->getShortName();

        // Calculate expected Entity class name from EntityCollection class name
        $entityCollectionReflection = $entityCollectionInfo['reflection'];
        $entityCollectionShortName = $entityCollectionReflection->getShortName();
        $entityCollectionNamespace = $entityCollectionReflection->getNamespaceName();

        // Apply singularization to get Entity short name
        $expectedEntityShortName = StringHelper::singularize($entityCollectionShortName);

        // Replace EntityCollection namespace with Entity namespace
        $expectedEntityNamespace = str_replace('\\EntityCollection', '\\Entity', $entityCollectionNamespace);

        // Build expected Entity class name
        $expectedEntityClass = $expectedEntityNamespace . '\\' . $expectedEntityShortName;

        // Parse EntityCollection file with expected Entity class
        $parsed = $this->parseEntityCollectionFile($entityCollectionFile, $expectedEntityClass);
        if ($parsed === null) {
            return $fixes;
        }

        // Compare imports
        $currentEntityClass = $parsed['entity_class'] ?? null;
        if ($currentEntityClass !== $entityClassName) {
            $fixes['update_imports'] = [
                'current' => $currentEntityClass,
                'expected' => $entityClassName,
                'entity_short_name' => $entityShortName,
            ];
        }

        // Compare method type hints
        $expectedEntityAlias = "Entity{$entityShortName}";
        $currentEntityAlias = $parsed['entity_alias'] ?? null;

        // Check if type hints are correct in methods
        $methodsToCheck = ['get', 'first', 'last', 'current', 'offsetGet'];
        $wrongTypeHints = [];

        foreach ($methodsToCheck as $methodName) {
            $expectedReturnType = $parsed['methods'][$methodName]['return_type'] ?? null;
            if ($expectedReturnType !== null && $expectedReturnType !== "?{$expectedEntityAlias}" && $expectedReturnType !== "{$expectedEntityAlias}|null") {
                // Check if it's actually correct but with different alias
                $actualReturnType = $parsed['methods'][$methodName]['actual_return_type'] ?? null;
                if ($actualReturnType !== "?{$expectedEntityAlias}" && $actualReturnType !== "{$expectedEntityAlias}|null") {
                    $wrongTypeHints[$methodName] = [
                        'current' => $actualReturnType ?? $expectedReturnType,
                        'expected' => "?{$expectedEntityAlias}",
                    ];
                }
            }
        }

        if (!empty($wrongTypeHints)) {
            $fixes['update_type_hints'] = [
                'wrong' => $wrongTypeHints,
                'entity_alias' => $expectedEntityAlias,
            ];
        }

        return $fixes;
    }

    /**
     * Parse EntityCollection file to extract current structure.
     *
     * @param string $filePath EntityCollection file path
     * @return ?array Parsed structure or null if failed
     */
    protected function parseEntityCollectionFile(string $filePath, ?string $expectedEntityClass = null): ?array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $parsed = [
            'entity_class' => null,
            'entity_alias' => null,
            'methods' => [],
        ];

        // Parse imports
        $foundEntityClasses = [];
        if (preg_match_all('/^use\s+([^\s;]+)(?:\s+as\s+(\w+))?;/m', $content, $useMatches, PREG_SET_ORDER)) {
            foreach ($useMatches as $useMatch) {
                $fullClassName = $useMatch[1];
                $alias = $useMatch[2] ?? null;

                // Skip base classes
                if ($fullClassName === 'Hilos\\Database\\Entity\\Collection\\EntityCollection' ||
                    $fullClassName === 'Hilos\\Database\\Entity\\Item\\Entity') {
                    continue;
                }

                if (str_contains($fullClassName, '\\Entity\\') && !str_contains($fullClassName, '\\EntityCollection\\')) {
                    $foundEntityClasses[] = [
                        'class' => $fullClassName,
                        'alias' => $alias ?? $this->getShortClassName($fullClassName),
                    ];
                }
            }
        }

        // If expected Entity class is provided, search for it specifically
        if ($expectedEntityClass !== null) {
            foreach ($foundEntityClasses as $entityInfo) {
                if ($entityInfo['class'] === $expectedEntityClass) {
                    $parsed['entity_class'] = $entityInfo['class'];
                    $parsed['entity_alias'] = $entityInfo['alias'];
                    break;
                }
            }
        } else {
            // Fallback: use first found Entity class (for backward compatibility)
            if (!empty($foundEntityClasses)) {
                $parsed['entity_class'] = $foundEntityClasses[0]['class'];
                $parsed['entity_alias'] = $foundEntityClasses[0]['alias'];
            }
        }

        // Parse method return types
        $methodsToCheck = ['get', 'first', 'last', 'current', 'offsetGet'];
        foreach ($methodsToCheck as $methodName) {
            // Match method signature: public function get(...): ?EntityUser
            if (preg_match('/(?:public|private|protected)\s+function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*:\s*(\??\w+)/', $content, $match)) {
                $parsed['methods'][$methodName] = [
                    'return_type' => trim($match[1]),
                    'actual_return_type' => trim($match[1]),
                ];
            }
        }

        return $parsed;
    }

    /**
     * Get short class name from full class name.
     *
     * @param string $fullClassName Fully qualified class name
     * @return string Short class name (last segment)
     */
    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);
        return end($parts);
    }

    /**
     * Apply fixes to EntityCollection files.
     *
     * @param array $fixes Fixes to apply (keyed by entity class name)
     * @param array $entityCollections Loaded EntityCollection files info
     * @param array $entities Loaded Entity files info
     * @return int Number of files updated
     */
    protected function applyEntityCollectionFixes(array $fixes, array $entityCollections, array $entities): int
    {
        $updated = 0;

        foreach ($fixes as $entityClassName => $collectionFixes) {
            // Get EntityCollection info
            $entityCollectionInfo = $entityCollections[$entityClassName] ?? null;
            if ($entityCollectionInfo === null) {
                continue;
            }

            // Get Entity info
            $entityInfo = null;
            foreach ($entities as $tableName => $info) {
                if ($info['class'] === $entityClassName) {
                    $entityInfo = $info;
                    break;
                }
            }
            if ($entityInfo === null) {
                continue;
            }

            $entityCollectionFile = $entityCollectionInfo['file'];

            if ($this->applyEntityCollectionFileFixes($entityCollectionFile, $collectionFixes, $entityInfo)) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Apply fixes to a single EntityCollection file.
     *
     * @param string $entityCollectionFile EntityCollection file path
     * @param array $fixes Fixes to apply
     * @param array $entityInfo Entity info
     * @return bool Success
     */
    private function applyEntityCollectionFileFixes(string $entityCollectionFile, array $fixes, array $entityInfo): bool
    {
        $content = file_get_contents($entityCollectionFile);
        if ($content === false) {
            return false;
        }

        $entityClassName = $entityInfo['class'];
        $entityReflection = $entityInfo['reflection'];
        $entityShortName = $entityReflection->getShortName();
        $expectedEntityAlias = "Entity{$entityShortName}";

        // Apply import fixes
        if (isset($fixes['update_imports'])) {
            $content = $this->rebuildEntityCollectionImports($content, $entityClassName, $expectedEntityAlias);
        }

        // Apply type hint fixes
        if (isset($fixes['update_type_hints'])) {
            $content = $this->rebuildEntityCollectionTypeHints($content, $expectedEntityAlias, $fixes['update_type_hints']['wrong']);
        }

        // Ensure ENTITY_CLASS constant is present
        $content = $this->ensureEntityCollectionHasEntityClassConstant($content, $expectedEntityAlias);

        // Write file
        return file_put_contents($entityCollectionFile, $content) !== false;
    }

    /**
     * Rebuild imports in EntityCollection file.
     *
     * @param string $content Current file content
     * @param string $entityClassName Entity class name
     * @param string $entityAlias Entity alias
     * @return string Updated content
     */
    private function rebuildEntityCollectionImports(string $content, string $entityClassName, string $entityAlias): string
    {
        // Remove old Entity import
        $content = preg_replace('/use\s+[^;]+\\\Entity\\\[^;]+(?:\s+as\s+\w+)?;/', '', $content);

        // Find namespace section
        if (preg_match('/(namespace\s+[^;]+;\n\n)(.*?)(\n(?:final\s+)?class\s+\w+)/s', $content, $matches)) {
            $existingUses = $matches[2];

            // Separate use statements from PHPDoc block
            $useStatements = '';
            $phpDocBlock = '';
            $separator = '';

            // Check if there's a PHPDoc block (/** ... */)
            // Pattern: capture use statements, separator (newlines), and PHPDoc block separately
            if (preg_match('/(.*?)(\n+)\s*(\/\*\*.*?\*\/)\s*$/s', $existingUses, $parts)) {
                // Has PHPDoc: separate use statements, separator, and PHPDoc
                $useStatements = trim($parts[1]);
                $separator = $parts[2]; // Preserve original separator (newlines before PHPDoc)
                $phpDocBlock = $parts[3]; // PHPDoc block without leading/trailing newlines
            } else {
                // No PHPDoc: only use statements
                $useStatements = trim($existingUses);
                $phpDocBlock = '';
                $separator = '';
            }

            // Process use statements: remove empty lines and normalize
            if (!empty($useStatements)) {
                $useStatements = preg_replace('/\n\s*\n/', "\n", $useStatements);
                $useStatements = trim($useStatements);
            }

            // Add new Entity import
            $newUse = "use {$entityClassName} as {$entityAlias};";

            // Add EntityCollection import if not present
            $entityCollectionUse = "use Hilos\\Database\\Entity\\Collection\\EntityCollection;";
            if (!str_contains($useStatements, $entityCollectionUse)) {
                $newUse .= "\n" . $entityCollectionUse;
            }

            // Add DatabaseException import if not present
            $dbExceptionUse = "use Hilos\\Exception\\DatabaseException;";
            if (!str_contains($useStatements, $dbExceptionUse) && str_contains($content, 'DatabaseException')) {
                $newUse .= "\n" . $dbExceptionUse;
            }

            // Build all uses section, preserving PHPDoc formatting
            if (empty($useStatements) && empty($phpDocBlock)) {
                // No existing uses and no PHPDoc
                $allUses = $newUse . "\n";
            } elseif (empty($useStatements)) {
                // Only PHPDoc, no use statements
                $allUses = $newUse . "\n" . $separator . $phpDocBlock;
            } elseif (empty($phpDocBlock)) {
                // Only use statements, no PHPDoc
                $allUses = $newUse . "\n" . $useStatements . "\n";
            } else {
                // Both use statements and PHPDoc
                $allUses = $newUse . "\n" . $useStatements . $separator . $phpDocBlock;
            }

            $content = str_replace($matches[0], $matches[1] . $allUses . $matches[3], $content);
        } else {
            // Insert after namespace
            if (preg_match('/(namespace\s+[^;]+;\n\n)/', $content, $nsMatch)) {
                $newUse = "use {$entityClassName} as {$entityAlias};\n";
                $newUse .= "use Hilos\\Database\\Entity\\Collection\\EntityCollection;\n";
                $newUse .= "use Hilos\\Exception\\DatabaseException;\n";
                $content = str_replace($nsMatch[1], $nsMatch[1] . $newUse, $content);
            }
        }

        return $content;
    }

    /**
     * Ensure EntityCollection has ENTITY_CLASS constant.
     *
     * @param string $content EntityCollection file content
     * @param string $entityAlias Entity class alias
     * @return string Updated content with ENTITY_CLASS constant if added
     */
    private function ensureEntityCollectionHasEntityClassConstant(string $content, string $entityAlias): string
    {
        if (str_contains($content, 'ENTITY_CLASS')) {
            return $content;
        }
        return preg_replace(
            '/((?:final\s+)?class\s+\w+\s+extends\s+EntityCollection\s*\{\s*)\n/',
            '$1' . "\n    public const string ENTITY_CLASS = {$entityAlias}::class;\n\n",
            $content,
            1
        );
    }

    /**
     * Rebuild type hints in EntityCollection file.
     *
     * @param string $content Current file content
     * @param string $entityAlias Entity alias
     * @param array $wrongTypeHints Wrong type hints to fix
     * @return string Updated content
     */
    private function rebuildEntityCollectionTypeHints(string $content, string $entityAlias, array $wrongTypeHints): string
    {
        foreach ($wrongTypeHints as $methodName => $typeInfo) {
            $expectedType = "?{$entityAlias}";

            // Replace return type in method signature
            $pattern = '/((?:public|private|protected)\s+function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*):\s*[^{]+/';
            $replacement = '$1: ' . $expectedType;
            $content = preg_replace($pattern, $replacement, $content);
        }

        return $content;
    }

    /**
     * Create EntityCollection file from Entity class.
     *
     * @param string $entityClassName Entity class name
     * @param string $entityCollectionDir EntityCollection directory
     * @param string $namespace EntityCollection namespace
     * @param ReflectionClass $entityReflection Entity reflection
     * @return bool Success
     */
    protected function createEntityCollectionFile(string $entityClassName, string $entityCollectionDir, string $namespace, ReflectionClass $entityReflection): bool
    {
        if (!is_dir($entityCollectionDir)) {
            if (!mkdir($entityCollectionDir, 0755, true)) {
                return false;
            }
        }

        $entityShortName = $entityReflection->getShortName();
        $entityCollectionShortName = StringHelper::pluralize($entityShortName);
        $entityCollectionFile = $entityCollectionDir . '/' . $entityCollectionShortName . '.php';

        // Determine aliases
        $entityAlias = "Entity{$entityShortName}";

        // Build file content
        $content = "<?php\n\n";
        $content .= "namespace {$namespace};\n\n";
        $content .= "use {$entityClassName} as {$entityAlias};\n";
        $content .= "use Hilos\\Database\\Entity\\Collection\\EntityCollection;\n\n";
        $content .= "/**\n";
        $content .= " * {$entityCollectionShortName} Entity Collection\n";
        $content .= " *\n";
        $content .= " * @extends EntityCollection<{$entityAlias}>\n";
        $content .= " * @implements \\Iterator<int|string, {$entityAlias}>\n";
        $content .= " * @implements \\ArrayAccess<int|string, {$entityAlias}>\n";
        $content .= " */\n";
        $content .= "final class {$entityCollectionShortName} extends EntityCollection\n";
        $content .= "{\n";
        $content .= "    public const string ENTITY_CLASS = {$entityAlias}::class;\n";
        $content .= "}\n";

        return file_put_contents($entityCollectionFile, $content) !== false;
    }

    /**
     * Find EntityCollection files that need to be created.
     *
     * @param array $entities Loaded Entity classes
     * @param array $entityCollections Loaded EntityCollection files
     * @param ?string $tableFilter Table name filter
     * @param array $brokenEntities Broken Entity files
     * @return array<string, array{entity_class: string, reflection: ReflectionClass}> EntityCollections to create
     */
    protected function findEntityCollectionsToCreate(array $entities, array $entityCollections, ?string $tableFilter, array $brokenEntities): array
    {
        $toCreate = [];

        foreach ($entities as $tableName => $entityInfo) {
            $entityClassName = $entityInfo['class'];
            $entityFile = $entityInfo['file'];

            // Skip broken entities
            if (isset($brokenEntities[$entityFile])) {
                continue;
            }

            // Apply table filter if specified
            if ($tableFilter !== null && $tableName !== $tableFilter) {
                continue;
            }

            // Check if EntityCollection already exists
            // Try direct lookup first
            $entityCollectionInfo = $entityCollections[$entityClassName] ?? null;

            // If not found, try to find by file name (handle pluralization mismatch)
            if ($entityCollectionInfo === null) {
                $entityReflection = $entityInfo['reflection'];
                $entityShortName = $entityReflection->getShortName();
                $entityCollectionShortName = StringHelper::pluralize($entityShortName);

                // Try to find EntityCollection by checking all loaded collections
                // and comparing their file names or class names
                foreach ($entityCollections as $loadedEntityClassName => $collectionInfo) {
                    $collectionReflection = $collectionInfo['reflection'];
                    $collectionShortName = $collectionReflection->getShortName();

                    // Check if collection name matches expected pluralized name
                    if ($collectionShortName === $entityCollectionShortName) {
                        // Verify that the loaded Entity class matches our Entity class
                        if ($loadedEntityClassName === $entityClassName) {
                            $entityCollectionInfo = $collectionInfo;
                            break;
                        }
                    }
                }
            }

            // If EntityCollection exists (found by direct lookup or by name), skip
            if ($entityCollectionInfo !== null) {
                continue;
            }

            $toCreate[$entityClassName] = [
                'entity_class' => $entityClassName,
                'reflection' => $entityInfo['reflection'],
            ];
        }

        return $toCreate;
    }

    /**
     * Find EntityCollection files to create for newly created Entity files.
     *
     * @param array $tablesToCreate Array of table names => TableInfo for newly created tables
     * @param array $entitiesAfter Loaded Entity classes after creation (includes new ones)
     * @param array $entityCollections Loaded EntityCollection files
     * @param array $brokenEntities Broken Entity files
     * @return array<string, array{entity_class: string, reflection: ReflectionClass}> EntityCollections to create
     */
    protected function findEntityCollectionsToCreateForNewEntities(array $tablesToCreate, array $entitiesAfter, array $entityCollections, array $brokenEntities): array
    {
        $toCreate = [];

        foreach ($tablesToCreate as $tableName => $dbTable) {
            // Skip migration table
            if ($tableName === 'migration') {
                continue;
            }

            // Find corresponding Entity in entitiesAfter
            if (!isset($entitiesAfter[$tableName])) {
                continue;
            }

            $entityInfo = $entitiesAfter[$tableName];
            $entityClassName = $entityInfo['class'];
            $entityFile = $entityInfo['file'];

            // Skip broken entities
            if (isset($brokenEntities[$entityFile])) {
                continue;
            }

            // Check if EntityCollection already exists
            $entityCollectionInfo = $entityCollections[$entityClassName] ?? null;

            // If not found, try to find by file name (handle pluralization mismatch)
            if ($entityCollectionInfo === null) {
                $entityReflection = $entityInfo['reflection'];
                $entityShortName = $entityReflection->getShortName();
                $entityCollectionShortName = StringHelper::pluralize($entityShortName);

                // Try to find EntityCollection by checking all loaded collections
                foreach ($entityCollections as $loadedEntityClassName => $collectionInfo) {
                    $collectionReflection = $collectionInfo['reflection'];
                    $collectionShortName = $collectionReflection->getShortName();

                    // Check if collection name matches expected pluralized name
                    if ($collectionShortName === $entityCollectionShortName) {
                        // Verify that the loaded Entity class matches our Entity class
                        if ($loadedEntityClassName === $entityClassName) {
                            $entityCollectionInfo = $collectionInfo;
                            break;
                        }
                    }
                }
            }

            // If EntityCollection exists, skip
            if ($entityCollectionInfo !== null) {
                continue;
            }

            $toCreate[$entityClassName] = [
                'entity_class' => $entityClassName,
                'reflection' => $entityInfo['reflection'],
            ];
        }

        return $toCreate;
    }

    /**
     * Find EntityCollection files to create for new entities (estimate phase).
     * Predicts entity class names from table names without loading files.
     *
     * @param array $tablesToCreate Array of table names => TableInfo for newly created tables
     * @param ?string $entityDir Entity directory
     * @param ?string $entityNamespace Entity namespace
     * @param array $entityCollections Loaded EntityCollection files
     * @param array $brokenEntities Broken Entity files
     * @return array<string, array{entity_class: string, reflection: ReflectionClass}> EntityCollections to create
     */
    protected function findEntityCollectionsToCreateForNewEntitiesEstimate(array $tablesToCreate, ?string $entityDir, ?string $entityNamespace, array $entityCollections, array $brokenEntities): array
    {
        $toCreate = [];

        foreach ($tablesToCreate as $tableName => $dbTable) {
            // Skip migration table
            if ($tableName === 'migration') {
                continue;
            }

            // Predict Entity class name from table name
            $entityShortName = $this->tableToPascalCase($tableName);
            if ($entityNamespace === null) {
                // Try to detect namespace from entityDir
                if ($entityDir !== null) {
                    $entityNamespace = \Hilos\Database\Generator::detectNamespaceFromPath($entityDir);
                }
                if ($entityNamespace === null) {
                    continue; // Cannot determine namespace
                }
            }
            $entityClassName = $entityNamespace . '\\' . $entityShortName;

            // Check if EntityCollection already exists
            $entityCollectionInfo = $entityCollections[$entityClassName] ?? null;

            // If not found, try to find by file name (handle pluralization mismatch)
            if ($entityCollectionInfo === null) {
                $entityCollectionShortName = StringHelper::pluralize($entityShortName);

                // Try to find EntityCollection by checking all loaded collections
                foreach ($entityCollections as $loadedEntityClassName => $collectionInfo) {
                    $collectionReflection = $collectionInfo['reflection'];
                    $collectionShortName = $collectionReflection->getShortName();

                    // Check if collection name matches expected pluralized name
                    if ($collectionShortName === $entityCollectionShortName) {
                        // Verify that the loaded Entity class matches our predicted Entity class
                        if ($loadedEntityClassName === $entityClassName) {
                            $entityCollectionInfo = $collectionInfo;
                            break;
                        }
                    }
                }
            }

            // If EntityCollection exists, skip
            if ($entityCollectionInfo !== null) {
                continue;
            }

            // For estimate, we don't need reflection yet - it will be created after Entity file is created
            // We just need to mark that this EntityCollection should be created
            // Use a placeholder structure that will be replaced after Entity creation
            $toCreate[$entityClassName] = [
                'entity_class' => $entityClassName,
                'reflection' => null, // Will be created after Entity file is created
                'table_name' => $tableName, // Store table name for later use
            ];
        }

        return $toCreate;
    }

    /**
     * Convert table name to PascalCase class name.
     *
     * @param string $tableName Database table name (snake_case)
     * @return string PascalCase class name
     */
    protected function tableToPascalCase(string $tableName): string
    {
        return str_replace('_', '', ucwords($tableName, '_'));
    }
}
