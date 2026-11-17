<?php

declare(strict_types=1);

namespace Hilos\Database\Settings;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Tables\Settings\HilosSettingTableRow;

/** Exact persisted settings state, including an absent override; never a normalized value. */
final class RawSettingSnapshot extends BaseDTO
{
    /** Number of fields in the complete raw snapshot contract. */
    private const int FIELD_COUNT = 5;

    /**
     * @param string $key Stable settings identity
     * @param bool $exists Whether the row was present
     * @param string $type Persisted type, or catalog type for an absent row
     * @param ?string $rawValue Persisted nullable text
     * @param string $valueSource Computed source of the effective value
     * @throws InvalidFormatException When the snapshot fields disagree
     */
    public function __construct(
        public readonly string $key,
        public readonly bool $exists,
        public readonly string $type,
        public readonly ?string $rawValue,
        public readonly string $valueSource,
    ) {
        $inherited = in_array($valueSource, [
            HilosSettingTableRow::VALUE_SOURCE_DEFAULT,
            HilosSettingTableRow::VALUE_SOURCE_REFERENCE,
        ], true);
        if (
            $key === ''
            || (!$exists && (!$inherited || $rawValue !== null))
            || ($exists && $inherited && $rawValue !== null)
            || ($valueSource === HilosSettingTableRow::VALUE_SOURCE_OVERRIDE && (!$exists || $rawValue === null))
            || ($valueSource === HilosSettingTableRow::VALUE_SOURCE_ORPHAN && !$exists)
            || !in_array($valueSource, [
                HilosSettingTableRow::VALUE_SOURCE_DEFAULT,
                HilosSettingTableRow::VALUE_SOURCE_REFERENCE,
                HilosSettingTableRow::VALUE_SOURCE_OVERRIDE,
                HilosSettingTableRow::VALUE_SOURCE_ORPHAN,
            ], true)
        ) {
            throw new InvalidFormatException('The raw settings snapshot fields disagree.');
        }
    }

    /**
     * Reads the complete snapshot without coercing raw fields.
     *
     * @param array<string, mixed> $data Snapshot payload
     * @return static Validated snapshot
     * @throws InvalidFormatException When a field is absent, malformed or inconsistent
     */
    public static function fromArray(array $data): static
    {
        if (
            count($data) !== self::FIELD_COUNT
            || !array_key_exists(SettingsCommandConstants::FIELD_RAW_VALUE, $data)
            || ($data[SettingsCommandConstants::FIELD_RAW_VALUE] !== null && !is_string($data[SettingsCommandConstants::FIELD_RAW_VALUE]))
        ) {
            throw new InvalidFormatException('The raw settings snapshot has an invalid shape.');
        }

        return new static(
            self::requireString($data, SettingsCommandConstants::FIELD_KEY),
            self::requireBool($data, SettingsCommandConstants::FIELD_EXISTS),
            self::requireString($data, SettingsCommandConstants::FIELD_TYPE),
            $data[SettingsCommandConstants::FIELD_RAW_VALUE],
            self::requireString($data, SettingsCommandConstants::FIELD_VALUE_SOURCE),
        );
    }

    /** @return array<string, mixed> Exact snapshot payload, including a nullable raw value */
    public function toArray(): array
    {
        return [
            SettingsCommandConstants::FIELD_KEY => $this->key,
            SettingsCommandConstants::FIELD_EXISTS => $this->exists,
            SettingsCommandConstants::FIELD_TYPE => $this->type,
            SettingsCommandConstants::FIELD_RAW_VALUE => $this->rawValue,
            SettingsCommandConstants::FIELD_VALUE_SOURCE => $this->valueSource,
        ];
    }
}
