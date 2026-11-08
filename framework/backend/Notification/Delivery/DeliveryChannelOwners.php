<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/** Derives a channel's owner set from its registered route and current pool width. */
final class DeliveryChannelOwners
{
    /**
     * Runs on the master loop without reading settings, recipients or transport configuration.
     *
     * @param string $channel Registered channel name
     * @return DeliveryChannelOwnerSet Current declared owners
     * @throws InvalidTopologyException When the channel or owner route is missing, ambiguous or inconsistent, or the pool width is not positive
     * @throws EnvException When the descriptor's pool configuration cannot be read
     */
    public static function of(string $channel): DeliveryChannelOwnerSet
    {
        $descriptor = Hilos::notificationChannelRegistryClass()::get($channel);
        $agentType = DeliveryOwnerResolver::forChannel($channel);
        if ($descriptor === null || $agentType === null) {
            throw new InvalidTopologyException("Notification channel '{$channel}' has no unique delivery owner route");
        }

        $agentIndexes = [null];
        if ($descriptor->isPooled()) {
            $poolSize = $descriptor->poolSize();
            if ($poolSize < 1) {
                throw new InvalidTopologyException("Notification channel '{$channel}' must declare a positive delivery pool width");
            }

            $agentIndexes = [];
            for ($index = 1; $index <= $poolSize; $index++) {
                $agentIndexes[] = (string)$index;
            }
        }

        return new DeliveryChannelOwnerSet($channel, $agentType, $agentIndexes);
    }
}
