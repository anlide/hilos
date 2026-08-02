<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\Notifications as ObjectNotifications;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\DTO\NotificationCreatedSignalData;
use Hilos\Notification\DTO\NotificationMarkAllReadPayloadDTO;
use Hilos\Notification\DTO\NotificationMarkReadPayloadDTO;
use Hilos\Notification\DTO\NotificationReadSignalData;
use Hilos\Notification\DTO\NotificationsSnapshotSignalData;
use Hilos\Notification\DTO\NotificationSyncPayloadDTO;
use Hilos\Notification\NotificationAction;

/**
 * Base class for the framework notification-center page (HIL-195).
 *
 * The recipient-scoped consumer of the durable notification model (HIL-102). This
 * page is a pure action host — it declares no page subscription (no BROWSER): the
 * SubscriptionRegistry holds a single page per connection, so an always-on page
 * subscription would clobber the route page on every navigation. The live channel
 * is instead the per-user WebSocket group {@see \Hilos\Notification\NotificationGroup}
 * (`hilos_notifications:<userId>`), which the client joins with a `group_subscribe`
 * at connect and keeps for the connection's whole life; the recipient's other
 * devices stay in sync off the group signals
 * {@see \Hilos\Notification\NotificationSignalName::CREATED} / READ.
 *
 * The page hosts three actions, all requiring an authenticated session
 * ({@see self::AUTH_ACTIONS}): {@see NotificationAction::SYNC} replies the initial
 * snapshot (the recent notifications plus the unread count) under
 * {@see HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_NOTIFICATIONS}, and
 * {@see NotificationAction::MARK_READ} / {@see NotificationAction::MARK_ALL_READ}
 * route into the HIL-102 item/collection actions and then fan a READ signal so the
 * recipient's other devices sync. Every recipient is resolved server-side from the
 * connection, never the payload, so a client can only ever read or mark its own
 * notifications. A project activates the feature by extending this page with a
 * `SUBSCRIPTION_AGENT_TYPE` and registering it.
 */
abstract class AbstractHilosNotificationsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_NOTIFICATIONS;

    /** Maximum notifications carried in the sync snapshot (the bell is recent-only). */
    public const int RECENT_LIMIT = 20;

    public const array ACTIONS = [
        NotificationAction::MARK_READ => NotificationMarkReadPayloadDTO::class,
        NotificationAction::MARK_ALL_READ => NotificationMarkAllReadPayloadDTO::class,
        NotificationAction::SYNC => NotificationSyncPayloadDTO::class,
    ];

    public const array AUTH_ACTIONS = [
        NotificationAction::MARK_READ,
        NotificationAction::MARK_ALL_READ,
        NotificationAction::SYNC,
    ];

    /**
     * Routes the notification-center actions to typed handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the action payload does not match the action name
     * @throws ItemNotFoundForUpdateException When the acting connection has no resolvable user
     * @throws ValidationException When the notification is missing or owned by another user
     * @throws LogicException When the notifications object collection is not configured
     * @throws DatabaseException When the snapshot list or unread count query fails
     * @throws HilosException When a routed mark-read mutation fails
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case NotificationAction::MARK_READ:
                if (!$dto instanceof NotificationMarkReadPayloadDTO) {
                    throw new InvalidActionPayloadException($action, NotificationMarkReadPayloadDTO::class, $dto);
                }
                $this->handleMarkRead($acceptKey, $dto);

                break;

            case NotificationAction::MARK_ALL_READ:
                if (!$dto instanceof NotificationMarkAllReadPayloadDTO) {
                    throw new InvalidActionPayloadException($action, NotificationMarkAllReadPayloadDTO::class, $dto);
                }
                $this->handleMarkAllRead($acceptKey);

                break;

            case NotificationAction::SYNC:
                if (!$dto instanceof NotificationSyncPayloadDTO) {
                    throw new InvalidActionPayloadException($action, NotificationSyncPayloadDTO::class, $dto);
                }
                $this->handleSync($acceptKey);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Replies the recipient's snapshot to the requesting connection.
     *
     * The recipient is resolved from the connection; the AUTHENTICATED guard on
     * the action already denied an anonymous session, so a missing user id here is
     * a defensive no-op.
     *
     * @param string $acceptKey Requesting connection accept key
     * @throws LogicException When the notifications object collection is not configured
     * @throws DatabaseException When the snapshot list or unread count query fails
     */
    private function handleSync(string $acceptKey): void
    {
        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($userId === null) {
            return;
        }

        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_NOTIFICATIONS,
            $acceptKey,
            $this->buildSnapshot($userId),
        );
    }

    /**
     * Marks one of the recipient's notifications read and fans the read to their devices.
     *
     * The row is resolved and ownership-checked against the connection's own user,
     * so a client can never mark another user's notification read.
     *
     * @param string $acceptKey Acting connection accept key
     * @param NotificationMarkReadPayloadDTO $dto Mark-read payload (notification id)
     * @throws ItemNotFoundForUpdateException When the connection has no resolvable user, or the row is not persisted
     * @throws ValidationException When the notification is missing or owned by another user
     * @throws HilosException When the mark-read query fails
     */
    private function handleMarkRead(string $acceptKey, NotificationMarkReadPayloadDTO $dto): void
    {
        $userId = $this->requireUserId($acceptKey);

        $notification = Hilos::$db->notifications[$dto->id] ?? null;
        if ($notification === null || $notification->userId !== $userId) {
            throw new ValidationException('Notification not found');
        }

        $notification->actions->markRead();

        Hilos::$notify->notifyRead($userId, $dto->id);
    }

    /**
     * Marks every unread notification of the recipient read and fans the mark-all.
     *
     * @param string $acceptKey Acting connection accept key
     * @throws ItemNotFoundForUpdateException When the acting connection has no resolvable user
     * @throws HilosException When the bulk mark-read query fails
     */
    private function handleMarkAllRead(string $acceptKey): void
    {
        $userId = $this->requireUserId($acceptKey);

        Hilos::$db->notifications->actions->markAllReadForUser($userId);

        Hilos::$notify->notifyRead($userId, NotificationReadSignalData::ALL);
    }

    /**
     * Resolves the acting connection's user id or fails the action.
     *
     * @param string $acceptKey Acting connection accept key
     * @return int Authenticated recipient user id
     * @throws ItemNotFoundForUpdateException When no user resolves for the connection
     */
    private function requireUserId(string $acceptKey): int
    {
        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($userId === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        return $userId;
    }

    /**
     * Builds the recipient's notification snapshot (recent rows + unread count).
     *
     * @param int $userId Recipient user id
     * @return NotificationsSnapshotSignalData Snapshot payload for the sync reply
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
            userId: $notification->userId ?? 0,
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
