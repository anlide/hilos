<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon\Module;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\DaemonContext;
use Hilos\Core\Daemon\DaemonManager;

/**
 * Exposes the timestamp the frontend build wrote into dist as HILOS_BUILD_TIMESTAMP,
 * so the handshake welcome frame can report the build the daemon is serving.
 *
 * Active only when a built dist is present (the timestamp file exists). The read is a
 * one-time bootstrap side-effect — never on the event loop — so the light master is
 * unaffected; without a build the value stays at its 'dev' default.
 */
final class BuildTimestampModule implements DaemonModule
{
    /** @var string Timestamp file name the frontend build writes under dist */
    private const string TIMESTAMP_FILE = 'build-timestamp.txt';

    /**
     * @param string $distPath Resolved frontend dist directory to read the timestamp from
     */
    public function __construct(
        private readonly string $distPath,
    ) {
    }

    /**
     * @return bool True when a built dist with a timestamp file is present
     */
    public function isActive(): bool
    {
        return is_file($this->timestampFile());
    }

    /**
     * Reads the build timestamp and publishes it into the process environment.
     *
     * @param DaemonManager $daemon Daemon being composed (registers no server here)
     * @param DaemonContext $context Resolved path context (unused; dist path is held on the module)
     */
    public function register(DaemonManager $daemon, DaemonContext $context): void
    {
        $buildTimestamp = trim((string)file_get_contents($this->timestampFile()));
        if ($buildTimestamp !== '') {
            putenv(EnvConstants::HILOS_BUILD_TIMESTAMP->name . '=' . $buildTimestamp);
        }
    }

    /**
     * @return string Absolute path to the build-timestamp file under dist
     */
    private function timestampFile(): string
    {
        return $this->distPath . '/' . self::TIMESTAMP_FILE;
    }
}
