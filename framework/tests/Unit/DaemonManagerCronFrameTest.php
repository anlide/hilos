<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Leadership;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\DaemonSection\DTO\DaemonMasterCronSignalData;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/** The master's cron part changes with rules and leadership, then repairs itself each minute. */
final class DaemonManagerCronFrameTest extends TestCase
{
    /** @var class-string<Hilos> */
    private string $previousAppClass;
    private ?RtContext $previousRt;
    private ?ClusterContext $previousCluster;

    protected function setUp(): void
    {
        $this->previousAppClass = Hilos::appClass();
        $this->previousRt = Hilos::$rt;
        $this->previousCluster = Hilos::$cluster;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, CronFrameTestHilos::class);
        Hilos::$rt = null;
        Hilos::$cluster = null;
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        Hilos::$rt = $this->previousRt;
        Hilos::$cluster = $this->previousCluster;
        parent::tearDown();
    }

    public function testRuleChangesLeadershipAndMinuteRepairSendWholeFrames(): void
    {
        $manager = new CronFrameTestDaemonManager();
        $server = $manager->addCaptureServer();
        $manager->addRule('backup', '0 3 * * *');
        $manager->tickCronAt(100.0);
        self::assertCount(1, $server->delivered);
        self::assertSame(null, $server->frame(0)->idleReason);
        self::assertSame('backup', $server->frame(0)->rules[0]->name);
        self::assertNull($server->frame(0)->rules[0]->lastRunAt);

        $manager->tickCronAt(101.0);
        self::assertCount(1, $server->delivered);

        $leadership = new CronFrameTestLeadership();
        $leadership->leader = false;
        $cluster = new ClusterContext();
        $cluster->registerLeadership($leadership);
        Hilos::$cluster = $cluster;
        $manager->tickCronAt(102.0);
        self::assertSame('not_leader', $server->frame(1)->idleReason);

        $leadership->leader = true;
        $manager->tickCronAt(103.0);
        self::assertNull($server->frame(2)->idleReason);

        $manager->changeRule('backup', '*/15 * * * *');
        $manager->tickCronAt(104.0);
        self::assertSame('*/15 * * * *', $server->frame(3)->rules[0]->expression);

        $manager->removeRule('backup');
        $manager->tickCronAt(105.0);
        self::assertSame([], $server->frame(4)->rules);
        $manager->tickCronAt(164.0);
        self::assertCount(5, $server->delivered);
        $manager->tickCronAt(165.0);
        self::assertCount(6, $server->delivered);
    }

    public function testFiringUpdatesLastRunAndStartsAChangedFrame(): void
    {
        $manager = new CronFrameTestDaemonManager();
        $server = $manager->addCaptureServer();
        $manager->addRule('always', '* * * * *');
        $manager->tickCronAt(100.0);

        $manager->getCronRules()['always']->lastRun = 0.0;
        $manager->fireDueCron();
        $manager->tickCronAt(101.0);
        self::assertCount(2, $server->delivered);
        self::assertGreaterThan(0, $server->frame(1)->rules[0]->lastRunAt);
    }

    public function testFreezeAndMissingNodeAgentKeepTheFrameLocal(): void
    {
        $manager = new CronFrameTestDaemonManager();
        $server = $manager->addCaptureServer();
        $agents = $manager->agents();
        $agents->started = false;
        $manager->tickCronAt(100.0);
        self::assertSame([], $server->delivered);

        $agents->started = true;
        $runtime = new CronFrameFreezeRtContext();
        $runtime->hilosProtectedModeRuntime->phase = StateProtectedModeRuntime::PHASE_ACTIVE;
        Hilos::$rt = $runtime;
        $manager->tickCronAt(101.0);
        self::assertSame([], $server->delivered);

        $runtime->hilosProtectedModeRuntime->phase = StateProtectedModeRuntime::PHASE_INACTIVE;
        $manager->tickCronAt(102.0);
        self::assertCount(1, $server->delivered);

        $manager->onAgentStarted(HilosAgentType::HILOS_DAEMON_NODE);
        $manager->tickCronAt(103.0);
        self::assertCount(2, $server->delivered);
    }

    public function testFailedDeliveryIsRetriedAfterOneMinute(): void
    {
        $manager = new CronFrameTestDaemonManager();
        $server = $manager->addCaptureServer();
        $server->refuse = true;
        $manager->tickCronAt(100.0);
        self::assertSame(1, $server->attempts);
        $manager->tickCronAt(101.0);
        self::assertSame(1, $server->attempts);
        $server->refuse = false;
        $manager->tickCronAt(160.0);
        self::assertSame(2, $server->attempts);
        self::assertCount(1, $server->delivered);
    }
}

