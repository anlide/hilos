<?php

declare(strict_types=1);

namespace Demo\Tasks\Database\Settings;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Log\LogSettingsCatalog;

/**
 * TasksSettingsCatalog - Project settings catalog for the tasks demo.
 *
 * Declares the allowed setting keys, their types, and default values; the
 * framework reads it back through Hilos::$setting->catalog(). Keys present in the
 * DB but absent here are treated as orphans. The demo ships only the framework
 * example keys — enough to exercise the settings admin feature end to end without
 * inventing project-specific configuration — plus the log keys the logging modes
 * screen writes, which would become orphans the moment they are saved if the
 * activated feature's own catalog were not merged in here.
 *
 * @see SettingsCatalogConstants
 * @see LogSettingsCatalog Keys of the logs feature this demo activates
 */
final class TasksSettingsCatalog implements CatalogProviderInterface
{
    /**
     * Returns the settings catalog for the tasks demo.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getCatalog(): array
    {
        return array_replace([
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
        ],
            LogSettingsCatalog::getCatalog(),
        );
    }
}
