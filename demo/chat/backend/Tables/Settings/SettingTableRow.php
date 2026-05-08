<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Settings;

use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Backend row payload for the settings table.
 */
final class SettingTableRow extends AbstractTableRow
{
    public const string VALUE_SOURCE_DEFAULT = 'default';
    public const string VALUE_SOURCE_REFERENCE = 'reference';
    public const string VALUE_SOURCE_OVERRIDE = 'override';
    public const string VALUE_SOURCE_ORPHAN = 'orphan';

    public const string id = ObjectSetting::id;
    public const string key = ObjectSetting::key;
    public const string type = ObjectSetting::type;
    public const string value = ObjectSetting::value;
    public const string overrideValue = 'override_value';
    public const string defaultValue = 'default_value';
    public const string defaultReferenceKey = 'default_reference_key';
    public const string valueSource = 'value_source';

    public function __construct(
        public ?int $id,
        public string $key,
        public string $type,
        public ?string $value,
        public ?string $overrideValue,
        public ?string $defaultValue,
        public ?string $defaultReferenceKey,
        public string $valueSource,
    ) {
    }

    /**
     * Returns the stable row key used by the settings table.
     *
     * Settings rows are keyed by the setting key instead of the numeric DB id.
     */
    public function getRowKey(): string
    {
        return $this->key;
    }

    /**
     * Serializes the row to the settings table payload shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::key => $this->key,
            self::type => $this->type,
            self::value => $this->value,
            self::overrideValue => $this->overrideValue,
            self::defaultValue => $this->defaultValue,
            self::defaultReferenceKey => $this->defaultReferenceKey,
            self::valueSource => $this->valueSource,
        ];
    }

    /**
     * Builds a settings row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     */
    public static function fromArray(array $data): static
    {
        return new self(
            id: isset($data[self::id]) ? (int) $data[self::id] : null,
            key: (string) ($data[self::key] ?? ''),
            type: (string) ($data[self::type] ?? ''),
            value: isset($data[self::value]) ? (string) $data[self::value] : null,
            overrideValue: isset($data[self::overrideValue]) ? (string) $data[self::overrideValue] : null,
            defaultValue: isset($data[self::defaultValue]) ? (string) $data[self::defaultValue] : null,
            defaultReferenceKey: isset($data[self::defaultReferenceKey]) ? (string) $data[self::defaultReferenceKey] : null,
            valueSource: (string) ($data[self::valueSource] ?? self::VALUE_SOURCE_ORPHAN),
        );
    }
}
