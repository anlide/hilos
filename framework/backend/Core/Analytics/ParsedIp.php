<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/**
 * Immutable parsed client IP split into its IPv4 and IPv6 representations.
 *
 * Exactly one field is set for a valid address; both are null when the input is
 * empty or not a valid IP.
 */
final class ParsedIp
{
    /**
     * @param ?int $ipv4 IPv4 as unsigned 32-bit integer, or null when not IPv4
     * @param ?string $ipv6Hex IPv6 as hex string of its packed bytes, or null when not IPv6
     */
    public function __construct(
        public readonly ?int $ipv4,
        public readonly ?string $ipv6Hex,
    ) {
    }
}
