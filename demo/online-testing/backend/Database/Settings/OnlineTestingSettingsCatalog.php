<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Database\Settings;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Log\LogSettingsCatalog;

/**
 * Project settings catalog for the online-testing demo.
 *
 * The three example keys are exercised by settings and notification e2e coverage. The logs
 * section needs its own settings fragment to persist logging modes. Auth, OAuth and legal
 * fragments are absent because those settings features are not activated here; step-up and
 * account deletion use their framework defaults.
 *
 * @see SettingsCatalogConstants
 * @see LogSettingsCatalog
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
        );
    }
}
