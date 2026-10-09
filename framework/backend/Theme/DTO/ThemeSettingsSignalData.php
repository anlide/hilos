<?php

declare(strict_types=1);

namespace Hilos\Theme\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\Theme\ThemeSettingsCatalog;

/** The installation's theme settings, sent on the handshake and when either changes. */
final class ThemeSettingsSignalData extends BaseDTO implements SignalDataInterface
{
    public const string switchingEnabled = 'switchingEnabled';
    public const string defaultTheme = 'defaultTheme';

    /**
     * @param bool $switchingEnabled Whether a person may switch themes
     * @param string $defaultTheme Installation theme when no personal choice applies
     */
    public function __construct(
        public readonly bool $switchingEnabled,
        public readonly string $defaultTheme,
    ) {
    }

    /**
     * Reads both settings, each on its default when the project's catalog lacks it.
     *
     * Every handshake sends this frame, so a project on the stub catalog, or with a catalog of its
     * own without the theme fragment, lives on the defaults rather than refusing every connection.
     *
     * @return self Settings in force
     * @throws DatabaseException When a stored setting cannot be read
     * @throws SettingException When the setting catalog or a value is invalid
     */
    public static function current(): self
    {
        return new self(self::switchingEnabledInForce(), self::defaultThemeInForce());
    }

    /**
     * @return array{switchingEnabled: bool, defaultTheme: string} Wire form
     */
    public function toArray(): array
    {
        return [
            self::switchingEnabled => $this->switchingEnabled,
            self::defaultTheme => $this->defaultTheme,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire form
     * @return static Restored payload
     * @throws InvalidFormatException When a member is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        $defaultTheme = self::requireString($data, self::defaultTheme);
        if (!in_array($defaultTheme, ThemeSettingsCatalog::THEME_VALUES, true)) {
            throw new InvalidFormatException('Invalid default theme');
        }

        return new static(self::requireBool($data, self::switchingEnabled), $defaultTheme);
    }

    /**
     * @return bool Whether switching is on, the catalog default when the catalog lacks the key
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting catalog or value is invalid
     */
    private static function switchingEnabledInForce(): bool
    {
        if (Hilos::$setting === null || !isset(Hilos::$setting[ThemeSettingsCatalog::SWITCHING_ENABLED_KEY])) {
            return ThemeSettingsCatalog::DEFAULT_SWITCHING_ENABLED;
        }

        return Hilos::$setting[ThemeSettingsCatalog::SWITCHING_ENABLED_KEY]->bool();
    }

    /**
     * @return string Default theme, the catalog default when the catalog lacks the key
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting catalog or value is invalid
     */
    private static function defaultThemeInForce(): string
    {
        if (Hilos::$setting === null || !isset(Hilos::$setting[ThemeSettingsCatalog::DEFAULT_THEME_KEY])) {
            return ThemeSettingsCatalog::DEFAULT_THEME;
        }

        return Hilos::$setting[ThemeSettingsCatalog::DEFAULT_THEME_KEY]->string();
    }
}
