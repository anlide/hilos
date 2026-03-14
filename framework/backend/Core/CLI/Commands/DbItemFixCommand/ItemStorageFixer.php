<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands\DbItemFixCommand;

/**
 * ItemStorageFixer trait.
 *
 * Handles synchronization of IdeaStorage.php file with ObjectCollection classes.
 *
 * Responsibilities:
 * - Compare IdeaStorage properties with ObjectCollection classes
 * - Update init() method to initialize all ObjectCollections
 * - Update initAgain() method to reload all ObjectCollections
 * - Update reloadCollection() method match expression
 * - Preserve lazy loading strategies and user comments
 *
 * @deprecated Idea layer removed; command no longer registered. Kept for reference.
 */
trait ItemStorageFixer
{
    // Constants for parsed structure keys
    private const string KEY_FILE = 'file';
    private const string KEY_NAMESPACE = 'namespace';
    private const string KEY_USE_STATEMENTS = 'use_statements';
    private const string KEY_USE_OBJECT_COLLECTIONS = 'object_collections';
    private const string KEY_USE_OTHER = 'other';
    private const string KEY_PROPERTIES = 'properties';
    private const string KEY_PROPERTY_TYPE = 'type';
    private const string KEY_PROPERTY_ALIAS = 'alias';
    private const string KEY_PROPERTY_COMMENT = 'comment';
    private const string KEY_INIT_METHOD = 'init_method';
    private const string KEY_INIT_COLLECTIONS = 'collections';
    private const string KEY_INIT_STRATEGY = 'strategy';
    private const string KEY_INIT_COMMENT = 'comment';
    private const string KEY_INIT_AGAIN_METHOD = 'initAgain_method';
    private const string KEY_INIT_AGAIN_COLLECTIONS = 'collections';
    private const string KEY_RELOAD_COLLECTION_METHOD = 'reloadCollection_method';
    private const string KEY_RELOAD_COLLECTION_CASES = 'cases';

    // Constants for fixes structure keys
    private const string FIX_REMOVE = 'remove';
    private const string FIX_ADD = 'add';
    private const string FIX_UPDATE = 'update';
    private const string FIX_REMOVE_PROPERTIES = 'properties';
    private const string FIX_REMOVE_USE_STATEMENTS = 'use_statements';
    private const string FIX_REMOVE_INIT_COLLECTIONS = 'init_collections';
    private const string FIX_REMOVE_INIT_AGAIN_COLLECTIONS = 'initAgain_collections';
    private const string FIX_REMOVE_RELOAD_COLLECTION_CASES = 'reloadCollection_cases';
    private const string FIX_ADD_PROPERTY = 'property';
    private const string FIX_ADD_USE_STATEMENT = 'use_statement';
    private const string FIX_ADD_INIT_COLLECTION = 'init_collection';
    private const string FIX_ADD_INIT_AGAIN_COLLECTION = 'initAgain_collection';
    private const string FIX_ADD_RELOAD_COLLECTION_CASE = 'reloadCollection_case';

    /**
     * Load IdeaStorage file
     *
     * @param ?string $ideaStorageFile IdeaStorage file path
     * @return ?array Loaded IdeaStorage info or null if not found
     */
    protected function loadIdeaStorage(?string $ideaStorageFile): ?array
    {
        if ($ideaStorageFile === null || !file_exists($ideaStorageFile)) {
            return null;
        }

        // Check PHP syntax first
        $output = [];
        $returnVar = 0;
        exec("php -l " . escapeshellarg($ideaStorageFile) . " 2>&1", $output, $returnVar);
        if ($returnVar !== 0) {
            return null; // Syntax error
        }

        // Parse file as text (do not load/require it to avoid fatal errors)
        $parsed = $this->parseIdeaStorageFile($ideaStorageFile);
        if ($parsed === null) {
            return null;
        }

        // Add file path to parsed structure
        $parsed[self::KEY_FILE] = $ideaStorageFile;

        return $parsed;
    }

