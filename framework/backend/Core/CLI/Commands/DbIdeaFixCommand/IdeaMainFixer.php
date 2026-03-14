<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands\DbIdeaFixCommand;

/**
 * IdeaMainFixer trait.
 *
 * Handles synchronization of Idea.php file with ObjectCollection classes.
 *
 * Responsibilities:
 * - Compare Idea.php constants with ObjectCollection classes
 * - Update init() method to call setRepresent() for all collections
 * - Preserve user-defined initialization logic
 *
 * @deprecated Idea layer removed; command no longer registered. Kept for reference.
 */
trait IdeaMainFixer
{
    // Constants for parsed structure keys
    private const string KEY_FILE = 'file';
    private const string KEY_NAMESPACE = 'namespace';
    private const string KEY_USE_STATEMENTS = 'use_statements';
    private const string KEY_USE_IDEA_COLLECTIONS = 'idea_collections';
    private const string KEY_USE_OTHER = 'other';
    private const string KEY_CONSTANTS = 'constants';
    private const string KEY_CONSTANT_NAME = 'name';
    private const string KEY_CONSTANT_VALUE = 'value';
    private const string KEY_INIT_METHOD = 'init_method';
    private const string KEY_INIT_SET_REPRESENT_CALLS = 'setRepresent_calls';
    private const string KEY_SET_REPRESENT_NAME = 'name';
    private const string KEY_SET_REPRESENT_IDEA_COLLECTION_CLASS = 'idea_collection_class';
    private const string KEY_SET_REPRESENT_IDEA_COLLECTION_ALIAS = 'idea_collection_alias';

    // Constants for fixes structure keys
    private const string FIX_REMOVE = 'remove';
    private const string FIX_ADD = 'add';
    private const string FIX_REMOVE_CONSTANTS = 'constants';
    private const string FIX_REMOVE_USE_STATEMENTS = 'use_statements';
    private const string FIX_REMOVE_SET_REPRESENT_CALLS = 'setRepresent_calls';
    private const string FIX_ADD_CONSTANT = 'constant';
    private const string FIX_ADD_USE_STATEMENT = 'use_statement';
    private const string FIX_ADD_SET_REPRESENT_CALL = 'setRepresent_call';

    /**
     * Load Idea.php file
     *
     * @param ?string $ideaFile Idea.php file path
     * @return ?array Loaded Idea.php info or null if not found
     */
    protected function loadIdeaMain(?string $ideaFile): ?array
    {
        if ($ideaFile === null || !file_exists($ideaFile)) {
            return null;
        }

        // Check PHP syntax first
        $output = [];
        $returnVar = 0;
        exec("php -l " . escapeshellarg($ideaFile) . " 2>&1", $output, $returnVar);
        if ($returnVar !== 0) {
            return null; // Syntax error
        }

        // Parse file as text (do not load/require it to avoid fatal errors)
        $parsed = $this->parseIdeaMainFile($ideaFile);
        if ($parsed === null) {
            return null;
        }

        // Add file path to parsed structure
        $parsed[self::KEY_FILE] = $ideaFile;

        return $parsed;
    }

