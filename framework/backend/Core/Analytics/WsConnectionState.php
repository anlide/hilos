<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/**
 * Mutable in-memory snapshot of a WebSocket connection row.
 *
 * Caches the persisted id and owning browser session id together with the
 * currently known client IP so the collector can record IP changes without
 * re-reading the row. The current* fields are updated in place on each change.
 */
final class WsConnectionState
{
    /**
     * @param int $id Persisted WS connection id
     * @param ?int $browserSessionId Owning browser session id, or null when anonymous
     * @param ?int $currentIpv4 Current IPv4 as unsigned 32-bit integer, or null when not IPv4
     * @param ?string $currentIpv6Hex Current IPv6 as hex string of its packed bytes, or null when not IPv6
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $browserSessionId,
        public ?int $currentIpv4,
        public ?string $currentIpv6Hex,
    ) {
    }
}
