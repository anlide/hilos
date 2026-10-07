<?php

declare(strict_types=1);

namespace Hilos\Tables\Appearance;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Theme\ThemeSettingsCatalog;

/** One theme setting and its catalog default on the appearance page. */
final class HilosAppearanceSettingsTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string value = 'value';
    public const string defaultValue = 'defaultValue';

    /**
     * @param string $rowKey Stable setting key
     * @param bool|string $value Current setting value
     * @param bool|string $defaultValue Catalog default
     */
    public function __construct(
        public string $rowKey,
        public bool|string $value,
        public bool|string $defaultValue,
    ) {
    }

    /** @return string Stable setting key */
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
        $key = self::requireString($data, self::rowKey);

        return match ($key) {
            ThemeSettingsCatalog::SWITCHING_ENABLED_KEY => new static(
                rowKey: $key,
                value: self::requireBool($data, self::value),
                defaultValue: self::requireBool($data, self::defaultValue),
            ),
            ThemeSettingsCatalog::DEFAULT_THEME_KEY => new static(
                rowKey: $key,
                value: self::requireString($data, self::value),
                defaultValue: self::requireString($data, self::defaultValue),
            ),
            default => throw new InvalidFormatException("Unknown appearance setting key: {$key}"),
        };
    }
}