    /**
     * Prepare fixes for Idea.php file
     *
     * @param array $objectCollections Loaded ObjectCollection classes
     * @param ?array $ideaMain Loaded Idea.php info
     * @return array Fixes to apply
     */
    protected function prepareIdeaMainFixes(array $objectCollections, ?array $ideaMain): array
    {
        $fixes = [
            self::FIX_REMOVE => [
                self::FIX_REMOVE_CONSTANTS => [],
                self::FIX_REMOVE_USE_STATEMENTS => [],
                self::FIX_REMOVE_SET_REPRESENT_CALLS => [],
            ],
            self::FIX_ADD => [],
        ];

        // If IdeaMain is null or empty, return empty fixes
        if ($ideaMain === null || empty($ideaMain)) {
            return $fixes;
        }

        // If no ObjectCollections, return empty fixes
        if (empty($objectCollections)) {
            return $fixes;
        }

        // Build mapping: ObjectCollection class name => property name (from IdeaStorage)
        // We need to determine property names from ObjectCollection classes
        $objectCollectionToProperty = [];
        foreach ($objectCollections as $objectCollectionClassName => $objectCollectionInfo) {
            $reflection = $objectCollectionInfo['reflection'];
            $propertyName = $this->determineIdeaMainPropertyNameFromObjectCollection($reflection);
            $objectCollectionToProperty[$objectCollectionClassName] = [
                'property_name' => $propertyName,
                'reflection' => $reflection,
            ];
        }

        // Get current constants and setRepresent calls from Idea.php
        $constants = $ideaMain[self::KEY_CONSTANTS] ?? [];
        $setRepresentCalls = $ideaMain[self::KEY_INIT_METHOD][self::KEY_INIT_SET_REPRESENT_CALLS] ?? [];
        $ideaCollectionUseStatements = $ideaMain[self::KEY_USE_STATEMENTS][self::KEY_USE_IDEA_COLLECTIONS] ?? [];

        // Step 1: Determine what to remove
        // Check constants - if property name doesn't exist in ObjectCollections, remove constant
        foreach ($constants as $constantName => $constantInfo) {
            $propertyName = $constantInfo[self::KEY_CONSTANT_VALUE] ?? $constantName;
            
            // Check if this property exists in any ObjectCollection
            $exists = false;
            foreach ($objectCollectionToProperty as $objectCollectionClassName => $info) {
                if ($info['property_name'] === $propertyName) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                // Property doesn't exist - mark for removal
                $fixes[self::FIX_REMOVE][self::FIX_REMOVE_CONSTANTS][] = $constantName;

                // Check if setRepresent call exists for this constant
                if (isset($setRepresentCalls[$propertyName])) {
                    $fixes[self::FIX_REMOVE][self::FIX_REMOVE_SET_REPRESENT_CALLS][] = $propertyName;
                }

                // Check if IdeaCollection use statement exists
                $ideaCollectionClass = $ideaMain[self::KEY_INIT_METHOD][self::KEY_INIT_SET_REPRESENT_CALLS][$propertyName][self::KEY_SET_REPRESENT_IDEA_COLLECTION_CLASS] ?? null;
                if ($ideaCollectionClass !== null && isset($ideaCollectionUseStatements[$ideaCollectionClass])) {
                    $fixes[self::FIX_REMOVE][self::FIX_REMOVE_USE_STATEMENTS][] = $ideaCollectionClass;
                }
            }
        }

        // Step 2: Determine what to add
        foreach ($objectCollectionToProperty as $objectCollectionClassName => $info) {
            $propertyName = $info['property_name'];
            $reflection = $info['reflection'];

            // Check if constant already exists
            $constantExists = false;
            foreach ($constants as $constantName => $constantInfo) {
                $constantValue = $constantInfo[self::KEY_CONSTANT_VALUE] ?? $constantName;
                if ($constantValue === $propertyName) {
                    $constantExists = true;
                    break;
                }
            }

            // Check if setRepresent call already exists
            $setRepresentExists = isset($setRepresentCalls[$propertyName]);

            // Determine IdeaCollection class name from ObjectCollection
            $ideaCollectionClassName = $this->determineIdeaCollectionClassNameFromObjectCollection($objectCollectionClassName, $reflection);
            if ($ideaCollectionClassName === null) {
                continue; // Skip if we can't determine IdeaCollection class
            }

            // Check if IdeaCollection use statement exists
            $ideaCollectionUseExists = isset($ideaCollectionUseStatements[$ideaCollectionClassName]);

            // Determine aliases
            $ideaCollectionAlias = $this->getIdeaMainShortClassName($ideaCollectionClassName);

            if (!$constantExists || !$setRepresentExists || !$ideaCollectionUseExists) {
                // Need to add something
                $addInfo = [
                    self::FIX_ADD_CONSTANT => [
                        'name' => $propertyName,
                        'value' => $propertyName,
                    ],
                    self::FIX_ADD_SET_REPRESENT_CALL => [
                        'name' => $propertyName,
                        'idea_collection_class' => $ideaCollectionClassName,
                        'idea_collection_alias' => $ideaCollectionAlias,
                    ],
                ];

                if (!$ideaCollectionUseExists) {
                    $addInfo[self::FIX_ADD_USE_STATEMENT] = $ideaCollectionClassName;
                }

                $fixes[self::FIX_ADD][$propertyName] = $addInfo;
            }
        }

        // Clean up empty arrays
        if (empty($fixes[self::FIX_REMOVE][self::FIX_REMOVE_CONSTANTS])) {
            unset($fixes[self::FIX_REMOVE][self::FIX_REMOVE_CONSTANTS]);
        }
        if (empty($fixes[self::FIX_REMOVE][self::FIX_REMOVE_USE_STATEMENTS])) {
            unset($fixes[self::FIX_REMOVE][self::FIX_REMOVE_USE_STATEMENTS]);
        }
        if (empty($fixes[self::FIX_REMOVE][self::FIX_REMOVE_SET_REPRESENT_CALLS])) {
            unset($fixes[self::FIX_REMOVE][self::FIX_REMOVE_SET_REPRESENT_CALLS]);
        }
        if (empty($fixes[self::FIX_REMOVE])) {
            unset($fixes[self::FIX_REMOVE]);
        }
        if (empty($fixes[self::FIX_ADD])) {
            unset($fixes[self::FIX_ADD]);
        }

        return $fixes;
    }

