<?php

declare(strict_types=1);

namespace Hilos\Database\Settings;

use Hilos\Core\Catalog\CatalogProviderInterface;

/**
 * SettingsCatalogStub - Stub-example of settings catalog.
 *
 * Project copies or extends this to define its own catalog.
 * Use SettingsCatalogConstants for all keys and types.
 *
 * @see SettingsCatalogConstants
 */
final class SettingsCatalogStub implements CatalogProviderInterface
{
    /**
     * Returns stub catalog array for example reference.
     *
     * @return array<string, array{type: string, default_value: mixed}>
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
            SettingsCatalogConstants::STUB_KEY_CHAT_BOT_TIMEOUT_SEC => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_FLOAT,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 90.0,
            ],
            SettingsCatalogConstants::STUB_KEY_CHAT_MODERATION_TIMEOUT_SEC => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_FLOAT,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 90.0,
            ],
        ];
    }
}
