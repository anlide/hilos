<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands\DbEntityFixCommand;

use Hilos\Database\Entity\Entity;
use ReflectionClass;

/**
 * EntityCollectionFixer trait
 *
 * Handles synchronization of EntityCollection files (EntityCollection/{Name}s.php)
 * with Entity classes.
 *
 * Responsibilities:
 * - Compare EntityCollection imports with Entity class
 * - Update method type hints to match Entity class
 * - Preserve user-defined methods
 */
trait EntityCollectionFixer
{
    /**
     * Load EntityCollection files from directory
     *
     * @param string|null $entityCollectionDir EntityCollection files directory
     * @param int $syntaxErrors Reference to syntax error counter
     * @param array $brokenFiles Reference to broken files array
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
     * Extract class name from EntityCollection file
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

        // Extract class name
        if (preg_match('/\b(?:final\s+)?class\s+(\w+)/', $content, $classMatch)) {
            $className = trim($classMatch[1]);
            return $namespace . '\\' . $className;
        }

        return null;
    }

    /**
     * Extract Entity class name from EntityCollection reflection or file
     */
    private function extractEntityClassNameFromEntityCollection(ReflectionClass $entityCollectionReflection, string $entityCollectionFile): ?string
    {
        $content = file_get_contents($entityCollectionFile);
        if ($content === false) {
            return null;
        }

        // Look for use statement: use ...\Entity\ClassName as EntityClassName;
        // Or: use ...\Entity\ClassName;
        if (preg_match('/use\s+([^;]+\\\Entity\\\[^;]+)(?:\s+as\s+(\w+))?;/', $content, $useMatch)) {
            $entityClassName = trim($useMatch[1]);
            return $entityClassName;
        }

        // Fallback: try to infer from EntityCollection class name
        // If EntityCollection class is "Users", Entity class should be "User" in Entity subnamespace
        $entityCollectionNamespace = $entityCollectionReflection->getNamespaceName();
        $entityCollectionShortName = $entityCollectionReflection->getShortName();
        
        // Replace EntityCollection namespace with Entity namespace
        $entityNamespace = str_replace('\\EntityCollection', '\\Entity', $entityCollectionNamespace);
        
        // Remove 's' from end if plural (Users -> User)
        $entityShortName = $entityCollectionShortName;
        if (str_ends_with($entityShortName, 's')) {
            $entityShortName = substr($entityShortName, 0, -1);
        }
        
        $entityClassName = $entityNamespace . '\\' . $entityShortName;
        
        if (class_exists($entityClassName)) {
            return $entityClassName;
        }

        return null;
    }

