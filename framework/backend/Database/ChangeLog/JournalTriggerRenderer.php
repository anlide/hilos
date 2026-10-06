<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog;

use Hilos\Backup\Anonymization\LiveTableSchema;
use Hilos\Database\Exception\UnplacedJournalColumnException;
use Hilos\Database\Schema\JournalColumnMode;
use Hilos\Database\Schema\JournalColumnPlacement;

/** Builds deterministic, installation-independent SQL for the three journal events. */
final class JournalTriggerRenderer
{
    private const string DATABASE_TOKEN = '{{change_log_database}}';
    private const int MAX_TRIGGER_NAME_LENGTH = 64;

    /**
     * @param LiveTableSchema $schema Live primary-database table
     * @param array<string, JournalColumnPlacement> $placements Column policy in live order
     * @param JournalTriggerColumnTypes $types Live octet bounds
     * @param int $migrationIndex Applied migration number
     * @return list<JournalTriggerFile> Insert, update and delete files
     * @throws UnplacedJournalColumnException When a name or type cannot be rendered safely
     */
    public static function render(
        LiveTableSchema $schema,
        array $placements,
        JournalTriggerColumnTypes $types,
        int $migrationIndex,
    ): array {
        self::identifier($schema->table);
        $kinds = [];
        foreach ($schema->columns as $column => $_nullable) {
            self::identifier($column);
            $placement = $placements[$column] ?? null;
            if ($placement === null) {
                throw new UnplacedJournalColumnException(["{$schema->table}.{$column}: no journal placement"]);
            }
            $kinds[$column] = $types->kindFor($schema, $column, $placement->mode);
        }
        if ($schema->primaryKey === []) {
            throw new UnplacedJournalColumnException(["{$schema->table}._primary: no live primary key"]);
        }

        $files = [];
        foreach (['insert', 'update', 'delete'] as $event) {
            $name = self::name($schema->table, $event);
            $files[] = new JournalTriggerFile(
                $name,
                self::body($name, $event, $schema, $kinds),
                $migrationIndex,
                false,
            );
        }

        return $files;
    }

    /**
     * @param string $table Previously journaled table
     * @param int $migrationIndex Applied migration number
     * @return list<JournalTriggerFile> Three DROP files preserving the names
     * @throws UnplacedJournalColumnException When the table name cannot form a trigger name
     */
    public static function tombstones(string $table, int $migrationIndex): array
    {
        $files = [];
        foreach (['insert', 'update', 'delete'] as $event) {
            $name = self::name($table, $event);
            $files[] = new JournalTriggerFile($name, "DROP TRIGGER IF EXISTS `{$name}`;", $migrationIndex, true);
        }
        return $files;
    }

    /**
     * @param string $table Live or formerly journaled table
     * @param string $event insert, update, or delete
     * @return string SQL trigger name
     * @throws UnplacedJournalColumnException When the name exceeds MariaDB's limit
     */
    private static function name(string $table, string $event): string
    {
        self::identifier($table);
        $name = "hilos_cl_{$table}_after_{$event}";
        if (strlen($name) > self::MAX_TRIGGER_NAME_LENGTH) {
            throw new UnplacedJournalColumnException(["{$table}: trigger name exceeds 64 characters"]);
        }
        return $name;
    }

    /**
     * @param string $name Trigger name
     * @param string $event Trigger event
     * @param LiveTableSchema $schema Live table
     * @param array<string, string> $kinds Storage kinds in live order
     * @return string One CREATE TRIGGER statement
     */
    private static function body(
        string $name,
        string $event,
        LiveTableSchema $schema,
        array $kinds,
    ): string {
        $lines = [
            "CREATE TRIGGER `{$name}` AFTER " . strtoupper($event) . ' ON ' . self::identifier($schema->table),
            'FOR EACH ROW BEGIN',
            '    DECLARE v_created_at DATETIME(6);',
            '    DECLARE v_table_id INT UNSIGNED;',
            '    DECLARE v_log_id BIGINT UNSIGNED;',
            '    DECLARE v_field_id INT UNSIGNED;',
            '    DECLARE v_change_id BIGINT UNSIGNED;',
            '    DECLARE v_record_key TEXT;',
            '    SET v_created_at = UTC_TIMESTAMP(6);',
        ];

        if ($event === 'insert') {
            array_push($lines, ...self::logStatements($schema, 'NEW', 'create'));
        } elseif ($event === 'delete') {
            array_push($lines, ...self::logStatements($schema, 'OLD', 'delete'));
            array_push($lines, ...self::fieldStatements($schema, $kinds, 'delete'));
        } else {
            $keyChanges = [];
            foreach ($schema->primaryKey as $column) {
                $keyChanges[] = self::changed($column, $schema->typeOf($column));
            }
            $valueChanges = [];
            foreach ($kinds as $column => $kind) {
                if ($kind !== 'ignored' && $kind !== 'key') {
                    $valueChanges[] = self::changed($column, $schema->typeOf($column));
                }
            }
            $lines[] = '    IF ' . implode(' OR ', $keyChanges) . ' THEN';
            array_push($lines, ...self::indent(self::logStatements($schema, 'OLD', 'delete')));
            array_push($lines, ...self::indent(self::fieldStatements($schema, $kinds, 'delete')));
            array_push($lines, ...self::indent(self::logStatements($schema, 'NEW', 'create')));
            if ($valueChanges !== []) {
                $lines[] = '    ELSEIF ' . implode(' OR ', $valueChanges) . ' THEN';
                array_push($lines, ...self::indent(self::logStatements($schema, 'NEW', 'update')));
                array_push($lines, ...self::indent(self::fieldStatements($schema, $kinds, 'update')));
            }
            $lines[] = '    END IF;';
        }

        $lines[] = 'END;';
        return implode("\n", $lines);
    }

