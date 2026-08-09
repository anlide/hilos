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
        return HilosSignalConstants::SETTING_ADD;
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
            value: array_key_exists(Setting::value, $inner) ? $inner[Setting::value] : null,
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
