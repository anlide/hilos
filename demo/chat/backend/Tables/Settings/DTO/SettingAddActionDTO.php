<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Settings\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Database\Entity\Item\Setting;
use Hilos\Constants\SignalPayloadConstants;

/**
 * DTO for setting_add action payload.
 */
final class SettingAddActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates setting add action DTO.
     *
     * @param string $key Setting key (must be in catalog)
     * @param mixed $value Value (null = use catalog default when reading)
     */
    public function __construct(
        public readonly string $key,
        public readonly mixed $value = null,
    ) {
    }

    /**
     * Action name this DTO represents.
     *
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return ChatSignalConstants::SETTING_ADD;
    }

    /**
     * Creates instance from payload array.
     *
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     * @return static Instance
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (is_array($inner) && isset($inner[SignalPayloadConstants::FIELD_DATA]) && is_array($inner[SignalPayloadConstants::FIELD_DATA])) {
            $inner = $inner[SignalPayloadConstants::FIELD_DATA];
        }

        return new static(
            key: is_string($inner[Setting::key] ?? null) ? trim($inner[Setting::key]) : '',
            value: array_key_exists(Setting::value, $inner ?? []) ? $inner[Setting::value] : null,
        );
    }

    /**
     * Serializes to array.
     *
     * @return array<string, mixed> Data with key and optional value
     */
    public function toArray(): array
    {
        $result = [Setting::key => $this->key];
        if ($this->value !== null) {
            $result[Setting::value] = $this->value;
        }
        return $result;
    }
}
