<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog;

use Hilos\Backup\Anonymization\LiveTableSchema;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\UnplacedJournalColumnException;
use Hilos\Database\Schema\JournalColumnMode;

/** Classifies live SQL types without using the ORM's incomplete length metadata. */
final readonly class JournalTriggerColumnTypes
{
    private const array INLINE_TYPES = [
        'tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double',
        'date', 'datetime', 'timestamp', 'time', 'year', 'enum', 'set',
    ];

    private const array LONG_TYPES = ['tinytext', 'text', 'mediumtext', 'longtext', 'json'];

    private const array BINARY_TYPES = [
        'binary', 'varbinary', 'bit', 'tinyblob', 'blob', 'mediumblob', 'longblob',
    ];

    private const int TEXT_MAX_OCTETS = 65535;

    /**
     * @param array<string, array<string, ?int>> $octetLengths CHARACTER_OCTET_LENGTH by table and column
     * @param array<string, array<string, string>> $dataTypes DATA_TYPE by table and column, when read from SQL
     */
    public function __construct(private array $octetLengths, private array $dataTypes = [])
    {
    }

    /**
     * @param int $connectionIndex Primary database connection
     * @return self Live type bounds for all tables
     * @throws DatabaseException When information_schema cannot be read
     */
    public static function read(int $connectionIndex): self
    {
        Database::useConnection($connectionIndex);
        Database::sql(
            'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, CHARACTER_OCTET_LENGTH'
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            . ' ORDER BY TABLE_NAME, ORDINAL_POSITION',
        );

        $lengths = [];
        $types = [];
        foreach (Database::rows() as $row) {
            $table = (string)$row['TABLE_NAME'];
            $column = (string)$row['COLUMN_NAME'];
            $types[$table][$column] = strtolower((string)$row['DATA_TYPE']);
            $lengths[$table][$column]
                = $row['CHARACTER_OCTET_LENGTH'] === null ? null : (int)$row['CHARACTER_OCTET_LENGTH'];
        }

        return new self($lengths, $types);
    }

    /**
     * @param LiveTableSchema $schema Live table with DATA_TYPE for each column
     * @param string $column Column to classify
     * @param JournalColumnMode $mode Journal placement
     * @return string inline, long, fact, or ignored
     * @throws UnplacedJournalColumnException When the SQL type cannot be represented safely
     */
    public function kindFor(LiveTableSchema $schema, string $column, JournalColumnMode $mode): string
    {
        $schemaType = $schema->typeOf($column);
        $label = "{$schema->table}.{$column}";
        if ($schemaType === null) {
            throw new UnplacedJournalColumnException(["{$label}: no live SQL type"]);
        }
        $type = $this->dataTypes[$schema->table][$column] ?? $schemaType;
        if ($type !== $schemaType) {
            throw new UnplacedJournalColumnException(["{$label}: live SQL type changed during introspection"]);
        }
        if ($mode === JournalColumnMode::SECRET || $mode === JournalColumnMode::NOISE) {
            return 'ignored';
        }
        if (!in_array($type, self::INLINE_TYPES, true)
            && !in_array($type, self::LONG_TYPES, true)
            && !in_array($type, self::BINARY_TYPES, true)
            && $type !== 'char' && $type !== 'varchar') {
            throw new UnplacedJournalColumnException(["{$label}: unsupported SQL type {$type}"]);
        }
        if ($mode === JournalColumnMode::RECORD_KEY) {
            if (in_array($type, self::BINARY_TYPES, true) || in_array($type, self::LONG_TYPES, true)) {
                throw new UnplacedJournalColumnException(["{$label}: unsupported record key type {$type}"]);
            }
            $this->valueKind($schema, $column, $type);
            return 'key';
        }
        if ($mode === JournalColumnMode::PERSONAL || $mode === JournalColumnMode::BINARY) {
            return 'fact';
        }

        return $this->valueKind($schema, $column, $type);
    }

    /**
     * @param LiveTableSchema $schema Live table
     * @param string $column Column to classify
     * @param string $type Lower-case DATA_TYPE
     * @return string Inline or long storage kind
     * @throws UnplacedJournalColumnException When the SQL type or bound is unsafe
     */
    private function valueKind(LiveTableSchema $schema, string $column, string $type): string
    {
        if (in_array($type, self::INLINE_TYPES, true)) {
            return 'inline';
        }
        if (in_array($type, self::LONG_TYPES, true)) {
            return 'long';
        }
        if ($type === 'char' || $type === 'varchar') {
            $length = $this->octetLengths[$schema->table][$column] ?? null;
            if ($length === null) {
                throw new UnplacedJournalColumnException(["{$schema->table}.{$column}: no CHARACTER_OCTET_LENGTH"]);
            }
            return $length <= self::TEXT_MAX_OCTETS ? 'inline' : 'long';
        }

        throw new UnplacedJournalColumnException(["{$schema->table}.{$column}: unsupported SQL type {$type}"]);
    }
}
