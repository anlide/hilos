<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Entity\Item\Notification;
use Hilos\Database\Entity\Item\NotificationPreference;
use Hilos\Groups\AbstractHilosNotificationsGroup;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Pages\AbstractHilosNotificationsPage;

/**
 * Durable notifications: storage, per-user preferences and the notifications page.
 *
 * The library agent is required first because it is the only writer (HIL-771): a project that
 * declared the feature and registered no {@see AbstractNotificationsLibraryAgent} has tables
 * nobody owns, so every emit reaches a frame that routes nowhere. It goes in `requiredAgents`
 * rather than `requiredSharedAgents` because it exists for this feature and no other - a
 * project registering it without declaring notifications is as much a gap as the reverse.
 *
 * Storage is what the feature is: the preference table is required alongside the notification
 * table because the dispatcher reads a recipient's preferences before it stores anything, so a
 * project that migrated one and not the other has notifications that fail on the first send
 * rather than a feature it can see is missing.
 *
 * The group is required beside the page because the bell lives on neither: it is fed by the
 * per-user group, and a project that registered the page and forgot the group would have a
 * join that reaches no owner and a bell that never fills - which is exactly the state the two
 * demos without a GROUPS constant were in before this requirement existed.
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
     * @return FeatureRequirements The library agent, the notifications page and group, and the notification and preference tables
     */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredAgents: [HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY],
            requiredPages: [AbstractHilosNotificationsPage::class],
            requiredGroups: [AbstractHilosNotificationsGroup::class],
            requiredDbTables: [Notification::_table, NotificationPreference::_table],
        );
    }
}
