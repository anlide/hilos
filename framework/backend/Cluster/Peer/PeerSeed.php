<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer;

/**
 * A seed peer address a joining node dials to enter the cluster.
 *
 * Seeds are declared as a comma-separated `host:port` list in configuration; a
 * bootstrap (first) node declares none. This value object is the parsed form the
 * dialer consumes.
 */
final readonly class PeerSeed
{
    /**
     * @param string $host Seed host
     * @param int $port Seed peer port
     */
    public function __construct(
        public string $host,
        public int $port,
    ) {
    }

    /**
     * Parses a comma-separated `host:port` seed list into value objects.
     *
     * Blank entries are skipped and an entry without a host or a positive
     * integer port is ignored, so a malformed seed never crashes daemon startup
     * — it is simply not dialed.
     *
     * @param string $raw Raw comma-separated `host:port` configuration value
     * @return list<self> Parsed seed addresses
     */
    public static function parseList(string $raw): array
    {
        $seeds = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            $separator = strrpos($entry, ':');
            if ($separator === false) {
                continue;
            }

            $host = trim(substr($entry, 0, $separator));
            $port = (int)substr($entry, $separator + 1);
            if ($host === '' || $port <= 0) {
                continue;
            }

            $seeds[] = new self($host, $port);
        }

        return $seeds;
    }
}
