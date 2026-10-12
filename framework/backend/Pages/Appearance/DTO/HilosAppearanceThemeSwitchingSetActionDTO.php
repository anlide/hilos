<?php

declare(strict_types=1);

namespace Hilos\Pages\Appearance\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Payload for the appearance page's theme-switching setting write. */
final class HilosAppearanceThemeSwitchingSetActionDTO extends ActionPayloadDTO
{
    /** Payload key: whether people may switch themes. */
    public const string enabled = 'enabled';

    public const array SECRET_FIELDS = [];

    /**
     * @param bool $enabled Whether people may switch themes
     */
    public function __construct(public readonly bool $enabled)
    {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::APPEARANCE_THEME_SWITCHING_SET;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Parsed payload
     * @throws InvalidFormatException When enabled is absent or not a boolean
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(enabled: self::requireBool($inner, self::enabled));
    }

    /**
     * @return array{enabled: bool} Wire form
     */
    public function toArray(): array
    {
        return [self::enabled => $this->enabled];
    }
}
