<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Entity\Item\NotificationDelivery;
use Hilos\Notification\Delivery\ChannelSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Notification\Delivery\DeliveryLogSettingsCatalog;
use Hilos\Pages\Communications\AbstractHilosCommunicationsDeliveriesPage;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;

/**
 * Delivery of stored notifications over channels, on top of NOTIFICATIONS.
 *
 * Split from {@see HilosFeature::NOTIFICATIONS} because that is where the real projects part:
 * the chat demo delivers over mail, SMS and push, while the poll and tasks demos only store
 * notifications and show them in the interface. Storing without delivering is a whole,
 * working feature; delivering without storing is not, hence the dependency.
 *
 * No channel agent is required by name: which channels a project delivers over is its own
 * decision, and the project's {@see DeliveryChannelRegistry} subclass is what states it.
 * Requiring the registry override is therefore the requirement - it is the one place where
 * the answer exists.
 *
 * The line falls the same way for settings keys, and that is why the journal retention window is
 * required here rather than by {@see HilosFeature::NOTIFICATIONS} even though the agent that
 * prunes belongs to that feature: there is nothing to retain in a project with no delivery table.
 * Requiring it of a store-only project would put a window over rows that do not exist on its
 * settings screen and arm a daily DELETE against a table it never migrated.
 */
final class NotificationDeliveryFeature extends FeatureDefinition
{
    /**
     * @return HilosFeature Notification delivery feature case
     */
    public function feature(): HilosFeature
    {
        return HilosFeature::NOTIFICATION_DELIVERY;
    }

    /**
     * @return FeatureRequirements The deliveries page with its table binding, the channel registry,
     *     the channel and journal catalog fragments, and the delivery table
     */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredPages: [AbstractHilosCommunicationsDeliveriesPage::class],
            requiredTables: [HilosNotificationDeliveriesTable::class],
            requiredPageTables: [
                AbstractHilosCommunicationsDeliveriesPage::class => HilosNotificationDeliveriesTable::class,
            ],
            requiredCatalogConstant: 'NOTIFICATION_CHANNEL_REGISTRY',
            requiredCatalogFragments: [ChannelSettingsCatalog::class, DeliveryLogSettingsCatalog::class],
            requires: [HilosFeature::NOTIFICATIONS],
            requiredDbTables: [NotificationDelivery::_table],
        );
    }
}
