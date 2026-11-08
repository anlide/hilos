<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

use Hilos\Core\Agent\AgentId;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Topology\AgentSignalRouteRegistry;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;

/** Resolves channel and saved delivery owners without reading a notification or recipient. */
final class DeliveryOwnerResolver
{
    /**
     * @param NotificationDeliveryIdentity $delivery Saved delivery coordinates
     * @return ?AgentId Current owner, or null when no unique descriptor and route agree
     * @throws EnvException When the descriptor's pool configuration is unreadable or invalid
     * @throws InvalidArgumentException When the saved key or pool width cannot be reduced
     */
    public static function forDelivery(NotificationDeliveryIdentity $delivery): ?AgentId
    {
        $descriptor = self::descriptorForChannel($delivery->channel);
        if ($descriptor === null) {
            return null;
        }

        $index = $descriptor->ownerIndexFor($delivery->routingKey ?? $delivery->notificationId);
        $agentType = self::forChannel($delivery->channel);
        if ($agentType === null
            || ($descriptor->isPooled() && ($index === null || $index < 1))
            || (!$descriptor->isPooled() && $index !== null)) {
            return null;
        }

        return new AgentId($agentType, $index === null ? null : (string)$index);
    }

    /**
     * Reads only descriptor and agent declarations, without reducing a delivery key.
     *
     * @param string $channel Registered channel name
     * @return ?string Owner type, or null when no unique descriptor and route agree
     */
    public static function forChannel(string $channel): ?string
    {
        $descriptor = self::descriptorForChannel($channel);
        if ($descriptor === null) {
            return null;
        }

        $signal = $descriptor->deliverSignalName();
        $agents = Hilos::appClass()::AGENTS;
        $agentType = AgentSignalRouteRegistry::routes($agents)[$signal] ?? null;
        if ($agentType === null) {
            return null;
        }
        $routeCount = 0;
        foreach ($agents as $type => $entry) {
            if (isset(AgentSignalRouteRegistry::routes([$type => $entry])[$signal])) {
                $routeCount++;
            }
        }
        if ($routeCount !== 1) {
            return null;
        }

        $owner = AgentId::fromId($agentType);
        $workerClass = AgentRegistry::workerClass($agents[$agentType]);
        if ($owner->index !== null || $workerClass === null) {
            return null;
        }
        $indexed = AgentRegistry::requiresIndex($agents[$agentType]);
        $indexField = AgentSignalRouteRegistry::indexFields([$agentType => $agents[$agentType]])[$signal] ?? null;
        if ($descriptor->isPooled() !== $indexed
            || $descriptor->indexField() !== $indexField
            || ($indexed && $indexField !== NotificationDeliverSignalData::shardKey)
            || (!$indexed && $indexField !== null)) {
            return null;
        }

        return $owner->type;
    }

    /**
     * @param string $channel Registered channel name
     * @return ?AbstractDeliveryChannel Descriptor with a unique name and delivery signal, or null
     */
    private static function descriptorForChannel(string $channel): ?AbstractDeliveryChannel
    {
        $descriptors = Hilos::notificationChannelRegistryClass()::all();
        $descriptor = $descriptors[$channel] ?? null;
        if ($descriptor === null || $descriptor->name() !== $channel || $channel === '') {
            return null;
        }

        $signal = $descriptor->deliverSignalName();
        if ($signal === '') {
            return null;
        }
        $matching = array_filter($descriptors, static fn (AbstractDeliveryChannel $other): bool =>
            $other->name() === $channel || $other->deliverSignalName() === $signal);

        return count($matching) === 1 ? $descriptor : null;
    }
}
