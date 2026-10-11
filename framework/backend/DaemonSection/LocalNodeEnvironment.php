<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

/**
 * Node environment reading held in the process serving its agent. Environment page
 * windows reach that agent through SignalRouter::boundPageAgentDestination(); only
 * NodeEnvironmentReading::view() turns this reading into browser values.
 */
final class LocalNodeEnvironment
{
    private static ?NodeEnvironmentReading $reading = null;

    /** @param NodeEnvironmentReading $reading Most recent reading of the local node */
    public static function hold(NodeEnvironmentReading $reading): void
    {
        self::$reading = $reading;
    }

    /** @return ?NodeEnvironmentReading Most recent node-local reading */
    public static function reading(): ?NodeEnvironmentReading
    {
        return self::$reading;
    }

    /** @param string $nodeId Node whose agent stopped */
    public static function forget(string $nodeId): void
    {
        if (self::$reading?->nodeId === $nodeId) {
            self::$reading = null;
        }
    }
}
