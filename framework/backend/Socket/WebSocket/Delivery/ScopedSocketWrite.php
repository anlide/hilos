<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\Delivery;

/** A packed frame's location in the socket write buffer before its first byte is accepted. */
final readonly class ScopedSocketWrite
{
    /**
     * @param int $offset Logical position of the first byte, including discarded prefixes
     * @param int $length Complete framed byte length
     * @param string $deliveryId Socket authority for the whole frame
     * @param bool $recovery Whether this is a recovery control
     * @param string $unscopedBytes Alternate complete frame, empty if no neighbors survive
     */
    public function __construct(
        public int $offset,
        public int $length,
        public string $deliveryId,
        public bool $recovery,
        public string $unscopedBytes,
    ) {
    }
}
