<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Database\Settings;

use Demo\FileGalleryManager\Notification\FileGalleryManagerDeliveryChannelRegistry;
use Hilos\Auth\AccountDeletion\AccountDeletionSettingsCatalog;
use Hilos\Auth\Impersonation\ImpersonationSettingsCatalog;
use Hilos\Auth\Method\AuthMethodSettingsCatalog;
use Hilos\Auth\SecondFactor\SecondFactorSettingsCatalog;
use Hilos\Auth\StepUp\StepUpSettingsCatalog;
use Hilos\Auth\Throttle\AuthThrottleSettingsCatalog;
use Hilos\Auth\Verification\VerificationSweepSettingsCatalog;
use Hilos\Core\Analytics\AnalyticsSettingsCatalog;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Feature\Definition\AnalyticsFeature;
use Hilos\Core\Feature\Definition\AuthThrottleFeature;
use Hilos\Core\Feature\Definition\LogsFeature;
use Hilos\Core\Feature\Definition\NotificationDeliveryFeature;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Legal\LegalSettingsCatalog;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\ChannelSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Notification\Delivery\DeliveryLogSettingsCatalog;
use Hilos\Theme\ThemeSettingsCatalog;

/**
 * FileGalleryManagerSettingsCatalog - Project settings catalog for the file-gallery-manager demo.
 *
 * Declares the allowed setting keys, their types, and default values; the framework reads it back
 * through Hilos::$setting->catalog(). Keys present in the DB but absent here are treated as
 * orphans.
 *
 * The catalog carries what the activated features bring and nothing for a product this demo does
 * not have. Four features refuse to start without their fragment - the logs section needs the
 * rotation thresholds and the logging modes its screens write, notification delivery needs one
 * block per registered channel and the delivery-journal keys, analytics needs the journal
 * ceiling, and the auth throttle needs the grace it gives its agent's silence. The sign-in,
 * second-factor, step-up, impersonation, account-deletion, verification-sweep and legal keys are
 * the account policies an administrator edits on the settings screen, and the theme keys are the
 * shared appearance defaults. The three example keys are framework keys that every demo
 * carries, so the catalog invents no project-specific setting of its own.
 *
 * @see SettingsCatalogConstants
 * @see LogsFeature The feature whose required fragment the log keys are
 * @see NotificationDeliveryFeature The feature whose required fragments the channel and journal keys are
 * @see AnalyticsFeature The feature whose required fragment the journal ceiling is
 * @see AuthThrottleFeature The feature whose required fragment the throttle grace key is
 */
final class FileGalleryManagerSettingsCatalog implements CatalogProviderInterface
{
    /**
     * Returns the settings catalog for the file-gallery-manager demo.
     *
     * @return array<string, array<string, mixed>> Setting definitions and defaults
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
            ChannelSettingsCatalog::entriesFor(FileGalleryManagerDeliveryChannelRegistry::all()),
            [
                DeliveryChannelSettings::enabledKey(MailDeliveryChannel::NAME) => [
                    SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                    SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                    SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => true,
                ],
            ],
            DeliveryLogSettingsCatalog::getCatalog(),
            LogSettingsCatalog::getCatalog(),
            ThemeSettingsCatalog::getCatalog(),
            AnalyticsSettingsCatalog::getCatalog(),
            LegalSettingsCatalog::getCatalog(),
            AuthMethodSettingsCatalog::getCatalog(),
            SecondFactorSettingsCatalog::getCatalog(),
            StepUpSettingsCatalog::getCatalog(),
            ImpersonationSettingsCatalog::getCatalog(),
            AccountDeletionSettingsCatalog::getCatalog(),
            VerificationSweepSettingsCatalog::getCatalog(),
            AuthThrottleSettingsCatalog::getCatalog(),
        );
    }
}
