<?php

declare(strict_types=1);

namespace Hilos\Tables\Settings\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Database\Entity\Item\Setting;

/**
 * DTO for the setting_add action payload.
 */
final class HilosSettingAddActionDTO extends ActionPayloadDTO
{
    /**
     * Creates setting add action DTO.
     *
     * @param string $key Setting key (must be in catalog)
     * @param mixed $value Value to store as the override (never null: a row without a value does not exist)
     */
    public function __construct(
        public readonly string $key,
        public readonly mixed $value,
    ) {
    }

    /**
     * Action name this DTO represents.
     *
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::SETTING_ADD;
    }

    /**
     * Creates instance from payload array.
     *
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When a field the action needs is absent, null, or not a string
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        if (isset($inner[SignalPayloadConstants::FIELD_DATA]) && is_array($inner[SignalPayloadConstants::FIELD_DATA])) {
            $inner = $inner[SignalPayloadConstants::FIELD_DATA];
        }

        // An absent or null value used to mean "no override, inherit the default"; that
        // state no longer exists, so the payload that carries it is malformed rather than
        // a reset in disguise — the reset has its own action.
        $value = $inner[Setting::value] ?? null;
        if ($value === null) {
            throw new InvalidFormatException('Payload carries no value under key ' . Setting::value);
        }

        return new static(
            key: trim(self::requireString($inner, Setting::key)),
            value: $value,
        );
    }

    /**
     * Serializes to array.
     *
     * @return array<string, mixed> Data with key and value
     */
    public function toArray(): array
    {
        return [
            Setting::key => $this->key,
            Setting::value => $this->value,
        ];
    }
}
