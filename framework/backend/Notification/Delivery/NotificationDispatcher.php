<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Object\Collection\NotificationDeliveries as ObjectNotificationDeliveries;
use Hilos\Database\Object\Collection\NotificationPreferences as ObjectNotificationPreferences;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Database\Object\Item\NotificationDelivery as ObjectNotificationDelivery;
use Hilos\Hilos;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Notification\HilosNotifier;
use Hilos\Notification\NotificationTypeRegistry;

/**
 * NotificationDispatcher - fans a persisted notification to its enabled channels (HIL-196).
 *
 * The delivery half of the emit seam: {@see HilosNotifier::emit()}
 * persists the notification and its live in-app signal, then hands the row here.
 * For every channel in the project's {@see DeliveryChannelRegistry} the dispatcher
 * applies the resolve policy — narrowed by the draft's optional channel list,
 * globally enabled (HIL-200 setting), a per-user-preference seam (HIL-485) that a
 * mandatory type ({@see NotificationTypeRegistry}) bypasses, and
 * a resolvable address — and for each survivor inserts a `pending`
 * hilos_notification_delivery row and queues the channel's deliver agent signal.
 *
 * Queue-only and non-blocking: the actual send happens off in the channel's
 * delivery agent ({@see AbstractDeliveryChannelAgent}), never here. With an empty
 * registry (the framework default) the dispatch is a no-op.
 */
class NotificationDispatcher
{
    /**
     * Fans a persisted notification to every channel that survives the resolve policy.
     *
     * @param ObjectNotification $notification The persisted notification to deliver
     * @param ?list<string> $channels Draft channel narrowing: null delivers to all enabled channels; a non-empty list restricts to the named channels
     * @throws DatabaseException When a delivery row cannot be persisted
     */
    public function dispatch(ObjectNotification $notification, ?array $channels): void
    {
        $notificationId = $notification->id;
        $userId = $notification->userId;
        if ($notificationId === null) {
            return;
        }

        $registry = Hilos::notificationChannelRegistryClass();
        $descriptors = $registry::all();
        if ($descriptors === []) {
            return;
        }

        $mandatory = Hilos::notificationTypeRegistryClass()::isMandatory($notification->type);
        $deliveries = $this->deliveries();

        foreach ($descriptors as $name => $descriptor) {
            if ($channels !== null && !in_array($name, $channels, true)) {
                continue;
            }
            if (!$this->isChannelEnabled($descriptor)) {
                continue;
            }
            if (!$mandatory && !$this->passesUserPreferences($userId, $name)) {
                continue;
            }

            $address = $descriptor->resolveAddress($userId);
            if ($address === null) {
                continue;
            }

            $deliveries->createPending($notificationId, $name);
            $this->queueDeliver(
                $descriptor,
                $notificationId,
                $name,
                $descriptor->shardKeyFor($userId, $notificationId),
            );
        }
    }

    /**
     * Re-queues a failed delivery for a fresh channel attempt (HIL-201).
     *
     * The admin retry mechanic: the row is reset to pending with zero attempts and
     * the channel's deliver signal is queued again, exactly as an original dispatch
     * would. No-op (the row is left as-is) when it cannot be delivered again — the
     * channel is no longer registered or the underlying notification is gone.
     *
     * @param ObjectNotificationDelivery $delivery Loaded failed delivery row
     * @throws DatabaseException When the row reset fails
     */
    public function requeue(ObjectNotificationDelivery $delivery): void
    {
        $notificationId = $delivery->notificationId;
        $channel = $delivery->channel;
        if ($channel === '') {
            return;
        }

        $descriptor = Hilos::notificationChannelRegistryClass()::get($channel);
        if ($descriptor === null) {
            return;
        }

        $notification = EntityNotification::getById($notificationId);
        $userId = $notification?->user_id;
        if ($userId === null) {
            return;
        }

        $this->deliveries()->resetForRetry($delivery);
        $this->queueDeliver(
            $descriptor,
            $notificationId,
            $channel,
            $descriptor->shardKeyFor($userId, $notificationId),
        );
    }

    /**
     * Whether a recipient's per-user preferences allow a non-mandatory channel (HIL-485).
     *
     * Reads the sparse opt-out table: a channel is allowed unless the recipient has
     * a muted row for it. A mandatory notification type never reaches this check.
     * When the preference collection is not configured (a project that activates
     * channels but not the hilos_notification_preference table) the channel is
     * allowed by default, so preferences are strictly additive.
     *
     * @param int $userId Recipient user id
     * @param string $channel Channel name
     * @return bool True when the recipient allows the channel
     * @throws DatabaseException When the preference lookup query fails
     */
    protected function passesUserPreferences(int $userId, string $channel): bool
    {
        $preferences = Hilos::$db?->getObjectCollection(HilosDbContext::notificationPreferences);
        if (!$preferences instanceof ObjectNotificationPreferences) {
            return true;
        }

        return $preferences->isAllowed($userId, $channel);
    }

    /**
     * Whether a channel is globally enabled by its settings switch (HIL-200).
     *
     * A channel whose enablement key is not in the settings catalog is treated as
     * disabled, so an unconfigured channel never delivers by accident.
     *
     * @param AbstractDeliveryChannel $descriptor Channel descriptor
     * @return bool True when the channel's enablement setting is present and true
     */
    private function isChannelEnabled(AbstractDeliveryChannel $descriptor): bool
    {
        $key = $descriptor->enabledSettingKey();
        if (Hilos::$setting === null || !isset(Hilos::$setting[$key])) {
            return false;
        }

        return Hilos::$setting[$key]->bool();
    }

    /**
     * Queues the channel's deliver agent signal for one delivery.
     *
     * The signal name and (for a pooled channel) the shard key are the channel's;
     * the concrete signal → agent-type route lives in the channel leaf's
     * AGENT_SIGNALS, so with no channel registered the router simply drops it.
     *
     * @param AbstractDeliveryChannel $descriptor Channel descriptor
     * @param int $notificationId Notification id being delivered
     * @param string $channel Channel name
     * @param ?int $shardKey Pool shard key for an indexed channel, or null for a singleton
     */
    private function queueDeliver(
        AbstractDeliveryChannel $descriptor,
        int $notificationId,
        string $channel,
        ?int $shardKey,
    ): void {
        Hilos::$sr?->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            signalName: new SignalName($descriptor->deliverSignalName()),
            signalData: new AgentSignalData(
                data: new NotificationDeliverSignalData($notificationId, $channel, $shardKey),
            ),
        );
    }

    /**
     * Resolves the framework-owned notification-deliveries object collection.
     *
     * @return ObjectNotificationDeliveries Delivery persistence primitives
     * @throws DatabaseException When the deliveries object collection is unavailable
     */
    private function deliveries(): ObjectNotificationDeliveries
    {
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::notificationDeliveries);
        if (!$collection instanceof ObjectNotificationDeliveries) {
            throw new DatabaseException('Notification deliveries object collection is not configured');
        }

        return $collection;
    }
}
