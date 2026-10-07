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
     * @return self Settings in force
     * @throws DatabaseException When a stored setting cannot be read
     * @throws SettingException When the setting catalog or a value is invalid
     */
    public static function current(): self
    {
        return new self(
            Hilos::$setting[ThemeSettingsCatalog::SWITCHING_ENABLED_KEY]->bool(),
            Hilos::$setting[ThemeSettingsCatalog::DEFAULT_THEME_KEY]->string(),
        );
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
}