    /**
     * Prepare fixes for IdeaStorage file
     *
     * @param array $objectCollections Loaded ObjectCollection classes
     * @param ?array $ideaStorage Loaded IdeaStorage info
     * @return array Fixes to apply
     */
    protected function prepareIdeaStorageFixes(array $objectCollections, ?array $ideaStorage): array
    {
        $fixes = [
            self::FIX_REMOVE => [
                self::FIX_REMOVE_PROPERTIES => [],
                self::FIX_REMOVE_USE_STATEMENTS => [],
                self::FIX_REMOVE_INIT_COLLECTIONS => [],
                self::FIX_REMOVE_INIT_AGAIN_COLLECTIONS => [],
                self::FIX_REMOVE_RELOAD_COLLECTION_CASES => [],
            ],
            self::FIX_ADD => [],
            self::FIX_UPDATE => [],
        ];

        // If IdeaStorage is null or empty, return empty fixes
        if ($ideaStorage === null || empty($ideaStorage)) {
            return $fixes;
        }

        // If no ObjectCollections, return empty fixes
        if (empty($objectCollections)) {
            return $fixes;
        }

        // Build mapping: ObjectCollection class name => property name
        $objectCollectionToProperty = [];
        foreach ($objectCollections as $objectCollectionClassName => $objectCollectionInfo) {
            $reflection = $objectCollectionInfo['reflection'];
            $propertyName = $this->determinePropertyNameFromObjectCollection($reflection);
            $objectCollectionToProperty[$objectCollectionClassName] = [
                'property_name' => $propertyName,
                'reflection' => $reflection,
            ];
        }

        // Build reverse mapping: property name => ObjectCollection class name (from IdeaStorage)
        $propertyToObjectCollection = [];
        $properties = $ideaStorage[self::KEY_PROPERTIES] ?? [];
        foreach ($properties as $propertyName => $propertyInfo) {
            $objectCollectionClassName = $propertyInfo[self::KEY_PROPERTY_TYPE] ?? null;
            if ($objectCollectionClassName !== null) {
                $propertyToObjectCollection[$propertyName] = $objectCollectionClassName;
            }
        }

        // Step 3: Determine what to remove
        foreach ($properties as $propertyName => $propertyInfo) {
            $objectCollectionClassName = $propertyInfo[self::KEY_PROPERTY_TYPE] ?? null;
            if ($objectCollectionClassName === null) {
                continue;
            }

            // Check if ObjectCollection still exists
            if (!isset($objectCollections[$objectCollectionClassName])) {
                // ObjectCollection doesn't exist - mark for removal
                $fixes[self::FIX_REMOVE][self::FIX_REMOVE_PROPERTIES][] = $propertyName;
                $fixes[self::FIX_REMOVE][self::FIX_REMOVE_USE_STATEMENTS][] = $objectCollectionClassName;

                // Check if property exists in init() method
                $initCollections = $ideaStorage[self::KEY_INIT_METHOD][self::KEY_INIT_COLLECTIONS] ?? [];
                if (isset($initCollections[$propertyName])) {
                    $fixes[self::FIX_REMOVE][self::FIX_REMOVE_INIT_COLLECTIONS][] = $propertyName;
                }

                // Check if property exists in initAgain() method
                $initAgainCollections = $ideaStorage[self::KEY_INIT_AGAIN_METHOD][self::KEY_INIT_AGAIN_COLLECTIONS] ?? [];
                if (in_array($propertyName, $initAgainCollections, true)) {
                    $fixes[self::FIX_REMOVE][self::FIX_REMOVE_INIT_AGAIN_COLLECTIONS][] = $propertyName;
                }

                // Check if property exists in reloadCollection() method
                $reloadCases = $ideaStorage[self::KEY_RELOAD_COLLECTION_METHOD][self::KEY_RELOAD_COLLECTION_CASES] ?? [];
                if (in_array($propertyName, $reloadCases, true)) {
                    $fixes[self::FIX_REMOVE][self::FIX_REMOVE_RELOAD_COLLECTION_CASES][] = $propertyName;
                }
            }
        }

        // Step 4: Determine what to add
        foreach ($objectCollectionToProperty as $objectCollectionClassName => $info) {
            $propertyName = $info['property_name'];
            $reflection = $info['reflection'];

            // Check if property already exists in IdeaStorage
            $exists = false;
            foreach ($properties as $existingPropertyName => $existingPropertyInfo) {
                $existingObjectCollectionClassName = $existingPropertyInfo[self::KEY_PROPERTY_TYPE] ?? null;
                if ($existingObjectCollectionClassName === $objectCollectionClassName) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                // Property doesn't exist - need to add it
                $alias = $this->generateAliasFromObjectCollection($objectCollectionClassName, $reflection, $ideaStorage);
                $strategy = $this->getDefaultLazyStrategy();

                // Get existing comment if use statement already exists
                $comment = '';
                $useStatements = $ideaStorage[self::KEY_USE_STATEMENTS][self::KEY_USE_OBJECT_COLLECTIONS] ?? [];
                if (isset($useStatements[$objectCollectionClassName])) {
                    // Use statement exists, check if there's a comment in properties
                    // For new properties, we'll generate a standard comment
                    $comment = "/** @var {$alias} */";
                } else {
                    $comment = "/** @var {$alias} */";
                }

                $fixes[self::FIX_ADD][$propertyName] = [
                    self::FIX_ADD_PROPERTY => [
                        'object_collection_class' => $objectCollectionClassName,
                        'alias' => $alias,
                        'comment' => $comment,
                    ],
                    self::FIX_ADD_USE_STATEMENT => $objectCollectionClassName,
                    self::FIX_ADD_INIT_COLLECTION => [
                        'strategy' => $strategy,
                        'comment' => "// {$propertyName} - {$strategy}",
                    ],
                    self::FIX_ADD_INIT_AGAIN_COLLECTION => true,
                    self::FIX_ADD_RELOAD_COLLECTION_CASE => true,
                ];
            }
        }

        // Clean up empty arrays
        if (empty($fixes[self::FIX_REMOVE][self::FIX_REMOVE_PROPERTIES])) {
            unset($fixes[self::FIX_REMOVE][self::FIX_REMOVE_PROPERTIES]);
        }
        if (empty($fixes[self::FIX_REMOVE][self::FIX_REMOVE_USE_STATEMENTS])) {
            unset($fixes[self::FIX_REMOVE][self::FIX_REMOVE_USE_STATEMENTS]);
        }
        if (empty($fixes[self::FIX_REMOVE][self::FIX_REMOVE_INIT_COLLECTIONS])) {
            unset($fixes[self::FIX_REMOVE][self::FIX_REMOVE_INIT_COLLECTIONS]);
        }
        if (empty($fixes[self::FIX_REMOVE][self::FIX_REMOVE_INIT_AGAIN_COLLECTIONS])) {
            unset($fixes[self::FIX_REMOVE][self::FIX_REMOVE_INIT_AGAIN_COLLECTIONS]);
        }
        if (empty($fixes[self::FIX_REMOVE][self::FIX_REMOVE_RELOAD_COLLECTION_CASES])) {
            unset($fixes[self::FIX_REMOVE][self::FIX_REMOVE_RELOAD_COLLECTION_CASES]);
        }
        if (empty($fixes[self::FIX_REMOVE])) {
            unset($fixes[self::FIX_REMOVE]);
        }
        if (empty($fixes[self::FIX_ADD])) {
            unset($fixes[self::FIX_ADD]);
        }
        if (empty($fixes[self::FIX_UPDATE])) {
            unset($fixes[self::FIX_UPDATE]);
        }

        return $fixes;
    }

