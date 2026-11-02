<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Core\Exception\InvalidFormatException;

/** One peer's last completed clock measurement, without exchange diagnostics. */
final readonly class DaemonClockReading
{
    /**
     * @param string $nodeId Measured peer
     * @param int $offsetMs Peer clock minus leader clock, in milliseconds
     * @param int $measuredAt Leader's epoch milliseconds at completion
     * @throws InvalidFormatException When the node id is empty
     */
    public function __construct(public string $nodeId, public int $offsetMs, public int $measuredAt)
    {
        if ($nodeId === '') {
            throw new InvalidFormatException('Daemon clock reading carries an empty node id');
        }
    }
}
