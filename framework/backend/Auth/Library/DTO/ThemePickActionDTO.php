<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Theme\ThemeSettingsCatalog;

/** The signed-in person's explicit choice, including a replacement of their stored choice. */
final class ThemePickActionDTO extends ActionPayloadDTO
{
    public const string themePick = 'themePick';

    public const array SECRET_FIELDS = [];

    /**
     * @param string $themePick Light, dark or system
     * @throws InvalidFormatException When the choice is outside the theme vocabulary
     */
    public function __construct(public readonly string $themePick)
    {
        if (!in_array($themePick, ThemeSettingsCatalog::THEME_VALUES, true)) {
            throw new InvalidFormatException(ThemeSettingsCatalog::THEME_VALUE_REFUSAL);
        }
    }

    /** @return string Browser action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_THEME_PICK;
    }

    /**
     * @param array<string, mixed> $data Browser payload; identity is resolved on the server
     * @return static Explicit theme choice payload
     * @throws InvalidFormatException When the choice is missing, mistyped or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, self::themePick));
    }

    /** @return array<string, string> Transport payload */
    public function toArray(): array
    {
        return [self::themePick => $this->themePick];
    }
}