    /**
     * Apply fixes to IdeaStorage file
     *
     * @param array $fixes Fixes to apply
     * @param string $ideaStorageFile IdeaStorage file path
     * @return bool Success
     */
    protected function applyIdeaStorageFixes(array $fixes, string $ideaStorageFile): bool
    {
        $content = file_get_contents($ideaStorageFile);
        if ($content === false) {
            return false;
        }

        // Apply remove operations first
        if (!empty($fixes[self::FIX_REMOVE])) {
            $remove = $fixes[self::FIX_REMOVE];
            
            // Remove use statements first
            if (!empty($remove[self::FIX_REMOVE_USE_STATEMENTS])) {
                $content = $this->removeUseStatements($content, $remove[self::FIX_REMOVE_USE_STATEMENTS]);
            }
            
            // Remove properties
            if (!empty($remove[self::FIX_REMOVE_PROPERTIES])) {
                $content = $this->removeProperties($content, $remove[self::FIX_REMOVE_PROPERTIES]);
            }
            
            // Remove from methods - extract positions from CURRENT content (after previous removals)
            // This ensures positions are always correct
            if (!empty($remove[self::FIX_REMOVE_INIT_COLLECTIONS])) {
                $content = $this->removeFromInitMethod($content, $remove[self::FIX_REMOVE_INIT_COLLECTIONS]);
            }
            
            if (!empty($remove[self::FIX_REMOVE_INIT_AGAIN_COLLECTIONS])) {
                $content = $this->removeFromInitAgainMethod($content, $remove[self::FIX_REMOVE_INIT_AGAIN_COLLECTIONS]);
            }
            
            if (!empty($remove[self::FIX_REMOVE_RELOAD_COLLECTION_CASES])) {
                $content = $this->removeFromReloadCollectionMethod($content, $remove[self::FIX_REMOVE_RELOAD_COLLECTION_CASES]);
            }
        }

        // Apply add operations
        if (!empty($fixes[self::FIX_ADD])) {
            $add = $fixes[self::FIX_ADD];
            
            // Add use statements
            foreach ($add as $propertyName => $addInfo) {
                $objectCollectionClass = $addInfo[self::FIX_ADD_USE_STATEMENT] ?? null;
                if ($objectCollectionClass !== null) {
                    $content = $this->addUseStatement($content, $objectCollectionClass, $addInfo[self::FIX_ADD_PROPERTY]['alias'] ?? '');
                }
            }
            
            // Add properties
            foreach ($add as $propertyName => $addInfo) {
                $propertyData = $addInfo[self::FIX_ADD_PROPERTY] ?? null;
                if ($propertyData !== null) {
                    $content = $this->addProperty($content, $propertyName, $propertyData);
                }
            }
            
            // Add to init() method
            foreach ($add as $propertyName => $addInfo) {
                $initData = $addInfo[self::FIX_ADD_INIT_COLLECTION] ?? null;
                if ($initData !== null) {
                    $content = $this->addToInitMethod($content, $propertyName, $addInfo[self::FIX_ADD_PROPERTY]['alias'] ?? '', $initData);
                }
            }
            
            // Add to initAgain() method
            foreach ($add as $propertyName => $addInfo) {
                if (!empty($addInfo[self::FIX_ADD_INIT_AGAIN_COLLECTION])) {
                    $content = $this->addToInitAgainMethod($content, $propertyName);
                }
            }
            
            // Add to reloadCollection() method
            foreach ($add as $propertyName => $addInfo) {
                if (!empty($addInfo[self::FIX_ADD_RELOAD_COLLECTION_CASE])) {
                    $content = $this->addToReloadCollectionMethod($content, $propertyName);
                }
            }
        }

        // TODO: Implement update operations when needed

        // Write file
        return file_put_contents($ideaStorageFile, $content) !== false;
    }

    /**
     * Parse IdeaStorage file to extract current structure
     *
     * @param string $filePath IdeaStorage file path
     * @return ?array Parsed structure or null if failed
     */
    protected function parseIdeaStorageFile(string $filePath): ?array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $parsed = [
            self::KEY_NAMESPACE => null,
            self::KEY_USE_STATEMENTS => [
                self::KEY_USE_OBJECT_COLLECTIONS => [],
                self::KEY_USE_OTHER => [],
            ],
            self::KEY_PROPERTIES => [],
            self::KEY_INIT_METHOD => [
                self::KEY_INIT_COLLECTIONS => [],
            ],
            self::KEY_INIT_AGAIN_METHOD => [
                self::KEY_INIT_AGAIN_COLLECTIONS => [],
            ],
            self::KEY_RELOAD_COLLECTION_METHOD => [
                self::KEY_RELOAD_COLLECTION_CASES => [],
            ],
        ];

