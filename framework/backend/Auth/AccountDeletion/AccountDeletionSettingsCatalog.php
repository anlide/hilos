<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountDeletion;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;

/**
 * AccountDeletionSettingsCatalog - the framework settings-catalog fragment of account deletion (HIL-302).
 *
 * One key, the grace period ({@see AccountDeletionSettings}), naming its rule so every write
 * path refuses the same values with the same words. A project folds this into its own
 * catalog beside the other framework fragments.
 */
final class AccountDeletionSettingsCatalog implements CatalogProviderInterface
{
    /**
     * Builds the account deletion settings entries.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    public static function getCatalog(): array
    {
        return [
            AccountDeletionSettings::GRACE_DAYS_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => AccountDeletionSettings::DEFAULT_GRACE_DAYS,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => AccountDeletionGraceDaysRule::class,
            ],
        ];
    }
}
