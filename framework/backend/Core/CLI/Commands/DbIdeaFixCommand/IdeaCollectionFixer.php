<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands\DbIdeaFixCommand;

use Hilos\Database\Idea\IdeaCollection;
use Hilos\Database\Object\Objects;
use Hilos\Utils\Helpers\StringHelper;
use ReflectionClass;

/**
 * IdeaCollectionFixer trait
 *
 * Handles synchronization of IdeaCollection files (IdeaCollection/{Name}s.php)
 * with ObjectCollection classes.
 *
 * Responsibilities:
 * - Compare IdeaCollection objectToIdea() method with Object class
 * - Update imports to match Object and Idea classes
 * - Preserve user-defined methods (filtering, searching, etc.)
 *
 * @deprecated Idea layer removed; command no longer registered. Kept for reference.
 */
trait IdeaCollectionFixer
{
    /**
     * Load IdeaCollection files from directory
     *
     * @param ?string $ideaCollectionDir IdeaCollection files directory
     * @param int $syntaxErrors Reference to syntax error counter
     * @param array $brokenFiles Reference to broken files array
     * @return array<string, array{class: string, file: string, reflection: ReflectionClass, object_collection_class: string}> Loaded IdeaCollection files info
     */
    protected function loadIdeaCollections(?string $ideaCollectionDir, int &$syntaxErrors = 0, array &$brokenFiles = []): array
    {
        // Auto-detect if not provided
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

            if ($ideaCollectionDir === null) {
                return [];
            }
        }

        if (!is_dir($ideaCollectionDir)) {
            return [];
        }

        $ideaCollections = [];
        $files = glob($ideaCollectionDir . '/*.php');

        if ($files === false) {
            return $ideaCollections;
        }

