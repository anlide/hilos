<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Settings;

use Hilos\Auth\AccountDeletion\AccountDeletionSettings;
use Hilos\Auth\StepUp\StepUpSettings;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;

/**
 * EcommerceShopSettingsCatalog - Project settings catalog for this demo.
 *
 * Declares the allowed setting keys, their types, and default values; the framework reads it back
 * through Hilos::$setting->catalog(). Keys present in the DB but absent here are treated as
 * orphans.
 *
 * The catalog carries only the three example keys: the settings and toast specs write them, and
 * no feature switched on here asks for a fragment of its own. The step-up list and the deletion
 * grace period answer their declared defaults when the catalog carries no key for them
 * ({@see StepUpSettings}, {@see AccountDeletionSettings}).
 *
 * @see SettingsCatalogConstants
 */
final class EcommerceShopSettingsCatalog implements CatalogProviderInterface
{
    /**
     * Returns the settings catalog for this demo.
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
