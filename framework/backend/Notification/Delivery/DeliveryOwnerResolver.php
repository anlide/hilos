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

/** Resolves saved delivery coordinates without reading a notification or recipient. */
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
        $descriptors = Hilos::notificationChannelRegistryClass()::all();
        $descriptor = $descriptors[$delivery->channel] ?? null;
        if ($descriptor === null || $descriptor->name() !== $delivery->channel || $delivery->channel === '') {
            return null;
        }

        $signal = $descriptor->deliverSignalName();
        if ($signal === '') {
            return null;
        }
        $matching = array_filter($descriptors, static fn (AbstractDeliveryChannel $other): bool =>
            $other->name() === $delivery->channel || $other->deliverSignalName() === $signal);
        if (count($matching) !== 1) {
            return null;
        }

        $index = $descriptor->ownerIndexFor($delivery->routingKey ?? $delivery->notificationId);
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
            || ($indexed && ($index === null || $index < 1 || $indexField !== NotificationDeliverSignalData::shardKey))
            || (!$indexed && ($index !== null || $indexField !== null))) {
            return null;
        }

        return new AgentId($owner->type, $index === null ? null : (string)$index);
    }
}
