<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Log\LogWriteLevelApplier;
use Hilos\Log\LogWriteLevelListenerInterface;
use Hilos\Socket\Worker\DTO\WorkerLogWriteLevelDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Utils\LogLevel;

/**
 * Passes this worker's write level on to the master process (HIL-761).
 *
 * The whole of why the level reaches daemon.log at all: the master may not read the setting that
 * decides it, so the level travels the link that already exists between the two, on a message of
 * its own. Every worker reports, and their values agree because they read one setting; the master
 * writes a line only when what it hears differs from what it holds.
 *
 * Registered with {@see LogWriteLevelApplier} once the registration is confirmed, so the first
 * report goes out over a channel the master has already acknowledged.
 */
final class WorkerLogWriteLevelReporter implements LogWriteLevelListenerInterface
{
    /**
     * @param WorkerDaemonClient $client Link to the master this worker reports over
     */
    public function __construct(private readonly WorkerDaemonClient $client)
    {
    }

    /**
     * Sends the new level to the master.
     *
     * @param LogLevel $level Level the process writes from now on
     */
    public function onWriteLevelChanged(LogLevel $level): void
    {
        $this->client->send(new WorkerLogWriteLevelDTO($level->value));
    }
}
