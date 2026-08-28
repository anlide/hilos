<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Groups\AbstractHilosNotificationsGroup;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\DTO\NotificationMarkAllReadPayloadDTO;
use Hilos\Notification\DTO\NotificationMarkReadPayloadDTO;
use Hilos\Notification\DTO\NotificationReadSignalData;
use Hilos\Notification\NotificationAction;
use Hilos\Notification\NotificationGroup;
use Hilos\Notification\NotificationSignalName;

/**
 * Base class for the framework notification-center page (HIL-195).
 *
 * The recipient-scoped consumer of the durable notification model (HIL-102). This
 * page is a pure action host — it declares no page subscription (no BROWSER): the
 * SubscriptionRegistry holds a single page per connection, so an always-on page
 * subscription would clobber the route page on every navigation. The live channel
 * is instead the per-user WebSocket group {@see NotificationGroup}
 * (`hilos_notifications:<userId>`), which the client joins with a `group_subscribe`
 * at connect and keeps for the connection's whole life; the recipient's other
 * devices stay in sync off the group signals
 * {@see NotificationSignalName::CREATED} / READ.
 *
 * The page hosts two write actions, both requiring an authenticated session
 * (closed by the page's AUTHENTICATED {@see self::ACCESS_LEVEL}, which gates
 * actions along with the subscription): {@see NotificationAction::MARK_READ} /
 * {@see NotificationAction::MARK_ALL_READ} route into the HIL-102 item/collection actions
 * and then fan a READ signal so the recipient's other devices sync. Every recipient is
 * resolved server-side from the connection, never the payload, so a client can only ever
 * read or mark its own notifications. A project activates the feature by extending this page
 * with a `SUBSCRIPTION_AGENT_TYPE` and registering it.
 *
 * The initial snapshot is NOT here: it is what the group answers a join with
 * ({@see AbstractHilosNotificationsGroup}), so the bell has it a frame earlier and stops
 * depending on the order two frames arrive in (HIL-721).
 */
abstract class AbstractHilosNotificationsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_NOTIFICATIONS;

    /**
     * Per-user surface, not an admin one: any signed-in user reads and marks
     * their own notifications. The level also closes the page's actions, so the
     * former per-action AUTH_ACTIONS list became redundant and was removed.
     */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;

    public const array ACTIONS = [
        NotificationAction::MARK_READ => NotificationMarkReadPayloadDTO::class,
        NotificationAction::MARK_ALL_READ => NotificationMarkAllReadPayloadDTO::class,
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

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
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
}
