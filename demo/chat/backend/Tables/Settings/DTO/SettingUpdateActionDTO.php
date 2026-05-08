<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Settings\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\ChatActionPayloadDTO;
use Hilos\Database\Entity\Item\Setting;
use Hilos\Constants\SignalPayloadConstants;

/**
 * DTO for setting_update action payload.
 */
final class SettingUpdateActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates setting update action DTO.
     *
     * @param string $key Setting key to update
     * @param mixed $value New value (null = use catalog default when reading)
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
        return ChatSignalConstants::SETTING_UPDATE;
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