    /**
     * Apply fixes to Idea.php file
     *
     * @param array $fixes Fixes to apply
     * @param string $ideaFile Idea.php file path
     * @return bool Success
     */
    protected function applyIdeaMainFixes(array $fixes, string $ideaFile): bool
    {
        // TODO: Not implemented yet
        return false;
    }

    /**
     * Parse Idea.php file to extract current structure
     *
     * @param string $filePath Idea.php file path
     * @return ?array Parsed structure or null if failed
     */
    protected function parseIdeaMainFile(string $filePath): ?array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $parsed = [
            self::KEY_NAMESPACE => null,
            self::KEY_USE_STATEMENTS => [
                self::KEY_USE_IDEA_COLLECTIONS => [],
                self::KEY_USE_OTHER => [],
            ],
            self::KEY_CONSTANTS => [],
            self::KEY_INIT_METHOD => [
                self::KEY_INIT_SET_REPRESENT_CALLS => [],
            ],
        ];

        // Parse namespace
        if (preg_match('/^namespace\s+([^;]+);/m', $content, $namespaceMatch)) {
            $parsed[self::KEY_NAMESPACE] = trim($namespaceMatch[1]);
        }

        // Parse use statements
        if (preg_match_all('/^use\s+([^\s;]+)(?:\s+as\s+(\w+))?;/m', $content, $useMatches, PREG_SET_ORDER)) {
            foreach ($useMatches as $useMatch) {
                $fullClassName = trim($useMatch[1]);
                $alias = isset($useMatch[2]) ? trim($useMatch[2]) : null;

                // Check if this is an IdeaCollection class
                if (strpos($fullClassName, '\\IdeaCollection\\') !== false) {
                    $parsed[self::KEY_USE_STATEMENTS][self::KEY_USE_IDEA_COLLECTIONS][$fullClassName] = $alias ?? $this->getIdeaMainShortClassName($fullClassName);
                } else {
                    $parsed[self::KEY_USE_STATEMENTS][self::KEY_USE_OTHER][] = [
                        'class' => $fullClassName,
                        'alias' => $alias,
                    ];
                }
            }
        }

        // Parse constants: public const string propertyName = 'propertyName';
        if (preg_match_all('/public\s+const\s+string\s+(\w+)\s*=\s*[\'"]([^\'"]+)[\'"];/m', $content, $constMatches, PREG_SET_ORDER)) {
            foreach ($constMatches as $constMatch) {
                $constantName = trim($constMatch[1]);
                $constantValue = trim($constMatch[2]);
                $parsed[self::KEY_CONSTANTS][$constantName] = [
                    self::KEY_CONSTANT_NAME => $constantName,
                    self::KEY_CONSTANT_VALUE => $constantValue,
                ];
            }
        }

