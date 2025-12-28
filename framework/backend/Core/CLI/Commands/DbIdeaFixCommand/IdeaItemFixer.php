<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands\DbIdeaFixCommand;

use Hilos\Database\Idea\IdeaItem;
use Hilos\Database\Object\Object_;
use Hilos\Utils\Helpers\StringHelper;
use ReflectionClass;

/**
 * IdeaItemFixer trait
 *
 * Handles synchronization of Idea item files (Idea/{Name}.php)
 * with Object classes. Idea is isolated from Entity and works only with Object.
 *
 * Responsibilities:
 * - Compare Idea item __get() method with Object properties
 * - Compare Idea item toArray() method with Object properties
 * - Update PHPDoc @property-read annotations
 * - Preserve user-defined methods (lazy loading, relationships, etc.)
 */
trait IdeaItemFixer
{
    /**
     * Load Idea item files from directory
     *
     * @param string|null $ideaDir Idea files directory
     * @param int $syntaxErrors Reference to syntax error counter
     * @param array $brokenFiles Reference to broken files array
     * @return array<string, array{class: string, file: string, reflection: ReflectionClass}> Loaded Idea item files info
     */
    protected function loadIdeaItems(?string $ideaDir, int &$syntaxErrors = 0, array &$brokenFiles = []): array
    {
        // Auto-detect if not provided
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

            if ($ideaDir === null) {
                // Idea directory doesn't exist yet, that's OK
                return [];
            }
        }

        if (!is_dir($ideaDir)) {
            return [];
        }

        $IdeaItems = [];
        $files = glob($ideaDir . '/*.php');

        if ($files === false) {
            return $IdeaItems;
        }

