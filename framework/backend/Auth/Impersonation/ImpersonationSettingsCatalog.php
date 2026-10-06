<?php

declare(strict_types=1);

namespace Hilos\Auth\Impersonation;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;

/**
 * ImpersonationSettingsCatalog - the framework settings-catalog fragment of impersonation (HIL-1170).
 *
 * Seven keys ({@see ImpersonationSettings}): six yes-or-no switches and the scope, which names its
 * rule so every write path - the impersonation administration screen, the general settings table,
 * a preset - refuses the same values with the same words. A project folds this into its own
 * catalog beside the other framework fragments; one that does not lives on the defaults.
 */
final class ImpersonationSettingsCatalog implements CatalogProviderInterface
{
    /**
     * Builds the impersonation settings entries.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    public static function getCatalog(): array
    {
        return [
            ImpersonationSettings::ALLOWED_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => ImpersonationSettings::DEFAULT_ALLOWED,
            ],
            ImpersonationSettings::SCOPE_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => ImpersonationSettings::DEFAULT_SCOPE,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => ImpersonationScopeRule::class,
            ],
            ImpersonationSettings::ACCOUNT_ACCESS_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => ImpersonationSettings::DEFAULT_ACCOUNT_ACCESS,
            ],
            ImpersonationSettings::CARRY_ADMIN_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => ImpersonationSettings::DEFAULT_CARRY_ADMIN,
            ],
            ImpersonationSettings::BLOCKED_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => ImpersonationSettings::DEFAULT_BLOCKED,
            ],
            ImpersonationSettings::FROZEN_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => ImpersonationSettings::DEFAULT_FROZEN,
            ],
            ImpersonationSettings::EQUAL_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => ImpersonationSettings::DEFAULT_EQUAL,
            ],
        ];
    }
}
