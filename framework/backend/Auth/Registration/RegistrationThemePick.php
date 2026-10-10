<?php

declare(strict_types=1);

namespace Hilos\Auth\Registration;

use Hilos\Core\Exception\ValidationException;
use Hilos\Theme\ThemeSettingsCatalog;

/** Validates the guest browser's theme choice at the account-creation boundary (HIL-1427). */
final class RegistrationThemePick
{
    public const string PAYLOAD_KEY = 'themePick';

    /**
     * @param ?string $themePick Submitted choice, or null when the guest never chose
     * @return ?string The choice to store with the new account
     * @throws ValidationException When the choice is outside the theme catalog
     */
    public static function readPayload(?string $themePick): ?string
    {
        if ($themePick === null) {
            return null;
        }
        if (!in_array($themePick, ThemeSettingsCatalog::THEME_VALUES, true)) {
            throw new ValidationException(ThemeSettingsCatalog::THEME_VALUE_REFUSAL);
        }

        return $themePick;
    }
}
