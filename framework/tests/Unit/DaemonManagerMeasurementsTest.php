<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\DaemonMeasurements;
use Hilos\Core\Daemon\Master\DaemonStatus;
use Hilos\Core\Router\SignalRouter;
use Hilos\DaemonSection\DTO\DaemonMasterMeasurementsSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Utils\Logger;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/** The master samples independently of the node agent and sends rounded changes. */
final class DaemonManagerMeasurementsTest extends TestCase
{
    /** @var class-string<Hilos> */
    private string $previousAppClass;
    private ?RtContext $previousRt;
    private ?ClusterContext $previousCluster;
    private ?EnvAccessor $previousEnv;

    /** Replaces facade inputs with a socketless node and agent topology. */
    protected function setUp(): void
    {
        $this->previousAppClass = Hilos::appClass();
        $this->previousRt = Hilos::$rt;
        $this->previousCluster = Hilos::$cluster;
        $this->previousEnv = Hilos::$env;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, MeasurementsTestHilos::class);
        Hilos::$rt = null;
        Hilos::$cluster = null;
        Hilos::$env = new EnvAccessor();
    }

    /** Restores facade inputs after each case. */
    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        Hilos::$rt = $this->previousRt;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$env = $this->previousEnv;
        parent::tearDown();
    }

    /** Sampling runs every five seconds; unchanged frames get only minute repair. */
    public function testCadenceChangeAndMinuteRepair(): void
    {
        [$manager, $status, $worker] = $this->fixture();
        $manager->tickMeasurementsAt(100.0);
        self::assertSame(1, $status->samples);
        self::assertCount(1, $worker->delivered);
        self::assertSame('standalone', $worker->frame(0)->nodeId);

        $status->next = self::measurement(1048576);
        $manager->tickMeasurementsAt(104.9);
        self::assertSame(1, $status->samples);
        self::assertCount(1, $worker->delivered);
        $manager->tickMeasurementsAt(105.0);
        self::assertSame(2, $status->samples);
        self::assertCount(2, $worker->delivered);
        self::assertSame(1048576, $worker->frame(1)->measurements->workersMemory);

        $manager->tickMeasurementsAt(160.0);
        self::assertCount(2, $worker->delivered);
        $manager->tickMeasurementsAt(165.0);
        self::assertCount(3, $worker->delivered);
    }

    /** The status door sees samples even when the node agent is absent. */
    public function testSamplingWithoutNodeAgentAndStartForce(): void
    {
        [$manager, $status, $worker] = $this->fixture();
        $manager->agents()->started = false;
        $manager->tickMeasurementsAt(100.0);
        self::assertSame(1, $status->samples);
        self::assertSame($status->last(), $manager->daemonStatusSnapshot()->measurements);
        self::assertSame([], $worker->delivered);

        $manager->agents()->started = true;
        $manager->onAgentStarted(HilosAgentType::HILOS_DAEMON_NODE);
        $manager->tickMeasurementsAt(101.0);
        self::assertSame(1, $status->samples);
        self::assertCount(1, $worker->delivered);
        $manager->onAgentStarted(HilosAgentType::HILOS_DAEMON_NODE);
        $manager->tickMeasurementsAt(101.1);
        self::assertCount(2, $worker->delivered);
    }

    /** A freeze suppresses delivery, while sampling continues and thaw forces a frame. */
    public function testFreezeKeepsSamplingAndForcesDeliveryAfterThaw(): void
    {
        [$manager, $status, $worker] = $this->fixture();
        $runtime = new MeasurementsTestFreezeRtContext();
        $runtime->hilosProtectedModeRuntime->phase = StateProtectedModeRuntime::PHASE_ACTIVE;
        Hilos::$rt = $runtime;
        $manager->tickMeasurementsAt(100.0);
        $manager->tickMeasurementsAt(105.0);
        self::assertSame(2, $status->samples);
        self::assertSame([], $worker->delivered);

        $runtime->hilosProtectedModeRuntime->phase = StateProtectedModeRuntime::PHASE_INACTIVE;
        $manager->tickMeasurementsAt(106.0);
        self::assertCount(1, $worker->delivered);
    }

    /** A failed delivery is attempted again at the minute and logged once per outcome. */
    public function testRefusalRetryAndLogDeduplication(): void
    {
        [$manager, $status, $worker] = $this->fixture();
        $logPath = tempnam(sys_get_temp_dir(), 'hilos-measurements-');
        self::assertIsString($logPath);
        $previousLogFile = new ReflectionProperty(Logger::class, 'logFile')->getValue();
        Logger::setLogFile($logPath);
        try {
            $worker->refuse = true;
            $manager->tickMeasurementsAt(100.0);
            $status->next = self::measurement(1048576);
            $manager->tickMeasurementsAt(105.0);
            self::assertSame(1, $worker->attempts);
            $manager->tickMeasurementsAt(160.0);
            self::assertSame(2, $worker->attempts);
            self::assertSame(1, substr_count((string)file_get_contents($logPath),
                HilosSignalConstants::DAEMON_MASTER_MEASUREMENTS));

            $worker->refuse = false;
            $manager->tickMeasurementsAt(220.0);
            self::assertSame(3, $worker->attempts);
            self::assertCount(1, $worker->delivered);
            self::assertArrayNotHasKey(HilosSignalConstants::DAEMON_MASTER_MEASUREMENTS,
                new ReflectionProperty(DaemonManager::class, 'masterFrameDeliveryProblems')->getValue($manager));
        } finally {
            if ($previousLogFile === null) {
                Logger::resetLogFile();
            } else {
                Logger::setLogFile($previousLogFile);
            }
            unlink($logPath);
        }
    }

    /** @return array{MeasurementsTestDaemonManager, MeasurementsTestStatus, MeasurementsTestCaptureServer} Test pieces */
    private function fixture(): array
    {
        $manager = new MeasurementsTestDaemonManager();
        $worker = $manager->addCaptureServer();
        $status = new MeasurementsTestStatus(self::measurement(0));
        new ReflectionProperty(DaemonManager::class, 'daemonStatus')->setValue($manager, $status);
        return [$manager, $status, $worker];
    }

    /**
     * @param int $workerBytes Rounded worker memory
     * @return DaemonMeasurements Complete test sample
     */
    private static function measurement(int $workerBytes): DaemonMeasurements
    {
        return new DaemonMeasurements(100, 2.0, DaemonMeasurements::CORES_CPUS, 0.0, 0.0,
            0, 1048576, $workerBytes, 0, 4194304, DaemonMeasurements::CEILING_CGROUP, null);
    }
}

