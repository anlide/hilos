<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Settings\SettingsCatalogConstants;

/**
 * DeliveryLogSettingsCatalog - the framework settings-catalog fragment for the delivery journal (HIL-201).
 *
 * One entry: the retention window the channel owners apply to terminal rows, defaulting to
 * {@see DeliveryLogRetention::DEFAULT_RETENTION_DAYS} days. A project folds this into its own catalog
 * with `array_replace(...)`, the same way it folds the delivery-channel fragment, and the key
 * appears on the settings screen as an ordinary row.
 *
 * The catalog entry lives here because a fragment is a thing a feature can name:
 * {@see HilosFeature::NOTIFICATION_DELIVERY} lists this class among its required catalog fragments,
 * and startup refuses to boot a project that declared the feature and forgot the `array_replace()`.
 * The key constant stays on DeliveryLogRetention alongside its pure age policy.
 */
final class DeliveryLogSettingsCatalog implements CatalogProviderInterface
{
    /**
     * Builds the delivery-journal retention entry.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by the retention setting key
     */
    public static function getCatalog(): array
    {
        return [
            DeliveryLogRetention::RETENTION_SETTING_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => DeliveryLogRetention::DEFAULT_RETENTION_DAYS,
            ],
        ];
    }
}
