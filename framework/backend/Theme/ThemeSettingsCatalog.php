<?php

declare(strict_types=1);

namespace Hilos\Theme;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;

/** The framework settings for theme switching and the installation default (HIL-1426). */
final class ThemeSettingsCatalog implements CatalogProviderInterface
{
    public const string SWITCHING_ENABLED_KEY = 'theme.switching_enabled';
    public const string DEFAULT_THEME_KEY = 'theme.default';

    public const string LIGHT = 'light';
    public const string DARK = 'dark';
    public const string SYSTEM = 'system';
    public const array THEME_VALUES = [self::LIGHT, self::DARK, self::SYSTEM];

    public const bool DEFAULT_SWITCHING_ENABLED = true;
    public const string DEFAULT_THEME = self::SYSTEM;
    public const array KEYS = [self::SWITCHING_ENABLED_KEY, self::DEFAULT_THEME_KEY];

    /**
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    public static function getCatalog(): array
    {
        return [
            self::SWITCHING_ENABLED_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => self::DEFAULT_SWITCHING_ENABLED,
            ],
            self::DEFAULT_THEME_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => self::DEFAULT_THEME,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => ThemeDefaultRule::class,
            ],
        ];
    }
}
