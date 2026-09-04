<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/**
 * Mutable in-memory snapshot of a WebSocket connection row.
 *
 * Caches the persisted id together with the currently known client IP. The current*
 * fields stay writable for a change of address, but nothing moves them: an address
 * cannot change inside a connection, so the only writer that would has no caller
 * (HIL-706, {@see AnalyticsCollector::trackWsConnectionIpChange()}).
 *
 * The owning browser session is not cached here: the row is opened by the master,
 * which never resolves a session, and attached by a worker that reaches the row by
 * its accept key. No caller of this snapshot has ever needed the owner.
 */
final class WsConnectionState
{
    /**
     * @param int $id Persisted WS connection id
     * @param ?int $currentIpv4 Current IPv4 as unsigned 32-bit integer, or null when not IPv4
     * @param ?string $currentIpv6Hex Current IPv6 as hex string of its packed bytes, or null when not IPv6
     */
    public function __construct(
        public readonly int $id,
        public ?int $currentIpv4,
        public ?string $currentIpv6Hex,
    ) {
    }
}
