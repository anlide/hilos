<?php

declare(strict_types=1);

namespace Hilos\Tables\Legal;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** Legal administration table row and its wire representation. */
final class HilosLegalSettingsTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string value = 'value';
    public const string defaultValue = 'defaultValue';

    /**
     * @param string $rowKey Stable row identity
     * @param string $value Value
     * @param string $defaultValue DefaultValue
     */
    public function __construct(
        public string $rowKey,
        public string $value,
        public string $defaultValue,
    ) {
    }

    /** @return string Stable row identity */
    public function getRowKey(): string
    {
        return $this->rowKey;
    }

    /** @return string Identity field in the wire payload */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /** @return array<string, mixed> Serialized table row */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::value => $this->value,
            self::defaultValue => $this->defaultValue,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire row payload
     * @return static Reconstructed row
     * @throws InvalidFormatException When a field is absent or has the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rowKey: self::requireString($data, self::rowKey),
            value: self::requireString($data, self::value),
            defaultValue: self::requireString($data, self::defaultValue),
        );
    }
}