    /**
     * @param LiveTableSchema $schema Live table
     * @param string $side OLD or NEW
     * @param string $mutation create, update, or delete
     * @return list<string> Indented SQL lines for one log row
     */
    private static function logStatements(LiveTableSchema $schema, string $side, string $mutation): array
    {
        $db = self::DATABASE_TOKEN;
        $key = implode(', ', array_map(static fn(string $column): string => $side . '.' . self::identifier($column),
            $schema->primaryKey));
        return [
            "    INSERT IGNORE INTO {$db}.`hilos_change_log_table` (`name`) VALUES (" . self::literal($schema->table) . ');',
            "    SELECT `id` INTO v_table_id FROM {$db}.`hilos_change_log_table`"
                . ' WHERE `name` = ' . self::literal($schema->table) . ';',
            "    SET v_record_key = JSON_ARRAY({$key});",
            "    INSERT INTO {$db}.`hilos_change_log`",
            '        (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)',
            "        VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), '{$mutation}');",
            '    SET v_log_id = LAST_INSERT_ID();',
        ];
    }

    /**
     * @param LiveTableSchema $schema Live table
     * @param array<string, string> $kinds Storage kinds
     * @param string $event update or delete
     * @return list<string> Field rows, with long values in their side table
     */
    private static function fieldStatements(
        LiveTableSchema $schema,
        array $kinds,
        string $event,
    ): array {
        $db = self::DATABASE_TOKEN;
        $lines = [];
        foreach ($kinds as $column => $kind) {
            if ($kind === 'ignored' || $kind === 'key') {
                continue;
            }
            $old = 'OLD.' . self::identifier($column);
            $new = 'NEW.' . self::identifier($column);
            if ($event === 'update') {
                $lines[] = '    IF ' . self::changed($column, $schema->typeOf($column)) . ' THEN';
            }
            $row = [
                "    INSERT IGNORE INTO {$db}.`hilos_change_log_field` (`table_id`, `name`)"
                    . ' VALUES (v_table_id, ' . self::literal($column) . ');',
                "    SELECT `id` INTO v_field_id FROM {$db}.`hilos_change_log_field`"
                    . ' WHERE `table_id` = v_table_id AND `name` = ' . self::literal($column) . ';',
                "    INSERT INTO {$db}.`hilos_change_log_change`",
                '        (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)',
            ];
            $oldValue = $kind === 'inline' ? "CAST({$old} AS CHAR)" : 'NULL';
            $newValue = $event === 'update' && $kind === 'inline' ? "CAST({$new} AS CHAR)" : 'NULL';
            $newPresent = $event === 'update' ? '1' : '0';
            $row[] = "        VALUES (v_created_at, v_log_id, v_field_id, '{$kind}', 1, {$newPresent},"
                . " {$oldValue}, {$newValue});";
            if ($kind === 'long') {
                $row[] = '    SET v_change_id = LAST_INSERT_ID();';
                $row[] = "    INSERT INTO {$db}.`hilos_change_log_value`"
                    . ' (`created_at`, `change_id`, `old_value`, `new_value`)';
                $longNew = $event === 'update' ? "CAST({$new} AS CHAR)" : 'NULL';
                $row[] = "        VALUES (v_created_at, v_change_id, CAST({$old} AS CHAR), {$longNew});";
            }
            array_push($lines, ...($event === 'update' ? self::indent($row) : $row));
            if ($event === 'update') {
                $lines[] = '    END IF;';
            }
        }
        return $lines;
    }

    /**
     * @param string $column Live column name
     * @param ?string $type DATA_TYPE
     * @return string NULL-safe change predicate
     */
    private static function changed(string $column, ?string $type): string
    {
        $old = 'OLD.' . self::identifier($column);
        $new = 'NEW.' . self::identifier($column);
        $textual = in_array($type, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json', 'enum', 'set'], true);
        return $textual ? "NOT (BINARY {$old} <=> BINARY {$new})" : "NOT ({$old} <=> {$new})";
    }

    /**
     * @param string $name Table or column name
     * @return string Quoted SQL identifier
     * @throws UnplacedJournalColumnException When the identifier is not representable in a trigger
     */
    private static function identifier(string $name): string
    {
        if ($name === '' || strlen($name) > 64 || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) !== 1) {
            throw new UnplacedJournalColumnException(["{$name}: invalid SQL identifier"]);
        }
        return "`{$name}`";
    }

    /**
     * @param string $value Validated schema name
     * @return string SQL string literal
     */
    private static function literal(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * @param list<string> $lines SQL lines already indented four spaces
     * @return list<string> Lines indented one more block
     */
    private static function indent(array $lines): array
    {
        return array_map(static fn(string $line): string => '    ' . $line, $lines);
    }
}
