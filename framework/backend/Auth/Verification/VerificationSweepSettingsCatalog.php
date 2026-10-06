<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Environment\Exception\EnvException;

/**
 * Settings fragment to fold into a project's sign-in catalog (HIL-1163).
 * The default retention equals this node's send-count window (P-417).
 */
final class VerificationSweepSettingsCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Settings keyed by their catalog keys
     */
    public static function getCatalog(): array
    {
        try {
            $window = VerificationSweepSettings::sendWindowSeconds();
        } catch (EnvException) {
            // Metadata stays available before the environment has passed its boot checks.
            $window = VerificationSweepSettings::SEND_WINDOW_FALLBACK_SECONDS;
        }

        return [
            VerificationSweepSettings::RETENTION_SECONDS_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => $window,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => VerificationRetentionRule::class,
            ],
            VerificationSweepSettings::SWEEP_CRON_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => VerificationSweepSettings::DEFAULT_SWEEP_CRON,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => VerificationSweepCronRule::class,
            ],
        ];
    }
}