final class CronFrameTestDaemonManager extends DaemonManager
{
    /** @return CronFrameCaptureServer Capturing local worker server */
    public function addCaptureServer(): CronFrameCaptureServer
    {
        $server = new CronFrameCaptureServer();
        $this->registerServer($server);
        return $server;
    }

    /** @return CronFrameAgentManager Mutable receiver presence */
    public function agents(): CronFrameAgentManager
    {
        return $this->agentManagerDaemon;
    }

    /** @param float $now Controlled loop time */
    public function tickCronAt(float $now): void
    {
        new ReflectionMethod(DaemonManager::class, 'tickCronFrame')->invoke($this, $now);
    }

    /**
     * @param string $name Rule name
     * @param string $expression Rule expression
     */
    public function addRule(string $name, string $expression): void
    {
        $this->addCronRule($name, $expression);
    }

    /**
     * @param string $name Rule name
     * @param string $expression Rule expression
     */
    public function changeRule(string $name, string $expression): void
    {
        $this->updateCronRule($name, $expression);
    }

    /** @param string $name Rule name */
    public function removeRule(string $name): void
    {
        $this->removeCronRule($name);
    }

    public function fireDueCron(): void
    {
        new ReflectionProperty(DaemonManager::class, 'workersReady')->setValue($this, true);
        new ReflectionMethod(DaemonManager::class, 'checkCronJobs')->invoke($this);
    }

    /** @param CronRule $rule Rule whose firing is recorded by its owner */
    protected function onCron(CronRule $rule): void
    {
    }

    /** @return SignalRouter Empty route table for directly addressed frames */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Manager with mutable node-agent presence */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new CronFrameAgentManager();
    }
}

final class CronFrameCaptureServer extends WorkerServer
{
    /** @var list<DaemonAgentMessageDTO> */
    public array $delivered = [];
    public int $attempts = 0;
    public bool $refuse = false;

    public function __construct()
    {
    }

    /**
     * @param string $agentType Receiver type
     * @param ?string $agentIndex Receiver index
     * @param DaemonAgentMessageDTO $messageDto Addressed frame
     * @throws LogicException When this test refuses delivery
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
        $this->attempts++;
        if ($this->refuse) {
            throw new LogicException('Delivery refused by test');
        }
        $this->delivered[] = $messageDto;
    }

    /**
     * @param int $index Frame index
     * @return DaemonMasterCronSignalData Sent frame
     */
    public function frame(int $index): DaemonMasterCronSignalData
    {
        $data = $this->delivered[$index]->signal->data->data;
        if (!$data instanceof DaemonMasterCronSignalData) {
            throw new LogicException('Captured frame is not a master cron report');
        }
        return $data;
    }

    protected function onStart(): void
    {
    }
}

final class CronFrameAgentManager extends AgentManagerDaemon
{
    public bool $started = true;

    /**
     * @param string $agentId Receiver id
     * @return bool Whether the node agent is up
     */
    public function isAgentStarted(string $agentId): bool
    {
        return $this->started && $agentId === HilosAgentType::HILOS_DAEMON_NODE;
    }

    /**
     * @param string $agentType Unused agent type
     * @param ?string $agentIndex Unused instance index
     * @return AgentDaemonInterface Never returned
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new LogicException('The cron frame test starts no agents');
    }
}

final class CronFrameFreezeRtContext extends RtContext
{
    public object $hilosProtectedModeRuntime;

    public function __construct()
    {
        $this->hilosProtectedModeRuntime = (object)['phase' => StateProtectedModeRuntime::PHASE_INACTIVE];
    }

    public function configure(): void
    {
    }
}

abstract class CronFrameTestHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_DAEMON_NODE => [AgentRegistryKey::SCOPE => AgentScope::NODE],
    ];
}

final class CronFrameTestLeadership implements Leadership
{
    public bool $leader = true;

    /** @return bool Controlled leader verdict */
    public function amLeader(): bool
    {
        return $this->leader;
    }

    /** @return ?string No known leader id */
    public function leaderId(): ?string
    {
        return null;
    }

    /** @return bool No known leader WebSocket */
    public function leaderWebSocketOpen(): bool
    {
        return false;
    }

    /** @return bool No quorum required by this test */
    public function hasQuorum(): bool
    {
        return false;
    }
}
