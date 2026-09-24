<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;

/**
 * SecondFactorSettingsCatalog - the framework settings-catalog fragment of the second factor (HIL-494).
 *
 * Six keys ({@see SecondFactorSettings}), each naming its rule so every write path - the
 * two-factor administration screen, the general settings table, a preset - refuses the same
 * values with the same words. A project folds this into its own catalog beside the other
 * framework fragments.
 */
final class SecondFactorSettingsCatalog implements CatalogProviderInterface
{
    /**
     * Builds the second-factor settings entries.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    public static function getCatalog(): array
    {
        return [
            SecondFactorSettings::REQUIRED_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => SecondFactorSettings::REQUIRED_NONE,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => SecondFactorRequiredRule::class,
            ],
            SecondFactorSettings::TRUST_DAYS_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => SecondFactorSettings::DEFAULT_TRUST_DAYS,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => SecondFactorTrustDaysRule::class,
            ],
            SecondFactorSettings::BACKUP_CODES_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => SecondFactorSettings::DEFAULT_BACKUP_CODES,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => SecondFactorBackupCodesRule::class,
            ],
            SecondFactorSettings::RESET_WAIT_DEFAULT_DAYS_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => SecondFactorSettings::DEFAULT_RESET_WAIT_DAYS,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => SecondFactorResetWaitDefaultRule::class,
            ],
            SecondFactorSettings::RESET_WAIT_MIN_DAYS_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => SecondFactorSettings::DEFAULT_RESET_WAIT_MIN_DAYS,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => SecondFactorResetWaitMinRule::class,
            ],
            SecondFactorSettings::RESET_WAIT_MAX_DAYS_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => SecondFactorSettings::DEFAULT_RESET_WAIT_MAX_DAYS,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => SecondFactorResetWaitMaxRule::class,
            ],
        ];
    }
}
