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
    public const string id = ObjectSetting::id;
    public const string key = ObjectSetting::key;
    public const string type = ObjectSetting::type;
    public const string value = ObjectSetting::value;

    public function __construct(
        public ?int $id,
        public string $key,
        public string $type,
        public ?string $value,
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
     * @return array{id: ?int, key: string, type: string, value: ?string}
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::key => $this->key,
            self::type => $this->type,
            self::value => $this->value,
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
        );
    }
}
