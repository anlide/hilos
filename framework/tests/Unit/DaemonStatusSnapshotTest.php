<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\Master\DaemonStatus;
use Hilos\Core\Router\SignalRouter;
use Hilos\Socket\Server\WorkerServer;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Unit tests for the master-side daemon status snapshot (HIL-749).
 *
 * The snapshot is what both status doors publish - the GET /status endpoint and the
 * `daemon:status` branch of the command channel - so these cases are about what the master
 * can say on its own: the clock and the memory figure it holds, the worker counts it reads
 * off the server it registered, and the three zeroes a daemon without a worker server owes
 * instead of a refusal.
 */
final class DaemonStatusSnapshotTest extends TestCase
{
    public function testTheSnapshotCarriesTheMastersOwnClockAndMemory(): void
    {
        // Uptime is counted from the status object the manager was built with, so a snapshot
        // taken a hundred seconds later reports a hundred seconds of life rather than zero:
        // the endpoint used to build a status object of its own and started the clock afresh.
        $manager = $this->buildManager(startTime: microtime(true) - 100.0);

        $snapshot = $manager->daemonStatusSnapshot();

        $this->assertSame(100, $snapshot->uptime);
        $this->assertGreaterThan(0, $snapshot->memory);
        $this->assertGreaterThan(0, $snapshot->timestamp);
    }

    public function testTheWorkerCountsComeFromTheRegisteredWorkerServer(): void
    {
        $manager = $this->buildManager(workerServer: $this->buildWorkerServer());

        $snapshot = $manager->daemonStatusSnapshot();

        $this->assertSame(StatusTestWorkerServer::REGULAR_WORKERS, $snapshot->workersRegular);
        $this->assertSame(StatusTestWorkerServer::MONOPOLISTIC_WORKERS, $snapshot->workersMonopolistic);
        $this->assertSame(StatusTestWorkerServer::MAX_REGULAR_WORKERS, $snapshot->workersMaxRegular);
    }

    public function testADaemonWithoutAWorkerServerReportsZeroWorkers(): void
    {
        // Zeroes rather than a refusal: a daemon that forks nobody has no workers, and that is
        // an answer about this daemon. The clock still runs, so the rest of the snapshot stands.
        $manager = $this->buildManager();

        $snapshot = $manager->daemonStatusSnapshot();

        $this->assertSame(0, $snapshot->workersRegular);
        $this->assertSame(0, $snapshot->workersMonopolistic);
        $this->assertSame(0, $snapshot->workersMaxRegular);
        $this->assertGreaterThan(0, $snapshot->memory);
    }

    /**
     * Builds a daemon manager holding the given status clock and server set.
     *
     * Skips the constructor: it builds a signal router, an agent manager and the freeze
     * watchers the snapshot never touches - the snapshot reads one status object and one server.
     *
     * @param ?float $startTime Start time the uptime is counted from, or null for "just now"
     * @param ?WorkerServer $workerServer Worker server to register, or null for a daemon without one
     * @return StatusTestDaemonManager Manager under test
     */
    private function buildManager(?float $startTime = null, ?WorkerServer $workerServer = null): StatusTestDaemonManager
    {
        $manager = new ReflectionClass(StatusTestDaemonManager::class)->newInstanceWithoutConstructor();

        new ReflectionProperty(DaemonManager::class, 'daemonStatus')->setValue($manager, new DaemonStatus($startTime));
        new ReflectionProperty(DaemonManager::class, 'servers')
            ->setValue($manager, $workerServer === null ? [] : [$workerServer]);

        return $manager;
    }

    /**
     * Builds a worker server answering the fixed counts this test asserts on.
     *
     * @return StatusTestWorkerServer Worker server standing in for a live one
     */
    private function buildWorkerServer(): StatusTestWorkerServer
    {
        return new ReflectionClass(StatusTestWorkerServer::class)->newInstanceWithoutConstructor();
    }
}

/**
 * Daemon manager reduced to the two factories the base class demands; neither is called here.
 */
final class StatusTestDaemonManager extends DaemonManager
{
    protected function createSignalRouter(): SignalRouter
    {
        throw new LogicException('The status snapshot never builds a signal router.');
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        throw new LogicException('The status snapshot never builds an agent manager.');
    }
}

/**
 * Worker server that answers fixed counts, standing in for one with live worker processes.
 */
final class StatusTestWorkerServer extends WorkerServer
{
    /** @var int Regular workers this stand-in reports as running */
    public const int REGULAR_WORKERS = 4;

    /** @var int Monopolistic workers this stand-in reports as running */
    public const int MONOPOLISTIC_WORKERS = 2;

    /** @var int Regular-worker capacity this stand-in reports */
    public const int MAX_REGULAR_WORKERS = 8;

    public function getRegularWorkersCount(): int
    {
        return self::REGULAR_WORKERS;
    }

    public function getMonopolisticWorkersCount(): int
    {
        return self::MONOPOLISTIC_WORKERS;
    }

    public function getMaxRegularWorkers(): int
    {
        return self::MAX_REGULAR_WORKERS;
    }

    protected function onStart(): void
    {
        // Not used in this test
    }
}
