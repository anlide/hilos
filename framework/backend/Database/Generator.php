<?php

namespace Hilos\Database;

use Hilos\Database\SqlParamCollection;
use Hilos\Exception\DatabaseException;

/**
 * Code generator for Entity, Object and Idea classes
 * Generates PHP classes from existing database tables
 */
class Generator
{
    /**
     * Generate Entity class from database table
     *
     * @param string $tableName Database table name
     * @param string $namespace Namespace for generated class
     * @param ?string $className Class name (defaults to PascalCase of table name)
     * @param ?string $entityDir Entity directory path for namespace auto-detection (if namespace is default)
     * @return string Generated PHP code
     * @throws DatabaseException
     */
    public static function generateEntity(string $tableName, string $namespace = 'App\\Entity', ?string $className = null, ?string $entityDir = null): string
    {
        if ($className === null) {
            $className = self::tableToPascalCase($tableName);
        }

        // Auto-detect namespace from entityDir if default namespace is used
        if ($namespace === 'App\\Entity' && $entityDir !== null) {
            $detectedNamespace = self::detectNamespaceFromPath($entityDir);
            if ($detectedNamespace !== 'App\\Entity') {
                $namespace = $detectedNamespace;
            }
        }

        // Get table structure
        Database::sql("DESCRIBE `{$tableName}`");
        $columns = Database::rows();

        // Get indexes
        Database::sql("SHOW INDEXES FROM `{$tableName}`");
        $indexes = Database::rows();

        // Parse columns
        $columnConstants = [];
        $columnList = [];
        $typesList = [];
        $properties = [];
        $primaryKeys = [];
        $foreignKeys = [];

        foreach ($columns as $column) {
            $field = $column['Field'];
            $type = self::mysqlTypeToPhp($column['Type']);
            // Normalize type: convert 'text' to 'string' (as in DbEntityFixCommand)
            $normalizedType = self::normalizeType($type);
            $nullable = $column['Null'] === 'YES';
            $default = $column['Default'];
            $key = $column['Key'];

            $columnConstants[] = "    public const string {$field} = '{$field}';";
            $columnList[] = "self::{$field}";
            $typeValue = self::formatTypeForArray($normalizedType);
            $typesList[] = "        self::{$field} => {$typeValue}";

            // Convert type for property: integer -> int, boolean -> bool, datetime -> string
            $propertyType = self::phpTypeToPropertyType($normalizedType);

            // Primary key columns should always be nullable with default null
            $isPrimary = $key === 'PRI';
            $shouldBeNullable = $isPrimary || $nullable;
            $phpType = $shouldBeNullable ? "?{$propertyType}" : $propertyType;

            // For primary keys, always set default to null
            // For other columns, use database default or null if nullable
            if ($isPrimary) {
                $defaultValue = ' = null';
            } elseif ($default !== null) {
                $defaultValue = " = " . self::formatDefaultValue($default, $normalizedType);
            } elseif ($nullable) {
                $defaultValue = ' = null';
            } else {
                $defaultValue = '';
            }

            $properties[] = "    public {$phpType} \${$field}{$defaultValue};";

            if ($isPrimary) {
                $primaryKeys[] = "self::{$field}";
            }
        }

        // Parse indexes
        $indexesList = [];
        $indexGroups = [];
        foreach ($indexes as $index) {
            $keyName = $index['Key_name'];
            if ($keyName === 'PRIMARY') continue;

            if (!isset($indexGroups[$keyName])) {
                $indexGroups[$keyName] = [
                    'unique' => $index['Non_unique'] === '0',
                    'columns' => [],
                ];
            }
            $indexGroups[$keyName]['columns'][] = $index['Column_name'];
        }

        foreach ($indexGroups as $keyName => $info) {
            // Use column constants instead of strings
            $indexColumnRefs = array_map(fn($col) => "self::{$col}", $info['columns']);
            $indexColumns = implode(", ", $indexColumnRefs);
            $unique = $info['unique'] ? "'unique' => true, " : "";
            $indexesList[] = "        '{$keyName}' => [{$unique}'columns' => [{$indexColumns}]]";
        }

        // Get real foreign keys from INFORMATION_SCHEMA
        $realForeignKeys = self::getRealForeignKeys($tableName);
        
        // Generate foreign key entries with Entity class constants
        foreach ($realForeignKeys as $columnsStr => $referencedTable) {
            $columns = explode(',', $columnsStr);
            
            if (count($columns) === 1) {
                // Simple foreign key
                $column = trim($columns[0]);
                $referencedEntityClass = self::tableToPascalCase($referencedTable);
                $foreignKeys[] = "        self::{$column} => Entity{$referencedEntityClass}::_table";
            } else {
                // Composite foreign key
                $columnRefs = array_map(fn($col) => "self::" . trim($col), $columns);
                $referencedEntityClass = self::tableToPascalCase($referencedTable);
                $foreignKeys[] = "        " . implode(' . \',\' . . ', $columnRefs) . " => Entity{$referencedEntityClass}::_table";
            }
        }
        
        // Collect Entity class names for use statements
        $entityImports = [];
        foreach ($realForeignKeys as $referencedTable) {
            $entityClassName = self::tableToPascalCase($referencedTable);
            $entityImports["Entity{$entityClassName}"] = "{$namespace}\\{$entityClassName}";
        }

        // Build primary key definition
        $isSinglePrimary = count($primaryKeys) === 1;
        $primaryDef = $isSinglePrimary
            ? $primaryKeys[0]
            : '[' . implode(', ', $primaryKeys) . ']';
        $primaryType = $isSinglePrimary ? 'string' : 'array';

        // Generate code
        $code = "<?php\n\n";
        $code .= "namespace {$namespace};\n\n";
        $code .= "use Hilos\\Database\\Entity\\Entity;\n";
        $code .= "use Hilos\\Database\\PhpType;\n";
        
        // Add Entity imports for foreign keys
        foreach ($entityImports as $alias => $fullClassName) {
            $code .= "use {$fullClassName} as {$alias};\n";
        }
        
        if (!empty($entityImports)) {
            $code .= "\n";
        } else {
            $code .= "\n";
        }
        $code .= "/**\n";
        $code .= " * {$className} Entity\n";
        $code .= " * Auto-generated from table: {$tableName}\n";
        $code .= " */\n";
        $code .= "final class {$className} extends Entity\n";
        $code .= "{\n";
        $code .= "    // Column name constants\n";
        $code .= implode("\n", $columnConstants) . "\n\n";
        $code .= "    // Table meta information\n";
        $code .= "    public const string _table = '{$tableName}';\n";
        $code .= "    public const {$primaryType} _primary = {$primaryDef};\n";
        $code .= "    public const array _columns = [\n        ";
        $code .= implode(",\n        ", $columnList);
        $code .= ",\n    ];\n\n";
        $code .= "    // Column types\n";
        $code .= "    public const array _types = [\n";
        $code .= implode(",\n", $typesList);
        $code .= ",\n    ];\n\n";

        if (!empty($foreignKeys)) {
            $code .= "    // Foreign keys\n";
            $code .= "    public const array _foreign = [\n";
            $code .= implode(",\n", $foreignKeys);
            $code .= ",\n    ];\n\n";
        }

        if (!empty($indexesList)) {
            $code .= "    // Indexes\n";
            $code .= "    public const array _indexes = [\n";
            $code .= implode(",\n", $indexesList);
            $code .= ",\n    ];\n\n";
        }

        $code .= "    // Properties\n";
        $code .= implode("\n", $properties) . "\n";
        $code .= "}\n";

        return $code;
    }

