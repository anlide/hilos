<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Entity\Item\Notification;
use Hilos\Database\Entity\Item\NotificationPreference;
use Hilos\Pages\AbstractHilosNotificationsPage;

/**
 * Durable notifications: storage, per-user preferences and the notifications page.
 *
 * Storage is what the feature is: the preference table is required alongside the notification
 * table because the dispatcher reads a recipient's preferences before it stores anything, so a
 * project that migrated one and not the other has notifications that fail on the first send
 * rather than a feature it can see is missing.
 */
final class NotificationsFeature extends FeatureDefinition
{
    /**
     * @return HilosFeature Notifications feature case
     */
    public function feature(): HilosFeature
    {
        return HilosFeature::NOTIFICATIONS;
    }

    /**
     * @return FeatureRequirements The notifications page and the notification and preference tables
     */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredPages: [AbstractHilosNotificationsPage::class],
            requiredDbTables: [Notification::_table, NotificationPreference::_table],
        );
    }
}
