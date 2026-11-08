<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Object\Collection\NotificationPreferences as ObjectNotificationPreferences;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Database\Object\Item\NotificationDelivery as ObjectNotificationDelivery;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\View\Collection\NotificationDeliveries;
use Hilos\Hilos;
use Hilos\HilosException;
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
     * @throws InvalidArgumentException When a channel's deliver signal cannot be named or queued
     * @throws SettingException When the settings accessor refuses a channel's enablement key
     * @throws DbCollectionNotReadableException When nothing here reads the deliveries or preferences collection, or its readiness is on its way
     * @throws HilosException When delivery creation, pool configuration or signal payload validation refuses the handoff
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

            $routingKey = $descriptor->routingKeyFor($userId, $notificationId);
            $delivery = $deliveries->actions->createPending($notificationId, $name, $routingKey);
            if ($delivery->id === null) {
                throw new DatabaseException('Notification delivery insert did not assign an id');
            }
            $this->queueDeliver(
                $descriptor,
                new NotificationDeliveryIdentity($delivery->id, $notificationId, $name, $routingKey),
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
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws DbCollectionNotReadableException When nothing here reads the deliveries collection, or its readiness is on its way
     * @throws HilosException When the row reset, pool configuration or signal payload validation refuses the handoff
     */
    public function requeue(ObjectNotificationDelivery $delivery): void
    {
        if ($delivery->id === null) {
            return;
        }
        $deliveries = $this->deliveries();
        $identity = $deliveries->readIdentity($delivery->id);
        if ($identity === null || $identity->channel === '') {
            return;
        }

        $descriptor = Hilos::notificationChannelRegistryClass()::get($identity->channel);
        if ($descriptor === null) {
            return;
        }

        if (EntityNotification::getById($identity->notificationId)?->user_id === null) {
            return;
        }
        $deliveries[$delivery->id]->actions->resetForRetry();
        $this->queueDeliver(
            $descriptor,
            $identity,
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
     * @throws DbCollectionNotReadableException When nothing here reads the preferences collection, or its readiness is on its way
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
     * @param NotificationDeliveryIdentity $delivery Saved delivery coordinates
     */
    private function queueDeliver(
        AbstractDeliveryChannel $descriptor,
        NotificationDeliveryIdentity $delivery,
    ): void {
        $owner = DeliveryOwnerResolver::forDelivery($delivery);
        if ($owner === null) {
            return;
        }

        Hilos::$sr?->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            signalName: new SignalName($descriptor->deliverSignalName()),
            signalData: new AgentSignalData(
                data: new NotificationDeliverSignalData(
                    $delivery->deliveryId,
                    $delivery->notificationId,
                    $delivery->channel,
                    $owner->index === null ? null : (int)$owner->index,
                ),
            ),
        );
    }

    /**
     * Resolves the framework-owned delivery collection and its action API.
     *
     * @return NotificationDeliveries Delivery collection
     * @throws DatabaseException When the DB context is unavailable
     */
    private function deliveries(): NotificationDeliveries
    {
        if (Hilos::$db === null) {
            throw new DatabaseException('Notification deliveries collection is not configured');
        }

        return Hilos::$db->notificationDeliveries;
    }
}
