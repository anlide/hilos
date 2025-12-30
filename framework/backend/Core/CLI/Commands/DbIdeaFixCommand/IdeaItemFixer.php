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
        // Use proper regex that separates class name from "as alias" part
        if (preg_match_all('/^use\s+([^\s;]+)(?:\s+as\s+(\w+))?;/m', $content, $useMatches, PREG_SET_ORDER)) {
            foreach ($useMatches as $useMatch) {
                $fullClassName = trim($useMatch[1]);
                // Check that this is an Object class, but not ObjectCollection or Objects
                if (strpos($fullClassName, '\\Object\\') !== false &&
                    strpos($fullClassName, '\\ObjectCollection\\') === false &&
                    strpos($fullClassName, '\\Object\\Objects') === false &&
                    strpos($fullClassName, '\\Object\\Object_') === false) {
                    // Found Object class - return it
                    return $fullClassName;
                }
            }
        }

        // Try to find from property type hint: private ObjectClassName $object...
        if (preg_match('/private\s+(\w+)\s+\$object\w+;/', $content, $propMatch)) {
            $typeHint = $propMatch[1];
            // Try to find use statement for this type with proper regex
            if (preg_match('/^use\s+([^\s;]+)\s+as\s+' . preg_quote($typeHint, '/') . ';/m', $content, $useMatch)) {
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
        
        // Check for type changes
        $typeChanged = [];
        foreach ($objectProperties as $propName => $propInfo) {
            if (isset($phpdocProperties[$propName])) {
                $objectType = $propInfo['type'];
                $phpdocType = $phpdocProperties[$propName]['type'] ?? '';
                if ($objectType !== $phpdocType) {
                    $typeChanged[$propName] = [
                        'old_type' => $phpdocType,
                        'new_type' => $objectType,
                    ];
                }
            }
        }
        
        if (!empty($missingInPhpDoc) || !empty($extraInPhpDoc) || !empty($typeChanged)) {
            $fixes['update_phpdoc'] = [
                'missing' => $missingInPhpDoc,
                'extra' => $extraInPhpDoc,
                'type_changed' => $typeChanged,
                'all_properties' => $objectProperties,
            ];
        }

        // Check use statements
        $useStatementsNeedUpdate = $this->checkIdeaItemUseStatements($content, $objectReflection);
        if ($useStatementsNeedUpdate) {
            $fixes['update_use_statements'] = true;
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
        if (isset($fixes['update_use_statements'])) {
            $content = $this->rebuildIdeaItemUseStatements($content, $objectReflection);
        }
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
        $content .= " * @extends BaseIdea<{$objectClassAlias}>\n";
        $content .= " *\n";
        $content .= $phpDocPropertiesStr . "\n";
        $content .= " */\n";

        // Class declaration
        $content .= "final class {$ideaClassName} extends BaseIdea\n";
        $content .= "{\n";
        $content .= "    /** @var self[] Global cache of {$ideaClassName} ideas */\n";
        $content .= "    private static array \${$pluralizedCacheName} = [];\n\n";
        $content .= "    protected function __construct({$objectClassAlias} &\${$objectPropertyName})\n";
        $content .= "    {\n";
        $content .= "        parent::__construct(\${$objectPropertyName});\n";
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
        $content .= "        } elseif (self::\${$pluralizedCacheName}[\$id]->_object !== \${$objectPropertyName}) {\n";
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
            $getterCases[] = "            {$objectClassAlias}::{$constant} => \$this->_object->{$constant},";
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
                $toArrayLines[] = "            \$data[{$objectClassAlias}::{$constant}] = \$this->_object->{$constant};";
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
            $toArrayLines[] = "        \$data[{$objectClassAlias}::{$constant}] = \$this->_object->{$constant};";
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
     * Extract method body with proper brace balancing
     *
     * @param string $content File content
     * @param string $methodName Method name
     * @return array|null Array with 'start', 'end', 'body' keys or null if not found
     */
    private function extractMethodBody(string $content, string $methodName): ?array
    {
        // Find method signature
        $pattern = '/public\s+function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*:[^\{]*\{/';
        if (!preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $startPos = $matches[0][1] + strlen($matches[0][0]) - 1; // Position of opening brace
        $braceCount = 1;
        $pos = $startPos + 1;

        // Find matching closing brace
        while ($pos < strlen($content) && $braceCount > 0) {
            $char = $content[$pos];
            if ($char === '{') {
                $braceCount++;
            } elseif ($char === '}') {
                $braceCount--;
            }
            $pos++;
        }

        if ($braceCount !== 0) {
            return null; // Unbalanced braces
        }

        $endPos = $pos - 1; // Position of closing brace
        $body = substr($content, $startPos + 1, $endPos - $startPos - 1);

        return [
            'start' => $matches[0][1],
            'end' => $endPos + 1,
            'body' => $body,
        ];
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
        $getterMethod = $this->extractMethodBody($content, '__get');
        if ($getterMethod !== null) {
            $getterBody = $getterMethod['body'];
            
            // Match cases: ObjectClass::property => $this->objectClass->property
            // Flexible regex similar to PHPDoc - finds ObjectClass::property pattern
            // Allows whitespace and newlines around =>
            if (preg_match_all('/(\w+)::(\w+)\s*=>/s', $getterBody, $matches, PREG_SET_ORDER)) {
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
        $toArrayMethod = $this->extractMethodBody($content, 'toArray');
        if ($toArrayMethod !== null) {
            $toArrayBody = $toArrayMethod['body'];
            
            // Match: $data[ObjectClass::property] = ...
            // Flexible regex similar to PHPDoc - finds $data[ObjectClass::property] pattern
            // Allows whitespace, newlines, and matches properties inside if blocks
            if (preg_match_all('/\$data\[(\w+)::(\w+)\]/s', $toArrayBody, $matches, PREG_SET_ORDER)) {
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
            
            // Match @property-read with optional comment after variable name
            // Pattern: @property-read type $name optional comment
            // Match each line separately - simpler and more reliable
            $lines = explode("\n", $phpdoc);
            foreach ($lines as $line) {
                // Match: * @property-read type $name optional comment
                if (preg_match('/\*\s*@property-read\s+([^\s\$]+)\s+\$(\w+)(?:\s+(.+))?$/', $line, $match)) {
                    $type = trim($match[1]);
                    $property = trim($match[2]);
                    $comment = isset($match[3]) ? trim($match[3]) : '';
                    $parsed['phpdoc_properties'][$property] = [
                        'type' => $type,
                        'property' => $property,
                        'comment' => $comment,
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
        // Returns array with 'name' and 'type' ('property' or 'variable')
        $objectPropertyInfo = $this->extractObjectPropertyInfo($content);
        if ($objectPropertyInfo === null) {
            return $content;
        }
        $objectPropertyName = $objectPropertyInfo['name'];
        $objectPropertyType = $objectPropertyInfo['type']; // 'property' or 'variable'
        $objectGetterMethod = $objectPropertyInfo['getter_method'] ?? null; // Method name if type is 'variable'

        // Find existing __get() method
        $getterMethod = $this->extractMethodBody($content, '__get');
        if ($getterMethod === null) {
            // Method doesn't exist - create new one
            $getterCases = [];
            foreach ($objectProperties as $propertyName => $propertyInfo) {
                $constant = $propertyInfo['constant'];
                $getterCases[] = "            {$objectClassAlias}::{$constant} => \$this->_object->{$constant},";
            }

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

            // Insert before closing brace of class
            if (preg_match('/(\n\s*)(\})/s', $content, $matches)) {
                $content = str_replace($matches[0], "\n\n" . $newGetter . "\n" . $matches[2], $content);
            }
            return $content;
        }

        // Extract existing match expression from method body
        $methodBody = $getterMethod['body'];
        if (!preg_match('/return\s+match\s*\([^)]+\)\s*\{([^}]+)\};/s', $methodBody, $matchMatch)) {
            // Can't parse match - replace entire method
            return $this->replaceEntireGetterMethod($content, $getterMethod, $objectProperties, $objectClassAlias, $objectPropertyName, $objectReflection);
        }

        $matchContent = $matchMatch[1];
        
        // Extract all existing case lines (property => value)
        // Store with object class alias (anchor) to identify Object properties vs user-defined cases
        $existingCases = [];
        $otherLines = [];
        $lines = explode("\n", $matchContent);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            // Match: ObjectClass::property => $this->object->property,
            if (preg_match('/^(\w+)::(\w+)\s*=>\s*(.+?),?\s*$/', $trimmed, $caseMatch)) {
                $objectClassInCase = $caseMatch[1]; // Anchor (e.g., "ObjectUser")
                $propertyName = $caseMatch[2];
                $existingCases[$propertyName] = [
                    'line' => $line, // Keep original formatting
                    'object_class' => $objectClassInCase, // For checking if it's our Object property
                ];
            } elseif (!empty($trimmed) && !preg_match('/^\s*\/\//', $trimmed)) {
                // Keep non-empty lines that are not comments (like default case, blank lines, string literal cases)
                // But skip property case lines we already captured
                $otherLines[] = $line;
            }
        }
        
        // Process existing cases: identify which should be removed (Object properties not in $objectProperties)
        // and which are user-defined (different anchor or string literals) that should be preserved
        $casesToRemove = [];
        foreach ($existingCases as $propertyName => $caseInfo) {
            // Check if case belongs to our Object class
            if ($caseInfo['object_class'] === $objectClassAlias) {
                // This is an Object property - it will be handled in the loop below
                // If property is not in $objectProperties, it will be removed (not added to $newCases)
                if (!isset($objectProperties[$propertyName])) {
                    $casesToRemove[] = $propertyName;
                }
                continue;
            } else {
                // Case with different anchor - this is a user-defined case (relationship, lazy loading, etc.)
                // Preserve it in $otherLines
                if (!in_array($caseInfo['line'], $otherLines, true)) {
                    $otherLines[] = $caseInfo['line'];
                }
            }
        }

        // Build new cases for all properties
        // Only Object properties from $objectProperties will be included
        // Cases with our Object anchor that are not in $objectProperties will be removed
        $newCases = [];
        foreach ($objectProperties as $propertyName => $propertyInfo) {
            $constant = $propertyInfo['constant'];
            // Always use $this->_object (generic template approach)
            $newCaseLine = "            {$objectClassAlias}::{$constant} => \$this->_object->{$constant},";
            
            // If property exists in existing cases, check if it needs update
            if (isset($existingCases[$propertyName])) {
                $caseInfo = $existingCases[$propertyName];
                $oldCaseLine = $caseInfo['line'];
                
                // Verify this is our Object case (should always be true at this point, but check for safety)
                if ($caseInfo['object_class'] === $objectClassAlias) {
                    // Compare normalized versions (ignore whitespace)
                    $oldNormalized = preg_replace('/\s+/', ' ', trim($oldCaseLine));
                    $newNormalized = preg_replace('/\s+/', ' ', trim($newCaseLine));
                    if ($oldNormalized !== $newNormalized) {
                        // Property changed - use new version
                        $newCases[$propertyName] = $newCaseLine;
                    } else {
                        // Property unchanged - keep original formatting
                        $newCases[$propertyName] = $oldCaseLine;
                    }
                } else {
                    // Edge case: same property name but different object class
                    // Use new version (shouldn't happen in practice)
                    $newCases[$propertyName] = $newCaseLine;
                }
            } else {
                // New property - add it
                $newCases[$propertyName] = $newCaseLine;
            }
        }
        
        // Note: Cases from $existingCases that have our Object anchor but are not in $objectProperties
        // are automatically removed by not being added to $newCases

        // Build updated match content
        $updatedMatchContent = implode("\n", $newCases);
        
        // Add other lines (comments, default case, etc.) if they exist
        if (!empty($otherLines)) {
            // Find where to insert other lines - usually after property cases
            $updatedMatchContent .= "\n" . implode("\n", $otherLines);
        } else {
            // Add default comments and default case if not present
            $updatedMatchContent .= "\n\n            // Example of lazy loading relationships (implement when you have related entities)\n";
            $updatedMatchContent .= "            // 'orders' => \$this->loadOrders(),\n";
            $updatedMatchContent .= "            // 'posts' => \$this->loadPosts(),\n";
            $updatedMatchContent .= "\n            default => throw new \\Exception(\"Property [{\$name}] does not exist on Idea{$objectReflection->getShortName()}\"),";
        }

        // Rebuild method body with updated match
        $updatedMethodBody = "        return match (\$name) {\n" . $updatedMatchContent . "\n        };";
        
        // Replace method body in content
        $methodStart = $getterMethod['start'];
        $methodBodyStart = strpos($content, '{', $methodStart) + 1;
        $methodBodyEnd = $getterMethod['end'] - 1; // Before closing brace
        
        $beforeBody = substr($content, 0, $methodBodyStart);
        $afterBody = substr($content, $methodBodyEnd);
        
        $content = $beforeBody . $updatedMethodBody . $afterBody;

        return $content;
    }

    /**
     * Replace entire __get() method (fallback when parsing fails)
     */
    private function replaceEntireGetterMethod(string $content, array $getterMethod, array $objectProperties, string $objectClassAlias, string $objectPropertyName, ReflectionClass $objectReflection): string
    {
        // Build getter cases
        $getterCases = [];
        foreach ($objectProperties as $propertyName => $propertyInfo) {
            $constant = $propertyInfo['constant'];
            $getterCases[] = "            {$objectClassAlias}::{$constant} => \$this->_object->{$constant},";
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

        // Replace from method start to method end
        $methodStart = $getterMethod['start'];
        $methodEnd = $getterMethod['end'];
        $oldMethodLength = $methodEnd - $methodStart;
        $content = substr_replace($content, $newGetter, $methodStart, $oldMethodLength);

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
     * Extract object property info (e.g., $objectUser)
     * 
     * First tries to find a private property, then looks for variable usage in __get() method
     * Returns array with 'name', 'type' ('property' or 'variable'), and optionally 'getter_method'
     */
    private function extractObjectPropertyInfo(string $content): ?array
    {
        // Try to find private property first (standard case)
        if (preg_match('/private\s+\w+\s+\$(\w+);/', $content, $match)) {
            return [
                'name' => $match[1],
                'type' => 'property',
            ];
        }

        // If no private property found, try to find variable usage in __get() method
        // Pattern: $objectUser = $this->getObjectUser(); or similar
        // Then look for usage: $objectUser->property
        if (preg_match('/public\s+function\s+__get[^{]*\{([^}]+)\}/s', $content, $getterMatch)) {
            $getterBody = $getterMatch[1];
            
            // Find variable assignment like: $objectUser = $this->getObjectUser();
            if (preg_match('/\$(\w+)\s*=\s*\$this->(get\w+)\(\);/', $getterBody, $varMatch)) {
                $varName = $varMatch[1];
                $getterMethodName = $varMatch[2];
                
                // Verify this variable is used to access Object properties (e.g., $objectUser->id)
                if (preg_match('/\$' . preg_quote($varName, '/') . '->\w+/', $getterBody)) {
                    return [
                        'name' => $varName,
                        'type' => 'variable',
                        'getter_method' => $getterMethodName,
                    ];
                }
            }
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

        // Extract object property info
        $objectPropertyInfo = $this->extractObjectPropertyInfo($content);
        if ($objectPropertyInfo === null) {
            return $content;
        }
        $objectPropertyName = $objectPropertyInfo['name'];
        $objectPropertyType = $objectPropertyInfo['type'];

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
                // Always use $this->_object (generic template approach)
                $toArrayLines[] = "            \$data[{$objectClassAlias}::{$constant}] = \$this->_object->{$constant};";
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
            // Always use $this->_object (generic template approach)
            $toArrayLines[] = "        \$data[{$objectClassAlias}::{$constant}] = \$this->_object->{$constant};";
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
        $toArrayMethod = $this->extractMethodBody($content, 'toArray');
        if ($toArrayMethod !== null) {
            // Find PHPDoc before method if exists
            $methodStart = $toArrayMethod['start'];
            $replaceStart = $methodStart;
            
            // Look backwards for PHPDoc before method signature
            $searchStart = max(0, $methodStart - 300);
            $beforeText = substr($content, $searchStart, $methodStart - $searchStart);
            
            // Find PHPDoc that ends just before method signature
            if (preg_match('/\/\*\*.*?\*\/\s*\n\s*public\s+function\s+toArray/s', $beforeText . substr($content, $methodStart, 100), $phpdocMatch, PREG_OFFSET_CAPTURE)) {
                // Find actual start position of PHPDoc in full content
                $phpdocInBefore = strrpos($beforeText, '/**');
                if ($phpdocInBefore !== false) {
                    $replaceStart = $searchStart + $phpdocInBefore;
                }
            }
            
            // Replace from PHPDoc start (or method start) to method end
            $oldMethodLength = $toArrayMethod['end'] - $replaceStart;
            $content = substr_replace($content, $newToArray, $replaceStart, $oldMethodLength);
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
        // Extract existing class description - improved regex to handle stars in description
        $customDescription = [];
        // Match from /** to first @property-read, capturing description lines
        // Use non-greedy match and stop at @property-read
        if (preg_match('/\/\*\*\s*\*\s*(.+?)\s*\*\s*@property-read/s', $content, $descMatch)) {
            $descText = $descMatch[1];
            $descLines = explode("\n", $descText);
            foreach ($descLines as $line) {
                // Remove leading * and spaces
                $line = preg_replace('/^\s*\*\s*/', '', trim($line));
                $customDescription[] = $line;
            }
        }

        // Extract existing comments for properties from old PHPDoc
        $existingComments = [];
        if (preg_match('/\/\*\*.*?\*\//s', $content, $phpdocMatch)) {
            $phpdoc = $phpdocMatch[0];
            // Match @property-read with optional comment - process line by line
            $lines = explode("\n", $phpdoc);
            foreach ($lines as $line) {
                // Match: * @property-read type $name optional comment
                if (preg_match('/\*\s*@property-read\s+[^\s\$]+\s+\$(\w+)(?:\s+(.+))?$/', $line, $match)) {
                    $propertyName = trim($match[1]);
                    if (isset($match[2]) && !empty(trim($match[2]))) {
                        $existingComments[$propertyName] = trim($match[2]);
                    }
                }
            }
        }

        // Build PHPDoc
        $phpDocLines = [];
        
        // Use custom description if available, otherwise use default
        if (!empty($customDescription)) {
            foreach ($customDescription as $line) {
                $phpDocLines[] = " * {$line}";
            }
        } else {
            // Default description if no custom description found
            $phpDocLines[] = " * {$objectReflection->getShortName()} Idea";
            $phpDocLines[] = " * High-level abstraction with lazy loading and relationships";
        }

        // Add @property-read annotations with preserved comments
        foreach ($objectProperties as $propertyName => $propertyInfo) {
            $type = $propertyInfo['type'];
            $comment = $existingComments[$propertyName] ?? '';
            if (!empty($comment)) {
                $phpDocLines[] = " * @property-read {$type} \${$propertyName} {$comment}";
            } else {
                $phpDocLines[] = " * @property-read {$type} \${$propertyName}";
            }
        }

        $newPhpDoc = "/**\n" . implode("\n", $phpDocLines) . "\n */";

        // Replace existing PHPDoc - preserve newline after */
        if (preg_match('/\/\*\*.*?\*\/\s*\n?/s', $content, $matches)) {
            // Check if there's a newline after */
            $hasNewlineAfter = (strlen($matches[0]) > 0 && substr($matches[0], -1) === "\n");
            $replacement = $newPhpDoc;
            if ($hasNewlineAfter) {
                $replacement .= "\n";
            }
            $content = str_replace($matches[0], $replacement, $content);
        } else {
            // Insert before class declaration
            if (preg_match('/(\n)(\s*(?:final\s+)?class\s+\w+)/', $content, $matches)) {
                $content = str_replace($matches[0], $matches[1] . $newPhpDoc . "\n" . $matches[2], $content);
            }
        }

        return $content;
    }

    /**
     * Check if use statements need to be updated
     *
     * @param string $content Current file content
     * @param ReflectionClass $objectReflection Object reflection
     * @return bool True if use statements need update
     */
    private function checkIdeaItemUseStatements(string $content, ReflectionClass $objectReflection): bool
    {
        $objectClassName = $objectReflection->getName();
        $objectShortName = $objectReflection->getShortName();
        $expectedAlias = "Object{$objectShortName}";

        // Check if Object class use statement exists and is correct
        $objectUsePattern = '/use\s+' . preg_quote($objectClassName, '/') . '\s+as\s+' . preg_quote($expectedAlias, '/') . ';/';
        if (!preg_match($objectUsePattern, $content)) {
            return true; // Object class use statement is missing or incorrect
        }

        // Check if BaseIdea use statement exists
        if (!preg_match('/use\s+Hilos\\\Database\\\Idea\\\IdeaItem\s+as\s+BaseIdea;/', $content)) {
            return true; // BaseIdea use statement is missing
        }

        // Check if IdeaCollection use statement exists
        if (!preg_match('/use\s+Hilos\\\Database\\\Idea\\\IdeaCollection;/', $content)) {
            return true; // IdeaCollection use statement is missing
        }

        // Check for old/incorrect Object class use statements
        // Find all Object class use statements
        if (preg_match_all('/^use\s+([^\s;]+)(?:\s+as\s+(\w+))?;/m', $content, $useMatches, PREG_SET_ORDER)) {
            foreach ($useMatches as $useMatch) {
                $fullClassName = trim($useMatch[1]);
                // Check if this is an Object class (but not ObjectCollection or Objects)
                if (strpos($fullClassName, '\\Object\\') !== false &&
                    strpos($fullClassName, '\\ObjectCollection\\') === false &&
                    strpos($fullClassName, '\\Object\\Objects') === false &&
                    strpos($fullClassName, '\\Object\\Object_') === false) {
                    // If it's not the current Object class, it needs to be removed
                    if ($fullClassName !== $objectClassName) {
                        return true;
                    }
                    // If alias is incorrect, it needs to be updated
                    $alias = $useMatch[2] ?? $objectShortName;
                    if ($alias !== $expectedAlias) {
                        return true;
                    }
                }
            }
        }

        return false; // All use statements are correct
    }

    /**
     * Rebuild use statements in IdeaItem file
     *
     * @param string $content Current file content
     * @param ReflectionClass $objectReflection Object reflection
     * @return string Updated content
     */
    protected function rebuildIdeaItemUseStatements(string $content, ReflectionClass $objectReflection): string
    {
        $objectClassName = $objectReflection->getName();
        $objectShortName = $objectReflection->getShortName();
        $objectClassAlias = "Object{$objectShortName}";

        // Build new use statements
        $newUseStatements = [];
        $newUseStatements[] = "use {$objectClassName} as {$objectClassAlias};";
        $newUseStatements[] = "use Hilos\\Database\\Idea\\IdeaItem as BaseIdea;";
        $newUseStatements[] = "use Hilos\\Database\\Idea\\IdeaCollection;";

        // Find existing use statements section
        if (preg_match('/(namespace\s+[^;]+;\n\n)(.*?)(\n(?:final\s+)?class\s+\w+)/s', $content, $matches)) {
            $existingUses = $matches[2];
            
            // Remove old use statements for Object classes, BaseIdea, and IdeaCollection
            $existingUses = preg_replace('/use\s+[^;]+\\\Object\\\[^;]*;?\n?/', '', $existingUses);
            $existingUses = preg_replace('/use\s+Hilos\\\Database\\\Idea\\\IdeaItem\s+as\s+BaseIdea;?\n?/', '', $existingUses);
            $existingUses = preg_replace('/use\s+Hilos\\\Database\\\Idea\\\IdeaCollection;?\n?/', '', $existingUses);
            
            // Keep other use statements (user-defined imports)
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
     * Find IdeaItem files to delete (when corresponding Object class doesn't exist)
     *
     * @param array $objects Loaded Object classes (keyed by class name)
     * @param array $ideaItems Loaded IdeaItem files (keyed by object class name)
     * @param string|null $ideaDir Idea directory (auto-detect if null)
     * @return array IdeaItem files to delete (keyed by object class name)
     */
    protected function findIdeaItemsToDelete(array $objects, array $ideaItems, ?string &$ideaDir = null): array
    {
        // Build set of existing Object class names for quick lookup
        $existingObjectClasses = [];
        foreach ($objects as $objectClassName => $objectInfo) {
            $existingObjectClasses[$objectClassName] = true;
        }

        // Find IdeaItem files that reference non-existent Object classes
        $toDelete = [];
        foreach ($ideaItems as $objectClassName => $ideaItemInfo) {
            // If Object class doesn't exist, mark IdeaItem for deletion
            if (!isset($existingObjectClasses[$objectClassName])) {
                $toDelete[$objectClassName] = $ideaItemInfo;
            }
        }

        return $toDelete;
    }

    /**
     * Delete IdeaItem files
     *
     * @param array $filesToDelete IdeaItem files to delete (keyed by object class name)
     * @return int Number of files deleted
     */
    protected function deleteIdeaItemFiles(array $filesToDelete): int
    {
        if (empty($filesToDelete)) {
            return 0;
        }

        $deleted = 0;
        foreach ($filesToDelete as $objectClassName => $ideaItemInfo) {
            try {
                $file = $ideaItemInfo['file'];
                if (file_exists($file)) {
                    unlink($file);
                    $deleted++;
                }
            } catch (\Throwable $e) {
                echo "⚠ Failed to delete IdeaItem file for {$ideaItemInfo['class']}: {$e->getMessage()}\n";
            }
        }

        return $deleted;
    }
}