final class MeasurementsTestStatus extends DaemonStatus
{
    public int $samples = 0;
    public DaemonMeasurements $next;
    private DaemonMeasurements $current;

    /** @param DaemonMeasurements $initial First and next sample */
    public function __construct(DaemonMeasurements $initial)
    {
        $this->current = $initial;
        $this->next = $initial;
    }

    /**
     * @param float $now Sample time
     * @param list<int> $workerPids Worker ids
     * @return DaemonMeasurements New sample
     */
    public function sample(float $now, array $workerPids): DaemonMeasurements
    {
        $this->samples++;
        $this->current = $this->next;
        return $this->current;
    }

    /** @return DaemonMeasurements Last sample */
    public function last(): DaemonMeasurements
    {
        return $this->current;
    }

    /** @return int Controlled uptime */
    public function getUptime(): int
    {
        return 123;
    }
}

final class MeasurementsTestDaemonManager extends DaemonManager
{
    /** @return MeasurementsTestCaptureServer Socketless receiver */
    public function addCaptureServer(): MeasurementsTestCaptureServer
    {
        $server = new MeasurementsTestCaptureServer();
        $this->registerServer($server);
        return $server;
    }

    /** @return MeasurementsTestAgentManager Mutable receiver presence */
    public function agents(): MeasurementsTestAgentManager
    {
        return $this->agentManagerDaemon;
    }

    /** @param float $now Controlled loop time */
    public function tickMeasurementsAt(float $now): void
    {
        new ReflectionMethod(DaemonManager::class, 'tickMeasurements')->invoke($this, $now);
    }

    /** @return SignalRouter Empty route table for addressed frames */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Manager with mutable receiver presence */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new MeasurementsTestAgentManager();
    }
}

final class MeasurementsTestCaptureServer extends WorkerServer
{
    /** @var list<DaemonAgentMessageDTO> */
    public array $delivered = [];
    public int $attempts = 0;
    public bool $refuse = false;

    /** Builds a socketless receiver. */
    public function __construct()
    {
    }

    /** @throws LogicException When this receiver refuses delivery */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
        $this->attempts++;
        if ($this->refuse) {
            throw new LogicException('Delivery refused by test');
        }
        $this->delivered[] = $messageDto;
    }

    /** @throws LogicException When the captured frame has another type */
    public function frame(int $index): DaemonMasterMeasurementsSignalData
    {
        $data = $this->delivered[$index]->signal->data->data;
        if (!$data instanceof DaemonMasterMeasurementsSignalData) {
            throw new LogicException('Captured frame is not measurements');
        }
        return $data;
    }

    /** @return int Socketless fixture capacity */
    public function getMaxRegularWorkers(): int
    {
        return 0;
    }

    /** Starts no real socket. */
    protected function onStart(): void
    {
    }
}

final class MeasurementsTestAgentManager extends AgentManagerDaemon
{
    public bool $started = true;

    /** @return bool Whether the node agent is up */
    public function isAgentStarted(string $agentId): bool
    {
        return $this->started && $agentId === HilosAgentType::HILOS_DAEMON_NODE;
    }

    /** @throws LogicException Always; this test starts no agent */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new LogicException('The measurement test starts no agents');
    }
}

final class MeasurementsTestFreezeRtContext extends RtContext
{
    public object $hilosProtectedModeRuntime;

    /** Starts inactive so the test can set a freeze. */
    public function __construct()
    {
        $this->hilosProtectedModeRuntime = (object)['phase' => StateProtectedModeRuntime::PHASE_INACTIVE];
    }

    /** Registers no runtime collections. */
    public function configure(): void
    {
    }
}

abstract class MeasurementsTestHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_DAEMON_NODE => [AgentRegistryKey::SCOPE => AgentScope::NODE],
    ];
}
