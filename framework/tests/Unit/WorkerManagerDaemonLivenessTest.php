<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Socket\Worker\DaemonConnectionState;
use Hilos\Socket\Worker\WorkerDaemonClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the two detectors that make an orphaned worker exit.
 */
final class WorkerManagerDaemonLivenessTest extends TestCase
{
    public function testLostDaemonConnectionRequestsExit(): void
    {
        $manager = new WorkerManagerDaemonLivenessTestManager();
        $manager->attachClient(new WorkerManagerDaemonLivenessTestClient(DaemonConnectionState::LOST));

        $manager->checkLiveness(1000.0);

        $this->assertTrue($manager->exitRequested());
    }

    public function testLiveDaemonConnectionKeepsWorkerRunning(): void
    {
        $manager = new WorkerManagerDaemonLivenessTestManager();
        $manager->attachClient(new WorkerManagerDaemonLivenessTestClient(DaemonConnectionState::CONNECTED));

        $manager->checkLiveness(1000.0);

        $this->assertFalse($manager->exitRequested());
    }

    public function testChangedParentPidRequestsExitWithoutAnyConnection(): void
    {
        $manager = new WorkerManagerDaemonLivenessTestManager();
        $manager->parentPid = 4242;
        $manager->rememberDaemonPid();
        $manager->parentPid = 1;

        $manager->checkLiveness(1000.0);

        $this->assertTrue($manager->exitRequested());
    }

    public function testUnchangedParentPidKeepsWorkerRunning(): void
    {
        $manager = new WorkerManagerDaemonLivenessTestManager();
        $manager->parentPid = 4242;
        $manager->rememberDaemonPid();

        $manager->checkLiveness(1000.0);

        $this->assertFalse($manager->exitRequested());
    }

    public function testParentPidIsCheckedAtMostOncePerSecond(): void
    {
        $manager = new WorkerManagerDaemonLivenessTestManager();
        $manager->parentPid = 4242;
        $manager->rememberDaemonPid();
        $manager->parentPidReads = 0;

        $manager->checkLiveness(1000.0);
        $manager->parentPid = 1;
        $manager->checkLiveness(1000.5);

        $this->assertFalse($manager->exitRequested());
        $this->assertSame(1, $manager->parentPidReads);

        $manager->checkLiveness(1001.5);

        $this->assertTrue($manager->exitRequested());
        $this->assertSame(2, $manager->parentPidReads);
    }
}

/**
 * Worker manager exposing the liveness check and a scripted parent pid.
 */
final class WorkerManagerDaemonLivenessTestManager extends WorkerManager
{
    /** Parent pid reported to the manager instead of the real one. */
    public int $parentPid = 1;

    /** How many times the manager asked for the parent pid. */
    public int $parentPidReads = 0;

    public function __construct()
    {
        parent::__construct(1);
    }

    /**
     * Captures the current parent pid the way run() does before connecting.
     */
    public function rememberDaemonPid(): void
    {
        $this->daemonPid = $this->currentParentPid();
    }

    /**
     * Puts a daemon client in place without opening a real connection.
     *
     * @param WorkerDaemonClient $client Client stub reporting a fixed state
     */
    public function attachClient(WorkerDaemonClient $client): void
    {
        $this->daemonClient = $client;
    }

    /**
     * @param float $loopStartTime Timestamp of the simulated loop iteration
     */
    public function checkLiveness(float $loopStartTime): void
    {
        $this->checkDaemonLiveness($loopStartTime);
    }

    /**
     * @return bool True once the manager asked its loop to exit
     */
    public function exitRequested(): bool
    {
        return $this->shouldExit;
    }

    protected function currentParentPid(): int
    {
        $this->parentPidReads++;

        return $this->parentPid;
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new WorkerManagerDaemonLivenessTestAgentManager();
    }
}

/**
 * Agent manager stub: the liveness check never creates agents.
 */
final class WorkerManagerDaemonLivenessTestAgentManager extends AgentManager
{
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        throw new RuntimeException('The liveness test never starts an agent.');
    }
}

/**
 * Daemon client stub that only reports a connection state.
 */
final class WorkerManagerDaemonLivenessTestClient extends WorkerDaemonClient
{
    /**
     * @param DaemonConnectionState $state State this stub reports
     */
    public function __construct(DaemonConnectionState $state)
    {
        $this->state = $state;
    }
}
