<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

/** Declared channel owners, independent of the running roster and cluster placement. */
final readonly class DeliveryChannelOwnerSet
{
    /**
     * @param string $channel Registered channel name
     * @param string $agentType Delivery owner type
     * @param list<?string> $agentIndexes One-based pool indexes, or a singleton's null index
     */
    public function __construct(
        public string $channel,
        public string $agentType,
        public array $agentIndexes,
    ) {
    }
}
