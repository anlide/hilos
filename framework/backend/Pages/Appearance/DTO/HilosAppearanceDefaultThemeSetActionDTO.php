<?php

declare(strict_types=1);

namespace Hilos\Pages\Appearance\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Payload for the appearance page's default-theme setting write. */
final class HilosAppearanceDefaultThemeSetActionDTO extends ActionPayloadDTO
{
    /** Payload key: the default theme position. */
    public const string defaultTheme = 'defaultTheme';

    public const array SECRET_FIELDS = [];

    /**
     * @param string $defaultTheme Default theme position, judged by the settings catalog
     */
    public function __construct(public readonly string $defaultTheme)
    {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::APPEARANCE_DEFAULT_THEME_SET;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Parsed payload
     * @throws InvalidFormatException When defaultTheme is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(defaultTheme: self::requireString($inner, self::defaultTheme));
    }

    /**
     * @return array{defaultTheme: string} Wire form
     */
    public function toArray(): array
    {
        return [self::defaultTheme => $this->defaultTheme];
    }
}
