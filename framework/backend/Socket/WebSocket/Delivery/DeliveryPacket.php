<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\Delivery;

/** One complete WebSocket text message, with the neighbors that survive a scope change. */
final readonly class DeliveryPacket
{
    /**
     * @param string $text Packed JSON to send while its scope is current
     * @param ?string $deliveryId Socket token protecting this packet, if scoped
     * @param ?string $unscopedText Alternate JSON containing just the unscoped neighbors
     * @param bool $recovery Whether the token authorizes an already accepted recovery control
     */
    public function __construct(
        public string $text,
        public ?string $deliveryId = null,
        public ?string $unscopedText = null,
        public bool $recovery = false,
    ) {
    }
}