        // Parse init() method - extract setRepresent() calls
        $initMethod = $this->extractIdeaMainMethodBody($content, 'init');
        if ($initMethod !== null) {
            $initBody = $initMethod['body'];

            // Extract setRepresent() calls:
            // self::$db->setRepresent(
            //     name: self::propertyName,
            //     objectCollection: $storage->propertyName,
            //     ideaCollectionClass: IdeaCollectionClass::class
            // );
            // Pattern matches multiline calls
            if (preg_match_all('/self::\$db->setRepresent\s*\(\s*name:\s*self::(\w+)\s*,\s*objectCollection:\s*\$storage->(\w+)\s*,\s*ideaCollectionClass:\s*([\w\\\\]+)::class\s*\)/s', $initBody, $setRepresentMatches, PREG_SET_ORDER)) {
                foreach ($setRepresentMatches as $match) {
                    $constantName = trim($match[1]);
                    $propertyName = trim($match[2]);
                    $ideaCollectionClass = trim($match[3]);

                    // Resolve full class name from use statement if needed
                    $fullIdeaCollectionClass = $this->resolveIdeaMainClassNameFromUse($content, $ideaCollectionClass);
                    if ($fullIdeaCollectionClass === null) {
                        // Try to construct from namespace
                        $namespace = $parsed[self::KEY_NAMESPACE];
                        if ($namespace !== null) {
                            $fullIdeaCollectionClass = $namespace . '\\IdeaCollection\\' . $ideaCollectionClass;
                        } else {
                            $fullIdeaCollectionClass = $ideaCollectionClass;
                        }
                    }

                    $parsed[self::KEY_INIT_METHOD][self::KEY_INIT_SET_REPRESENT_CALLS][$propertyName] = [
                        self::KEY_SET_REPRESENT_NAME => $constantName,
                        self::KEY_SET_REPRESENT_IDEA_COLLECTION_CLASS => $fullIdeaCollectionClass,
                        self::KEY_SET_REPRESENT_IDEA_COLLECTION_ALIAS => $ideaCollectionClass,
                    ];
                }
            }
        }

