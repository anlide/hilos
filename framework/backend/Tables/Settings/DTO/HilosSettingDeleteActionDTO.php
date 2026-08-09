<?php

declare(strict_types=1);

namespace Hilos\Tables\Settings\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Database\Entity\Item\Setting;

/**
 * DTO for the setting_delete action payload.
 */
final class HilosSettingDeleteActionDTO extends ActionPayloadDTO
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
        return HilosSignalConstants::SETTING_DELETE;
    }

    /**
     * Creates instance from payload array.
     *
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
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

        return new static(
            key: trim(self::requireString($inner, Setting::key)),
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
