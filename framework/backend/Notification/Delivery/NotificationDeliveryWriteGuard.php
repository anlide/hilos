<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

use Hilos\Constants\AgentConstants;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\DbWriterPartition;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;
use Hilos\Database\Object\Collection\NotificationDeliveries as ObjectNotificationDeliveries;
use Hilos\Database\Object\Item\NotificationDelivery as ObjectNotificationDelivery;
use Hilos\Database\Object\Item\Object_;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/** Fresh row coordinates supplement, and never widen, the ordinary DB grant. */
final class NotificationDeliveryWriteGuard
{
    private const array IDENTITY_COLUMNS = [
        EntityNotificationDelivery::id,
        EntityNotificationDelivery::notification_id,
        EntityNotificationDelivery::channel,
        EntityNotificationDelivery::routing_key,
    ];

    /**
     * @param ?string $agentId Claimant whose registered worker supplies the proof kind
     * @return ?DbWriterPartition Declared proof, null for ordinary and offline claimants
     */
    public static function partitionFor(?string $agentId): ?DbWriterPartition
    {
        if ($agentId === null) {
            return null;
        }

        $worker = AgentRegistry::workerClass(Hilos::appClass()::AGENTS[AgentId::fromId($agentId)->type] ?? null);

        return $worker === null ? null : $worker::DB_WRITER_PARTITION;
    }

    /**
     * All writers, including the transition writers, preserve the saved coordinates.
     * The changed-column check also rejects a dirty primary key pointing at another existing row.
     *
     * @param string $idString Delivery primary key supplied to the write door
     * @param TruthSourceOperation $operation Operation already admitted by the grant
     * @param ?Object_ $item Proposed row, mandatory for an update
     * @return NotificationDeliveryIdentity Fresh persisted coordinates, never a cached Object
     * @throws WriteNotAllowedException When the row is gone or its identity is being rewritten
     * @throws DatabaseException When the mounted fresh reader cannot query the database
     */
    public static function guardIdentity(
        string $idString,
        TruthSourceOperation $operation,
        ?Object_ $item,
    ): NotificationDeliveryIdentity {
        $id = (int)$idString;
        if ($id < 1 || (string)$id !== $idString) {
            throw new WriteNotAllowedException("Delivery '{$idString}' has no current owner: invalid delivery id.");
        }
        $deliveries = Hilos::$db?->mountedObjectCollection(HilosDbContext::notificationDeliveries);
        if (!$deliveries instanceof ObjectNotificationDeliveries) {
            throw new WriteNotAllowedException("Delivery '{$idString}' has no mounted owning collection.");
        }
        $saved = $deliveries->readIdentity($id);
        if ($saved === null) {
            throw new WriteNotAllowedException("Delivery '{$idString}' has no current owner: the saved row is missing.");
        }

        if (($operation === TruthSourceOperation::Update && $item === null)
            || ($item !== null && (
                !$item instanceof ObjectNotificationDelivery
                || array_intersect(self::IDENTITY_COLUMNS, $item->getChangedColumns()) !== []
                || $item->id !== $saved->deliveryId
                || $item->notificationId !== $saved->notificationId
                || $item->channel !== $saved->channel
                || $item->routingKey !== $saved->routingKey
            ))) {
            self::refuse($saved, 'the proposed row must preserve its saved identity');
        }

        return $saved;
    }

    /**
     * @param NotificationDeliveryIdentity $saved Fresh coordinates checked at this write door
     * @throws WriteNotAllowedException When the executing worker is not this row's registered current owner
     */
    public static function guardOwner(NotificationDeliveryIdentity $saved): void
    {
        $expected = self::ownerFor($saved);
        $actor = ExecutionContext::currentAgentId();
        $worker = $actor === null ? null
            : AgentRegistry::workerClass(Hilos::appClass()::AGENTS[AgentId::fromId($actor)->type] ?? null);
        if ($expected === null || $actor !== self::ownerId($expected)
            || $worker === null || !is_subclass_of($worker, AbstractDeliveryChannelAgent::class)
            || $worker::AGENT_TYPE !== $expected->type) {
            self::refuse($saved, 'the executing worker does not own this delivery');
        }

        $descriptors = Hilos::notificationChannelRegistryClass()::all();
        $matches = array_filter($descriptors, static fn (AbstractDeliveryChannel $descriptor): bool =>
            array_key_exists($descriptor->deliverSignalName(), $worker::AGENT_SIGNALS)
            || in_array($descriptor->deliverSignalName(), $worker::AGENT_SIGNALS, true));
        if (count($matches) !== 1 || array_key_first($matches) !== $saved->channel) {
            self::refuse($saved, 'the claimant has no unique delivery descriptor');
        }
    }

    /** Resolves only the saved channel/key; a malformed pool cannot confer write permission. */
    private static function ownerFor(NotificationDeliveryIdentity $saved): ?AgentId
    {
        try {
            return DeliveryOwnerResolver::forDelivery($saved);
        } catch (EnvException | InvalidArgumentException) {
            throw new WriteNotAllowedException("Delivery '{$saved->deliveryId}' has no valid current channel/shard owner.");
        }
    }

    /** Canonical execution address, including the current shard when pooled. */
    private static function ownerId(AgentId $owner): string
    {
        return $owner->type . ($owner->index === null ? '' : AgentConstants::ID_SEPARATOR . $owner->index);
    }

    /** Refusals disclose only the delivery id and its expected owner, never recipient data. */
    private static function refuse(NotificationDeliveryIdentity $saved, string $reason): never
    {
        $owner = self::ownerFor($saved);
        $expected = $owner === null ? 'no registered channel/shard owner' : self::ownerId($owner);
        throw new WriteNotAllowedException("Delivery '{$saved->deliveryId}' expects {$expected}: {$reason}.");
    }
}
