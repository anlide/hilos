<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Database\Settings;

use Hilos\Core\Analytics\AnalyticsSettingsCatalog;
use Hilos\Auth\Throttle\AuthThrottleSettingsCatalog;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Log\LogSettingsCatalog;
use Hilos\ProtectedMode\ProtectedModeSettingsCatalog;
use Hilos\Theme\ThemeSettingsCatalog;

/**
 * Project settings catalog for the online-testing demo.
 *
 * The three example keys are exercised by settings and notification e2e coverage. The catalog
 * also carries shared theme settings, the logs fragment needed to persist logging modes, and
 * the auth throttle fragment its feature requires. Auth, OAuth and legal fragments are absent
 * because those settings features are not activated here; step-up and account deletion use
 * their framework defaults.
 *
 * @see SettingsCatalogConstants
 * @see LogSettingsCatalog
 * @see AuthThrottleSettingsCatalog
 */
final class OnlineTestingSettingsCatalog implements CatalogProviderInterface
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
        ],
            LogSettingsCatalog::getCatalog(),
            ThemeSettingsCatalog::getCatalog(),
            AnalyticsSettingsCatalog::getCatalog(),
            ProtectedModeSettingsCatalog::getCatalog(),
            AuthThrottleSettingsCatalog::getCatalog(),
        );
    }
}
