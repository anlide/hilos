<?php

declare(strict_types=1);

namespace Hilos\Tables\ChangeLog;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\ChangeLog\Section\ChangeLogColumn;

/** One schema column, keyed by its name within the selected table. */
final class HilosChangeLogTableColumnsTableRow extends AbstractTableRow
{
    public const string name = 'name';
    public const string sqlType = 'sqlType';
    public const string mode = 'mode';
    public const string storage = 'storage';
    public const string noiseReason = 'noiseReason';

    /**
     * @param string $name Schema column name and row key
     * @param string $sqlType Live SQL data type
     * @param ?string $mode Journal mode, or null outside the journal
     * @param ?string $storage Journal placement, or null for a record key or untracked table
     * @param ?string $noiseReason Declared reason for excluding a noise column
     */
    public function __construct(
        public string $name,
        public string $sqlType,
        public ?string $mode,
        public ?string $storage,
        public ?string $noiseReason,
    ) {
    }

    /**
     * @param ChangeLogColumn $column Live schema column and journal placement
     * @return self Inline column metadata without an entity id
     */
    public static function fromColumn(ChangeLogColumn $column): self
    {
        return new self($column->name, $column->sqlType, $column->mode?->value, $column->storage, $column->noiseReason);
    }

    /** @return string Column name within the selected table */
    public function getRowKey(): string
    {
        return $this->name;
    }

    /** @return string Payload field carrying the column key */
    public static function keyField(): string
    {
        return self::name;
    }

    /** @return array<string, mixed> Inline column slot with explicit nullable placement fields */
    public function toArray(): array
    {
        return [
            self::name => $this->name,
            self::sqlType => $this->sqlType,
            self::mode => $this->mode,
            self::storage => $this->storage,
            self::noiseReason => $this->noiseReason,
        ];
    }

    /**
     * @param array<string, mixed> $data Column slot payload
     * @return static Reconstructed column metadata
     * @throws InvalidFormatException When a field is missing or has the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, self::name),
            self::requireString($data, self::sqlType),
            self::requireNullableString($data, self::mode),
            self::requireNullableString($data, self::storage),
            self::requireNullableString($data, self::noiseReason),
        );
    }
}