        foreach ($files as $file) {
            $className = $this->extractClassNameFromIdeaFile($file);
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
                if (!$reflection->isSubclassOf(IdeaItem::class)) {
                    continue;
                }

                // Extract Object class name from IdeaItem
                $objectClassName = $this->extractObjectClassNameFromIdeaItem($reflection, $file);
                if ($objectClassName === null) {
                    $brokenFiles[$file] = 'Cannot determine Object class name';
                    continue;
                }

                $IdeaItems[$objectClassName] = [
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

        return $IdeaItems;
    }

    /**
     * Extract class name from Idea file
     */
    private function extractClassNameFromIdeaFile(string $file): ?string
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
     * Extract Object class name from IdeaItem reflection or file
     */
    private function extractObjectClassNameFromIdeaItem(ReflectionClass $ideaReflection, string $ideaFile): ?string
    {
        // Try to find Object class from use statements or property type
        $content = file_get_contents($ideaFile);
        if ($content === false) {
            return null;
        }

        // Look for use statement: use ...\Object\ClassName as ObjectClassName;
        // Or: use ...\Object\ClassName;
        if (preg_match('/use\s+([^;]+\\\Object\\\[^;]+)(?:\s+as\s+(\w+))?;/', $content, $useMatch)) {
            $objectClassName = trim($useMatch[1]);
            // Remove "as Alias" part if exists
            if (isset($useMatch[2])) {
                // If there's an alias, we need to find the actual class name
                // Look for property type hint
                if (preg_match('/private\s+(\w+)\s+\$object\w+;/', $content, $propMatch)) {
                    $alias = $useMatch[2];
                    // The alias should match the property type
                    if ($propMatch[1] === $alias) {
                        return $objectClassName;
                    }
                }
            } else {
                return $objectClassName;
            }
        }

        // Try to find from property type hint: private ObjectClassName $object...
        if (preg_match('/private\s+(\w+)\s+\$object\w+;/', $content, $propMatch)) {
            $typeHint = $propMatch[1];
            // Try to find use statement for this type
            if (preg_match('/use\s+([^;]+)\s+as\s+' . preg_quote($typeHint, '/') . ';/', $content, $useMatch)) {
                return trim($useMatch[1]);
            }
        }

        // Fallback: try to infer from Idea class name
        // If Idea class is "User", Object class should be in same namespace but Object subnamespace
        $ideaNamespace = $ideaReflection->getNamespaceName();
        $ideaShortName = $ideaReflection->getShortName();
        
        // Replace Idea namespace with Object namespace
        $objectNamespace = str_replace('\\Idea', '\\Object', $ideaNamespace);
        $objectClassName = $objectNamespace . '\\' . $ideaShortName;
        
        if (class_exists($objectClassName)) {
            return $objectClassName;
        }

        return null;
    }

    /**
     * Prepare fixes for Idea item files
     *
     * @param array $objects Loaded Object classes
     * @param array $IdeaItems Loaded Idea item files
     * @param string|null $tableFilter Table name filter
     * @param array $brokenIdeaItems Reference to broken files array (will be populated with parse errors)
     * @return array Fixes to apply
     */
    protected function prepareIdeaItemFixes(array $objects, array $IdeaItems, ?string $tableFilter, array &$brokenIdeaItems = []): array
    {
        $fixes = [];

        foreach ($objects as $objectClassName => $objectInfo) {
            $objectReflection = $objectInfo['reflection'];
            
            // Get table name from Entity (Object has reference to Entity)
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

            // Check if IdeaItem exists for this Object
            $IdeaItemInfo = $IdeaItems[$objectClassName] ?? null;
            
            if ($IdeaItemInfo === null) {
                // IdeaItem doesn't exist - will be created
                continue;
            }

            // Compare IdeaItem with Object
            $parseError = null;
            $ideaFixes = $this->compareIdeaItemWithObject($IdeaItemInfo, $objectInfo, $parseError);
            
            // If parsing failed, add to broken files and skip
            if ($parseError !== null) {
                $brokenIdeaItems[$IdeaItemInfo['file']] = $parseError;
                continue;
            }
            
            if (!empty($ideaFixes)) {
                $fixes[$objectClassName] = $ideaFixes;
            }
        }

        return $fixes;
    }

    /**
     * Compare IdeaItem with Object and prepare fixes
     *
     * @param array $IdeaItemInfo IdeaItem info
     * @param array $objectInfo Object info
     * @param array|null $parseError Reference to store parse error message
     * @return array Fixes to apply
     */
    private function compareIdeaItemWithObject(array $IdeaItemInfo, array $objectInfo, ?string &$parseError = null): array
    {
        $fixes = [];
        $parseError = null;
        $ideaFile = $IdeaItemInfo['file'];
        $content = file_get_contents($ideaFile);
        if ($content === false) {
            $parseError = 'Failed to read file content';
            return $fixes;
        }

        $objectReflection = $objectInfo['reflection'];
        
        // Parse IdeaItem file
        $ideaParsed = $this->parseIdeaItemFile($ideaFile);
        if ($ideaParsed === null) {
            $parseError = 'Failed to parse IdeaItem file structure (possibly due to unsupported syntax or malformed file)';
            return $fixes;
        }

        // Get Object properties from PHPDoc
        $objectProperties = $this->extractObjectProperties($objectReflection);
        
        // Compare __get() method
        $getterProperties = $ideaParsed['getter_properties'] ?? [];
        $missingInGetter = array_diff_key($objectProperties, $getterProperties);
        $extraInGetter = array_diff_key($getterProperties, $objectProperties);
        
        if (!empty($missingInGetter) || !empty($extraInGetter)) {
            $fixes['update_getter'] = [
                'missing' => $missingInGetter,
                'extra' => $extraInGetter,
                'all_properties' => $objectProperties,
            ];
        }

        // Compare toArray() method
        $toArrayProperties = $ideaParsed['toarray_properties'] ?? [];
        $missingInToArray = array_diff_key($objectProperties, $toArrayProperties);
        $extraInToArray = array_diff_key($toArrayProperties, $objectProperties);
        
        if (!empty($missingInToArray) || !empty($extraInToArray)) {
            $fixes['update_toarray'] = [
                'missing' => $missingInToArray,
                'extra' => $extraInToArray,
                'all_properties' => $objectProperties,
            ];
        }

        // Compare PHPDoc @property-read
        $phpdocProperties = $ideaParsed['phpdoc_properties'] ?? [];
        $missingInPhpDoc = array_diff_key($objectProperties, $phpdocProperties);
        $extraInPhpDoc = array_diff_key($phpdocProperties, $objectProperties);
        
        if (!empty($missingInPhpDoc) || !empty($extraInPhpDoc)) {
            $fixes['update_phpdoc'] = [
                'missing' => $missingInPhpDoc,
                'extra' => $extraInPhpDoc,
                'all_properties' => $objectProperties,
            ];
        }

        return $fixes;
    }

    /**
     * Extract Object properties from ReflectionClass
     *
     * @param ReflectionClass $objectReflection Object reflection
     * @return array<string, array{type: string, constant: string}> Object properties
     */
    private function extractObjectProperties(ReflectionClass $objectReflection): array
    {
        $properties = [];
        
        // Get PHPDoc from Object class
        $docComment = $objectReflection->getDocComment();
        if ($docComment !== false) {
            // Parse @property and @property-read annotations
            if (preg_match_all('/@property(?:-read)?\s+(\S+)\s+\$(\w+)/', $docComment, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $type = trim($match[1]);
                    $name = trim($match[2]);
                    
                    // Get constant name from Object class
                    $constantName = $this->getObjectConstantName($objectReflection, $name);
                    
                    $properties[$name] = [
                        'type' => $type,
                        'constant' => $constantName,
                    ];
                }
            }
        }

        return $properties;
    }

    /**
     * Get Object constant name for property
     *
     * @param ReflectionClass $objectReflection Object reflection
     * @param string $propertyName Property name (camelCase)
     * @return string Constant name
     */
    private function getObjectConstantName(ReflectionClass $objectReflection, string $propertyName): string
    {
        // Try to get constant from Object class
        // Constants are usually named the same as properties (camelCase)
        if ($objectReflection->hasConstant($propertyName)) {
            $constantValue = $objectReflection->getConstant($propertyName);
            // Constant value should match property name
            if (is_string($constantValue)) {
                return $propertyName; // Return the constant name (same as property name)
            }
        }

        // Fallback: property name is the constant name
        // In Object classes, constants are typically named the same as properties
        return $propertyName;
    }

    /**
     * Apply fixes to Idea item files
     *
     * @param array $fixes Fixes to apply (keyed by object class name)
     * @param array $IdeaItems Loaded IdeaItem files info
     * @param array $objects Loaded Object files info
     * @return int Number of files updated
     */
    protected function applyIdeaItemFixes(array $fixes, array $IdeaItems, array $objects): int
    {
        $updated = 0;

        foreach ($fixes as $objectClassName => $ideaFixes) {
            // Get IdeaItem info
            $IdeaItemInfo = $IdeaItems[$objectClassName] ?? null;
            if ($IdeaItemInfo === null) {
                continue;
            }

            // Get Object info
            $objectInfo = $objects[$objectClassName] ?? null;
            if ($objectInfo === null) {
                continue;
            }

            $ideaFile = $IdeaItemInfo['file'];
            $objectReflection = $objectInfo['reflection'];

            if ($this->applyIdeaItemFileFixes($ideaFile, $ideaFixes, $objectReflection)) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Apply fixes to a single IdeaItem file
     *
     * @param string $ideaFile IdeaItem file path
     * @param array $fixes Fixes to apply
     * @param ReflectionClass $objectReflection Object reflection
     * @return bool Success
     */
    private function applyIdeaItemFileFixes(string $ideaFile, array $fixes, ReflectionClass $objectReflection): bool
    {
        $content = file_get_contents($ideaFile);
        if ($content === false) {
            return false;
        }

        // Get Object properties
        $objectProperties = $this->extractObjectProperties($objectReflection);

        // Apply fixes
        if (isset($fixes['update_getter'])) {
            $content = $this->rebuildIdeaItemGetter($content, $objectProperties, $objectReflection);
        }

        if (isset($fixes['update_toarray'])) {
            $content = $this->rebuildIdeaItemToArray($content, $objectProperties, $objectReflection);
        }

        if (isset($fixes['update_phpdoc'])) {
            $content = $this->rebuildIdeaItemPhpDoc($content, $objectProperties, $objectReflection);
        }

        // Write file
        return file_put_contents($ideaFile, $content) !== false;
    }

    /**
     * Create Idea item file from Object class
     *
     * @param string $objectClassName Object class name
     * @param string $ideaDir Idea directory
     * @param string $namespace Idea namespace
     * @param ReflectionClass $objectReflection Object reflection
     * @return bool Success
     */
    protected function createIdeaItemFile(string $objectClassName, string $ideaDir, string $namespace, ReflectionClass $objectReflection): bool
    {
        if (!is_dir($ideaDir)) {
            if (!mkdir($ideaDir, 0755, true)) {
                return false;
            }
        }

        // Prepare all variables upfront
        $ideaClassName = $objectReflection->getShortName();
        $ideaFile = $ideaDir . '/' . $ideaClassName . '.php';
        $objectProperties = $this->extractObjectProperties($objectReflection);
        $objectShortName = $objectReflection->getShortName();
        $objectClassAlias = "Object{$objectShortName}";
        $objectPropertyName = $this->camelCaseToPropertyName($objectShortName);
        $pluralizedCacheName = StringHelper::pluralize(strtolower($ideaClassName));

        // Build PHPDoc property annotations
        $phpDocProperties = [];
        foreach ($objectProperties as $propertyName => $propertyInfo) {
            $type = $propertyInfo['type'];
            $phpDocProperties[] = " * @property-read {$type} \${$propertyName}";
        }
        $phpDocPropertiesStr = implode("\n", $phpDocProperties);

        // Build file content
        $content = "<?php\n\n";
        $content .= "namespace {$namespace};\n\n";
        $content .= "use {$objectClassName} as {$objectClassAlias};\n";
        $content .= "use Hilos\\Database\\Idea\\IdeaItem as BaseIdea;\n";
        $content .= "use Hilos\\Database\\Idea\\IdeaCollection;\n\n";

        // PHPDoc
        $content .= "/**\n";
        $content .= " * {$ideaClassName} Idea\n";
        $content .= " * High-level abstraction with lazy loading and relationships\n";
        $content .= " *\n";
        $content .= $phpDocPropertiesStr . "\n";
        $content .= " */\n";

        // Class declaration
        $content .= "final class {$ideaClassName} extends BaseIdea\n";
        $content .= "{\n";
        $content .= "    /** @var self[] Global cache of {$ideaClassName} ideas */\n";
        $content .= "    private static array \${$pluralizedCacheName} = [];\n\n";
        $content .= "    private {$objectClassAlias} \${$objectPropertyName};\n\n";
        $content .= "    protected function __construct({$objectClassAlias} &\${$objectPropertyName})\n";
        $content .= "    {\n";
        $content .= "        parent::__construct();\n";
        $content .= "        \$this->{$objectPropertyName} = &\${$objectPropertyName};\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Flush global cache\n";
        $content .= "     */\n";
        $content .= "    public static function flushCache(): void\n";
        $content .= "    {\n";
        $content .= "        self::\${$pluralizedCacheName} = [];\n";
        $content .= "    }\n\n";
        $content .= "    /**\n";
        $content .= "     * Get {$ideaClassName} idea instance (cached)\n";
        $content .= "     */\n";
        $content .= "    public static function get({$objectClassAlias} &\${$objectPropertyName}): self\n";
        $content .= "    {\n";
        $content .= "        \$id = \${$objectPropertyName}->id;\n\n";
        $content .= "        if (!isset(self::\${$pluralizedCacheName}[\$id])) {\n";
        $content .= "            self::\${$pluralizedCacheName}[\$id] = new self(\${$objectPropertyName});\n";
        $content .= "        } elseif (self::\${$pluralizedCacheName}[\$id]->{$objectPropertyName} !== \${$objectPropertyName}) {\n";
        $content .= "            // Object reference changed, recreate\n";
        $content .= "            self::\${$pluralizedCacheName}[\$id] = new self(\${$objectPropertyName});\n";
        $content .= "        }\n\n";
        $content .= "        return self::\${$pluralizedCacheName}[\$id];\n";
        $content .= "    }\n\n";

        // __get() method
        $getterMethod = $this->buildGetterMethod($objectProperties, $objectClassAlias, $objectPropertyName, $ideaClassName);
        $content .= $getterMethod;
        $content .= "\n\n";

        // toArray() method
        $toArrayMethod = $this->buildToArrayMethod($objectProperties, $objectClassAlias, $objectPropertyName);
        $content .= $toArrayMethod;
        $content .= "\n";
        $content .= "}\n";

        return file_put_contents($ideaFile, $content) !== false;
    }

    /**
     * Build __get() method content
     */
    private function buildGetterMethod(array $objectProperties, string $objectClassAlias, string $objectPropertyName, string $ideaClassName): string
    {
        $getterCases = [];
        foreach ($objectProperties as $propertyName => $propertyInfo) {
            $constant = $propertyInfo['constant'];
            $getterCases[] = "            {$objectClassAlias}::{$constant} => \$this->{$objectPropertyName}->{$constant},";
        }

        $method = "    /**\n";
        $method .= "     * Property getter (read-only access)\n";
        $method .= "     */\n";
        $method .= "    public function __get(string \$name): IdeaCollection|int|string|bool|null\n";
        $method .= "    {\n";
        $method .= "        return match (\$name) {\n";
        $method .= implode("\n", $getterCases) . "\n";
        $method .= "\n            // Example of lazy loading relationships (implement when you have related entities)\n";
        $method .= "            // 'orders' => \$this->loadOrders(),\n";
        $method .= "            // 'posts' => \$this->loadPosts(),\n";
        $method .= "\n            default => throw new \\Exception(\"Property [{\$name}] does not exist on Idea{$ideaClassName}\"),\n";
        $method .= "        };\n";
        $method .= "    }";

        return $method;
    }

    /**
     * Build toArray() method content
     */
    private function buildToArrayMethod(array $objectProperties, string $objectClassAlias, string $objectPropertyName): string
    {
        $toArrayLines = [];
        $toArrayLines[] = "        \$data = [];";
        $toArrayLines[] = "";

        // Add ID field first if exists
        $hasId = false;
        foreach ($objectProperties as $propertyName => $propertyInfo) {
            if (strtolower($propertyName) === 'id') {
                $constant = $propertyInfo['constant'];
                $toArrayLines[] = "        if (\$withId) {";
                $toArrayLines[] = "            \$data[{$objectClassAlias}::{$constant}] = \$this->{$objectPropertyName}->{$constant};";
                $toArrayLines[] = "        }";
                $toArrayLines[] = "";
                $hasId = true;
                break;
            }
        }

        // Add other properties
        foreach ($objectProperties as $propertyName => $propertyInfo) {
            if (strtolower($propertyName) === 'id') {
                continue;
            }
            $constant = $propertyInfo['constant'];
            $toArrayLines[] = "        \$data[{$objectClassAlias}::{$constant}] = \$this->{$objectPropertyName}->{$constant};";
        }

        $toArrayLines[] = "";
        $toArrayLines[] = "        return \$data;";

        $method = "    /**\n";
        $method .= "     * Convert to array\n";
        $method .= "     */\n";
        $method .= "    public function toArray(bool \$withId = true, bool \$idAsIndex = true, bool \$withBridges = false, bool \$withCalculation = false): array\n";
        $method .= "    {\n";
        $method .= implode("\n", $toArrayLines) . "\n";
        $method .= "    }";

        return $method;
    }

    /**
     * Convert camelCase to property name (e.g., User -> objectUser)
     */
    private function camelCaseToPropertyName(string $className): string
    {
        return 'object' . $className;
    }


    /**
     * Parse Idea item file to extract current structure
     *
     * @param string $filePath Idea item file path
     * @return array|null Parsed structure or null if failed
     */
    protected function parseIdeaItemFile(string $filePath): ?array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $parsed = [
            'getter_properties' => [],
            'toarray_properties' => [],
            'phpdoc_properties' => [],
        ];

        // Parse __get() method
        if (preg_match('/public\s+function\s+__get\s*\([^)]*\)\s*:[^\{]*\{([^}]+)\}/s', $content, $getterMatch)) {
            $getterBody = $getterMatch[1];
            
            // Match cases: ObjectClass::property => $this->objectClass->property
            if (preg_match_all('/(\w+)::(\w+)\s*=>\s*\$this->object\w+->\w+/', $getterBody, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $objectClass = $match[1]; // e.g., "ObjectUser"
                    $property = $match[2]; // e.g., "id"
                    $parsed['getter_properties'][$property] = [
                        'object_class' => $objectClass,
                        'property' => $property,
                    ];
                }
            }
        }

        // Parse toArray() method
        if (preg_match('/public\s+function\s+toArray\s*\([^)]*\)\s*:[^\{]*\{([^}]+)\}/s', $content, $toArrayMatch)) {
            $toArrayBody = $toArrayMatch[1];
            
            // Match: ObjectClass::property => $this->objectClass->property
            if (preg_match_all('/(\w+)::(\w+)\s*=>\s*\$this->object\w+->\w+/', $toArrayBody, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $objectClass = $match[1];
                    $property = $match[2];
                    $parsed['toarray_properties'][$property] = [
                        'object_class' => $objectClass,
                        'property' => $property,
                    ];
                }
            }
        }

        // Parse PHPDoc @property-read
        if (preg_match('/\/\*\*.*?\*\//s', $content, $phpdocMatch)) {
            $phpdoc = $phpdocMatch[0];
            
            if (preg_match_all('/@property-read\s+(\S+)\s+\$(\w+)/', $phpdoc, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $type = trim($match[1]);
                    $property = trim($match[2]);
                    $parsed['phpdoc_properties'][$property] = [
                        'type' => $type,
                        'property' => $property,
                    ];
                }
            }
        }

        return $parsed;
    }

    /**
     * Extract user-defined methods from Idea item file
     *
     * @param string $content File content
     * @return array<string, string> User-defined methods (method name => method code)
     */
    protected function extractIdeaItemUserMethods(string $content): array
    {
        $userMethods = [];

        // Standard methods to exclude
        $standardMethods = [
            '__construct',
            '__get',
            'toArray',
            'flushCache',
            'get',
            'hasRelatedCache',
            'getCachedRelated',
            'setCachedRelated',
            'clearRelatedCache',
        ];

        // Extract all methods
        if (preg_match_all('/(?:public|private|protected)\s+(?:static\s+)?function\s+(\w+)\s*\([^)]*\)\s*:[^\{]*\{([^}]+)\}/s', $content, $matches, PREG_SET_ORDER)) {
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
     * Rebuild __get() method in Idea item
     *
     * @param string $content Current file content
     * @param array $objectProperties Object properties to include
     * @param ReflectionClass $objectReflection Object reflection
     * @return string Updated content
     */
    protected function rebuildIdeaItemGetter(string $content, array $objectProperties, ReflectionClass $objectReflection): string
    {
        // Extract Object class alias from use statements
        $objectClassAlias = $this->extractObjectClassAlias($content, $objectReflection);
        if ($objectClassAlias === null) {
            return $content;
        }

        // Extract object property name (e.g., $objectUser)
        $objectPropertyName = $this->extractObjectPropertyName($content);
        if ($objectPropertyName === null) {
            return $content;
        }

        // Build getter cases
        $getterCases = [];
        foreach ($objectProperties as $propertyName => $propertyInfo) {
            $constant = $propertyInfo['constant'];
            $getterCases[] = "            {$objectClassAlias}::{$constant} => \$this->{$objectPropertyName}->{$constant},";
        }

        // Build new __get() method
        $newGetter = "    /**\n";
        $newGetter .= "     * Property getter (read-only access)\n";
        $newGetter .= "     */\n";
        $newGetter .= "    public function __get(string \$name): IdeaCollection|int|string|bool|null\n";
        $newGetter .= "    {\n";
        $newGetter .= "        return match (\$name) {\n";
        $newGetter .= implode("\n", $getterCases) . "\n";
        $newGetter .= "\n            // Example of lazy loading relationships (implement when you have related entities)\n";
        $newGetter .= "            // 'orders' => \$this->loadOrders(),\n";
        $newGetter .= "            // 'posts' => \$this->loadPosts(),\n";
        $newGetter .= "\n            default => throw new \\Exception(\"Property [{\$name}] does not exist on Idea{$objectReflection->getShortName()}\"),\n";
        $newGetter .= "        };\n";
        $newGetter .= "    }";

        // Replace existing __get() method
        if (preg_match('/(public\s+function\s+__get\s*\([^)]*\)\s*:[^\{]*\{[^}]*\})/s', $content, $matches)) {
            $content = str_replace($matches[1], $newGetter, $content);
        } else {
            // Insert before closing brace of class
            if (preg_match('/(\n\s*)(\})/s', $content, $matches)) {
                $content = str_replace($matches[0], "\n\n" . $newGetter . "\n" . $matches[2], $content);
            }
        }

        return $content;
    }

    /**
     * Extract Object class alias from use statements
     */
    private function extractObjectClassAlias(string $content, ReflectionClass $objectReflection): ?string
    {
        $objectClassName = $objectReflection->getName();
        $objectShortName = $objectReflection->getShortName();

        // Look for use statement with alias
        if (preg_match('/use\s+' . preg_quote($objectClassName, '/') . '\s+as\s+(\w+);/', $content, $match)) {
            return $match[1];
        }

        // Look for use statement without alias
        if (preg_match('/use\s+' . preg_quote($objectClassName, '/') . ';/', $content)) {
            return $objectShortName;
        }

        // Try to find from property type hint
        if (preg_match('/private\s+(\w+)\s+\$object\w+;/', $content, $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * Extract object property name (e.g., $objectUser)
     */
    private function extractObjectPropertyName(string $content): ?string
    {
        if (preg_match('/private\s+\w+\s+\$(\w+);/', $content, $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * Rebuild toArray() method in Idea item
     *
     * @param string $content Current file content
     * @param array $objectProperties Object properties to include
     * @param ReflectionClass $objectReflection Object reflection
     * @return string Updated content
     */
    protected function rebuildIdeaItemToArray(string $content, array $objectProperties, ReflectionClass $objectReflection): string
    {
        // Extract Object class alias
        $objectClassAlias = $this->extractObjectClassAlias($content, $objectReflection);
        if ($objectClassAlias === null) {
            return $content;
        }

        // Extract object property name
        $objectPropertyName = $this->extractObjectPropertyName($content);
        if ($objectPropertyName === null) {
            return $content;
        }

        // Build toArray() method
        $toArrayLines = [];
        $toArrayLines[] = "        \$data = [];";
        $toArrayLines[] = "";
        
        // Add ID field first if exists
        $hasId = false;
        foreach ($objectProperties as $propertyName => $propertyInfo) {
            if (strtolower($propertyName) === 'id') {
                $constant = $propertyInfo['constant'];
                $toArrayLines[] = "        if (\$withId) {";
                $toArrayLines[] = "            \$data[{$objectClassAlias}::{$constant}] = \$this->{$objectPropertyName}->{$constant};";
                $toArrayLines[] = "        }";
                $toArrayLines[] = "";
                $hasId = true;
                break;
            }
        }

        // Add other properties
        foreach ($objectProperties as $propertyName => $propertyInfo) {
            if (strtolower($propertyName) === 'id') {
                continue; // Already added
            }
            $constant = $propertyInfo['constant'];
            $toArrayLines[] = "        \$data[{$objectClassAlias}::{$constant}] = \$this->{$objectPropertyName}->{$constant};";
        }

        $toArrayLines[] = "";
        $toArrayLines[] = "        return \$data;";

        $newToArray = "    /**\n";
        $newToArray .= "     * Convert to array\n";
        $newToArray .= "     */\n";
        $newToArray .= "    public function toArray(bool \$withId = true, bool \$idAsIndex = true, bool \$withBridges = false, bool \$withCalculation = false): array\n";
        $newToArray .= "    {\n";
        $newToArray .= implode("\n", $toArrayLines) . "\n";
        $newToArray .= "    }";

        // Replace existing toArray() method
        if (preg_match('/(public\s+function\s+toArray\s*\([^)]*\)\s*:[^\{]*\{[^}]*\})/s', $content, $matches)) {
            $content = str_replace($matches[1], $newToArray, $content);
        } else {
            // Insert before closing brace of class
            if (preg_match('/(\n\s*)(\})/s', $content, $matches)) {
                $content = str_replace($matches[0], "\n\n" . $newToArray . "\n" . $matches[2], $content);
            }
        }

        return $content;
    }

    /**
     * Rebuild PHPDoc in Idea item
     *
     * @param string $content Current file content
     * @param array $objectProperties Object properties to include
     * @param ReflectionClass $objectReflection Object reflection
     * @return string Updated content
     */
    protected function rebuildIdeaItemPhpDoc(string $content, array $objectProperties, ReflectionClass $objectReflection): string
    {
        // Extract existing class description if any
        $customDescription = [];
        if (preg_match('/\/\*\*\s*\*\s*([^*]+)\s*\*\s*@property-read/s', $content, $descMatch)) {
            $descLines = explode("\n", trim($descMatch[1]));
            foreach ($descLines as $line) {
                $line = trim($line);
                if (!empty($line) && !preg_match('/^Idea\s+/', $line)) {
                    $customDescription[] = $line;
                }
            }
        }

        // Build PHPDoc
        $phpDocLines = [];
        $phpDocLines[] = " * {$objectReflection->getShortName()} Idea";
        $phpDocLines[] = " * High-level abstraction with lazy loading and relationships";
        
        if (!empty($customDescription)) {
            $phpDocLines[] = " *";
            foreach ($customDescription as $line) {
                $phpDocLines[] = " * {$line}";
            }
        }
        
        $phpDocLines[] = " *";

        // Add @property-read annotations
        foreach ($objectProperties as $propertyName => $propertyInfo) {
            $type = $propertyInfo['type'];
            $phpDocLines[] = " * @property-read {$type} \${$propertyName}";
        }

        $newPhpDoc = "/**\n" . implode("\n", $phpDocLines) . "\n */";

        // Replace existing PHPDoc
        if (preg_match('/\/\*\*.*?\*\//s', $content, $matches)) {
            $content = str_replace($matches[0], $newPhpDoc, $content);
        } else {
            // Insert before class declaration
            if (preg_match('/(\n)(\s*(?:final\s+)?class\s+\w+)/', $content, $matches)) {
                $content = str_replace($matches[0], $matches[1] . $newPhpDoc . $matches[1] . $matches[2], $content);
            }
        }

        return $content;
    }
}

