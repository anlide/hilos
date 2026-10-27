<?php

declare(strict_types=1);

namespace Hilos\Tables\ChangeLog;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\ChangeLog\Section\ChangeLogNoisyColumn;

/** One inline noise declaration, keyed by its live table and column names. */
final class HilosChangeLogNoisyColumnsTableRow extends AbstractTableRow
{
    public const string columnKey = 'columnKey';
    public const string table = 'table';
    public const string column = 'column';
    public const string reason = 'reason';

    /**
     * @param string $columnKey Table-qualified column name and row key
     * @param string $table Live journaled table name
     * @param string $column Noisy column name
     * @param string $reason Declared reason for excluding the column
     */
    public function __construct(
        public string $columnKey,
        public string $table,
        public string $column,
        public string $reason,
    ) {
    }

    /**
     * @param ChangeLogNoisyColumn $noisyColumn Live noise declaration
     * @return self Inline row with a table-qualified key
     */
    public static function fromColumn(ChangeLogNoisyColumn $noisyColumn): self
    {
        return new self(
            $noisyColumn->table . '.' . $noisyColumn->column,
            $noisyColumn->table,
            $noisyColumn->column,
            $noisyColumn->reason,
        );
    }

    /** @return string Table-qualified column name */
    public function getRowKey(): string
    {
        return $this->columnKey;
    }

    /** @return string Payload field carrying the row key */
    public static function keyField(): string
    {
        return self::columnKey;
    }

    /** @return array<string, mixed> Inline noise declaration without an entity id */
    public function toArray(): array
    {
        return [
            self::columnKey => $this->columnKey,
            self::table => $this->table,
            self::column => $this->column,
            self::reason => $this->reason,
        ];
    }

    /**
     * @param array<string, mixed> $data Noise declaration slot
     * @return static Reconstructed inline row
     * @throws InvalidFormatException When a required string field is missing or malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, self::columnKey),
            self::requireString($data, self::table),
            self::requireString($data, self::column),
            self::requireString($data, self::reason),
        );
    }
}
