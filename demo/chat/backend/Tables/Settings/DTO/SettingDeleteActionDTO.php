<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Settings\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\ChatActionPayloadDTO;
use Hilos\Database\Entity\Item\Setting;
use Hilos\Constants\SignalPayloadConstants;

/**
 * DTO for setting_delete action payload.
 */
final class SettingDeleteActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates setting delete action DTO.
     *
     * @param string $key Setting key to delete (orphan only)
     */
    public function __construct(
        public readonly string $key,
    ) {
    }

    /**
     * Action name this DTO represents.
     *
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return ChatSignalConstants::SETTING_DELETE;
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
        );
    }

    /**
     * Serializes to array.
     *
     * @return array<string, mixed> Data with key
     */
    public function toArray(): array
    {
        return [Setting::key => $this->key];
    }
}
