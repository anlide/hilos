<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Database\Settings;

use Demo\HilosWebsite\Notification\HilosWebsiteDeliveryChannelRegistry;
use Hilos\Auth\Throttle\AuthThrottleSettingsCatalog;
use Hilos\Core\Analytics\AnalyticsSettingsCatalog;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Feature\Definition\AnalyticsFeature;
use Hilos\Core\Feature\Definition\AuthThrottleFeature;
use Hilos\Core\Feature\Definition\NotificationDeliveryFeature;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\ChannelSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Notification\Delivery\DeliveryLogSettingsCatalog;
use Hilos\Notification\Delivery\NotificationDispatcher;

/**
 * HilosWebsiteSettingsCatalog - Project settings catalog for the hilos-website demo.
 *
 * Declares the allowed setting keys, their types, and default values; the framework reads it back
 * through Hilos::$setting->catalog(). Keys present in the DB but absent here are treated as
 * orphans.
 *
 * The catalog carries what an activated feature requires and nothing more: Analytics, the auth
 * throttle and notification delivery refuse to start without their fragments. The site has no spec
 * that writes a setting, so the framework example keys are left out, and so are the theme keys -
 * the site has no Appearance section, and the theme falls back to its declared defaults.
 *
 * The email channel is switched on by default, as in every demo. The switch gates only the
 * notifications {@see NotificationDispatcher} sends - the site sends none, and its codes leave past
 * it - but with it off the head of the delivery journal would call the channel disabled.
 *
 * @see SettingsCatalogConstants
 * @see AnalyticsFeature The feature whose required fragment the Analytics keys are
 * @see AuthThrottleFeature The feature whose required fragment the throttle grace key is
 * @see NotificationDeliveryFeature The feature whose required fragments the channel and journal keys are
 */
final class HilosWebsiteSettingsCatalog implements CatalogProviderInterface
{
    /**
     * Returns the settings catalog for the hilos-website demo.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by setting name
     */
    public static function getCatalog(): array
    {
        return array_replace(
            ChannelSettingsCatalog::entriesFor(HilosWebsiteDeliveryChannelRegistry::all()),
            [
                DeliveryChannelSettings::enabledKey(MailDeliveryChannel::NAME) => [
                    SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                    SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                    SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => true,
                ],
            ],
            DeliveryLogSettingsCatalog::getCatalog(),
            AnalyticsSettingsCatalog::getCatalog(),
            AuthThrottleSettingsCatalog::getCatalog(),
        );
    }
}
