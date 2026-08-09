<?php

declare(strict_types=1);

namespace Hilos\Tables\Settings;

use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\SettingsCatalogConstants;

/**
 * Backend row payload for the framework settings table.
 *
 * `persisted` says whether a DB row backs this key, which `valueSource` cannot:
 * a stored row whose value is NULL (no override, inherit the default) and a
 * catalog key with no row at all both resolve to `default` / `reference`. The
 * frontend needs the distinction to edit an existing row instead of inserting a
 * duplicate, and the DB id cannot carry it — {@see HilosSettingsTable::browserRow()}
 * strips the id so the frontend normalizer does not read the slot as an entity.
 */
final class HilosSettingTableRow extends AbstractTableRow
{
    public const string VALUE_SOURCE_DEFAULT = 'default';
    public const string VALUE_SOURCE_REFERENCE = 'reference';
    public const string VALUE_SOURCE_OVERRIDE = 'override';
    public const string VALUE_SOURCE_ORPHAN = 'orphan';

    public const string id = ObjectSetting::id;
    public const string key = ObjectSetting::key;
    public const string type = ObjectSetting::type;
    public const string value = ObjectSetting::value;
    public const string overrideValue = 'overrideValue';
    public const string defaultValue = 'defaultValue';
    public const string defaultReferenceKey = 'defaultReferenceKey';
    public const string valueSource = 'valueSource';
    public const string persisted = 'persisted';

    public function __construct(
        public ?int $id,
        public string $key,
        public string $type,
        public ?string $value,
        public ?string $overrideValue,
        public ?string $defaultValue,
        public ?string $defaultReferenceKey,
        public string $valueSource,
        public bool $persisted,
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
            self::persisted => $this->persisted,
        ];
    }

    /**
     * Builds a settings row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     */
    public static function fromArray(array $data): static
    {
        return new static(
            id: isset($data[self::id]) ? (int) $data[self::id] : null,
            key: (string) $data[self::key],
            type: (string) ($data[self::type] ?? SettingsCatalogConstants::TYPE_STRING),
            value: isset($data[self::value]) ? (string) $data[self::value] : null,
            overrideValue: isset($data[self::overrideValue]) ? (string) $data[self::overrideValue] : null,
            defaultValue: isset($data[self::defaultValue]) ? (string) $data[self::defaultValue] : null,
            defaultReferenceKey: isset($data[self::defaultReferenceKey]) ? (string) $data[self::defaultReferenceKey] : null,
            valueSource: (string) ($data[self::valueSource] ?? self::VALUE_SOURCE_ORPHAN),
            persisted: (bool) ($data[self::persisted] ?? false),
        );
    }
}