        return $parsed;
    }

    /**
     * Extract method body with proper brace balancing
     *
     * @param string $content File content
     * @param string $methodName Method name
     * @return ?array Array with 'start', 'end', 'body' keys or null if not found
     */
    private function extractIdeaMainMethodBody(string $content, string $methodName): ?array
    {
        // Find method signature (supports static and non-static, with or without return type)
        $pattern = '/(?:public|private|protected)\s+(?:static\s+)?function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*(?::[^{]*)?{/';
        if (!preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $startPos = (int) $matches[0][1] + strlen($matches[0][0]) - 1; // Position of opening brace
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
            'start' => $startPos + 1, // Position after opening brace (start of body)
            'end' => $endPos, // Position of closing brace
            'body' => $body,
        ];
    }

    /**
     * Resolve full class name from use statement alias
     *
     * @param string $content File content
     * @param string $alias Alias or short class name
     * @return ?string Full class name or null if not found
     */
    private function resolveIdeaMainClassNameFromUse(string $content, string $alias): ?string
    {
        // Look for use statement with this alias
        if (preg_match('/^use\s+([^\s;]+)\s+as\s+' . preg_quote($alias, '/') . ';/m', $content, $match)) {
            return trim($match[1]);
        }

        // Look for use statement without alias (short name matches)
        $shortName = $this->getIdeaMainShortClassName($alias);
        if (preg_match_all('/^use\s+([^\s;]+);/m', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fullClassName = trim($match[1]);
                if ($this->getIdeaMainShortClassName($fullClassName) === $shortName) {
                    return $fullClassName;
                }
            }
        }

        return null;
    }

    /**
     * Get short class name from full class name
     *
     * @param string $fullClassName Full class name
     * @return string Short class name
     */
    private function getIdeaMainShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);
        return end($parts);
    }

    /**
     * Determine property name from ObjectCollection reflection
     * Converts ObjectCollection short name to camelCase property name
     * Example: Users -> users, UserSettings -> userSettings
     *
     * @param \ReflectionClass $reflection ObjectCollection reflection
     * @return string Property name in camelCase
     */
    private function determineIdeaMainPropertyNameFromObjectCollection(\ReflectionClass $reflection): string
    {
        $shortName = $reflection->getShortName();
        // First letter to lowercase
        return lcfirst($shortName);
    }

    /**
     * Determine IdeaCollection class name from ObjectCollection class name
     * Replaces ObjectCollection namespace with IdeaCollection namespace
     *
     * @param string $objectCollectionClassName ObjectCollection class name
     * @param \ReflectionClass $reflection ObjectCollection reflection
     * @return ?string IdeaCollection class name or null if cannot determine
     */
    private function determineIdeaCollectionClassNameFromObjectCollection(string $objectCollectionClassName, \ReflectionClass $reflection): ?string
    {
        // Replace ObjectCollection with IdeaCollection in namespace
        $ideaCollectionClassName = str_replace('\\ObjectCollection\\', '\\IdeaCollection\\', $objectCollectionClassName);
        
        // Check if class exists
        if (class_exists($ideaCollectionClassName)) {
            return $ideaCollectionClassName;
        }

        return null;
    }

    /**
     * Rebuild Idea.php constants
     *
     * @param string $content Current file content
     * @param array $objectCollections ObjectCollection classes to include
     * @return string Updated content
     */
    protected function rebuildIdeaMainConstants(string $content, array $objectCollections): string
    {
        // TODO: Not implemented yet
        return $content;
    }

    /**
     * Rebuild init() method in Idea.php
     *
     * @param string $content Current file content
     * @param array $objectCollections ObjectCollection classes to include
     * @return string Updated content
     */
    protected function rebuildIdeaMainInit(string $content, array $objectCollections): string
    {
        // TODO: Not implemented yet
        return $content;
    }

    /**
     * Extract user-defined initialization logic from Idea.php
     *
     * @param string $content File content
     * @return array User-defined code sections to preserve
     */
    protected function extractIdeaMainUserCode(string $content): array
    {
        // TODO: Not implemented yet
        return [];
    }

    /**
     * Display Idea.php fixes that will be applied
     *
     * @param array $fixes Fixes to apply
     * @param bool $dryRun Dry run mode
     */
    protected function displayIdeaMainFixes(array $fixes, bool $dryRun): void
    {
        $hasRemove = !empty($fixes[self::FIX_REMOVE]);
        $hasAdd = !empty($fixes[self::FIX_ADD]);

        // Check if there are any changes at all
        if (!$hasRemove && !$hasAdd) {
            return;
        }

        // Display single header for all changes
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo ($dryRun ? "[DRY RUN] " : "") . "Idea.php File to Update:\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        // Display remove operations
        if ($hasRemove) {
            $remove = $fixes[self::FIX_REMOVE];
            $constantsCount = count($remove[self::FIX_REMOVE_CONSTANTS] ?? []);
            $useStatementsCount = count($remove[self::FIX_REMOVE_USE_STATEMENTS] ?? []);
            $setRepresentCount = count($remove[self::FIX_REMOVE_SET_REPRESENT_CALLS] ?? []);

            if ($constantsCount > 0) {
                echo "  Remove constants:\n";
                foreach ($remove[self::FIX_REMOVE_CONSTANTS] ?? [] as $constantName) {
                    echo "    - {$constantName}\n";
                }
                echo "\n";
            }

            if ($useStatementsCount > 0) {
                echo "  Remove use statements:\n";
                foreach ($remove[self::FIX_REMOVE_USE_STATEMENTS] ?? [] as $className) {
                    $shortName = $this->getIdeaMainShortClassName($className);
                    echo "    - {$shortName}\n";
                }
                echo "\n";
            }

            if ($setRepresentCount > 0) {
                echo "  Remove from init() method (setRepresent calls):\n";
                foreach ($remove[self::FIX_REMOVE_SET_REPRESENT_CALLS] ?? [] as $propertyName) {
                    echo "    - {$propertyName}\n";
                }
                echo "\n";
            }
        }

        // Display add operations
        if ($hasAdd) {
            $add = $fixes[self::FIX_ADD];
            $addCount = count($add);

            if ($addCount > 0) {
                echo "  Add collections:\n";
                foreach ($add as $propertyName => $addInfo) {
                    $ideaCollectionClass = $addInfo[self::FIX_ADD_SET_REPRESENT_CALL]['idea_collection_class'] ?? '';
                    $shortName = $this->getIdeaMainShortClassName($ideaCollectionClass);
                    $alias = $addInfo[self::FIX_ADD_SET_REPRESENT_CALL]['idea_collection_alias'] ?? $shortName;

                    echo "    + {$propertyName}";
                    if (!empty($ideaCollectionClass)) {
                        echo " ({$shortName}" . ($alias !== $shortName ? " as {$alias}" : "") . ")";
                    }
                    echo "\n";
                }
                echo "\n";
            }
        }
    }
}

