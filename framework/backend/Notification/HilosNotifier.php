<?php

declare(strict_types=1);

namespace Hilos\Notification;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\Notifications as ObjectNotifications;
use Hilos\Database\Object\Item\NotificationDelivery as ObjectNotificationDelivery;
use Hilos\Hilos;
use Hilos\Notification\DTO\NotificationCreatedSignalData;
use Hilos\Notification\DTO\NotificationReadSignalData;
use Hilos\Notification\Delivery\NotificationDispatcher;

/**
 * HilosNotifier - the emit seam of the durable notification model (HIL-102).
 *
 * The facade global {@see \Hilos\Hilos::$notify}. {@see emit()} writes a durable
 * notification row from the calling worker (the verification-service pattern: any
 * worker can call it directly, no owner agent) and best-effort fans a live in-app
 * signal to the recipient's connections. Persistence is authoritative; the live
 * fan is a convenience the unread COUNT recovers from when the recipient was
 * offline at emit time.
 *
 * Channel delivery (email, Telegram, push) is folded in through the
 * {@see NotificationDispatcher} (HIL-196): after the durable write, the dispatcher
 * fans the notification to every enabled channel — inserting a delivery row and
 * queueing the channel's delivery agent — deliberately not bottlenecked on one
 * owner. With no channel registered (the framework default) that fan is a no-op.
 */
class HilosNotifier
{
    /**
     * @param NotificationDispatcher $dispatcher Channel-delivery dispatcher folded into the emit seam
     */
    public function __construct(
        private readonly NotificationDispatcher $dispatcher = new NotificationDispatcher(),
    ) {
    }

    /**
     * Persists a notification, fans it live to the recipient's connections, and dispatches channels.
     *
     * @param NotificationDraft $draft The notification to persist and deliver
     * @return int The persisted notification id
     * @throws EmptyValueException When the draft type or title is empty
     * @throws DatabaseException When the notification cannot be persisted
     * @throws LogicException When the notifications object collection is unavailable
     */
    public function emit(NotificationDraft $draft): int
    {
        $severity = NotificationSeverity::isValid($draft->severity)
            ? $draft->severity
            : NotificationSeverity::INFO;

        $notification = $this->collection()->createFor(
            $draft->userId,
            $draft->type,
            $severity,
            $draft->title,
            $draft->body,
            $this->encodeData($draft->data),
        );

        $id = $notification->id;
        if ($id === null) {
            throw new DatabaseException('Notification insert did not assign an id');
        }

        $this->fan(
            $draft->userId,
            NotificationSignalName::CREATED,
            new NotificationCreatedSignalData(
                id: $id,
                userId: $draft->userId,
                type: $notification->type,
                severity: $notification->severity,
                title: $notification->title,
                body: $notification->body,
                data: $notification->decodedData(),
                readAt: $notification->readAt,
                createdAt: $notification->createdAt,
            ),
        );

        $this->dispatcher->dispatch($notification, $draft->channels);

        return $id;
    }

    /**
     * Fans a mark-read to the recipient's connections for multi-device badge sync.
     *
     * Called by the notification-center page (HIL-195) after a mark-read action
     * persists, so the recipient's other devices clear the same row (or all rows)
     * without a refetch. Passing {@see NotificationReadSignalData::ALL} signals a
     * mark-all-read.
     *
     * @param int $userId Recipient user id whose connections receive the signal
     * @param int|string $idOrAll Marked-read notification id, or the "all" sentinel
     */
    public function notifyRead(int $userId, int|string $idOrAll): void
    {
        $this->fan(
            $userId,
            NotificationSignalName::READ,
            new NotificationReadSignalData($idOrAll),
        );
    }

    /**
     * Re-queues a failed channel delivery for a fresh attempt (HIL-201).
     *
     * The emit-seam counterpart of {@see emit()} for the admin delivery journal: the
     * deliveries page validates the row is failed, then hands it here to reset and
     * re-dispatch through the same {@see NotificationDispatcher}. Best-effort — a row
     * whose channel or notification no longer exists is left unchanged.
     *
     * @param ObjectNotificationDelivery $delivery Loaded failed delivery row
     * @throws DatabaseException When the row reset fails
     */
    public function retryDelivery(ObjectNotificationDelivery $delivery): void
    {
        $this->dispatcher->requeue($delivery);
    }

    /**
     * Queues a server → client signal to a recipient's notification group.
     *
     * Best-effort: when the signal router is not initialized (e.g. a CLI context)
     * or the recipient has no subscribed connection, the signal simply reaches no
     * one — the durable row already carries the state.
     *
     * @param int $userId Recipient user id (resolves the group name)
     * @param string $signalName Signal name (see NotificationSignalName)
     * @param NotificationCreatedSignalData|NotificationReadSignalData $data Inner payload
     */
    private function fan(int $userId, string $signalName, mixed $data): void
    {
        Hilos::$sr?->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::WS_GROUP),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(
                data: $data,
                targetGroup: NotificationGroup::forUser($userId),
            ),
        );
    }

    /**
     * Encodes the draft's structured data for storage as a JSON string.
     *
     * @param ?array<string, mixed> $data Structured data, or null
     * @return ?string JSON string, or null when there is no data
     */
    private function encodeData(?array $data): ?string
    {
        if ($data === null || $data === []) {
            return null;
        }

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $encoded === false ? null : $encoded;
    }

    /**
     * Resolves the framework-owned notifications object collection.
     *
     * @return ObjectNotifications Notifications persistence primitives
     * @throws LogicException When the collection is missing or misconfigured
     */
    private function collection(): ObjectNotifications
    {
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::notifications);
        if (!$collection instanceof ObjectNotifications) {
            throw new LogicException('Notifications object collection is not configured');
        }

        return $collection;
    }
}
