<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands\DbObjectFixCommand;

use Hilos\Database\Object\Item\Object_;
use Hilos\Utils\Helpers\StringHelper;
use ReflectionClass;

/**
 * ObjectCollectionFixer trait.
 *
 * Handles synchronization of ObjectCollection files (ObjectCollection/{Name}s.php)
 * with Object classes.
 *
 * Responsibilities:
 * - Compare ObjectCollection imports with Object class
 * - Update method type hints to match Object class
 * - Preserve user-defined methods
 */
trait ObjectCollectionFixer
{
    /**
     * Validate EntityCollection files (check for syntax errors)
     *
     * @param ?string $entityCollectionDir EntityCollection files directory
     * @param int $syntaxErrors Reference to syntax error counter
     * @param array<string, string> $brokenFiles Reference to broken files (file path => error message)
     * @return bool True if all EntityCollection files are valid
     */
    protected function validateEntityCollections(?string $entityCollectionDir, int &$syntaxErrors = 0, array &$brokenFiles = []): bool
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
                return true; // No EntityCollection directory found, nothing to validate
            }
        }

        if (!is_dir($entityCollectionDir)) {
            return true; // Directory doesn't exist, nothing to validate
        }

        $files = glob($entityCollectionDir . '/*.php');
        if ($files === false) {
            return true;
        }

        foreach ($files as $file) {
            try {
                // Check PHP syntax
                $output = [];
                $returnVar = 0;
                exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $returnVar);
                if ($returnVar !== 0) {
                    $errorMessage = implode(' ', $output);
                    $brokenFiles[$file] = "PHP syntax error: {$errorMessage}";
                    $syntaxErrors++;
                }
            } catch (\Throwable $e) {
                $brokenFiles[$file] = $e->getMessage();
            }
        }

        return empty($brokenFiles) && $syntaxErrors === 0;
    }

    /**
     * Load ObjectCollection files from directory
     *
     * @param ?string $objectCollectionDir ObjectCollection files directory
     * @param int $syntaxErrors Reference to syntax error counter
     * @param array<string, string> $brokenFiles Reference to broken files (file path => error message)
     * @return array<string, array{class: string, file: string, reflection: ReflectionClass, object_class: string}> Loaded ObjectCollection files info
     */
    protected function loadObjectCollections(?string $objectCollectionDir, int &$syntaxErrors = 0, array &$brokenFiles = []): array
    {
        // Auto-detect if not provided
        if ($objectCollectionDir === null) {
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
                    $objectCollectionDir = $realPath;
                    break;
                }
            }

            if ($objectCollectionDir === null) {
                return [];
            }
        }

        if (!is_dir($objectCollectionDir)) {
            return [];
        }

        $objectCollections = [];
        $files = glob($objectCollectionDir . '/*.php');

        if ($files === false) {
            return $objectCollections;
        }

        foreach ($files as $file) {
            $className = $this->extractClassNameFromObjectCollectionFile($file);
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

                // Extract Object class name from ObjectCollection
                $objectClassName = $this->extractObjectClassNameFromObjectCollection($reflection, $file);
                if ($objectClassName === null) {
                    $brokenFiles[$file] = 'Cannot determine Object class name';
                    continue;
                }

                $objectCollections[$objectClassName] = [
                    'class' => $className,
                    'file' => $file,
                    'reflection' => $reflection,
                    'object_class' => $objectClassName,
                ];
            } catch (\Throwable $e) {
                $brokenFiles[$file] = $e->getMessage();
                continue;
            }
        }

        return $objectCollections;
    }

    /**
     * Extract class name from ObjectCollection file
     */
    private function extractClassNameFromObjectCollectionFile(string $file): ?string
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
     * Extract Object class name from ObjectCollection reflection or file
     */
    private function extractObjectClassNameFromObjectCollection(ReflectionClass $objectCollectionReflection, string $objectCollectionFile): ?string
    {
        $content = file_get_contents($objectCollectionFile);
        if ($content === false) {
            return null;
        }

        // Look for use statement: use ...\Object\ClassName as ObjectClassName;
        // Or: use ...\Object\ClassName;
        // Use preg_match_all to find all use statements and filter for Object classes
        // This avoids capturing comments or strings that might contain \Object\
        // Pattern: capture class name (without "as alias") in group 1, alias in group 2
        if (preg_match_all('/^use\s+([^\s;]+)(?:\s+as\s+(\w+))?;/m', $content, $useMatches, PREG_SET_ORDER)) {
            foreach ($useMatches as $useMatch) {
                $fullClassName = trim($useMatch[1]);
                // Check that this is an Object class, but not ObjectCollection or Objects
                if (strpos($fullClassName, '\\Object\\') !== false &&
                    strpos($fullClassName, '\\ObjectCollection\\') === false &&
                    strpos($fullClassName, '\\Object\\Objects') === false &&
                    strpos($fullClassName, '\\Object\\Item\\Object_') === false) {
                    return $fullClassName;
                }
            }
        }

        // Fallback: try to infer from ObjectCollection class name
        // If ObjectCollection class is "Users", Object class should be "User" in Object subnamespace
        $objectCollectionNamespace = $objectCollectionReflection->getNamespaceName();
        $objectCollectionShortName = $objectCollectionReflection->getShortName();

        // Replace ObjectCollection namespace with Object namespace
        $objectNamespace = str_replace('\\ObjectCollection', '\\Object', $objectCollectionNamespace);

        // Remove plural ending to get singular form (Users -> User, Events -> Event)
        $objectShortName = StringHelper::singularize($objectCollectionShortName);

        $objectClassName = $objectNamespace . '\\' . $objectShortName;

        if (class_exists($objectClassName)) {
            return $objectClassName;
        }

        return null;
    }

    /**
     * Prepare fixes for ObjectCollection files
     *
     * @param array $objects Loaded Object classes
     * @param array $objectCollections Loaded ObjectCollection files
     * @param ?string $tableFilter Table name filter
     * @return array Fixes to apply
     */
    protected function prepareObjectCollectionFixes(array $objects, array $objectCollections, ?string $tableFilter): array
    {
        $fixes = [];

        foreach ($objects as $objectClassName => $objectInfo) {
            // Apply table filter if specified
            // We need to get table name from Object's Entity
            if ($tableFilter !== null) {
                // Try to find Entity class for this Object to get table name
                $objectFile = $objectInfo['file'];
                $entityClassName = $this->extractEntityClassNameFromObject($objectFile);
                if ($entityClassName !== null && class_exists($entityClassName)) {
                    $entityReflection = new ReflectionClass($entityClassName);
                    $table = $entityReflection->getConstant('TABLE');
                    if ($table !== $tableFilter) {
                        continue;
                    }
                } else {
                    // Skip if we can't determine table
                    continue;
                }
            }

            // Check if ObjectCollection exists for this Object
            // Try direct lookup first
            $objectCollectionInfo = $objectCollections[$objectClassName] ?? null;

            // If not found, try to find by file name (handle pluralization mismatch)
            if ($objectCollectionInfo === null) {
                // Extract short name from Object class name
                $objectShortName = substr($objectClassName, strrpos($objectClassName, '\\') + 1);
                $objectCollectionShortName = StringHelper::pluralize($objectShortName);

                // Try to find ObjectCollection by checking all loaded collections
                // and comparing their file names or class names
                foreach ($objectCollections as $loadedObjectClassName => $collectionInfo) {
                    $collectionReflection = $collectionInfo['reflection'];
                    $collectionShortName = $collectionReflection->getShortName();

                    // Check if collection name matches expected pluralized name
                    if ($collectionShortName === $objectCollectionShortName) {
                        // Verify that the loaded Object class matches our Object class
                        if ($loadedObjectClassName === $objectClassName) {
                            $objectCollectionInfo = $collectionInfo;
                            break;
                        }
                    }
                }
            }

            if ($objectCollectionInfo === null) {
                // ObjectCollection doesn't exist - will be created
                continue;
            }

            // Compare ObjectCollection with Object
            $collectionFixes = $this->compareObjectCollectionWithObject($objectCollectionInfo, $objectInfo);
            if (!empty($collectionFixes)) {
                $fixes[$objectClassName] = $collectionFixes;
            }
        }

        return $fixes;
    }

    /**
     * Extract Entity class name from Object file
     */
    private function extractEntityClassNameFromObject(string $objectFile): ?string
    {
        $content = file_get_contents($objectFile);
        if ($content === false) {
            return null;
        }

        // Look for use statement: use ...\Entity\ClassName as EntityClassName;
        if (preg_match_all('/^use\s+([^\s;]+)(?:\s+as\s+(\w+))?;/m', $content, $useMatches, PREG_SET_ORDER)) {
            foreach ($useMatches as $useMatch) {
                $fullClassName = trim($useMatch[1]);
                // Check that this is an Entity class
                if (strpos($fullClassName, '\\Entity\\') !== false &&
                    strpos($fullClassName, '\\EntityCollection\\') === false) {
                    return $fullClassName;
                }
            }
        }

        return null;
    }

    /**
     * Compare ObjectCollection with Object and prepare fixes
     *
     * @param array $objectCollectionInfo ObjectCollection info
     * @param array $objectInfo Object info
     * @return array Fixes to apply
     */
    private function compareObjectCollectionWithObject(array $objectCollectionInfo, array $objectInfo): array
    {
        $fixes = [];
        $objectCollectionFile = $objectCollectionInfo['file'];
        $content = file_get_contents($objectCollectionFile);
        if ($content === false) {
            return $fixes;
        }

        $objectClassName = $objectInfo['class'];
        $objectShortName = substr($objectClassName, strrpos($objectClassName, '\\') + 1);

        // Calculate expected Object class name from ObjectCollection class name
        $objectCollectionReflection = $objectCollectionInfo['reflection'];
        $objectCollectionShortName = $objectCollectionReflection->getShortName();
        $objectCollectionNamespace = $objectCollectionReflection->getNamespaceName();

        // Apply singularization to get Object short name
        $expectedObjectShortName = StringHelper::singularize($objectCollectionShortName);

        // Replace ObjectCollection namespace with Object namespace
        $expectedObjectNamespace = str_replace('\\ObjectCollection', '\\Object', $objectCollectionNamespace);

        // Build expected Object class name
        $expectedObjectClass = $expectedObjectNamespace . '\\' . $expectedObjectShortName;

        // Parse ObjectCollection file with expected Object class
        $parsed = $this->parseObjectCollectionFile($objectCollectionFile, $expectedObjectClass);
        if ($parsed === null) {
            return $fixes;
        }

        // Compare imports
        $currentObjectClass = $parsed['object_class'] ?? null;
        if ($currentObjectClass !== $objectClassName) {
            $fixes['update_imports'] = [
                'current' => $currentObjectClass,
                'expected' => $objectClassName,
                'object_short_name' => $objectShortName,
            ];
        }

        // Compare method type hints
        $expectedObjectAlias = "Object{$objectShortName}";
        $currentObjectAlias = $parsed['object_alias'] ?? null;

        // Check if type hints are correct in methods
        $methodsToCheck = ['get', 'first', 'last', 'current', 'offsetGet'];
        $wrongTypeHints = [];

        foreach ($methodsToCheck as $methodName) {
            $expectedReturnType = $parsed['methods'][$methodName]['return_type'] ?? null;
            if ($expectedReturnType !== null && $expectedReturnType !== "?{$expectedObjectAlias}" && $expectedReturnType !== "{$expectedObjectAlias}|null") {
                // Check if it's actually correct but with different alias
                $actualReturnType = $parsed['methods'][$methodName]['actual_return_type'] ?? null;
                if ($actualReturnType !== "?{$expectedObjectAlias}" && $actualReturnType !== "{$expectedObjectAlias}|null") {
                    $wrongTypeHints[$methodName] = [
                        'current' => $actualReturnType ?? $expectedReturnType,
                        'expected' => "?{$expectedObjectAlias}",
                    ];
                }
            }
        }

        if (!empty($wrongTypeHints)) {
            $fixes['update_type_hints'] = [
                'wrong' => $wrongTypeHints,
                'object_alias' => $expectedObjectAlias,
            ];
        }

        return $fixes;
    }

    /**
     * Parse ObjectCollection file to extract current structure.
     *
     * @param string $filePath ObjectCollection file path
     * @param class-string<Object_>|null $expectedObjectClass Expected Object class name (optional)
     * @return array{object_class: string|null, object_alias: string|null, methods: array<string, array<string, mixed>>}|null
     *         Parsed structure or null if failed
     */
    protected function parseObjectCollectionFile(string $filePath, ?string $expectedObjectClass = null): ?array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $parsed = [
            'object_class' => null,
            'object_alias' => null,
            'methods' => [],
        ];

        // Parse imports
        $foundObjectClasses = [];
        if (preg_match_all('/^use\s+([^\s;]+)(?:\s+as\s+(\w+))?;/m', $content, $useMatches, PREG_SET_ORDER)) {
            foreach ($useMatches as $useMatch) {
                $fullClassName = $useMatch[1];
                $alias = $useMatch[2] ?? null;

                // Skip base classes
                if ($fullClassName === 'Hilos\\Database\\Object\\Objects' ||
                    $fullClassName === 'Hilos\\Database\\Object\\Item\\Object_') {
                    continue;
                }

                if (str_contains($fullClassName, '\\Object\\') && 
                    !str_contains($fullClassName, '\\ObjectCollection\\') &&
                    !str_contains($fullClassName, '\\Object\\Objects') &&
                    !str_contains($fullClassName, '\\Object\\Item\\Object_')) {
                    $foundObjectClasses[] = [
                        'class' => $fullClassName,
                        'alias' => $alias ?? $this->getShortClassName($fullClassName),
                    ];
                }
            }
        }

        // If expected Object class is provided, search for it specifically
        if ($expectedObjectClass !== null) {
            foreach ($foundObjectClasses as $objectInfo) {
                if ($objectInfo['class'] === $expectedObjectClass) {
                    $parsed['object_class'] = $objectInfo['class'];
                    $parsed['object_alias'] = $objectInfo['alias'];
                    break;
                }
            }
        } else {
            // Fallback: use first found Object class (for backward compatibility)
            if (!empty($foundObjectClasses)) {
                $parsed['object_class'] = $foundObjectClasses[0]['class'];
                $parsed['object_alias'] = $foundObjectClasses[0]['alias'];
            }
        }

        // Parse method return types
        $methodsToCheck = ['get', 'first', 'last', 'current', 'offsetGet'];
        foreach ($methodsToCheck as $methodName) {
            // Match method signature: public function get(...): ?ObjectUser
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
     * Apply fixes to ObjectCollection files
     *
     * @param array $fixes Fixes to apply (keyed by object class name)
     * @param array $objectCollections Loaded ObjectCollection files info
     * @param array $objects Loaded Object files info
     * @return int Number of files updated
     */
    protected function applyObjectCollectionFixes(array $fixes, array $objectCollections, array $objects): int
    {
        $updated = 0;

        foreach ($fixes as $objectClassName => $collectionFixes) {
            // Get ObjectCollection info
            $objectCollectionInfo = $objectCollections[$objectClassName] ?? null;
            if ($objectCollectionInfo === null) {
                continue;
            }

            // Get Object info
            $objectInfo = $objects[$objectClassName] ?? null;
            if ($objectInfo === null) {
                continue;
            }

            $objectCollectionFile = $objectCollectionInfo['file'];

            if ($this->applyObjectCollectionFileFixes($objectCollectionFile, $collectionFixes, $objectInfo)) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Apply fixes to a single ObjectCollection file
     *
     * @param string $objectCollectionFile ObjectCollection file path
     * @param array $fixes Fixes to apply
     * @param array $objectInfo Object info
     * @return bool Success
     */
    private function applyObjectCollectionFileFixes(string $objectCollectionFile, array $fixes, array $objectInfo): bool
    {
        $content = file_get_contents($objectCollectionFile);
        if ($content === false) {
            return false;
        }

        $objectClassName = $objectInfo['class'];
        $objectShortName = substr($objectClassName, strrpos($objectClassName, '\\') + 1);
        $expectedObjectAlias = "Object{$objectShortName}";

        // Apply import fixes
        if (isset($fixes['update_imports'])) {
            $content = $this->rebuildObjectCollectionImports($content, $objectClassName, $expectedObjectAlias);
        }

        // Apply type hint fixes
        if (isset($fixes['update_type_hints'])) {
            $content = $this->rebuildObjectCollectionTypeHints($content, $expectedObjectAlias, $fixes['update_type_hints']['wrong']);
        }

        // Write file
        return file_put_contents($objectCollectionFile, $content) !== false;
    }

    /**
     * Rebuild imports in ObjectCollection file.
     *
     * @param string $content Current file content
     * @param class-string<Object_> $objectClassName Object class name
     * @param string $objectAlias Object alias
     * @return string Updated content
     */
    private function rebuildObjectCollectionImports(string $content, string $objectClassName, string $objectAlias): string
    {
        // Remove old Object import
        $content = preg_replace('/use\s+[^;]+\\\Object\\\[^;]+(?:\s+as\s+\w+)?;/', '', $content);

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

            // Add new Object import
            $newUse = "use {$objectClassName} as {$objectAlias};";

            // Add Objects import if not present
            $objectsUse = "use Hilos\\Database\\Object\\Objects;";
            if (!str_contains($useStatements, $objectsUse)) {
                $newUse .= "\n" . $objectsUse;
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
                $newUse = "use {$objectClassName} as {$objectAlias};\n";
                $newUse .= "use Hilos\\Database\\Object\\Objects;\n";
                $newUse .= "use Hilos\\Exception\\DatabaseException;\n";
                $content = str_replace($nsMatch[1], $nsMatch[1] . $newUse, $content);
            }
        }

        return $content;
    }

    /**
     * Rebuild type hints in ObjectCollection file.
     *
     * @param string $content Current file content
     * @param string $objectAlias Object alias
     * @param array<string, array<string, mixed>> $wrongTypeHints Wrong type hints to fix (method => type info)
     * @return string Updated content
     */
    private function rebuildObjectCollectionTypeHints(string $content, string $objectAlias, array $wrongTypeHints): string
    {
        foreach ($wrongTypeHints as $methodName => $typeInfo) {
            $expectedType = "?{$objectAlias}";

            // Replace return type in method signature
            $pattern = '/((?:public|private|protected)\s+function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*):\s*[^{]+(\s*)/';
            $replacement = '$1: ' . $expectedType . '$2';
            $content = preg_replace($pattern, $replacement, $content);
        }

        return $content;
    }

    /**
     * Create ObjectCollection file from Object class.
     *
     * @param class-string<Object_> $objectClassName Object class name
     * @param string $objectCollectionDir ObjectCollection directory
     * @param string $namespace ObjectCollection namespace
     * @param ReflectionClass<Object_> $objectReflection Object reflection
     * @return bool Success
     */
    protected function createObjectCollectionFile(string $objectClassName, string $objectCollectionDir, string $namespace, ReflectionClass $objectReflection): bool
    {
        if (!is_dir($objectCollectionDir)) {
            if (!mkdir($objectCollectionDir, 0755, true)) {
                return false;
            }
        }

        $objectShortName = $objectReflection->getShortName();
        $objectCollectionShortName = StringHelper::pluralize($objectShortName);
        $objectCollectionFile = $objectCollectionDir . '/' . $objectCollectionShortName . '.php';

        // Determine aliases
        $objectAlias = "Object{$objectShortName}";

        // Extract Entity class name from Object
        $objectFile = $objectReflection->getFileName();
        $entityClassName = null;
        $entityAlias = null;
        $entityCollectionClassName = null;
        $entityCollectionAlias = null;
        
        if ($objectFile !== false) {
            $objectContent = file_get_contents($objectFile);
            if ($objectContent !== false) {
                // Find Entity class import
                if (preg_match_all('/^use\s+([^\s;]+)(?:\s+as\s+(\w+))?;/m', $objectContent, $useMatches, PREG_SET_ORDER)) {
                    foreach ($useMatches as $useMatch) {
                        $fullClassName = trim($useMatch[1]);
                        if (strpos($fullClassName, '\\Entity\\') !== false &&
                            strpos($fullClassName, '\\EntityCollection\\') === false) {
                            $entityClassName = $fullClassName;
                            $entityAlias = $useMatch[2] ?? substr($fullClassName, strrpos($fullClassName, '\\') + 1);
                            break;
                        }
                    }
                }
                
                // Try to find EntityCollection import
                if (preg_match_all('/^use\s+([^\s;]+)(?:\s+as\s+(\w+))?;/m', $objectContent, $useMatches, PREG_SET_ORDER)) {
                    foreach ($useMatches as $useMatch) {
                        $fullClassName = trim($useMatch[1]);
                        if (strpos($fullClassName, '\\EntityCollection\\') !== false) {
                            $entityCollectionClassName = $fullClassName;
                            $entityCollectionAlias = $useMatch[2] ?? substr($fullClassName, strrpos($fullClassName, '\\') + 1);
                            break;
                        }
                    }
                }
            }
        }

        // If EntityCollection not found, try to construct it
        if ($entityCollectionClassName === null && $entityClassName !== null) {
            $entityNamespace = substr($entityClassName, 0, strrpos($entityClassName, '\\'));
            $entityShortName = substr($entityClassName, strrpos($entityClassName, '\\') + 1);
            $entityCollectionShortName = StringHelper::pluralize($entityShortName);
            $entityCollectionNamespace = preg_replace('/\\\\Entity$/', '\\\\EntityCollection', $entityNamespace);
            $entityCollectionClassName = $entityCollectionNamespace . '\\' . $entityCollectionShortName;
            $entityCollectionAlias = "Entity{$entityCollectionShortName}";
        }

        // Build file content
        $content = "<?php\n\n";
        $content .= "namespace {$namespace};\n\n";
        $content .= "use ArrayAccess;\n";
        $content .= "use Countable;\n";
        if ($entityClassName !== null) {
            $content .= "use {$entityClassName} as {$entityAlias};\n";
        }
        if ($entityCollectionClassName !== null) {
            $content .= "use {$entityCollectionClassName} as {$entityCollectionAlias};\n";
        }
        $content .= "use {$objectClassName} as {$objectAlias};\n";
        $content .= "use Hilos\\Database\\Database;\n";
        $content .= "use Hilos\\Database\\Object\\Objects;\n";
        $content .= "use Hilos\\Exception\\DatabaseException;\n";
        $content .= "use Iterator;\n\n";
        $content .= "/**\n";
        $content .= " * {$objectCollectionShortName} Object Collection\n";
        $content .= " * Typed wrapper around Objects for {$objectShortName} objects\n";
        $content .= " *\n";
        $content .= " * This is a convenience class that provides type safety and convenience methods\n";
        $content .= " * while using Objects internally.\n";
        $content .= " */\n";
        $content .= "final class {$objectCollectionShortName} extends Objects implements Iterator, ArrayAccess, Countable\n";
        $content .= "{\n";
        $content .= "    /** @var {$objectAlias}[] \$objects */\n";
        $content .= "    protected array \$objects = [];\n\n";
        $content .= "    /**\n";
        $content .= "     * Load all {$objectShortName} objects from database\n";
        $content .= "     * Clears existing objects and loads all from database\n";
        $content .= "     *\n";
        $content .= "     * @throws DatabaseException\n";
        $content .= "     */\n";
        $content .= "    public function loadAllFromDB(): void\n";
        $content .= "    {\n";
        $content .= "        \$this->objects = [];\n";
        if ($entityCollectionClassName !== null) {
            $content .= "        \${$entityCollectionAlias} = {$entityCollectionAlias}::initFullDB();\n";
            $content .= "        foreach (\${$entityCollectionAlias} as \$key => \${$entityAlias}) {\n";
            $content .= "            \$this->objects[\$key] = {$objectAlias}::fromEntity(\${$entityAlias});\n";
            $content .= "        }\n";
        } else {
            $content .= "        // TODO: Implement loadAllFromDB\n";
        }
        $content .= "        \$this->_allLoaded = true;\n";
        $content .= "        \$this->_allowLazyLoading = false;\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Initialize empty collection\n";
        $content .= "     *\n";
        $content .= "     * @return static\n";
        $content .= "     */\n";
        $content .= "    public static function initEmpty(): static\n";
        $content .= "    {\n";
        $content .= "        \$self = new self();\n";
        $content .= "        \$self->objects = [];\n";
        $content .= "        return \$self;\n";
        $content .= "    }\n\n";
        if ($entityClassName !== null) {
            $content .= "    /**\n";
            $content .= "     * Get table name from Entity\n";
            $content .= "     * \n";
            $content .= "     * @return string Table name\n";
            $content .= "     */\n";
            $content .= "    protected function getTableName(): string\n";
            $content .= "    {\n";
            $content .= "        return {$entityAlias}::_table;\n";
            $content .= "    }\n\n";
        }
        $content .= "    /**\n";
        $content .= "     * Get current {$objectShortName} object\n";
        $content .= "     *\n";
        $content .= "     * @return ?{$objectAlias} Current {$objectShortName} object or null if invalid position\n";
        $content .= "     */\n";
        $content .= "    public function current(): ?{$objectAlias}\n";
        $content .= "    {\n";
        $content .= "        return parent::current();\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Set {$objectShortName} object at offset\n";
        $content .= "     *\n";
        $content .= "     * @param mixed \$offset\n";
        $content .= "     * @param {$objectAlias} \$value\n";
        $content .= "     */\n";
        $content .= "    public function offsetSet(\$offset, \$value): void\n";
        $content .= "    {\n";
        $content .= "        if (\$value instanceof {$objectAlias}) {\n";
        $content .= "            if (\$offset === null) {\n";
        $content .= "                \$this->objects[] = \$value;\n";
        $content .= "            } else {\n";
        $content .= "                \$this->objects[\$offset] = \$value;\n";
        $content .= "            }\n";
        $content .= "        }\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Get {$objectShortName} object at offset\n";
        $content .= "     *\n";
        $content .= "     * @param mixed \$offset\n";
        $content .= "     * @return ?{$objectAlias}\n";
        $content .= "     */\n";
        $content .= "    public function offsetGet(\$offset): ?{$objectAlias}\n";
        $content .= "    {\n";
        $content .= "        return parent::offsetGet(\$offset);\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Lazy load {$objectShortName} object by key\n";
        $content .= "     *\n";
        $content .= "     * @param int|string \$key {$objectShortName} ID\n";
        $content .= "     * @return ?{$objectAlias}\n";
        $content .= "     * @throws DatabaseException\n";
        $content .= "     */\n";
        $content .= "    protected function lazyLoadObject(int|string \$key): ?{$objectAlias}\n";
        $content .= "    {\n";
        if ($entityClassName !== null) {
            $content .= "        \${$entityAlias} = {$entityAlias}::getById((int)\$key);\n";
            $content .= "        return \${$entityAlias} !== null ? {$objectAlias}::fromEntity(\${$entityAlias}) : null;\n";
        } else {
            $content .= "        // TODO: Implement lazyLoadObject\n";
            $content .= "        return null;\n";
        }
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Lazy load count of {$objectShortName} objects from database\n";
        $content .= "     *\n";
        $content .= "     * @return int\n";
        $content .= "     * @throws DatabaseException\n";
        $content .= "     */\n";
        $content .= "    protected function lazyLoadCount(): int\n";
        $content .= "    {\n";
        if ($entityClassName !== null) {
            $content .= "        // Use Entity to get count\n";
            $content .= "        \$resultSetCollection = Database::sql(\n";
            $content .= "            \"SELECT COUNT(*) as count FROM `\" . {$entityAlias}::_table . \"`\"\n";
            $content .= "        );\n";
            $content .= "        \$firstResultSet = \$resultSetCollection->first();\n\n";
            $content .= "        if (\$firstResultSet === null) {\n";
            $content .= "            return 0;\n";
            $content .= "        }\n\n";
            $content .= "        \$row = \$firstResultSet->first();\n";
            $content .= "        return \$row !== null ? (int)(\$row['count'] ?? 0) : 0;\n";
        } else {
            $content .= "        // TODO: Implement lazyLoadCount\n";
            $content .= "        return 0;\n";
        }
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Lazy load all {$objectShortName} objects from database (for batch strategy)\n";
        $content .= "     *\n";
        $content .= "     * @throws DatabaseException\n";
        $content .= "     */\n";
        $content .= "    protected function lazyLoadAll(): void\n";
        $content .= "    {\n";
        if ($entityCollectionClassName !== null) {
            $content .= "        \${$entityCollectionAlias} = {$entityCollectionAlias}::initFullDB();\n\n";
            $content .= "        foreach (\${$entityCollectionAlias} as \$key => \${$entityAlias}) {\n";
            $content .= "            if (!isset(\$this->objects[\$key])) {\n";
            $content .= "                \$this->objects[\$key] = {$objectAlias}::fromEntity(\${$entityAlias});\n";
            $content .= "            }\n";
            $content .= "        }\n\n";
        } else {
            $content .= "        // TODO: Implement lazyLoadAll\n";
        }
        $content .= "        \$this->_allLoaded = true;\n";
        $content .= "    }\n";
        $content .= "}\n";

        return file_put_contents($objectCollectionFile, $content) !== false;
    }

    /**
     * Find ObjectCollection files that need to be created
     *
     * @param array $objects Loaded Object classes
     * @param array $objectCollections Loaded ObjectCollection files
     * @param ?string $tableFilter Table name filter
     * @param array $brokenObjects Broken Object files
     * @return array<string, array{object_class: string, reflection: ReflectionClass}> ObjectCollections to create
     */
    protected function findObjectCollectionsToCreate(array $objects, array $objectCollections, ?string $tableFilter, array $brokenObjects): array
    {
        $toCreate = [];

        foreach ($objects as $objectClassName => $objectInfo) {
            $objectFile = $objectInfo['file'];

            // Skip broken objects
            if (isset($brokenObjects[$objectFile])) {
                continue;
            }

            // Apply table filter if specified
            if ($tableFilter !== null) {
                $entityClassName = $this->extractEntityClassNameFromObject($objectFile);
                if ($entityClassName !== null && class_exists($entityClassName)) {
                    $entityReflection = new ReflectionClass($entityClassName);
                    $table = $entityReflection->getConstant('TABLE');
                    if ($table !== $tableFilter) {
                        continue;
                    }
                } else {
                    continue;
                }
            }

            // Check if ObjectCollection already exists
            // Try direct lookup first
            $objectCollectionInfo = $objectCollections[$objectClassName] ?? null;

            // If not found, try to find by file name (handle pluralization mismatch)
            if ($objectCollectionInfo === null) {
                $objectShortName = substr($objectClassName, strrpos($objectClassName, '\\') + 1);
                $objectCollectionShortName = StringHelper::pluralize($objectShortName);

                // Try to find ObjectCollection by checking all loaded collections
                // and comparing their file names or class names
                foreach ($objectCollections as $loadedObjectClassName => $collectionInfo) {
                    $collectionReflection = $collectionInfo['reflection'];
                    $collectionShortName = $collectionReflection->getShortName();

                    // Check if collection name matches expected pluralized name
                    if ($collectionShortName === $objectCollectionShortName) {
                        // Verify that the loaded Object class matches our Object class
                        if ($loadedObjectClassName === $objectClassName) {
                            $objectCollectionInfo = $collectionInfo;
                            break;
                        }
                    }
                }
            }

            // If ObjectCollection exists (found by direct lookup or by name), skip
            if ($objectCollectionInfo !== null) {
                continue;
            }

            $toCreate[$objectClassName] = [
                'object_class' => $objectClassName,
                'reflection' => new ReflectionClass($objectClassName),
            ];
        }

        return $toCreate;
    }

    /**
     * Find ObjectCollection files to create for newly created Object files
     *
     * @param array $objectsToCreate Array of tableName => entityInfo for newly created objects
     * @param array $objectsAfter Loaded Object classes after creation (includes new ones)
     * @param array $objectCollections Loaded ObjectCollection files
     * @param array $brokenObjects Broken Object files
     * @return array<string, array{object_class: string, reflection: ReflectionClass}> ObjectCollections to create
     */
    protected function findObjectCollectionsToCreateForNewObjects(array $objectsToCreate, array $objectsAfter, array $objectCollections, array $brokenObjects): array
    {
        $toCreate = [];

        foreach ($objectsToCreate as $tableName => $entityInfo) {
            $entityClassName = $entityInfo['class'];
            
            // Find corresponding Object in objectsAfter by Entity class name
            $objectClassName = null;
            foreach ($objectsAfter as $loadedObjectClassName => $objectInfoAfter) {
                // Extract Entity class name from Object file
                $objectFile = $objectInfoAfter['file'];
                $extractedEntityClassName = $this->extractEntityClassNameFromObject($objectFile);
                if ($extractedEntityClassName === $entityClassName) {
                    $objectClassName = $loadedObjectClassName;
                    break;
                }
            }

            if ($objectClassName === null) {
                continue;
            }

            $objectInfoAfter = $objectsAfter[$objectClassName];
            $objectFile = $objectInfoAfter['file'];

            // Skip broken objects
            if (isset($brokenObjects[$objectFile])) {
                continue;
            }

            // Create reflection if not present
            if (!isset($objectInfoAfter['reflection'])) {
                try {
                    $objectInfoAfter['reflection'] = new ReflectionClass($objectClassName);
                } catch (\Throwable $e) {
                    continue; // Skip if reflection cannot be created
                }
            }

            // Check if ObjectCollection already exists
            $objectCollectionInfo = $objectCollections[$objectClassName] ?? null;

            // If not found, try to find by file name (handle pluralization mismatch)
            if ($objectCollectionInfo === null) {
                $objectShortName = substr($objectClassName, strrpos($objectClassName, '\\') + 1);
                $objectCollectionShortName = StringHelper::pluralize($objectShortName);

                // Try to find ObjectCollection by checking all loaded collections
                foreach ($objectCollections as $loadedObjectClassName => $collectionInfo) {
                    $collectionReflection = $collectionInfo['reflection'];
                    $collectionShortName = $collectionReflection->getShortName();

                    // Check if collection name matches expected pluralized name
                    if ($collectionShortName === $objectCollectionShortName) {
                        // Verify that the loaded Object class matches our Object class
                        if ($loadedObjectClassName === $objectClassName) {
                            $objectCollectionInfo = $collectionInfo;
                            break;
                        }
                    }
                }
            }

            // If ObjectCollection exists, skip
            if ($objectCollectionInfo !== null) {
                continue;
            }

            $toCreate[$objectClassName] = [
                'object_class' => $objectClassName,
                'reflection' => $objectInfoAfter['reflection'],
            ];
        }

        return $toCreate;
    }

    /**
     * Find ObjectCollection files to create for new objects (estimate phase)
     * Uses entity info to predict object class names
     *
     * @param array $objectsToCreate Array of tableName => entityInfo for newly created objects
     * @param array $objects Loaded Object classes
     * @param array $objectCollections Loaded ObjectCollection files
     * @param array $brokenObjects Broken Object files
     * @return array<string, array{object_class: string, reflection: ReflectionClass}> ObjectCollections to create
     */
    protected function findObjectCollectionsToCreateForNewObjectsEstimate(array $objectsToCreate, array $objects, array $objectCollections, array $brokenObjects): array
    {
        $toCreate = [];

        foreach ($objectsToCreate as $tableName => $entityInfo) {
            $entityClassName = $entityInfo['class'];
            
            // Predict Object class name from Entity class name
            $objectClassName = str_replace('\\Entity\\', '\\Object\\', $entityClassName);
            
            // Check if Object already exists (shouldn't, but check anyway)
            if (isset($objects[$objectClassName])) {
                continue;
            }

            // Check if ObjectCollection already exists
            $objectCollectionInfo = $objectCollections[$objectClassName] ?? null;

            // If not found, try to find by file name (handle pluralization mismatch)
            if ($objectCollectionInfo === null) {
                $objectShortName = substr($objectClassName, strrpos($objectClassName, '\\') + 1);
                $objectCollectionShortName = StringHelper::pluralize($objectShortName);

                // Try to find ObjectCollection by checking all loaded collections
                foreach ($objectCollections as $loadedObjectClassName => $collectionInfo) {
                    $collectionReflection = $collectionInfo['reflection'];
                    $collectionShortName = $collectionReflection->getShortName();

                    // Check if collection name matches expected pluralized name
                    if ($collectionShortName === $objectCollectionShortName) {
                        // Verify that the loaded Object class matches our predicted Object class
                        if ($loadedObjectClassName === $objectClassName) {
                            $objectCollectionInfo = $collectionInfo;
                            break;
                        }
                    }
                }
            }

            // If ObjectCollection exists, skip
            if ($objectCollectionInfo !== null) {
                continue;
            }

            // For estimate, we don't need reflection yet - it will be created after Object file is created
            // We just need to mark that this ObjectCollection should be created
            $toCreate[$objectClassName] = [
                'object_class' => $objectClassName,
                'reflection' => null, // Will be created after Object file is created
            ];
        }

        return $toCreate;
    }
}