    /**
     * Generate Object class skeleton
     */
    public static function generateObject(string $tableName, string $namespace = 'App\\Object', string $entityNamespace = 'App\\Entity', ?string $className = null): string
    {
        if ($className === null) {
            $className = self::tableToPascalCase($tableName);
        }

        // Get table structure
        Database::sql("DESCRIBE `{$tableName}`");
        $columns = Database::rows();

        $camelCaseConstants = [];
        $getterCases = [];
        $setterCases = [];
        $readOnlyProps = [];
        $writableProps = [];

        foreach ($columns as $column) {
            $field = $column['Field'];
            $key = $column['Key'];
            $camelCase = self::snakeToCamelCase($field);
            $type = self::mysqlTypeToPhp($column['Type']);
            $nullable = $column['Null'] === 'YES';
            $phpType = $nullable ? "?{$type}" : $type;

            $camelCaseConstants[] = "    public const string {$camelCase} = '{$camelCase}';";
            $getterCases[] = "            self::{$camelCase} => \$this->entity->{$field}";

            if ($key !== 'PRI') { // Primary keys are usually read-only
                $setterCases[] = "            self::{$camelCase} => \$this->entity->{$field} = ({$type})\$value";
                $writableProps[] = " * @property {$phpType} \${$camelCase}";
            } else {
                $readOnlyProps[] = " * @property-read {$phpType} \${$camelCase}";
            }
        }

        $code = "<?php\n\n";
        $code .= "namespace {$namespace};\n\n";
        $code .= "use Hilos\\Database\\Object\\Object_;\n";
        $code .= "use {$entityNamespace}\\{$className} as Entity{$className};\n\n";
        $code .= "/**\n";
        $code .= " * {$className} Object\n";
        $code .= " * Auto-generated from table: {$tableName}\n";
        $code .= " *\n";
        $code .= implode("\n", array_merge($readOnlyProps, $writableProps)) . "\n";
        $code .= " */\n";
        $code .= "final class {$className} extends Object_\n";
        $code .= "{\n";
        $code .= implode("\n", $camelCaseConstants) . "\n\n";
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
        $code .= "    public function __get(string \$property): mixed\n";
        $code .= "    {\n";
        $code .= "        return match (\$property) {\n";
        $code .= implode(",\n", $getterCases) . ",\n";
        $code .= "            default => parent::__get(\$property),\n";
        $code .= "        };\n";
        $code .= "    }\n\n";
        $code .= "    public function __set(string \$property, mixed \$value): void\n";
        $code .= "    {\n";
        $code .= "        match (\$property) {\n";
        $code .= implode(",\n", $setterCases) . ",\n";
        $code .= "            default => parent::__set(\$property, \$value),\n";
        $code .= "        };\n";
        $code .= "    }\n";
        $code .= "}\n";

        return $code;
    }

