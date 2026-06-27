<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Database\Settings;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;

/**
 * PollSettingsCatalog - Project settings catalog for the simple-poll demo.
 *
 * Declares the allowed setting keys, their types, and default values; the
 * framework reads it back through Hilos::$setting->catalog(). Keys present in the
 * DB but absent here are treated as orphans. The demo ships only the framework
 * example keys — enough to exercise the settings admin feature end to end without
 * inventing project-specific configuration.
 *
 * @see SettingsCatalogConstants
 */
final class PollSettingsCatalog implements CatalogProviderInterface
{
    /**
     * Returns the settings catalog for the simple-poll demo.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getCatalog(): array
    {
        return [
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => '',
            ],
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_INTEGER => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 0,
            ],
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_BOOLEAN => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => false,
            ],
        ];
    }
}
