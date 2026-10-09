<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Settings;

use Hilos\Core\Analytics\AnalyticsSettingsCatalog;
use Hilos\Auth\AccountDeletion\AccountDeletionSettings;
use Hilos\Auth\StepUp\StepUpSettings;
use Hilos\Auth\Throttle\AuthThrottleSettingsCatalog;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Theme\ThemeSettingsCatalog;

/**
 * EcommerceShopSettingsCatalog - Project settings catalog for this demo.
 *
 * Declares the allowed setting keys, their types, and default values; the framework reads it back
 * through Hilos::$setting->catalog(). Keys present in the DB but absent here are treated as
 * orphans.
 *
 * The catalog carries the three example keys, the shared theme settings and the grace of a
 * silent throttle agent, which the auth throttle feature requires. The settings and toast
 * specs write the example keys. The step-up list and the deletion grace period answer their
 * declared defaults when the catalog carries no key for them ({@see StepUpSettings},
 * {@see AccountDeletionSettings}).
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
        ], ThemeSettingsCatalog::getCatalog(), AnalyticsSettingsCatalog::getCatalog(), AuthThrottleSettingsCatalog::getCatalog());
    }
}