        // Extract namespace
        if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatch)) {
            $parsed[self::KEY_NAMESPACE] = trim($nsMatch[1]);
        } else {
            return null; // Namespace is required
        }

        // Parse use statements
        if (preg_match_all('/^use\s+([^\s;]+)(?:\s+as\s+(\w+))?;/m', $content, $useMatches, PREG_SET_ORDER)) {
            foreach ($useMatches as $useMatch) {
                $fullClassName = trim($useMatch[1]);
                $alias = isset($useMatch[2]) ? trim($useMatch[2]) : null;

                // Check if this is an ObjectCollection class
                if (strpos($fullClassName, '\\ObjectCollection\\') !== false) {
                    $parsed[self::KEY_USE_STATEMENTS][self::KEY_USE_OBJECT_COLLECTIONS][$fullClassName] = $alias ?? $this->getIdeaStorageShortClassName($fullClassName);
                } else {
                    $parsed[self::KEY_USE_STATEMENTS][self::KEY_USE_OTHER][] = [
                        'class' => $fullClassName,
                        'alias' => $alias,
                    ];
                }
            }
        }

        // Parse properties (public ObjectCollection $propertyName)
        // Pattern: /** @var AliasName */ public AliasName $propertyName;
        // Support multiline PHPDoc comments
        if (preg_match_all('/\/\*\*.*?@var\s+(\w+).*?\*\/\s*public\s+(\w+)\s+\$(\w+);/s', $content, $propMatches, PREG_SET_ORDER)) {
            foreach ($propMatches as $propMatch) {
                $phpdocType = trim($propMatch[1]);
                $typeHint = trim($propMatch[2]);
                $propertyName = trim($propMatch[3]);

                // Resolve full class name from alias
                $fullClassName = $this->resolveIdeaStorageClassNameFromUse($content, $typeHint);
                if ($fullClassName === null) {
                    continue;
                }

                // Get full PHPDoc comment
                $comment = '';
                $propPos = strpos($content, $propMatch[0]);
                if ($propPos !== false) {
                    // Extract the PHPDoc comment from the match
                    if (preg_match('/(\/\*\*.*?\*\/)/s', $propMatch[0], $commentMatch)) {
                        $comment = trim($commentMatch[1]);
                    }
                }

                $parsed[self::KEY_PROPERTIES][$propertyName] = [
                    self::KEY_PROPERTY_TYPE => $fullClassName,
                    self::KEY_PROPERTY_ALIAS => $typeHint,
                    self::KEY_PROPERTY_COMMENT => $comment,
                ];
            }
        }

        // Parse init() method
        $initMethod = $this->extractIdeaStorageMethodBody($content, 'init');
        if ($initMethod !== null) {
            $initBody = $initMethod['body'];
            
            // Extract collection initializations: $self->property = Alias::initPartialDB(Objects::LAZY_STRATEGY_KEY);
            // Find all lines with this pattern
            if (preg_match_all('/\$self->(\w+)\s*=\s*(\w+)::initPartialDB\(Objects::(LAZY_STRATEGY_\w+)\);/m', $initBody, $initMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($initMatches as $initMatch) {
                    $propertyName = trim($initMatch[1][0]);
                    $alias = trim($initMatch[2][0]);
                    $strategy = trim($initMatch[3][0]);
                    
                    // Get match position safely
                    $matchPos = isset($initMatch[0][1]) ? (int)$initMatch[0][1] : 0;

                    // Get comment before line (if any)
                    $comment = '';
                    if ($matchPos > 0) {
                        $beforeText = substr($initBody, max(0, $matchPos - 200), $matchPos);
                        // Look for comment before assignment (single line comments)
                        if (preg_match('/(\/\/[^\n]*\n\s*)+$/', $beforeText, $commentMatch)) {
                            $comment = trim($commentMatch[0]);
                        }
                    }

                    // Resolve full class name from alias
                    $fullClassName = $this->resolveIdeaStorageClassNameFromUse($content, $alias);
                    if ($fullClassName === null) {
                        continue;
                    }

                    $parsed[self::KEY_INIT_METHOD][self::KEY_INIT_COLLECTIONS][$propertyName] = [
                        self::KEY_INIT_STRATEGY => $strategy,
                        self::KEY_INIT_COMMENT => $comment,
                        'object_collection_class' => $fullClassName,
                        'alias' => $alias,
                    ];
                }
            }
        }

        // Parse initAgain() method
        $initAgainMethod = $this->extractIdeaStorageMethodBody($content, 'initAgain');
        if ($initAgainMethod !== null) {
            $initAgainBody = $initAgainMethod['body'];
            
            // Extract: $this->property->initAgainFullDB();
            if (preg_match_all('/\$this->(\w+)->initAgainFullDB\(\);/m', $initAgainBody, $initAgainMatches, PREG_SET_ORDER)) {
                foreach ($initAgainMatches as $match) {
                    $propertyName = trim($match[1]);
                    $parsed[self::KEY_INIT_AGAIN_METHOD][self::KEY_INIT_AGAIN_COLLECTIONS][] = $propertyName;
                }
            }
        }

        // Parse reloadCollection() method
        $reloadMethod = $this->extractIdeaStorageMethodBody($content, 'reloadCollection');
        if ($reloadMethod !== null) {
            $reloadBody = $reloadMethod['body'];
            
            // Extract match cases: 'propertyName' => $this->propertyName->initAgainFullDB(),
            // Pattern matches both single and double quotes, and property name in both places
            if (preg_match_all('/[\'"]([^\'"]+)[\'"]\s*=>\s*\$this->(\w+)->initAgainFullDB\(\)/m', $reloadBody, $reloadMatches, PREG_SET_ORDER)) {
                foreach ($reloadMatches as $match) {
                    $caseName = trim($match[1]);
                    $propertyName = trim($match[2]);
                    // Use property name from $this->propertyName (more reliable)
                    $parsed[self::KEY_RELOAD_COLLECTION_METHOD][self::KEY_RELOAD_COLLECTION_CASES][] = $propertyName;
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
    private function extractIdeaStorageMethodBody(string $content, string $methodName): ?array
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
    private function resolveIdeaStorageClassNameFromUse(string $content, string $alias): ?string
    {
        // Look for use statement with this alias
        if (preg_match('/^use\s+([^\s;]+)\s+as\s+' . preg_quote($alias, '/') . ';/m', $content, $match)) {
            return trim($match[1]);
        }

        // Look for use statement without alias (short name matches)
        $shortName = $this->getIdeaStorageShortClassName($alias);
        if (preg_match_all('/^use\s+([^\s;]+);/m', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fullClassName = trim($match[1]);
                if ($this->getIdeaStorageShortClassName($fullClassName) === $shortName) {
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
    private function getIdeaStorageShortClassName(string $fullClassName): string
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
    private function determinePropertyNameFromObjectCollection(\ReflectionClass $reflection): string
    {
        $shortName = $reflection->getShortName();
        // First letter to lowercase
        return lcfirst($shortName);
    }

    /**
     * Generate alias from ObjectCollection class name
     * Checks if alias already exists in IdeaStorage use statements
     * If exists, uses existing alias, otherwise generates new one (Object + ShortName)
     *
     * @param string $objectCollectionClassName Full ObjectCollection class name
     * @param \ReflectionClass $reflection ObjectCollection reflection
     * @param array $ideaStorage Parsed IdeaStorage structure
     * @return string Alias name
     */
    private function generateAliasFromObjectCollection(string $objectCollectionClassName, \ReflectionClass $reflection, array $ideaStorage): string
    {
        // Check if alias already exists in use statements
        $useStatements = $ideaStorage[self::KEY_USE_STATEMENTS][self::KEY_USE_OBJECT_COLLECTIONS] ?? [];
        if (isset($useStatements[$objectCollectionClassName])) {
            return $useStatements[$objectCollectionClassName];
        }

        // Generate new alias: Object + ShortName
        $shortName = $reflection->getShortName();
        return 'Object' . $shortName;
    }

    /**
     * Get default lazy loading strategy
     *
     * @return string Strategy constant name
     */
    private function getDefaultLazyStrategy(): string
    {
        return 'LAZY_STRATEGY_KEY';
    }

    /**
     * Display IdeaStorage fixes that will be applied
     *
     * @param array $fixes Fixes to apply
     * @param bool $dryRun Dry run mode
     */
    protected function displayIdeaStorageFixes(array $fixes, bool $dryRun): void
    {
        $hasRemove = !empty($fixes[self::FIX_REMOVE]);
        $hasAdd = !empty($fixes[self::FIX_ADD]);
        $hasUpdate = !empty($fixes[self::FIX_UPDATE]);

        // Check if there are any changes at all
        if (!$hasRemove && !$hasAdd && !$hasUpdate) {
            return;
        }

        // Display single header for all changes
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo ($dryRun ? "[DRY RUN] " : "") . "IdeaStorage.php to Update:\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        // Display remove operations
        if ($hasRemove) {
            $remove = $fixes[self::FIX_REMOVE];
            $propertiesCount = count($remove[self::FIX_REMOVE_PROPERTIES] ?? []);
            $useStatementsCount = count($remove[self::FIX_REMOVE_USE_STATEMENTS] ?? []);
            $initCount = count($remove[self::FIX_REMOVE_INIT_COLLECTIONS] ?? []);
            $initAgainCount = count($remove[self::FIX_REMOVE_INIT_AGAIN_COLLECTIONS] ?? []);
            $reloadCount = count($remove[self::FIX_REMOVE_RELOAD_COLLECTION_CASES] ?? []);

            if ($propertiesCount > 0) {
                echo "  Remove properties:\n";
                foreach ($remove[self::FIX_REMOVE_PROPERTIES] ?? [] as $propertyName) {
                    echo "    - {$propertyName}\n";
                }
                echo "\n";
            }

            if ($useStatementsCount > 0) {
                echo "  Remove use statements:\n";
                foreach ($remove[self::FIX_REMOVE_USE_STATEMENTS] ?? [] as $className) {
                    $shortName = $this->getIdeaStorageShortClassName($className);
                    echo "    - {$shortName}\n";
                }
                echo "\n";
            }

            if ($initCount > 0) {
                echo "  Remove from init() method:\n";
                foreach ($remove[self::FIX_REMOVE_INIT_COLLECTIONS] ?? [] as $propertyName) {
                    echo "    - {$propertyName}\n";
                }
                echo "\n";
            }

            if ($initAgainCount > 0) {
                echo "  Remove from initAgain() method:\n";
                foreach ($remove[self::FIX_REMOVE_INIT_AGAIN_COLLECTIONS] ?? [] as $propertyName) {
                    echo "    - {$propertyName}\n";
                }
                echo "\n";
            }

            if ($reloadCount > 0) {
                echo "  Remove from reloadCollection() method:\n";
                foreach ($remove[self::FIX_REMOVE_RELOAD_COLLECTION_CASES] ?? [] as $propertyName) {
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
                    $objectCollectionClass = $addInfo[self::FIX_ADD_PROPERTY]['object_collection_class'] ?? '';
                    $shortName = $this->getIdeaStorageShortClassName($objectCollectionClass);
                    $alias = $addInfo[self::FIX_ADD_PROPERTY]['alias'] ?? '';
                    $strategy = $addInfo[self::FIX_ADD_INIT_COLLECTION]['strategy'] ?? '';

                    echo "    + {$propertyName} ({$shortName} as {$alias}, {$strategy})\n";
                }
                echo "\n";
            }
        }

        // Display update operations
        if ($hasUpdate) {
            $update = $fixes[self::FIX_UPDATE];
            $updateCount = count($update);

            if ($updateCount > 0) {
                echo "  Update collections:\n";
                foreach ($update as $propertyName => $updateInfo) {
                    echo "    ~ {$propertyName}";
                    if (isset($updateInfo['strategy'])) {
                        echo " (strategy: {$updateInfo['strategy']})";
                    }
                    if (isset($updateInfo['comment'])) {
                        echo " (comment updated)";
                    }
                    echo "\n";
                }
                echo "\n";
            }
        }
    }

    /**
     * Remove use statements from IdeaStorage file
     *
     * @param string $content File content
     * @param array $classNames Full class names to remove
     * @return string Updated content
     */
    private function removeUseStatements(string $content, array $classNames): string
    {
        foreach ($classNames as $className) {
            // Remove use statement with or without alias
            // Match entire line including newline to avoid leaving empty lines
            $pattern = '/^use\s+' . preg_quote($className, '/') . '(?:\s+as\s+\w+)?;\s*\n/m';
            $content = preg_replace($pattern, '', $content);
        }
        return $content;
    }

    /**
     * Remove properties from IdeaStorage file
     *
     * @param string $content File content
     * @param array $propertyNames Property names to remove
     * @return string Updated content
     */
    private function removeProperties(string $content, array $propertyNames): string
    {
        foreach ($propertyNames as $propertyName) {
            // Remove property with PHPDoc comment: /** @var Alias */ public Alias $propertyName;
            // Pattern must be precise to avoid removing class-level comments
            // Match from newline (with indentation) to end of property declaration
            // Pattern: (newline)(indentation)/** @var Type */ public Type $propertyName;\n (optional \n)
            // Important: use lookbehind for \n at start and lookahead for \n at end
            // This preserves newlines from previous and next lines and prevents line merging
            $pattern = '/(?<=\n)(\s*)\/\*\*\s*(?:\*\s*)?@var\s+\w+\s*\*\/\s*public\s+\w+\s+\$' . preg_quote($propertyName, '/') . ';\s*(?=\n)/';
            $content = preg_replace($pattern, '', $content);
        }
        return $content;
    }

    /**
     * Remove collection initialization from init() method
     *
     * @param string $content File content
     * @param array $propertyNames Property names to remove
     * @return string Updated content
     */
    private function removeFromInitMethod(string $content, array $propertyNames): string
    {
        $initMethod = $this->extractIdeaStorageMethodBody($content, 'init');
        if ($initMethod === null) {
            return $content;
        }

        return $this->removeFromInitMethodWithPosition($content, $propertyNames, $initMethod);
    }

    /**
     * Remove collection initialization from init() method with pre-calculated position
     *
     * @param string $content File content
     * @param array $propertyNames Property names to remove
     * @param array $initMethod Pre-calculated method position info
     * @return string Updated content
     */
    private function removeFromInitMethodWithPosition(string $content, array $propertyNames, array $initMethod): string
    {
        $initBody = $initMethod['body'];
        $newBody = $initBody;

        foreach ($propertyNames as $propertyName) {
            // Remove line with comment and initialization: // comment \n $self->property = Alias::initPartialDB(...);
            $pattern = '/(?:\/\/[^\n]*\n\s*)?\$self->' . preg_quote($propertyName, '/') . '\s*=\s*\w+::initPartialDB\([^)]+\);\s*\n?/m';
            $newBody = preg_replace($pattern, '', $newBody);
        }

        // Replace method body (start is already position after opening brace)
        $newContent = substr_replace($content, $newBody, $initMethod['start'], strlen($initBody));
        return $newContent;
    }

    /**
     * Remove collection reload from initAgain() method
     *
     * @param string $content File content
     * @param array $propertyNames Property names to remove
     * @return string Updated content
     */
    private function removeFromInitAgainMethod(string $content, array $propertyNames): string
    {
        $initAgainMethod = $this->extractIdeaStorageMethodBody($content, 'initAgain');
        if ($initAgainMethod === null) {
            return $content;
        }

        return $this->removeFromInitAgainMethodWithPosition($content, $propertyNames, $initAgainMethod);
    }

    /**
     * Remove collection reload from initAgain() method with pre-calculated position
     *
     * @param string $content File content
     * @param array $propertyNames Property names to remove
     * @param array $initAgainMethod Pre-calculated method position info
     * @return string Updated content
     */
    private function removeFromInitAgainMethodWithPosition(string $content, array $propertyNames, array $initAgainMethod): string
    {
        $initAgainBody = $initAgainMethod['body'];
        $newBody = $initAgainBody;

        foreach ($propertyNames as $propertyName) {
            // Remove line: $this->property->initAgainFullDB();
            // Pattern: \n + indentation + $this->property->initAgainFullDB(); + \n
            // Use lookbehind for \n at start, but remove \n at end to avoid double newlines
            $pattern = '/(?<=\n)(\s*)\$this->' . preg_quote($propertyName, '/') . '->initAgainFullDB\(\);\s*\n/';
            $newBody = preg_replace($pattern, '', $newBody);
        }

        // Replace method body (start is already position after opening brace)
        $newContent = substr_replace($content, $newBody, $initAgainMethod['start'], strlen($initAgainBody));
        return $newContent;
    }

    /**
     * Remove case from reloadCollection() method
     *
     * @param string $content File content
     * @param array $propertyNames Property names to remove
     * @return string Updated content
     */
    private function removeFromReloadCollectionMethod(string $content, array $propertyNames): string
    {
        $reloadMethod = $this->extractIdeaStorageMethodBody($content, 'reloadCollection');
        if ($reloadMethod === null) {
            return $content;
        }

        return $this->removeFromReloadCollectionMethodWithPosition($content, $propertyNames, $reloadMethod);
    }

    /**
     * Remove case from reloadCollection() method with pre-calculated position
     *
     * @param string $content File content
     * @param array $propertyNames Property names to remove
     * @param array $reloadMethod Pre-calculated method position info
     * @return string Updated content
     */
    private function removeFromReloadCollectionMethodWithPosition(string $content, array $propertyNames, array $reloadMethod): string
    {
        $reloadBody = $reloadMethod['body'];
        $newBody = $reloadBody;

        foreach ($propertyNames as $propertyName) {
            // Remove case: 'propertyName' => $this->propertyName->initAgainFullDB(),
            // Use lookbehind for \n at start, but remove \n at end to avoid double newlines
            $pattern = '/(?<=\n)(\s*)[\'"]' . preg_quote($propertyName, '/') . '[\'"]\s*=>\s*\$this->'
                . preg_quote($propertyName, '/') . '->initAgainFullDB\(\),\s*\n/';
            $newBody = preg_replace($pattern, '', $newBody);
        }

        // Replace method body (start is already position after opening brace)
        $newContent = substr_replace($content, $newBody, $reloadMethod['start'], strlen($reloadBody));
        return $newContent;
    }

    /**
     * Add use statement to IdeaStorage file
     *
     * @param string $content File content
     * @param string $className Full class name
     * @param string $alias Alias name
     * @return string Updated content
     */
    private function addUseStatement(string $content, string $className, string $alias): string
    {
        // Check if use statement already exists
        $pattern = '/\nuse\s+' . preg_quote($className, '/') . '(?:\s+as\s+\w+)?;/';
        if (preg_match($pattern, $content)) {
            return $content; // Already exists
        }

        // Find last use statement (all use statements together)
        $useStatement = "use {$className} as {$alias};\n";
        
        // Find all use statements and insert after the last one
        // Without flag 'm', we use explicit \n to match line start
        if (preg_match_all('/\nuse\s+[^;]+;\s*\n/', $content, $useMatches, PREG_OFFSET_CAPTURE)) {
            $lastMatch = end($useMatches[0]);
            $lastUsePos = (int) $lastMatch[1] + strlen($lastMatch[0]);
            // Insert after last use statement (pattern already includes \n, so we insert on new line)
            $content = substr_replace($content, $useStatement, $lastUsePos, 0);
        } else {
            // No use statements, insert after namespace
            if (preg_match('/(namespace\s+[^;]+;\n\n)/', $content, $nsMatch, PREG_OFFSET_CAPTURE)) {
                $nsEnd = (int) $nsMatch[0][1] + strlen($nsMatch[0][0]);
                $content = substr_replace($content, $useStatement, $nsEnd, 0);
            }
        }

        return $content;
    }

    /**
     * Add property to IdeaStorage file
     *
     * @param string $content File content
     * @param string $propertyName Property name
     * @param array $propertyData Property data (alias, comment)
     * @return string Updated content
     */
    private function addProperty(string $content, string $propertyName, array $propertyData): string
    {
        $alias = $propertyData['alias'] ?? '';
        $comment = $propertyData['comment'] ?? "/** @var {$alias} */";

        // Check if property already exists
        $pattern = '/public\s+\w+\s+\$' . preg_quote($propertyName, '/') . ';/';
        if (preg_match($pattern, $content)) {
            return $content; // Already exists
        }

        $propertyCode = "    {$comment}\n    public {$alias} \${$propertyName};\n\n";

        // Find position after last property (before init() method or any method)
        if (preg_match_all('/(\/\*\*.*?\*\/\s*public\s+\w+\s+\$\w+;\s*\n)/s', $content, $propMatches, PREG_OFFSET_CAPTURE)) {
            $lastMatch = end($propMatches[0]);
            $lastPropPos = (int) $lastMatch[1] + strlen($lastMatch[0]);
            // Insert property code - the pattern already includes \n at the end, so we insert right after it
            // This means propertyCode will be inserted on a new line, which is correct
            $content = substr_replace($content, $propertyCode, $lastPropPos, 0);
        } else {
            // No properties, insert after class opening brace
            if (preg_match('/(final\s+class\s+\w+\s+extends\s+\w+\s*\{)/', $content, $classMatch, PREG_OFFSET_CAPTURE)) {
                $classEnd = (int) $classMatch[0][1] + strlen($classMatch[0][0]);
                $content = substr_replace($content, "\n" . $propertyCode, $classEnd, 0);
            }
        }

        return $content;
    }

    /**
     * Add collection initialization to init() method
     *
     * @param string $content File content
     * @param string $propertyName Property name
     * @param string $alias Alias name
     * @param array $initData Init data (strategy, comment)
     * @return string Updated content
     */
    private function addToInitMethod(string $content, string $propertyName, string $alias, array $initData): string
    {
        $initMethod = $this->extractIdeaStorageMethodBody($content, 'init');
        if ($initMethod === null) {
            return $content;
        }

        $initBody = $initMethod['body'];
        $strategy = $initData['strategy'] ?? 'LAZY_STRATEGY_KEY';

        // Check if already exists
        $pattern = '/\$self->' . preg_quote($propertyName, '/') . '\s*=/';
        if (preg_match($pattern, $initBody)) {
            return $content; // Already exists
        }

        $initLine = "        \$self->{$propertyName} = {$alias}::initPartialDB(Objects::{$strategy});\n";

        // Find position before return statement
        // We need to insert after \n\n and before return statement
        // First, find the position of \n\n before return
        $returnPattern = '/\n\n\s{8}return\s+\$self;/';
        if (preg_match($returnPattern, $initBody, $returnMatch, PREG_OFFSET_CAPTURE)) {
            // $returnMatch[0][1] is the position where \n\n starts
            // We want to insert after \n\n, so we add 2 (length of \n\n)
            $newlinePos = (int) $returnMatch[0][1];
            $insertPos = $newlinePos + 1; // Position after \n\n
            $newBody = substr_replace($initBody, $initLine, $insertPos, 0);
        } else {
            // No return statement - should not happen, but keep for safety
            $newBody = $initBody . $initLine;
        }

        // Replace method body (start is already position after opening brace)
        $newContent = substr_replace($content, $newBody, $initMethod['start'], strlen($initBody));
        return $newContent;
    }

    /**
     * Add collection reload to initAgain() method
     *
     * @param string $content File content
     * @param string $propertyName Property name
     * @return string Updated content
     */
    private function addToInitAgainMethod(string $content, string $propertyName): string
    {
        $initAgainMethod = $this->extractIdeaStorageMethodBody($content, 'initAgain');
        if ($initAgainMethod === null) {
            return $content;
        }

        $initAgainBody = $initAgainMethod['body'];

        // Check if already exists
        $pattern = '/\$this->' . preg_quote($propertyName, '/') . '->initAgainFullDB\(\);/';
        if (preg_match($pattern, $initAgainBody)) {
            return $content; // Already exists
        }

        $reloadLine = "    \$this->{$propertyName}->initAgainFullDB();\n";

        // Append before closing brace
        // Simply append the line - it already has \n at the end
        $newBody = $initAgainBody . $reloadLine . '    ';

        // Replace method body (start is already position after opening brace)
        $newContent = substr_replace($content, $newBody, $initAgainMethod['start'], strlen($initAgainBody));
        return $newContent;
    }

    /**
     * Add case to reloadCollection() method
     *
     * @param string $content File content
     * @param string $propertyName Property name
     * @return string Updated content
     */
    private function addToReloadCollectionMethod(string $content, string $propertyName): string
    {
        $reloadMethod = $this->extractIdeaStorageMethodBody($content, 'reloadCollection');
        if ($reloadMethod === null) {
            return $content;
        }

        $reloadBody = $reloadMethod['body'];

        // Check if already exists
        $pattern = '/[\'"]' . preg_quote($propertyName, '/') . '[\'"]\s*=>/';
        if (preg_match($pattern, $reloadBody)) {
            return $content; // Already exists
        }

        $caseLine = "            '{$propertyName}' => \$this->{$propertyName}->initAgainFullDB(),\n";

        // Find position before default case
        // Pattern: \n + spaces before default
        if (preg_match('/\n(\s+default\s+=>)/', $reloadBody, $defaultMatch, PREG_OFFSET_CAPTURE)) {
            // $defaultMatch[0][1] is position of \n before default
            // We want to insert after \n, so add 1
            $newlinePos = (int) $defaultMatch[0][1];
            $insertPos = $newlinePos + 1; // Position after \n
            $newBody = substr_replace($reloadBody, $caseLine, $insertPos, 0);
        } else {
            // No default case - should not happen
            throw new \RuntimeException("reloadCollection() method must have a default case");
        }

        // Replace method body (start is already position after opening brace)
        $newContent = substr_replace($content, $newBody, $reloadMethod['start'], strlen($reloadBody));
        return $newContent;
    }

    /**
     * Rebuild IdeaStorage properties
     *
     * @param string $content Current file content
     * @param array $objectCollections ObjectCollection classes to include
     * @return string Updated content
     */
    protected function rebuildIdeaStorageProperties(string $content, array $objectCollections): string
    {
        // This method is not used in applyIdeaStorageFixes, but kept for future use
        return $content;
    }

    /**
     * Rebuild init() method in IdeaStorage
     *
     * @param string $content Current file content
     * @param array $objectCollections ObjectCollection classes to include
     * @return string Updated content
     */
    protected function rebuildIdeaStorageInit(string $content, array $objectCollections): string
    {
        // TODO: Not implemented yet
        return $content;
    }

    /**
     * Rebuild initAgain() method in IdeaStorage
     *
     * @param string $content Current file content
     * @param array $objectCollections ObjectCollection classes to include
     * @return string Updated content
     */
    protected function rebuildIdeaStorageInitAgain(string $content, array $objectCollections): string
    {
        // TODO: Not implemented yet
        return $content;
    }

    /**
     * Rebuild reloadCollection() method in IdeaStorage
     *
     * @param string $content Current file content
     * @param array $objectCollections ObjectCollection classes to include
     * @return string Updated content
     */
    protected function rebuildIdeaStorageReloadCollection(string $content, array $objectCollections): string
    {
        // TODO: Not implemented yet
        return $content;
    }

    /**
     * Extract lazy loading strategies from IdeaStorage
     *
     * @param string $content File content
     * @return array Lazy loading strategies per collection
     */
    protected function extractIdeaStorageLazyStrategies(string $content): array
    {
        // TODO: Not implemented yet
        return [];
    }
}

