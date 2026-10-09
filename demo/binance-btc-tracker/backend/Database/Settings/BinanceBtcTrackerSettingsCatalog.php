<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Database\Settings;

use Demo\BinanceBtcTracker\Notification\BinanceBtcTrackerDeliveryChannelRegistry;
use Hilos\Core\Analytics\AnalyticsSettingsCatalog;
use Hilos\Auth\AccountDeletion\AccountDeletionSettings;
use Hilos\Auth\StepUp\StepUpSettings;
use Hilos\Auth\Throttle\AuthThrottleSettingsCatalog;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Feature\Definition\AuthThrottleFeature;
use Hilos\Core\Feature\Definition\LogsFeature;
use Hilos\Core\Feature\Definition\NotificationDeliveryFeature;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Notification\Delivery\ChannelSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryLogSettingsCatalog;
use Hilos\Theme\ThemeSettingsCatalog;

/**
 * BinanceBtcTrackerSettingsCatalog - Project settings catalog for the binance-btc-tracker demo.
 *
 * Declares the allowed setting keys, their types, and default values; the framework reads it back
 * through Hilos::$setting->catalog(). Keys present in the DB but absent here are treated as
 * orphans.
 *
 * The catalog is deliberately narrow: it carries what an activated feature requires, the
 * framework example keys, and the shared theme settings. Three features refuse to start without
 * their fragment - the logs section needs the rotation thresholds and the logging modes its
 * screens write, notification delivery needs one block per registered channel and the
 * delivery-journal keys, and the auth throttle needs the grace it gives its agent's silence. The
 * three example keys are what the settings and toast specs write, so no spec has to invent a
 * project-specific setting. The sign-in fragments are left out on purpose: no spec of this demo
 * reads one, and the step-up list and the deletion grace period answer their declared defaults
 * when the catalog carries no key for them ({@see StepUpSettings},
 * {@see AccountDeletionSettings}).
 *
 * @see SettingsCatalogConstants
 * @see LogsFeature The feature whose required fragment the log keys are
 * @see NotificationDeliveryFeature The feature whose required fragments the channel and journal keys are
 * @see AuthThrottleFeature The feature whose required fragment the throttle grace key is
 * @see LogSettingsCatalog Keys of the logs feature this demo activates
 */
final class BinanceBtcTrackerSettingsCatalog implements CatalogProviderInterface
{
    /**
     * Returns the settings catalog for the binance-btc-tracker demo.
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
            ChannelSettingsCatalog::entriesFor(BinanceBtcTrackerDeliveryChannelRegistry::all()),
            DeliveryLogSettingsCatalog::getCatalog(),
            LogSettingsCatalog::getCatalog(),
            ThemeSettingsCatalog::getCatalog(),
            AnalyticsSettingsCatalog::getCatalog(),
            AuthThrottleSettingsCatalog::getCatalog(),
        );
    }
}