    /**
     * Prepare fixes for EntityCollection files
     *
     * @param array $entities Loaded Entity classes
     * @param array $entityCollections Loaded EntityCollection files
     * @param string|null $tableFilter Table name filter
     * @return array Fixes to apply
     */
    protected function prepareEntityCollectionFixes(array $entities, array $entityCollections, ?string $tableFilter): array
    {
        $fixes = [];

        foreach ($entities as $tableName => $entityInfo) {
            // Apply table filter if specified
            if ($tableFilter !== null && $tableName !== $tableFilter) {
                continue;
            }

            $entityClassName = $entityInfo['class'];
            
            // Check if EntityCollection exists for this Entity
            $entityCollectionInfo = $entityCollections[$entityClassName] ?? null;
            
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
     * Compare EntityCollection with Entity and prepare fixes
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
        
        // Parse EntityCollection file
        $parsed = $this->parseEntityCollectionFile($entityCollectionFile);
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
     * Parse EntityCollection file to extract current structure
     *
     * @param string $filePath EntityCollection file path
     * @return array|null Parsed structure or null if failed
     */
    protected function parseEntityCollectionFile(string $filePath): ?array
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
        if (preg_match_all('/use\s+([^;]+)(?:\s+as\s+(\w+))?;/', $content, $useMatches, PREG_SET_ORDER)) {
            foreach ($useMatches as $useMatch) {
                $fullClassName = trim($useMatch[1]);
                $alias = isset($useMatch[2]) ? trim($useMatch[2]) : null;

                if (strpos($fullClassName, '\\Entity\\') !== false && strpos($fullClassName, '\\EntityCollection\\') === false) {
                    $parsed['entity_class'] = $fullClassName;
                    $parsed['entity_alias'] = $alias ?? $this->getShortClassName($fullClassName);
                }
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
     * Get short class name from full class name
     */
    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);
        return end($parts);
    }

    /**
     * Apply fixes to EntityCollection files
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
     * Apply fixes to a single EntityCollection file
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

        // Write file
        return file_put_contents($entityCollectionFile, $content) !== false;
    }

    /**
     * Rebuild imports in EntityCollection file
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
            
            // Remove empty lines
            $existingUses = preg_replace('/\n\s*\n/', "\n", $existingUses);
            $existingUses = trim($existingUses);
            
            // Add new Entity import
            $newUse = "use {$entityClassName} as {$entityAlias};";
            
            // Add EntityCollection import if not present
            $entityCollectionUse = "use Hilos\\Database\\Entity\\EntityCollection;";
            if (strpos($existingUses, $entityCollectionUse) === false) {
                $newUse .= "\n" . $entityCollectionUse;
            }
            
            // Add DatabaseException import if not present
            $dbExceptionUse = "use Hilos\\Exception\\DatabaseException;";
            if (strpos($existingUses, $dbExceptionUse) === false && strpos($content, 'DatabaseException') !== false) {
                $newUse .= "\n" . $dbExceptionUse;
            }
            
            $allUses = $newUse . "\n" . ($existingUses ? $existingUses . "\n" : "");
            
            $content = str_replace($matches[0], $matches[1] . $allUses . $matches[3], $content);
        } else {
            // Insert after namespace
            if (preg_match('/(namespace\s+[^;]+;\n\n)/', $content, $nsMatch)) {
                $newUse = "use {$entityClassName} as {$entityAlias};\n";
                $newUse .= "use Hilos\\Database\\Entity\\EntityCollection;\n";
                $newUse .= "use Hilos\\Exception\\DatabaseException;\n";
                $content = str_replace($nsMatch[1], $nsMatch[1] . $newUse, $content);
            }
        }

        return $content;
    }

    /**
     * Rebuild type hints in EntityCollection file
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
            $pattern = '/((?:public|private|protected)\s+function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*):\s*[^\{]+/';
            $replacement = '$1: ' . $expectedType;
            $content = preg_replace($pattern, $replacement, $content);
        }

        return $content;
    }

    /**
     * Create EntityCollection file from Entity class
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
        $entityCollectionShortName = $entityShortName . 's'; // Pluralize
        $entityCollectionFile = $entityCollectionDir . '/' . $entityCollectionShortName . '.php';

        // Determine aliases
        $entityAlias = "Entity{$entityShortName}";

        // Build file content
        $content = "<?php\n\n";
        $content .= "namespace {$namespace};\n\n";
        $content .= "use {$entityClassName} as {$entityAlias};\n";
        $content .= "use Hilos\\Database\\Entity\\EntityCollection;\n";
        $content .= "use Hilos\\Exception\\DatabaseException;\n\n";
        $content .= "/**\n";
        $content .= " * {$entityCollectionShortName} Entity Collection\n";
        $content .= " * Typed wrapper around EntityCollection for {$entityShortName} entities\n";
        $content .= " *\n";
        $content .= " * This is a convenience class that provides type safety and convenience methods\n";
        $content .= " * while using EntityCollection internally.\n";
        $content .= " */\n";
        $content .= "final class {$entityCollectionShortName}\n";
        $content .= "{\n";
        $content .= "    private EntityCollection \$collection;\n\n";
        $content .= "    /**\n";
        $content .= "     * Private constructor\n";
        $content .= "     */\n";
        $content .= "    private function __construct(EntityCollection \$collection)\n";
        $content .= "    {\n";
        $content .= "        \$this->collection = \$collection;\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Initialize collection with all {$entityShortName} entities from database\n";
        $content .= "     *\n";
        $content .= "     * @return self\n";
        $content .= "     * @throws DatabaseException\n";
        $content .= "     */\n";
        $content .= "    public static function initFullDB(): self\n";
        $content .= "    {\n";
        $content .= "        \$entityCollection = {$entityAlias}::getAll();\n";
        $content .= "        return new self(\$entityCollection);\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Initialize empty collection\n";
        $content .= "     *\n";
        $content .= "     * @return self\n";
        $content .= "     */\n";
        $content .= "    public static function initEmpty(): self\n";
        $content .= "    {\n";
        $content .= "        return new self(EntityCollection::empty());\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Create from EntityCollection\n";
        $content .= "     *\n";
        $content .= "     * @param EntityCollection \$collection\n";
        $content .= "     * @return self\n";
        $content .= "     */\n";
        $content .= "    public static function fromEntityCollection(EntityCollection \$collection): self\n";
        $content .= "    {\n";
        $content .= "        return new self(\$collection);\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Get underlying EntityCollection\n";
        $content .= "     *\n";
        $content .= "     * @return EntityCollection\n";
        $content .= "     */\n";
        $content .= "    public function getCollection(): EntityCollection\n";
        $content .= "    {\n";
        $content .= "        return \$this->collection;\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Get {$entityShortName} entity by key\n";
        $content .= "     *\n";
        $content .= "     * @param int|string \$key\n";
        $content .= "     * @return {$entityAlias}|null\n";
        $content .= "     */\n";
        $content .= "    public function get(int|string \$key): ?{$entityAlias}\n";
        $content .= "    {\n";
        $content .= "        \$entity = \$this->collection->get(\$key);\n";
        $content .= "        return \$entity instanceof {$entityAlias} ? \$entity : null;\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Get first {$entityShortName} entity\n";
        $content .= "     *\n";
        $content .= "     * @return {$entityAlias}|null\n";
        $content .= "     */\n";
        $content .= "    public function first(): ?{$entityAlias}\n";
        $content .= "    {\n";
        $content .= "        \$entity = \$this->collection->first();\n";
        $content .= "        return \$entity instanceof {$entityAlias} ? \$entity : null;\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Get last {$entityShortName} entity\n";
        $content .= "     *\n";
        $content .= "     * @return {$entityAlias}|null\n";
        $content .= "     */\n";
        $content .= "    public function last(): ?{$entityAlias}\n";
        $content .= "    {\n";
        $content .= "        \$entity = \$this->collection->last();\n";
        $content .= "        return \$entity instanceof {$entityAlias} ? \$entity : null;\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Get count of {$entityShortName} entities\n";
        $content .= "     */\n";
        $content .= "    public function count(): int\n";
        $content .= "    {\n";
        $content .= "        return \$this->collection->count();\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Check if collection is empty\n";
        $content .= "     */\n";
        $content .= "    public function isEmpty(): bool\n";
        $content .= "    {\n";
        $content .= "        return \$this->collection->count() === 0;\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Filter collection\n";
        $content .= "     *\n";
        $content .= "     * @param callable({$entityAlias}): bool \$callback\n";
        $content .= "     * @return self\n";
        $content .= "     */\n";
        $content .= "    public function filter(callable \$callback): self\n";
        $content .= "    {\n";
        $content .= "        \$filtered = \$this->collection->filter(function(\$entity) use (\$callback) {\n";
        $content .= "            return \$entity instanceof {$entityAlias} && \$callback(\$entity);\n";
        $content .= "        });\n";
        $content .= "        return new self(\$filtered);\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Convert to array\n";
        $content .= "     *\n";
        $content .= "     * @return {$entityAlias}[]\n";
        $content .= "     */\n";
        $content .= "    public function toArray(): array\n";
        $content .= "    {\n";
        $content .= "        \$result = [];\n";
        $content .= "        foreach (\$this->collection as \$entity) {\n";
        $content .= "            if (\$entity instanceof {$entityAlias}) {\n";
        $content .= "                \$result[] = \$entity;\n";
        $content .= "            }\n";
        $content .= "        }\n";
        $content .= "        return \$result;\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Iterator support - get current {$entityShortName}\n";
        $content .= "     *\n";
        $content .= "     * @return {$entityAlias}|null\n";
        $content .= "     */\n";
        $content .= "    public function current(): ?{$entityAlias}\n";
        $content .= "    {\n";
        $content .= "        \$entity = \$this->collection->current();\n";
        $content .= "        return \$entity instanceof {$entityAlias} ? \$entity : null;\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Iterator support - get current key\n";
        $content .= "     */\n";
        $content .= "    public function key(): int|string|null\n";
        $content .= "    {\n";
        $content .= "        return \$this->collection->key();\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Iterator support - move to next\n";
        $content .= "     */\n";
        $content .= "    public function next(): void\n";
        $content .= "    {\n";
        $content .= "        \$this->collection->next();\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Iterator support - rewind\n";
        $content .= "     */\n";
        $content .= "    public function rewind(): void\n";
        $content .= "    {\n";
        $content .= "        \$this->collection->rewind();\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Iterator support - check if valid\n";
        $content .= "     */\n";
        $content .= "    public function valid(): bool\n";
        $content .= "    {\n";
        $content .= "        return \$this->collection->valid();\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * ArrayAccess support - check if offset exists\n";
        $content .= "     */\n";
        $content .= "    public function offsetExists(mixed \$offset): bool\n";
        $content .= "    {\n";
        $content .= "        return \$this->collection->offsetExists(\$offset);\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * ArrayAccess support - get {$entityShortName} at offset\n";
        $content .= "     */\n";
        $content .= "    public function offsetGet(mixed \$offset): ?{$entityAlias}\n";
        $content .= "    {\n";
        $content .= "        \$entity = \$this->collection->offsetGet(\$offset);\n";
        $content .= "        return \$entity instanceof {$entityAlias} ? \$entity : null;\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * ArrayAccess support - set {$entityShortName} at offset\n";
        $content .= "     */\n";
        $content .= "    public function offsetSet(mixed \$offset, mixed \$value): void\n";
        $content .= "    {\n";
        $content .= "        if (\$value instanceof {$entityAlias}) {\n";
        $content .= "            \$this->collection->offsetSet(\$offset, \$value);\n";
        $content .= "        }\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * ArrayAccess support - unset {$entityShortName} at offset\n";
        $content .= "     */\n";
        $content .= "    public function offsetUnset(mixed \$offset): void\n";
        $content .= "    {\n";
        $content .= "        \$this->collection->offsetUnset(\$offset);\n";
        $content .= "    }\n";
        $content .= "}\n";

        return file_put_contents($entityCollectionFile, $content) !== false;
    }

    /**
     * Find EntityCollection files that need to be created
     *
     * @param array $entities Loaded Entity classes
     * @param array $entityCollections Loaded EntityCollection files
     * @param string|null $tableFilter Table name filter
     * @param array $brokenEntities Broken Entity files
     * @return array<string, array{entity_class: string, reflection: ReflectionClass}> EntityCollections to create
     */
    protected function findEntityCollectionsToCreate(array $entities, array $entityCollections, ?string $tableFilter, array $brokenEntities): array
    {
        $toCreate = [];

        foreach ($entities as $tableName => $entityInfo) {
            // Skip broken entities
            if (isset($brokenEntities[$entityInfo['file']])) {
                continue;
            }

            // Apply table filter if specified
            if ($tableFilter !== null && $tableName !== $tableFilter) {
                continue;
            }

            $entityClassName = $entityInfo['class'];

            // Check if EntityCollection already exists
            if (isset($entityCollections[$entityClassName])) {
                continue;
            }

            $toCreate[$entityClassName] = [
                'entity_class' => $entityClassName,
                'reflection' => $entityInfo['reflection'],
            ];
        }

        return $toCreate;
    }
}

