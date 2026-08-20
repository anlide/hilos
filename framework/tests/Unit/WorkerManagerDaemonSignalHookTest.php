<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\Worker\ContainedFailure;
use Hilos\Core\Daemon\Worker\WorkerTickUnit;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\Worker\DaemonConnectionState;
use Hilos\Socket\Worker\DTO\DaemonWorkerSignalDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Utils\Logger;
use Hilos\Utils\WorkerTickFailureLog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The worker end of the master's broadcast door (HIL-618).
 *
 * The frame arrives among the daemon messages of an ordinary tick and is handed to a hook a
 * project overrides. Two things are worth pinning: that the payload reaches the hook as the
 * class the master sent rather than as a bag of keys, and that the hook is left unguarded on
 * purpose - a project reaction that raises is contained as a failure of the daemon-message
 * unit, exactly like every other message, instead of being caught here under its own name.
 */
final class WorkerManagerDaemonSignalHookTest extends TestCase
{
    private const string SIGNAL_NAME = 'project_master_reaction';

    /** Temporary main log file the assertions read the written lines back from */
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-worker-daemon-signal');
        Logger::setLogFile($this->logFile);
        WorkerTickFailureLog::reset();
        ExecutionContext::clear();
    }

    protected function tearDown(): void
    {
        // The manager's constructor registers its signal router globally; left set, it outlives
        // this file.
        Hilos::$sr = null;
        Logger::resetLogFile();
        WorkerTickFailureLog::reset();
        ExecutionContext::clear();

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    public function testTheHookIsHandedTheNameAndThePayloadTheMasterSent(): void
    {
        $manager = new WorkerManagerDaemonSignalHookTestManager();

        $manager->handleDaemonMessage(WorkerDTO::factoryWorkerDTO(
            new DaemonWorkerSignalDTO(
                self::SIGNAL_NAME,
                new WorkerManagerDaemonSignalHookTestPayload('degraded'),
            )->toJson(),
        ));

        $this->assertCount(1, $manager->seen);
        $this->assertSame(self::SIGNAL_NAME, $manager->seen[0][0]);
        $this->assertInstanceOf(WorkerManagerDaemonSignalHookTestPayload::class, $manager->seen[0][1]);
        $this->assertSame('degraded', $manager->seen[0][1]->reason);
    }

    /**
     * The type says one thing and the frame is another - the same refusal every neighbour in
     * the switch makes, written rather than handed on.
     */
    public function testAFrameOfTheWrongClassIsWrittenAndNotHandedToTheHook(): void
    {
        $manager = new WorkerManagerDaemonSignalHookTestManager();

        $manager->handleDaemonMessage(new WorkerManagerDaemonSignalHookTestImpostor());

        $this->assertSame([], $manager->seen);
        $this->assertStringContainsString('onDaemonSignal - unexpected type', $this->logged());
    }

    /**
     * The hook carries no try/catch of its own, and this is why that is safe: the call lands
     * inside the tick's guard, so a raising project reaction is contained as a daemon-message
     * failure, offered to onTickFailure, and the tick goes on.
     */
    public function testARaisingHookIsContainedAsADaemonMessageFailure(): void
    {
        $manager = new WorkerManagerDaemonSignalHookTestManager();
        $manager->hookFails = true;
        $manager->queueMessage(new DaemonWorkerSignalDTO(self::SIGNAL_NAME, new SignalData([])));

        $manager->run();

        $this->assertCount(1, $manager->containedFailures);
        $this->assertSame(WorkerTickUnit::DAEMON_MESSAGE, $manager->containedFailures[0]->unit);
        $this->assertSame(WorkerConstants::MESSAGE_DAEMON_WORKER_SIGNAL, $manager->containedFailures[0]->address);
        $this->assertStringContainsString(
            'contained a failure in daemon message (' . WorkerConstants::MESSAGE_DAEMON_WORKER_SIGNAL . ')',
            $this->logged(),
        );
    }

    /**
     * Reads back whatever the worker wrote to the temporary log.
     *
     * @return string Log contents, empty when the worker stayed silent
     */
    private function logged(): string
    {
        return (string)file_get_contents($this->logFile);
    }
}

