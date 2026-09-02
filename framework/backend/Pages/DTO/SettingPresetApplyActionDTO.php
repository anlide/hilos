<?php

declare(strict_types=1);

namespace Hilos\Pages\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DTO for the setting_preset_apply action payload (HIL-762).
 *
 * One field, and the missing one is the point: the group is declared by the page the action is
 * routed to, not named by the sender, so no browser sitting on the Logs screen can apply a preset
 * of some other section — and the server has no membership to check by hand.
 *
 * Both gestures the screen offers ride this one action: clicking an unlit card, and the button
 * inside the lit one offering to put its values back. Both say the same thing — make it as the
 * named preset says — and a second action name would have been a second way to say it.
 */
final class SettingPresetApplyActionDTO extends ActionPayloadDTO
{
    /** Payload key: machine name of the preset to apply. */
    public const string preset = 'preset';

    /**
     * @param string $preset Machine name of the preset to apply
     */
    public function __construct(
        public readonly string $preset,
    ) {
    }

    /**
     * Action name this DTO represents.
     *
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::SETTING_PRESET_APPLY;
    }

    /**
     * Creates instance from payload array.
     *
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When the preset name is absent or not a string
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
            preset: trim(self::requireString($inner, self::preset)),
        );
    }

    /**
     * Serializes to array.
     *
     * @return array<string, mixed> Data with the preset name
     */
    public function toArray(): array
    {
        return [self::preset => $this->preset];
    }
}
