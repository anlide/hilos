<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Core\Exception\InvalidFormatException;

/** Leader's completed peer clock measurements in node-id order. */
final readonly class DaemonClockPicture
{
    /**
     * @param int $thresholdMs Positive skew threshold in milliseconds
     * @param list<DaemonClockReading> $nodes Completed readings sorted by unique node id
     * @throws InvalidFormatException When the threshold, readings, or their order is invalid
     */
    public function __construct(public int $thresholdMs, public array $nodes)
    {
        if ($thresholdMs < 1 || !array_is_list($nodes)) {
            throw new InvalidFormatException('Daemon clock picture carries an invalid threshold or reading list');
        }
        $previousId = null;
        foreach ($nodes as $reading) {
            if (!$reading instanceof DaemonClockReading || ($previousId !== null && strcmp($previousId, $reading->nodeId) >= 0)) {
                throw new InvalidFormatException('Daemon clock readings must have unique sorted node ids');
            }
            $previousId = $reading->nodeId;
        }
    }

    /**
     * @param string $nodeId Peer to find
     * @return ?DaemonClockReading Last completed reading, or null before a measurement
     */
    public function reading(string $nodeId): ?DaemonClockReading
    {
        foreach ($this->nodes as $reading) {
            if ($reading->nodeId === $nodeId) {
                return $reading;
            }
        }
        return null;
    }
}
