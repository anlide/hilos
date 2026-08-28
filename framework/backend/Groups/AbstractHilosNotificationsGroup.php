<?php

declare(strict_types=1);

namespace Hilos\Groups;

use Hilos\Core\Exception\LogicException;
use Hilos\Core\Group\AbstractGroup;
use Hilos\Core\Group\Config\GroupAddressSource;
use Hilos\Core\Group\Exception\GroupSubscriptionException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\Notifications as ObjectNotifications;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Hilos;
use Hilos\Notification\DTO\NotificationCreatedSignalData;
use Hilos\Notification\DTO\NotificationsSnapshotSignalData;
use Hilos\Notification\NotificationGroup;
use Hilos\Notification\NotificationSignalName;
use Hilos\Pages\AbstractHilosNotificationsPage;

/**
 * Base class for the framework notification group (HIL-721).
 *
 * The live channel of the notification center: the recipient's connections join it and the
 * bell in the application shell is fed from it, both by the {@see NotificationSignalName}
 * signals fanned to it later and by the snapshot this class answers the join with. The bell
 * sits in the shell rather than on a page, which is why the channel is a group and not a page
 * subscription - it survives navigation, and the registry holds one page per connection.
 *
 * The address is the user behind the connection, so the client never names the recipient and
 * cannot name anyone else's: the full name (`hilos_notifications:<userId>`) is built by the
 * server out of the identity it already judges page access with. That is what closes the leak
 * that existed while any connection could ask to join any recipient's channel.
 *
 * A project activates the feature by extending this group with a `SUBSCRIPTION_AGENT_TYPE` -
 * the agent that owns {@see AbstractHilosNotificationsPage} - and registering it in GROUPS.
 */
abstract class AbstractHilosNotificationsGroup extends AbstractGroup
{
    public const string GROUP = NotificationGroup::NAME;

    /** The recipient is whoever is behind the connection, and nobody may name another. */
    public const GroupAddressSource ADDRESS = GroupAddressSource::SESSION_USER;

    /** Maximum notifications carried in the join snapshot (the bell is recent-only). */
    public const int RECENT_LIMIT = 20;

    /**
     * Admits the connection.
     *
     * Unconditionally, and that is not a hole: the address already decided it. A
     * SESSION_USER group is named by the server out of the connection's own identity, so a
     * connection that got this far is asking about its own notifications and no others, and
     * one with no identity at all was refused before this method by the framework's own
     * resolution.
     *
     * @param string $acceptKey WebSocket accept key of the joining connection
     * @param array<string, string> $params Subscription params carried by the join frame
     */
    protected function assertSubscribable(string $acceptKey, array $params): void
    {
    }

    /**
     * Answers the join with the recipient's snapshot: recent rows plus the unread count.
     *
     * @param array<string, string> $params Subscription params carried by the join frame
     * @return SignalDataInterface Snapshot the bell replaces its store with
     * @throws GroupSubscriptionException When the recipient cannot be resolved off the connection
     * @throws LogicException When the notifications object collection is not configured
     * @throws DatabaseException When the snapshot list or the unread count query fails
     */
    protected function buildGroupPayload(array $params): SignalDataInterface
    {
        return $this->buildSnapshot($this->recipientId());
    }

    /**
     * Reads the recipient this group instance answers for.
     *
     * The user id is taken back off the full group name the framework built rather than read
     * from the identity seam a second time: the name IS the verdict, and two reads of a live
     * seam can disagree between the admission and the snapshot.
     *
     * @return int Recipient user id
     * @throws GroupSubscriptionException When the group name carries no recipient
     */
    private function recipientId(): int
    {
        $userId = NotificationGroup::userOf($this->fullGroupName());
        if ($userId === null) {
            throw new GroupSubscriptionException('Notification group carries no recipient');
        }

        return $userId;
    }

    /**
     * Builds the recipient's notification snapshot (recent rows + unread count).
     *
     * @param int $userId Recipient user id
     * @return NotificationsSnapshotSignalData Snapshot payload for the join answer
     * @throws LogicException When the notifications object collection is not configured
     * @throws DatabaseException When the list or unread count query fails
     */
    private function buildSnapshot(int $userId): NotificationsSnapshotSignalData
    {
        $collection = $this->notificationsCollection();

        $recent = [];
        foreach ($collection->listForUser($userId, self::RECENT_LIMIT) as $notification) {
            $recent[] = $this->rowFor($notification);
        }

        return new NotificationsSnapshotSignalData(
            recent: $recent,
            unreadCount: $collection->countUnreadForUser($userId),
        );
    }

    /**
     * Maps a persisted notification to its client row (the CREATED signal shape).
     *
     * @param ObjectNotification $notification Persisted notification
     * @return NotificationCreatedSignalData Client row for the snapshot
     */
    private function rowFor(ObjectNotification $notification): NotificationCreatedSignalData
    {
        return new NotificationCreatedSignalData(
            id: $notification->id ?? 0,
            userId: $notification->userId,
            type: $notification->type,
            severity: $notification->severity,
            title: $notification->title,
            body: $notification->body,
            data: $notification->decodedData(),
            readAt: $notification->readAt,
            createdAt: $notification->createdAt,
        );
    }

    /**
     * Resolves the framework-owned notifications object collection.
     *
     * @return ObjectNotifications Notifications persistence primitives
     * @throws LogicException When the collection is missing or misconfigured
     */
    private function notificationsCollection(): ObjectNotifications
    {
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::notifications);
        if (!$collection instanceof ObjectNotifications) {
            throw new LogicException('Notifications object collection is not configured');
        }

        return $collection;
    }
}
