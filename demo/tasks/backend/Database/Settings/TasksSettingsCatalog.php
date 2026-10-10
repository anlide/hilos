<?php

declare(strict_types=1);

namespace Demo\Tasks\Database\Settings;

use Hilos\Core\Analytics\AnalyticsSettingsCatalog;
use Hilos\Auth\AccountDeletion\AccountDeletionSettingsCatalog;
use Hilos\Auth\Method\AuthMethodSettingsCatalog;
use Hilos\Auth\Method\PasskeyAddressPolicy;
use Hilos\Auth\OAuth\OAuthSettingsCatalog;
use Hilos\Auth\SecondFactor\SecondFactorSettingsCatalog;
use Hilos\Auth\StepUp\StepUpSettingsCatalog;
use Hilos\Auth\Throttle\AuthThrottleSettingsCatalog;
use Hilos\Auth\Verification\VerificationSweepSettingsCatalog;
use Hilos\Auth\Impersonation\ImpersonationSettingsCatalog;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Legal\LegalSettingsCatalog;
use Hilos\ProtectedMode\ProtectedModeSettingsCatalog;
use Hilos\Theme\ThemeSettingsCatalog;

/**
 * TasksSettingsCatalog - Project settings catalog for the tasks demo.
 *
 * Declares the allowed setting keys, their types, and default values; the
 * framework reads it back through Hilos::$setting->catalog(). Keys present in the
 * DB but absent here are treated as orphans. The demo ships the framework
 * example and theme keys — enough to exercise the settings admin feature end to end
 * without inventing project-specific configuration — plus the log keys the logging modes
 * screen writes, which would become orphans the moment they are saved if the
 * activated feature's own catalog were not merged in here.
 *
 * @see SettingsCatalogConstants
 * @see LogSettingsCatalog Keys of the logs feature this demo activates
 * @see AuthThrottleSettingsCatalog The grace of a silent throttle agent, required by the throttle feature
 */
final class TasksSettingsCatalog implements CatalogProviderInterface
{
    /**
     * Returns the settings catalog for the tasks demo.
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
            LegalSettingsCatalog::getCatalog(),
            OAuthSettingsCatalog::getCatalog(),
            AuthMethodSettingsCatalog::getCatalog(),
            SecondFactorSettingsCatalog::getCatalog(),
            StepUpSettingsCatalog::getCatalog(),
            ImpersonationSettingsCatalog::getCatalog(),
            AccountDeletionSettingsCatalog::getCatalog(),
            VerificationSweepSettingsCatalog::getCatalog(),
            AuthThrottleSettingsCatalog::getCatalog(),
            // Tasks opts in to accounts without an address for the passkey specs HIL-1324 moved here, as chat
            // does on the owner's word of 26.09.2026 (HIL-1106); kept on 09.10.2026, tasks switches everything on.
            [PasskeyAddressPolicy::SETTING_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => true,
            ]],
        );
    }
}
