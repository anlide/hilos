<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer;

/**
 * A reachable peer endpoint: the host and port another node dials to reach it.
 *
 * The same value type serves two roles — a configured seed to dial on join and a
 * node's own advertised address announced to peers — since both are just "where
 * to reach a node". Parsing is lenient: a malformed entry yields null (single) or
 * is skipped (list) rather than crashing daemon startup.
 */
final readonly class PeerAddress
{
    /**
     * @param string $host Endpoint host
     * @param int $port Endpoint port
     */
    public function __construct(
        public string $host,
        public int $port,
    ) {
    }

    /**
     * Parses a single `host:port` value, returning null when malformed.
     *
     * @param string $value Raw `host:port` value
     * @return ?self Parsed address, or null when the host or port is missing or invalid
     */
    public static function fromString(string $value): ?self
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $separator = strrpos($value, ':');
        if ($separator === false) {
            return null;
        }

        $host = trim(substr($value, 0, $separator));
        $port = (int) substr($value, $separator + 1);
        if ($host === '' || $port <= 0) {
            return null;
        }

        return new self($host, $port);
    }

    /**
     * Parses a comma-separated `host:port` list, skipping blank or malformed entries.
     *
     * Used for the seed list, so a bad seed never crashes daemon startup — it is
     * simply not dialed.
     *
     * @param string $raw Raw comma-separated `host:port` configuration value
     * @return list<self> Parsed addresses
     */
    public static function parseList(string $raw): array
    {
        $addresses = [];
        foreach (explode(',', $raw) as $entry) {
            $address = self::fromString($entry);
            if ($address !== null) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * @return string The `host:port` form
     */
    public function toString(): string
    {
        return "{$this->host}:{$this->port}";
    }
}
