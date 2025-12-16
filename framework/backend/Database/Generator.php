<?php

namespace Hilos\Database;

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
     * @return string Generated PHP code
     * @throws DatabaseException
     */
    public static function generateEntity(string $tableName, string $namespace = 'App\\Entity', ?string $className = null): string
    {
        if ($className === null) {
            $className = self::tableToPascalCase($tableName);
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
            $nullable = $column['Null'] === 'YES';
            $default = $column['Default'];
            $key = $column['Key'];

            $columnConstants[] = "    public const string {$field} = '{$field}';";
            $columnList[] = "self::{$field}";
            $typeValue = self::formatTypeForArray($type);
            $typesList[] = "        self::{$field} => {$typeValue}";

            $phpType = $nullable ? "?{$type}" : $type;
            $defaultValue = $default !== null ? " = " . self::formatDefaultValue($default, $type) : ($nullable ? ' = null' : '');

            $properties[] = "    public {$phpType} \${$field}{$defaultValue};";

            if ($key === 'PRI') {
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
                    'columns' => []
                ];
            }
            $indexGroups[$keyName]['columns'][] = $index['Column_name'];
        }

        foreach ($indexGroups as $keyName => $info) {
            $indexColumns = "'" . implode("', '", $info['columns']) . "'";
            $unique = $info['unique'] ? "'unique' => true, " : "";
            $indexesList[] = "        '{$keyName}' => [{$unique}'columns' => [{$indexColumns}]]";
        }

        // Detect foreign keys (simplified - by naming convention)
        foreach ($columns as $column) {
            $field = $column['Field'];
            if (preg_match('/^id_(\w+)$/', $field, $matches)) {
                $foreignTable = $matches[1];
                $foreignKeys[] = "        self::{$field} => '{$foreignTable}'";
            }
        }

        // Build primary key definition
        $primaryDef = count($primaryKeys) === 1 
            ? $primaryKeys[0] 
            : '[' . implode(', ', $primaryKeys) . ']';

        // Generate code
        $code = "<?php\n\n";
        $code .= "namespace {$namespace};\n\n";
        $code .= "use Hilos\\Database\\Entity\\Entity;\n";
        $code .= "use Hilos\\Database\\PhpType;\n\n";
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
        $code .= "    public const string|array _primary = {$primaryDef};\n";
        $code .= "    public const array _columns = [\n        ";
        $code .= implode(",\n        ", $columnList);
        $code .= "\n    ];\n\n";
        $code .= "    // Column types\n";
        $code .= "    public const array _types = [\n";
        $code .= implode(",\n", $typesList);
        $code .= "\n    ];\n\n";

        if (!empty($foreignKeys)) {
            $code .= "    // Foreign keys\n";
            $code .= "    public const array _foreign = [\n";
            $code .= implode(",\n", $foreignKeys);
            $code .= "\n    ];\n\n";
        }

        if (!empty($indexesList)) {
            $code .= "    // Indexes\n";
            $code .= "    public const array _indexes = [\n";
            $code .= implode(",\n", $indexesList);
            $code .= "\n    ];\n\n";
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
}

