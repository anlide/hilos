<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Notification\DeliveryLogPruner;

/**
 * DeliveryLogSettingsCatalog - the framework settings-catalog fragment for the delivery journal (HIL-201).
 *
 * One entry: the retention window {@see DeliveryLogPruner} prunes the journal to, defaulting to
 * {@see DeliveryLogPruner::DEFAULT_RETENTION_DAYS} days. A project folds this into its own catalog
 * with `array_replace(...)`, the same way it folds the delivery-channel fragment, and the key
 * appears on the settings screen as an ordinary row.
 *
 * The key lives here rather than on the pruner because a fragment is a thing a feature can name:
 * {@see HilosFeature::NOTIFICATION_DELIVERY} lists this class among its required catalog fragments,
 * and startup refuses to boot a project that declared the feature and forgot the `array_replace()`.
 * The key constant itself stays on the pruner - that is what the agent reads to prune with.
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
            DeliveryLogPruner::RETENTION_SETTING_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => DeliveryLogPruner::DEFAULT_RETENTION_DAYS,
            ],
        ];
    }
}