        foreach ($files as $file) {
            $className = $this->extractClassNameFromIdeaCollectionFile($file);
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

                // Check if file can be loaded safely (without fatal errors)
                // Use class_exists with autoload=false to avoid triggering autoloader
                // which would load the file and potentially cause fatal errors
                if (!class_exists($className, false)) {
                    if (!$this->canLoadFileSafely($file, $className)) {
                        $brokenFiles[$file] = 'Cannot load file: missing dependencies or fatal error during class loading';
                        continue;
                    }
                    require_once $file;
                }

                $reflection = new ReflectionClass($className);
                if (!$reflection->isSubclassOf(IdeaCollection::class)) {
                    continue;
                }

                // Extract ObjectCollection class name from IdeaCollection
                $objectCollectionClassName = $this->extractObjectCollectionClassNameFromIdeaCollection($reflection, $file);
                if ($objectCollectionClassName === null) {
                    $brokenFiles[$file] = 'Cannot determine ObjectCollection class name';
                    continue;
                }

                $ideaCollections[$objectCollectionClassName] = [
                    'class' => $className,
                    'file' => $file,
                    'reflection' => $reflection,
                    'object_collection_class' => $objectCollectionClassName,
                ];
            } catch (\Throwable $e) {
                $brokenFiles[$file] = $e->getMessage();
                continue;
            }
        }

        return $ideaCollections;
    }

    /**
     * Extract class name from IdeaCollection file
     */
    private function extractClassNameFromIdeaCollectionFile(string $file): ?string
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
     * Extract ObjectCollection class name from IdeaCollection reflection or file
     */
    private function extractObjectCollectionClassNameFromIdeaCollection(ReflectionClass $ideaCollectionReflection, string $ideaCollectionFile): ?string
    {
        $content = file_get_contents($ideaCollectionFile);
        if ($content === false) {
            return null;
        }

        // Look for use statement: use ...\ObjectCollection\ClassName as ObjectClassName;
        // Or: use ...\ObjectCollection\ClassName;
        // Use proper regex that separates class name from "as alias" part
        if (preg_match_all('/^use\s+([^\s;]+)(?:\s+as\s+(\w+))?;/m', $content, $useMatches, PREG_SET_ORDER)) {
            foreach ($useMatches as $useMatch) {
                $fullClassName = trim($useMatch[1]);
                // Check that this is an ObjectCollection class
                if (strpos($fullClassName, '\\ObjectCollection\\') !== false) {
                    return $fullClassName;
                }
            }
        }

        // Fallback: try to infer from IdeaCollection class name
        // If IdeaCollection class is "Users", ObjectCollection class should be in same namespace but ObjectCollection subnamespace
        $ideaCollectionNamespace = $ideaCollectionReflection->getNamespaceName();
        $ideaCollectionShortName = $ideaCollectionReflection->getShortName();
        
        // Replace IdeaCollection namespace with ObjectCollection namespace
        $objectCollectionNamespace = str_replace('\\IdeaCollection', '\\ObjectCollection', $ideaCollectionNamespace);
        $objectCollectionClassName = $objectCollectionNamespace . '\\' . $ideaCollectionShortName;
        
        if (class_exists($objectCollectionClassName)) {
            return $objectCollectionClassName;
        }

        return null;
    }

    /**
     * Prepare fixes for IdeaCollection files
     *
     * @param array $objectCollections Loaded ObjectCollection classes
     * @param array $ideaCollections Loaded IdeaCollection files
     * @param ?string $tableFilter Table name filter
     * @return array Fixes to apply
     */
    protected function prepareIdeaCollectionFixes(array $objectCollections, array $ideaCollections, ?string $tableFilter): array
    {
        $fixes = [];

        foreach ($objectCollections as $objectCollectionClassName => $objectCollectionInfo) {
            // Get table name from Entity (ObjectCollection has reference to Entity)
            $objectCollectionReflection = $objectCollectionInfo['reflection'];
            
            // Try to get Entity class from ObjectCollection
            $entityClassName = $this->extractEntityClassNameFromObjectCollection($objectCollectionReflection);
            if ($entityClassName === null) {
                continue;
            }

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

            // Check if IdeaCollection exists for this ObjectCollection
            $ideaCollectionInfo = $ideaCollections[$objectCollectionClassName] ?? null;
            
            if ($ideaCollectionInfo === null) {
                // IdeaCollection doesn't exist - will be created
                continue;
            }

            // Compare IdeaCollection with ObjectCollection
            $ideaFixes = $this->compareIdeaCollectionWithObjectCollection($ideaCollectionInfo, $objectCollectionInfo);
            if (!empty($ideaFixes)) {
                $fixes[$objectCollectionClassName] = $ideaFixes;
            }
        }

        return $fixes;
    }

    /**
     * Extract Entity class name from ObjectCollection
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
        // Or: use ...\Entity\ClassName;
        // Use proper regex that separates class name from "as alias" part
        if (preg_match_all('/^use\s+([^\s;]+)(?:\s+as\s+(\w+))?;/m', $content, $useMatches, PREG_SET_ORDER)) {
            foreach ($useMatches as $useMatch) {
                $fullClassName = trim($useMatch[1]);
                // Check that this is an Entity class, but not EntityCollection
                if (strpos($fullClassName, '\\Entity\\') !== false &&
                    strpos($fullClassName, '\\EntityCollection\\') === false) {
                    return $fullClassName;
                }
            }
        }

        return null;
    }

    /**
     * Compare IdeaCollection with ObjectCollection and prepare fixes
     *
     * @param array $ideaCollectionInfo IdeaCollection info
     * @param array $objectCollectionInfo ObjectCollection info
     * @return array Fixes to apply
     */
    private function compareIdeaCollectionWithObjectCollection(array $ideaCollectionInfo, array $objectCollectionInfo): array
    {
        $fixes = [];
        $ideaCollectionFile = $ideaCollectionInfo['file'];
        $content = file_get_contents($ideaCollectionFile);
        if ($content === false) {
            return $fixes;
        }

        $objectCollectionReflection = $objectCollectionInfo['reflection'];
        
        // Parse IdeaCollection file
        $ideaParsed = $this->parseIdeaCollectionFile($ideaCollectionFile);
        if ($ideaParsed === null) {
            return $fixes;
        }

        // Get Object class name from ObjectCollection
        $objectClassName = $this->extractObjectClassNameFromObjectCollection($objectCollectionReflection);
        if ($objectClassName === null) {
            return $fixes;
        }

        // Get Idea class name (should be in same namespace as IdeaCollection, but Idea subnamespace)
        $ideaCollectionReflection = $ideaCollectionInfo['reflection'];
        $ideaCollectionNamespace = $ideaCollectionReflection->getNamespaceName();
        $ideaNamespace = str_replace('\\IdeaCollection', '\\Idea', $ideaCollectionNamespace);
        $objectShortName = (new ReflectionClass($objectClassName))->getShortName();
        $ideaClassName = $ideaNamespace . '\\' . $objectShortName;

        // Compare objectToIdea() method
        $currentObjectClass = $ideaParsed['object_class'] ?? null;
        $currentIdeaClass = $ideaParsed['idea_class'] ?? null;
        
        if ($currentObjectClass !== $objectClassName || $currentIdeaClass !== $ideaClassName) {
            $fixes['update_objectToIdea'] = [
                'object_class' => $objectClassName,
                'idea_class' => $ideaClassName,
                'current_object_class' => $currentObjectClass,
                'current_idea_class' => $currentIdeaClass,
            ];
        }

        // Compare imports
        $currentImports = $ideaParsed['imports'] ?? [];
        $expectedImports = [
            'idea' => $ideaClassName,
            'object' => $objectClassName,
            'object_collection' => $objectCollectionInfo['class'],
        ];

        $missingImports = [];
        $wrongImports = [];
        
        foreach ($expectedImports as $type => $expectedClass) {
            $currentClass = $currentImports[$type] ?? null;
            if ($currentClass === null) {
                $missingImports[$type] = $expectedClass;
            } elseif ($currentClass !== $expectedClass) {
                $wrongImports[$type] = ['current' => $currentClass, 'expected' => $expectedClass];
            }
        }

        if (!empty($missingImports) || !empty($wrongImports)) {
            $fixes['update_imports'] = [
                'missing' => $missingImports,
                'wrong' => $wrongImports,
                'expected' => $expectedImports,
            ];
        }

        return $fixes;
    }

    /**
     * Extract Object class name from ObjectCollection
     */
    private function extractObjectClassNameFromObjectCollection(ReflectionClass $objectCollectionReflection): ?string
    {
        $file = $objectCollectionReflection->getFileName();
        if ($file === false) {
            return null;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        // Look for use statement: use ...\Object\ClassName as ObjectClassName;
        // Or: use ...\Object\ClassName;
        // Use proper regex that separates class name from "as alias" part
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

        return null;
    }

    /**
     * Apply fixes to IdeaCollection files
     *
     * @param array $fixes Fixes to apply (keyed by object collection class name)
     * @param array $ideaCollections Loaded IdeaCollection files info
     * @param array $objectCollections Loaded ObjectCollection files info
     * @return int Number of files updated
     */
    protected function applyIdeaCollectionFixes(array $fixes, array $ideaCollections, array $objectCollections): int
    {
        $updated = 0;

        foreach ($fixes as $objectCollectionClassName => $ideaFixes) {
            // Get IdeaCollection info
            $ideaCollectionInfo = $ideaCollections[$objectCollectionClassName] ?? null;
            if ($ideaCollectionInfo === null) {
                continue;
            }

            // Get ObjectCollection info
            $objectCollectionInfo = $objectCollections[$objectCollectionClassName] ?? null;
            if ($objectCollectionInfo === null) {
                continue;
            }

            $ideaCollectionFile = $ideaCollectionInfo['file'];
            $objectCollectionReflection = $objectCollectionInfo['reflection'];

            if ($this->applyIdeaCollectionFileFixes($ideaCollectionFile, $ideaFixes, $objectCollectionReflection)) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Apply fixes to a single IdeaCollection file
     *
     * @param string $ideaCollectionFile IdeaCollection file path
     * @param array $fixes Fixes to apply
     * @param ReflectionClass $objectCollectionReflection ObjectCollection reflection
     * @return bool Success
     */
    private function applyIdeaCollectionFileFixes(string $ideaCollectionFile, array $fixes, ReflectionClass $objectCollectionReflection): bool
    {
        $content = file_get_contents($ideaCollectionFile);
        if ($content === false) {
            return false;
        }

        // Get Object and Idea class names
        $objectClassName = $this->extractObjectClassNameFromObjectCollection($objectCollectionReflection);
        if ($objectClassName === null) {
            return false;
        }

        // Get Idea class name
        $objectShortName = (new ReflectionClass($objectClassName))->getShortName();
        $ideaCollectionReflection = new ReflectionClass($this->extractClassNameFromIdeaCollectionFile($ideaCollectionFile));
        $ideaCollectionNamespace = $ideaCollectionReflection->getNamespaceName();
        $ideaNamespace = str_replace('\\IdeaCollection', '\\Idea', $ideaCollectionNamespace);
        $ideaClassName = $ideaNamespace . '\\' . $objectShortName;

        // Apply fixes
        if (isset($fixes['update_objectToIdea'])) {
            $content = $this->rebuildIdeaCollectionObjectToIdea($content, $objectClassName, $ideaClassName);
        }

        if (isset($fixes['update_imports'])) {
            $content = $this->rebuildIdeaCollectionImports($content, $objectClassName, $ideaClassName, $objectCollectionReflection->getName());
        }

        // Write file
        return file_put_contents($ideaCollectionFile, $content) !== false;
    }

    /**
     * Create IdeaCollection file from ObjectCollection class
     *
     * @param string $objectCollectionClassName ObjectCollection class name
     * @param string $ideaCollectionDir IdeaCollection directory
     * @param string $namespace IdeaCollection namespace
     * @param ReflectionClass $objectCollectionReflection ObjectCollection reflection
     * @return bool Success
     */
    protected function createIdeaCollectionFile(string $objectCollectionClassName, string $ideaCollectionDir, string $namespace, ReflectionClass $objectCollectionReflection): bool
    {
        if (!is_dir($ideaCollectionDir)) {
            if (!mkdir($ideaCollectionDir, 0755, true)) {
                return false;
            }
        }

        $ideaCollectionClassName = $objectCollectionReflection->getShortName();
        $ideaCollectionFile = $ideaCollectionDir . '/' . $ideaCollectionClassName . '.php';

        // Get Object class name from ObjectCollection
        $objectClassName = $this->extractObjectClassNameFromObjectCollection($objectCollectionReflection);
        if ($objectClassName === null) {
            return false;
        }

        // Get Idea class name
        $objectShortName = (new ReflectionClass($objectClassName))->getShortName();
        $ideaNamespace = str_replace('\\IdeaCollection', '\\Idea', $namespace);
        $ideaClassName = $ideaNamespace . '\\' . $objectShortName;

        // Determine aliases
        $objectAlias = "Object{$objectShortName}";
        $ideaAlias = "Idea{$objectShortName}";
        $objectCollectionAlias = $ideaCollectionClassName;

        // Build file content
        $content = "<?php\n\n";
        $content .= "namespace {$namespace};\n\n";
        $content .= "use {$ideaClassName} as {$ideaAlias};\n";
        $content .= "use {$objectClassName} as {$objectAlias};\n";
        $content .= "use {$objectCollectionClassName} as {$objectCollectionAlias};\n";
        $content .= "use Hilos\\Database\\Idea\\IdeaCollection;\n";
        $content .= "use Hilos\\Database\\Object\\Objects;\n\n";
        $content .= "/**\n";
        $content .= " * {$ideaCollectionClassName} Idea Collection\n";
        $content .= " * Collection of {$objectShortName} ideas with additional filtering methods\n";
        $content .= " */\n";
        $content .= "final class {$ideaCollectionClassName} extends IdeaCollection\n";
        $content .= "{\n";
        $content .= "    /**\n";
        $content .= "     * Initialize collection from objects\n";
        $content .= "     */\n";
        $content .= "    public static function init(Objects &\$objects): self\n";
        $content .= "    {\n";
        $content .= "        return new self(\$objects);\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Convert object to idea\n";
        $content .= "     */\n";
        $content .= "    protected function objectToIdea(mixed \$object): {$ideaAlias}\n";
        $content .= "    {\n";
        $content .= "        if (!(\$object instanceof {$objectAlias})) {\n";
        $content .= "            throw new \\InvalidArgumentException(\"Object must be instance of {$objectAlias}\");\n";
        $content .= "        }\n\n";
        $content .= "        return {$ideaAlias}::get(\$object);\n";
        $content .= "    }\n";
        $content .= "}\n";

        return file_put_contents($ideaCollectionFile, $content) !== false;
    }

    /**
     * Parse IdeaCollection file to extract current structure
     *
     * @param string $filePath IdeaCollection file path
     * @return ?array Parsed structure or null if failed
     */
    protected function parseIdeaCollectionFile(string $filePath): ?array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $parsed = [
            'object_class' => null,
            'idea_class' => null,
            'imports' => [],
        ];

        // Parse objectToIdea() method
        if (preg_match('/protected\s+function\s+objectToIdea\s*\([^)]*\)\s*:\s*(\w+)\s*\{([^}]+)}/s', $content, $objectToIdeaMatch)) {
            $returnType = trim($objectToIdeaMatch[1]); // Idea class name
            $methodBody = $objectToIdeaMatch[2];

            // Extract Object class from instanceof check
            if (preg_match('/instanceof\s+(\w+)/', $methodBody, $instanceofMatch)) {
                $parsed['object_class'] = $this->resolveClassNameFromUse($content, $instanceofMatch[1]);
            }

            // Extract Idea class from return type or IdeaClass::get() call
            if (preg_match('/(\w+)::get\(/', $methodBody, $getMatch)) {
                $parsed['idea_class'] = $this->resolveClassNameFromUse($content, $getMatch[1]);
            } else {
                $parsed['idea_class'] = $this->resolveClassNameFromUse($content, $returnType);
            }
        }

        // Parse imports
        // Use proper regex that separates class name from "as alias" part
        if (preg_match_all('/^use\s+([^\s;]+)(?:\s+as\s+(\w+))?;/m', $content, $useMatches, PREG_SET_ORDER)) {
            foreach ($useMatches as $useMatch) {
                $fullClassName = trim($useMatch[1]);
                $alias = isset($useMatch[2]) ? trim($useMatch[2]) : null;

                // Determine type of import
                if (strpos($fullClassName, '\\Idea\\') !== false || strpos($fullClassName, '\\IdeaCollection\\') !== false) {
                    $shortName = $alias ?? $this->getShortClassName($fullClassName);
                    if (strpos($fullClassName, '\\Idea\\') !== false) {
                        $parsed['imports']['idea'] = $fullClassName;
                    }
                } elseif (strpos($fullClassName, '\\Object\\') !== false) {
                    $parsed['imports']['object'] = $fullClassName;
                } elseif (strpos($fullClassName, '\\ObjectCollection\\') !== false) {
                    $parsed['imports']['object_collection'] = $fullClassName;
                }
            }
        }

        return $parsed;
    }

    /**
     * Resolve full class name from use statement alias
     */
    private function resolveClassNameFromUse(string $content, string $alias): ?string
    {
        // Look for use statement with this alias
        // Use proper regex that separates class name from "as alias" part
        if (preg_match('/^use\s+([^\s;]+)\s+as\s+' . preg_quote($alias, '/') . ';/m', $content, $match)) {
            return trim($match[1]);
        }

        // Look for use statement without alias (short name matches)
        $shortName = $this->getShortClassName($alias);
        if (preg_match_all('/^use\s+([^\s;]+);/m', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fullClassName = trim($match[1]);
                if ($this->getShortClassName($fullClassName) === $shortName) {
                    return $fullClassName;
                }
            }
        }

        return null;
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
     * Extract user-defined methods from IdeaCollection file
     *
     * @param string $content File content
     * @return array<string, string> User-defined methods (method name => method code)
     */
    protected function extractIdeaCollectionUserMethods(string $content): array
    {
        $userMethods = [];

        // Standard methods to exclude
        $standardMethods = [
            '__construct',
            'objectToIdea',
            'init',
            'clearCache',
            'getIdeaForKey',
            'toArray',
            'filter',
            'map',
            'first',
            'last',
            'backupIndex',
            'restoreIndex',
            'offsetExists',
            'offsetGet',
            'offsetSet',
            'offsetUnset',
            'count',
            'current',
            'key',
            'next',
            'rewind',
            'valid',
            '__debugInfo',
        ];

        // Extract all methods
        if (preg_match_all('/(?:public|private|protected)\s+(?:static\s+)?function\s+(\w+)\s*\([^)]*\)\s*:[^\{]*\{([^}]+)}/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $methodName = $match[1];
                
                // Skip standard methods
                if (in_array($methodName, $standardMethods, true)) {
                    continue;
                }

                // Skip magic methods
                if (strpos($methodName, '__') === 0) {
                    continue;
                }

                $userMethods[$methodName] = trim($match[0]);
            }
        }

        return $userMethods;
    }

    /**
     * Rebuild objectToIdea() method in IdeaCollection
     *
     * @param string $content Current file content
     * @param string $objectClassName Object class name
     * @param string $ideaClassName Idea class name
     * @return string Updated content
     */
    protected function rebuildIdeaCollectionObjectToIdea(string $content, string $objectClassName, string $ideaClassName): string
    {
        // Extract aliases from use statements
        $objectAlias = $this->extractAliasFromUse($content, $objectClassName) ?? $this->getShortClassName($objectClassName);
        $ideaAlias = $this->extractAliasFromUse($content, $ideaClassName) ?? $this->getShortClassName($ideaClassName);

        // Build new objectToIdea() method
        $newMethod = "    /**\n";
        $newMethod .= "     * Convert object to idea\n";
        $newMethod .= "     */\n";
        $newMethod .= "    protected function objectToIdea(mixed \$object): {$ideaAlias}\n";
        $newMethod .= "    {\n";
        $newMethod .= "        if (!(\$object instanceof {$objectAlias})) {\n";
        $newMethod .= "            throw new \\InvalidArgumentException(\"Object must be instance of {$objectAlias}\");\n";
        $newMethod .= "        }\n\n";
        $newMethod .= "        return {$ideaAlias}::get(\$object);\n";
        $newMethod .= "    }";

        // Replace existing objectToIdea() method
        if (preg_match('/(protected\s+function\s+objectToIdea\s*\([^)]*\)\s*:[^\{]*\{[^}]*})/s', $content, $matches)) {
            $content = str_replace($matches[1], $newMethod, $content);
        } else {
            // Insert before closing brace of class
            if (preg_match('/(\n\s*)(})/s', $content, $matches)) {
                $content = str_replace($matches[0], "\n\n" . $newMethod . "\n" . $matches[2], $content);
            }
        }

        return $content;
    }

    /**
     * Extract alias from use statement
     */
    private function extractAliasFromUse(string $content, string $fullClassName): ?string
    {
        // Look for use statement with alias
        if (preg_match('/use\s+' . preg_quote($fullClassName, '/') . '\s+as\s+(\w+);/', $content, $match)) {
            return trim($match[1]);
        }

        // Look for use statement without alias
        if (preg_match('/use\s+' . preg_quote($fullClassName, '/') . ';/', $content)) {
            return $this->getShortClassName($fullClassName);
        }

        return null;
    }

    /**
     * Rebuild imports in IdeaCollection file
     *
     * @param string $content Current file content
     * @param string $objectClassName Object class name
     * @param string $ideaClassName Idea class name
     * @param string $objectCollectionClassName ObjectCollection class name
     * @return string Updated content
     */
    protected function rebuildIdeaCollectionImports(string $content, string $objectClassName, string $ideaClassName, string $objectCollectionClassName): string
    {
        // Determine aliases
        $objectShortName = $this->getShortClassName($objectClassName);
        $ideaShortName = $this->getShortClassName($ideaClassName);
        $objectCollectionShortName = $this->getShortClassName($objectCollectionClassName);

        $objectAlias = "Object{$objectShortName}";
        $ideaAlias = "Idea{$ideaShortName}";
        $objectCollectionAlias = $objectCollectionShortName;

        // Build new use statements
        $newUseStatements = [];
        $newUseStatements[] = "use {$ideaClassName} as {$ideaAlias};";
        $newUseStatements[] = "use {$objectClassName} as {$objectAlias};";
        $newUseStatements[] = "use {$objectCollectionClassName} as {$objectCollectionAlias};";
        $newUseStatements[] = "use Hilos\\Database\\Idea\\IdeaCollection;";
        $newUseStatements[] = "use Hilos\\Database\\Object\\Objects;";

        // Find existing use statements section
        if (preg_match('/(namespace\s+[^;]+;\n\n)(.*?)(\n(?:final\s+)?class\s+\w+)/s', $content, $matches)) {
            $existingUses = $matches[2];
            
            // Remove old use statements for these classes
            $existingUses = preg_replace('/use\s+[^;]+\\\Idea[^;]*;?\n?/', '', $existingUses);
            $existingUses = preg_replace('/use\s+[^;]+\\\Object[^;]*;?\n?/', '', $existingUses);
            $existingUses = preg_replace('/use\s+[^;]+\\\ObjectCollection[^;]*;?\n?/', '', $existingUses);
            $existingUses = preg_replace('/use\s+Hilos\\\Database\\\Idea\\\IdeaCollection;?\n?/', '', $existingUses);
            $existingUses = preg_replace('/use\s+Hilos\\\Database\\\Object\\\Objects;?\n?/', '', $existingUses);
            
            // Keep other use statements
            $otherUses = trim($existingUses);
            
            // Combine new and existing use statements
            $allUseStatements = array_merge($newUseStatements, $otherUses ? [$otherUses] : []);
            
            $newUseSection = implode("\n", $allUseStatements) . "\n";
            
            $content = str_replace($matches[0], $matches[1] . $newUseSection . $matches[3], $content);
        } else {
            // Insert after namespace
            if (preg_match('/(namespace\s+[^;]+;\n\n)/', $content, $nsMatch)) {
                $content = str_replace($nsMatch[1], $nsMatch[1] . implode("\n", $newUseStatements) . "\n\n", $content);
            }
        }

        return $content;
    }

    /**
     * Find IdeaCollection files to delete (when corresponding ObjectCollection class doesn't exist)
     *
     * @param array $objectCollections Loaded ObjectCollection classes (keyed by class name)
     * @param array $ideaCollections Loaded IdeaCollection files (keyed by object collection class name)
     * @return array IdeaCollection files to delete (keyed by object collection class name)
     */
    protected function findIdeaCollectionsToDelete(array $objectCollections, array $ideaCollections): array
    {
        // Build set of existing ObjectCollection class names for quick lookup
        $existingObjectCollectionClasses = [];
        foreach ($objectCollections as $objectCollectionClassName => $objectCollectionInfo) {
            $existingObjectCollectionClasses[$objectCollectionClassName] = true;
        }

        // Find IdeaCollection files that reference non-existent ObjectCollection classes
        $toDelete = [];
        foreach ($ideaCollections as $objectCollectionClassName => $ideaCollectionInfo) {
            // If ObjectCollection class doesn't exist, mark IdeaCollection for deletion
            if (!isset($existingObjectCollectionClasses[$objectCollectionClassName])) {
                $toDelete[$objectCollectionClassName] = $ideaCollectionInfo;
            }
        }

        return $toDelete;
    }

    /**
     * Find vendor/autoload.php path relative to current working directory or file location
     *
     * @param ?string $referenceFile Optional reference file to search from
     * @return ?string Path to autoload.php or null if not found
     */
    private function findAutoloadPath(?string $referenceFile = null): ?string
    {
        $searchDirs = [];
        
        if ($referenceFile !== null) {
            $dir = dirname($referenceFile);
            // Go up to 5 levels to find vendor
            for ($i = 0; $i < 5; $i++) {
                $searchDirs[] = $dir;
                $dir = dirname($dir);
            }
        }
        
        $cwd = getcwd();
        $searchDirs[] = $cwd;
        // Go up to 5 levels from CWD
        $dir = $cwd;
        for ($i = 0; $i < 5; $i++) {
            $searchDirs[] = $dir;
            $dir = dirname($dir);
        }
        
        foreach (array_unique($searchDirs) as $dir) {
            $autoloadPath = $dir . '/vendor/autoload.php';
            if (file_exists($autoloadPath)) {
                return $autoloadPath;
            }
        }
        
        return null;
    }

    /**
     * Check if PHP file can be loaded safely without fatal errors
     * Uses a child process to isolate potential fatal errors
     *
     * @param string $file File path to check
     * @param string $className Expected class name
     * @return bool True if file can be loaded safely, false otherwise
     */
    private function canLoadFileSafely(string $file, string $className): bool
    {
        $autoloadPath = $this->findAutoloadPath($file);
        if ($autoloadPath === null) {
            // If autoload not found, try to load anyway (might work without it)
            // But this is risky, so we'll return false to be safe
            return false;
        }
        
        $autoloadPath = realpath($autoloadPath);
        $filePath = realpath($file);
        
        if ($autoloadPath === false || $filePath === false) {
            return false;
        }
        
        // Create temporary PHP script to test file loading
        $tempScript = sys_get_temp_dir() . '/hilos_check_' . md5($filePath . $className) . '.php';
        
        // Build test script
        $scriptContent = "<?php\n";
        $scriptContent .= "error_reporting(E_ALL);\n";
        $scriptContent .= "ini_set('display_errors', 0);\n";
        $scriptContent .= "ini_set('log_errors', 0);\n";
        $scriptContent .= "\n";
        $scriptContent .= "// Load autoloader\n";
        $scriptContent .= "require_once " . var_export($autoloadPath, true) . ";\n";
        $scriptContent .= "\n";
        $scriptContent .= "try {\n";
        $scriptContent .= "    // Try to load the file\n";
        $scriptContent .= "    require_once " . var_export($filePath, true) . ";\n";
        $scriptContent .= "    \n";
        $scriptContent .= "    // Check if class exists\n";
        $scriptContent .= "    if (!class_exists(" . var_export($className, true) . ")) {\n";
        $scriptContent .= "        exit(1);\n";
        $scriptContent .= "    }\n";
        $scriptContent .= "    \n";
        $scriptContent .= "    // Try to create reflection (this will trigger compatibility checks)\n";
        $scriptContent .= "    \$reflection = new ReflectionClass(" . var_export($className, true) . ");\n";
        $scriptContent .= "    \n";
        $scriptContent .= "    // Success\n";
        $scriptContent .= "    exit(0);\n";
        $scriptContent .= "} catch (Throwable \$e) {\n";
        $scriptContent .= "    // Any error means file cannot be loaded safely\n";
        $scriptContent .= "    exit(1);\n";
        $scriptContent .= "}\n";
        
        // Write temporary script
        if (file_put_contents($tempScript, $scriptContent) === false) {
            return false;
        }
        
        // Execute script in child process
        $output = [];
        $returnVar = 0;
        exec('php ' . escapeshellarg($tempScript) . ' 2>&1', $output, $returnVar);
        
        // Clean up temporary file
        @unlink($tempScript);
        
        // Return true if exit code is 0 (success)
        return $returnVar === 0;
    }
}