/**
 * Worker manager recording what the hook was handed, and able to run one scripted tick.
 */
final class WorkerManagerDaemonSignalHookTestManager extends WorkerManager
{
    /** @var list<array{0: string, 1: SignalDataInterface}> Name and payload each hook call was handed */
    public array $seen = [];

    /** Whether the project's reaction raises. */
    public bool $hookFails = false;

    /** @var list<ContainedFailure> What the guard handed to the project, in order. */
    public array $containedFailures = [];

    /** @var list<WorkerDTO> Messages the scripted client hands out, in order. */
    private array $messages = [];

    public function __construct()
    {
        parent::__construct(1);
    }

    /**
     * Puts one message in the queue the loop drains.
     *
     * @param WorkerDTO $message Message the scripted client hands out
     */
    public function queueMessage(WorkerDTO $message): void
    {
        $this->messages[] = $message;
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new WorkerManagerDaemonSignalHookTestAgentManager();
    }

    protected function connectToDaemon(): void
    {
        $this->daemonClient = new WorkerManagerDaemonSignalHookTestClient($this->messages);
    }

    protected function onDaemonSignal(string $signalName, SignalDataInterface $data): void
    {
        $this->seen[] = [$signalName, $data];

        if ($this->hookFails) {
            throw new RuntimeException('project reaction refused');
        }
    }

    protected function onTickFailure(ContainedFailure $failure): void
    {
        $this->containedFailures[] = $failure;
    }

    protected function setupErrorHandling(): void
    {
    }

    protected function setupSignalHandlers(): void
    {
    }

    protected function checkDaemonLiveness(float $loopStartTime): void
    {
    }

    protected function sleepWithPreciseTiming(float $loopStartTime, int $targetLoopTimeMicroseconds = 10000): void
    {
        $this->shouldExit = true;
    }

    protected function cleanup(): void
    {
    }
}

/**
 * Agent manager stub: this file never starts an agent, the broadcast door does not address one.
 */
final class WorkerManagerDaemonSignalHookTestAgentManager extends AgentManager
{
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        throw new RuntimeException("The test manager has no agent for '{$agentType}'.");
    }
}

/**
 * Daemon client stub: connected, silent, handing out scripted messages once.
 */
final class WorkerManagerDaemonSignalHookTestClient extends WorkerDaemonClient
{
    /**
     * @param list<WorkerDTO> $messages Messages to hand out, in order
     */
    public function __construct(private array $messages)
    {
        $this->state = DaemonConnectionState::CONNECTED;
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function read(): void
    {
    }

    public function write(): void
    {
    }

    public function getNextMessage(): ?WorkerDTO
    {
        return array_shift($this->messages);
    }
}

/**
 * A frame that claims the broadcast type and is not the broadcast DTO.
 */
final class WorkerManagerDaemonSignalHookTestImpostor extends WorkerDTO
{
    public function getType(): string
    {
        return WorkerConstants::MESSAGE_DAEMON_WORKER_SIGNAL;
    }

    /**
     * @return array{type: string} This message as the wire would carry it
     */
    public function toArray(): array
    {
        return [self::TYPE => $this->getType()];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Impostor, which carries nothing
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}

/**
 * A project payload the framework has never heard of, so the class coming back on the worker
 * side means the envelope did its work.
 */
final class WorkerManagerDaemonSignalHookTestPayload implements SignalDataInterface
{
    /**
     * @param string $reason What the master is telling its workers about
     */
    public function __construct(
        public readonly string $reason,
    ) {
    }

    /**
     * @return array<string, mixed> Signal payload
     */
    public function toArray(): array
    {
        return ['reason' => $this->reason];
    }

    /**
     * @param array<string, mixed> $data Signal payload
     * @return static Restored signal payload
     */
    public static function fromArray(array $data): static
    {
        return new static((string)$data['reason']);
    }
}