    /**
     * Get real foreign keys from INFORMATION_SCHEMA
     * Supports both simple and composite foreign keys
     * 
     * @param string $tableName Table name
     * @return array<string, string> Foreign keys mapping:
     *   - Simple: 'column' => 'referenced_table'
     *   - Composite: 'col1,col2' => 'referenced_table'
     * @throws DatabaseException
     */
    private static function getRealForeignKeys(string $tableName): array
    {
        try {
            // Get database name from current connection
            Database::sql('SELECT DATABASE() as db_name');
            $dbRow = Database::row();
            $dbName = $dbRow['db_name'] ?? null;
            
            if ($dbName === null) {
                return [];
            }

            // Query INFORMATION_SCHEMA for foreign keys
            // GROUP_CONCAT with ORDER BY to handle composite keys correctly
            Database::sql("
                SELECT 
                    GROUP_CONCAT(kcu.COLUMN_NAME ORDER BY kcu.ORDINAL_POSITION SEPARATOR ',') as columns,
                    kcu.REFERENCED_TABLE_NAME as referenced_table
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                WHERE kcu.TABLE_SCHEMA = ?
                  AND kcu.TABLE_NAME = ?
                  AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
                GROUP BY kcu.CONSTRAINT_NAME, kcu.REFERENCED_TABLE_NAME
                ORDER BY kcu.CONSTRAINT_NAME, MIN(kcu.ORDINAL_POSITION)
            ", SqlParamCollection::fromArray([$dbName, $tableName]));

            $foreignKeys = [];
            while ($row = Database::row()) {
                $columns = $row['columns'];
                $referencedTable = $row['referenced_table'];
                
                // For composite keys, use comma-separated string as key
                // For simple keys, use single column name
                $foreignKeys[$columns] = $referencedTable;
            }

            return $foreignKeys;
        } catch (DatabaseException $e) {
            // If INFORMATION_SCHEMA query fails, return empty array
            // This allows the system to continue working even if INFORMATION_SCHEMA is not accessible
            return [];
        }
    }

    /**
     * Normalize type to PhpType enum value
     * Converts 'text' to 'string' (as TEXT should be represented as STRING in Entity)
     * This is a common method used both in Generator and DbEntityFixCommand
     */
    public static function normalizeType(string $type): string
    {
        // Convert 'text' to 'string' (TEXT types should use STRING in Entity, not PhpType::TEXT)
        if ($type === PhpType::TEXT->value) {
            return PhpType::STRING->value;
        }

        // Try to find matching PhpType enum case
        foreach (PhpType::cases() as $phpType) {
            if ($phpType->value === $type) {
                return $phpType->value;
            }
        }

        // If not found, return as-is (fallback for unknown types)
        return $type;
    }

    /**
     * Format type for use in _types array (use PhpType enum if available, otherwise string)
     */
    private static function formatTypeForArray(string $type): string
    {
        // Try to find matching PhpType enum case
        foreach (PhpType::cases() as $phpType) {
            if ($phpType->value === $type) {
                // Use PhpType enum directly: PhpType::INTEGER->value
                return "PhpType::{$phpType->name}->value";
            }
        }

        // Fallback for unknown types
        return "'{$type}'";
    }

    /**
     * Convert MySQL type to PHP type
     */
    private static function mysqlTypeToPhp(string $mysqlType): string
    {
        // Check for TINYINT(1) which should be boolean
        if (preg_match('/^tinyint\s*\(\s*1\s*\)/i', $mysqlType)) {
            return 'boolean';
        }
        if (preg_match('/^(tiny|small|medium|big)?int/i', $mysqlType)) {
            return 'integer';
        }
        if (preg_match('/^(float|double|decimal|numeric)/i', $mysqlType)) {
            return 'float';
        }
        if (preg_match('/^(bool|boolean)/i', $mysqlType)) {
            return 'boolean';
        }
        if (preg_match('/^(date|datetime|timestamp)/i', $mysqlType)) {
            return 'datetime';
        }
        return 'string';
    }

    /**
     * Convert table name to PascalCase
     */
    private static function tableToPascalCase(string $tableName): string
    {
        return str_replace('_', '', ucwords($tableName, '_'));
    }

    /**
     * Convert snake_case to camelCase
     */
    private static function snakeToCamelCase(string $snake): string
    {
        return lcfirst(str_replace('_', '', ucwords($snake, '_')));
    }

    /**
     * Convert PhpType enum value to PHP type hint for properties
     * Converts 'integer' -> 'int', 'boolean' -> 'bool', 'datetime' -> 'string'
     */
    public static function phpTypeToPropertyType(string $type): string
    {
        return match ($type) {
            'integer' => 'int',
            'boolean' => 'bool',
            'datetime' => 'string', // datetime in DB is represented as string in PHP
            default => $type,
        };
    }

    /**
     * Format default value for code generation
     */
    private static function formatDefaultValue(mixed $default, string $type): string
    {
        if ($default === null) {
            return 'null';
        }

        return match ($type) {
            'integer' => (string)(int)$default,
            'float' => (string)(float)$default,
            'boolean' => $default ? 'true' : 'false',
            default => "'" . addslashes($default) . "'",
        };
    }

    /**
     * Generate all classes (Entity + Object) from table
     * @throws DatabaseException
     */
    public static function generateAll(
        string $tableName,
        string $outputDir,
        string $entityNamespace = 'App\\Entity',
        string $objectNamespace = 'App\\Object',
        ?string $className = null
    ): array {
        if ($className === null) {
            $className = self::tableToPascalCase($tableName);
        }

        $entity = self::generateEntity($tableName, $entityNamespace, $className);
        $object = self::generateObject($tableName, $objectNamespace, $entityNamespace, $className);

        $entityDir = $outputDir . '/Entity';
        $objectDir = $outputDir . '/Object';

        if (!is_dir($entityDir)) {
            mkdir($entityDir, 0755, true);
        }
        if (!is_dir($objectDir)) {
            mkdir($objectDir, 0755, true);
        }

        $entityFile = $entityDir . '/' . $className . '.php';
        $objectFile = $objectDir . '/' . $className . '.php';

        file_put_contents($entityFile, $entity);
        file_put_contents($objectFile, $object);

        return [
            'entity' => $entityFile,
            'object' => $objectFile,
        ];
    }

    /**
     * Generate migration from current database state
     * @throws DatabaseException
     */
    public static function generateMigrationFromTable(string $tableName): string
    {
        Database::sql("SHOW CREATE TABLE `{$tableName}`");
        $row = Database::row();

        if ($row === null) {
            throw new DatabaseException("Table {$tableName} not found");
        }

        $createStatement = $row['Create Table'];

        $migration = "-- Migration: Create {$tableName} table\n";
        $migration .= "-- Auto-generated: " . date('Y-m-d H:i:s') . "\n\n";
        $migration .= $createStatement . ";\n";

        return $migration;
    }

    /**
     * List all tables in current database
     * @throws DatabaseException
     */
    public static function listTables(): array
    {
        Database::sql('SHOW TABLES');
        $tables = [];
        while ($row = Database::row()) {
            $tables[] = reset($row);
        }
        return $tables;
    }

    /**
     * Detect namespace from Entity directory path
     * Used both for creating and updating Entity files
     *
     * Algorithm:
     * 1. Try to find composer.json by going up from entityDir
     * 2. Read autoload.psr-4 from composer.json
     * 3. Find matching namespace prefix for the path
     * 4. Build namespace: prefix + path remainder (converted to PascalCase)
     * 5. Fallback to old path-based algorithm if composer.json not found
     */
    public static function detectNamespaceFromPath(string $entityDir): string
    {
        // Try to extract namespace by looking at existing Entity files
        // But ignore wrong namespaces (starting with App\)
        $files = glob($entityDir . '/*.php');
        if ($files !== false && !empty($files)) {
            $content = file_get_contents($files[0]);
            if ($content !== false && preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
                $detectedNamespace = trim($matches[1]);
                $detectedNamespace = ltrim($detectedNamespace, '\\');
                // Only use detected namespace if it doesn't look like a default/wrong one
                if (!str_starts_with($detectedNamespace, 'App\\')) {
                    return $detectedNamespace;
                }
            }
        }

        // Normalize paths
        $entityDir = str_replace('\\', '/', $entityDir);
        $entityDir = rtrim($entityDir, '/');

        // Try to find composer.json by going up from entityDir
        $currentDir = $entityDir;
        $composerJsonPath = null;

        while ($currentDir !== '' && $currentDir !== '/') {
            $composerJsonPath = $currentDir . '/composer.json';
            if (file_exists($composerJsonPath)) {
                break;
            }
            $parentDir = dirname($currentDir);
            if ($parentDir === $currentDir) {
                break; // Reached root
            }
            $currentDir = $parentDir;
        }

        // If composer.json found, use it to determine namespace
        if ($composerJsonPath !== null && file_exists($composerJsonPath)) {
            $composerContent = file_get_contents($composerJsonPath);
            if ($composerContent !== false) {
                $composer = json_decode($composerContent, true);
                if (isset($composer['autoload']['psr-4']) && is_array($composer['autoload']['psr-4'])) {
                    // Get directory where composer.json is located
                    $composerDir = dirname($composerJsonPath);
                    $composerDir = str_replace('\\', '/', $composerDir);
                    $composerDir = rtrim($composerDir, '/');

                    // Calculate relative path from composer.json directory to Entity directory
                    $relativePath = str_replace($composerDir . '/', '', $entityDir);

                    // Try to find matching psr-4 mapping
                    foreach ($composer['autoload']['psr-4'] as $namespacePrefix => $pathPrefix) {
                        $pathPrefix = rtrim(str_replace('\\', '/', $pathPrefix), '/');

                        // Check if relative path starts with path prefix
                        if (str_starts_with($relativePath, $pathPrefix . '/') || $relativePath === $pathPrefix) {
                            // Get remainder after path prefix
                            $remainder = substr($relativePath, strlen($pathPrefix));
                            $remainder = ltrim($remainder, '/');

                            // Convert remainder to namespace parts (PascalCase)
                            $remainderParts = explode('/', $remainder);
                            $namespaceParts = [];
                            foreach ($remainderParts as $part) {
                                if ($part !== '') {
                                    // Convert to PascalCase: handle dashes and underscores
                                    $namespaceParts[] = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $part)));
                                }
                            }

                            // Build final namespace
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

        // Fallback: try to extract from path structure (old algorithm)
        $path = str_replace('\\', '/', $entityDir);
        $parts = explode('/', $path);

        // Find "Entity" in path
        $entityIndex = -1;
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            if ($parts[$i] === 'Entity') {
                $entityIndex = $i;
                break;
            }
        }

        if ($entityIndex > 0) {
            // Find "backend" or similar root directory
            $backendIndex = -1;
            for ($i = $entityIndex - 1; $i >= 0; $i--) {
                if (in_array($parts[$i], ['backend', 'src', 'app'], true)) {
                    $backendIndex = $i;
                    break;
                }
            }

            if ($backendIndex >= 0) {
                // Take parts from before backend to Entity (inclusive)
                $namespaceParts = [];
                if ($backendIndex > 0) {
                    $projectParts = array_slice($parts, 0, $backendIndex);
                    foreach ($projectParts as $part) {
                        $namespaceParts[] = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $part)));
                    }
                }
                $pathParts = array_slice($parts, $backendIndex, $entityIndex - $backendIndex + 1);
                foreach ($pathParts as $part) {
                    $namespaceParts[] = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $part)));
                }
                return implode('\\', $namespaceParts);
            } else {
                $namespaceParts = array_slice($parts, 0, $entityIndex + 1);
                return implode('\\', array_map(fn($p) => str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $p))), $namespaceParts));
            }
        }

        // Final fallback
        return 'App\\Entity';
    }
}
