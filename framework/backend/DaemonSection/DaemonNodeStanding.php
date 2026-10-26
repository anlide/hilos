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
     * @param ?int $commandPort Registered command listener port, or null when absent
     * @throws InvalidFormatException When counts or the port are invalid or standalone carries consensus
     */
    public function __construct(
        public int $sessions,
        public int $connections,
        public bool $clustered,
        public ?DaemonConsensusPicture $consensus,
        public ?int $commandPort,
    ) {
        if ($sessions < 0 || $connections < 0 || $sessions > $connections || (!$clustered && $consensus !== null)) {
            throw new InvalidFormatException('Daemon node standing carries invalid sessions, connections, or consensus');
        }
        if ($commandPort !== null && ($commandPort < 0 || $commandPort > 65535)) {
            throw new InvalidFormatException('Daemon node standing carries an invalid command port');
        }
    }
}
