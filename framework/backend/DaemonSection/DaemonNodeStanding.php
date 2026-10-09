<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Core\Exception\InvalidFormatException;

/** Browser sockets and the cluster view known only to this node's master. */
final readonly class DaemonNodeStanding
{
    /**
     * @param int $sessions Distinct browser sessions
     * @param int $connections Handshaked browser sockets
     * @param bool $clustered Whether this node belongs to a cluster
     * @param ?DaemonConsensusPicture $consensus Local master's consensus view
     * @throws InvalidFormatException When counts are invalid or standalone carries consensus
     */
    public function __construct(
        public int $sessions,
        public int $connections,
        public bool $clustered,
        public ?DaemonConsensusPicture $consensus,
    ) {
        if ($sessions < 0 || $connections < 0 || $sessions > $connections || (!$clustered && $consensus !== null)) {
            throw new InvalidFormatException('Daemon node standing carries invalid sessions, connections, or consensus');
        }
    }
}
