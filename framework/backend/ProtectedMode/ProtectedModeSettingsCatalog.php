<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;

/**
 * A manual-window restart is routine by default: the watchdog logs it without mailing an alert.
 * Turning the setting off gives it the same restored-from-disk alarm as a restore freeze.
 */
final class ProtectedModeSettingsCatalog implements CatalogProviderInterface
{
    public const string MANUAL_RESTART_IS_NORMAL = 'protected_mode.manual_maintenance.restart_is_normal';
    public const bool DEFAULT_MANUAL_RESTART_IS_NORMAL = true;

    /**
     * @return array<string, array<string, mixed>> Settings keyed by their catalog keys
     */
    public static function getCatalog(): array
    {
        return [
            self::MANUAL_RESTART_IS_NORMAL => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => self::DEFAULT_MANUAL_RESTART_IS_NORMAL,
            ],
        ];
    }
}
