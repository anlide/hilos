<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\Worker\WorkerTickUnit;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\Worker\DaemonConnectionState;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Utils\Logger;
use Hilos\Utils\WorkerTickFailureLog;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * What one tick of a worker lets out, and what it keeps to itself (HIL-574).
 *
 * The worker's loop had no boundary: a signal dispatch or a broken page declaration that
 * raised anything the two named catches did not know took the process with it, and
 * ensureMinWorkers raised a replacement that fell in the same place. These cases pin the
 * rule that replaced it - a unit of work fails alone, is written down, is offered to the
 * project, and the units around it finish their tick.
 */
final class WorkerManagerTickGuardTest extends TestCase
{
    /** Temporary main log file the assertions read the written lines back from */
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-worker-tick-guard');
        Logger::setLogFile($this->logFile);
        WorkerTickFailureLog::reset();
        ExecutionContext::clear();
    }

    protected function tearDown(): void
    {
        // The manager's constructor registers its signal router globally, and the tick
        // reads the browser context from the same place: left set, both outlive this file.
        Hilos::$sr = null;
        Hilos::$browser = null;
        Logger::resetLogFile();
        WorkerTickFailureLog::reset();
        ExecutionContext::clear();

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    public function testAFailingDaemonMessageIsWrittenAndTheNextMessageIsStillHandled(): void
    {
        $manager = new WorkerManagerTickGuardTestManager();
        $manager->queueMessage(new WorkerManagerTickGuardTestMessage('agent_start'));
        $manager->queueMessage(new WorkerManagerTickGuardTestMessage('cron'));
        $manager->failingMessageType = 'agent_start';

        $manager->run();

        $this->assertSame(['cron'], $manager->handledMessageTypes);
        $this->assertStringContainsString(
            'contained a failure in daemon message (agent_start)',
            $this->logged(),
        );
    }

    /**
     * An agent is not a connection: dropping it would lose the truth sources it owns and
     * the work it had started, and only the agent itself may ask to stop.
     */
    public function testAFailingAgentStaysInTheManagerAndTheNextAgentTicks(): void
    {
        $manager = new WorkerManagerTickGuardTestManager();
        $failing = new WorkerManagerTickGuardTestAgent('1');
        $failing->failsOnTick = true;
        $neighbour = new WorkerManagerTickGuardTestAgent('2');
        $manager->addTestAgent($failing);
        $manager->addTestAgent($neighbour);

        $manager->run();

        $this->assertSame(1, $neighbour->ticks);
        $this->assertTrue($manager->hasTestAgent($failing));
        $this->assertStringContainsString(
            'contained a failure in agent (unit_tick_guard:1)',
            $this->logged(),
        );
    }

    public function testTheProjectHookIsHandedTheUnitTheAddressAndTheSameFailure(): void
    {
        $manager = new WorkerManagerTickGuardTestManager();
        $failing = new WorkerManagerTickGuardTestAgent('1');
        $failing->failsOnTick = true;
        $manager->addTestAgent($failing);

        $manager->run();

        $this->assertCount(1, $manager->containedFailures);
        $contained = $manager->containedFailures[0];
        $this->assertSame(WorkerTickUnit::AGENT, $contained->unit);
        $this->assertSame('unit_tick_guard:1', $contained->address);
        $this->assertSame($failing->raised, $contained->failure);
    }

    /**
     * The project's hook is the project's code and can fail like any other. Unguarded it
     * would take down the tick the guard exists to keep alive, and written as the unit's
     * own failure it would read as if the first failure had happened twice.
     */
    public function testAFailingHookDoesNotBreakTheTickAndIsWrittenOnItsOwn(): void
    {
        $manager = new WorkerManagerTickGuardTestManager();
        $manager->hookFails = true;
        $failing = new WorkerManagerTickGuardTestAgent('1');
        $failing->failsOnTick = true;
        $neighbour = new WorkerManagerTickGuardTestAgent('2');
        $manager->addTestAgent($failing);
        $manager->addTestAgent($neighbour);

        $manager->run();

        $this->assertSame(1, $neighbour->ticks);
        $logged = $this->logged();
        $this->assertStringContainsString('contained a failure in agent (unit_tick_guard:1)', $logged);
        $this->assertStringContainsString('contained a failure in failure hook (onTickFailure)', $logged);
    }

    /**
     * Left set, the id of the agent that just fell would sign the journal lines of every
     * unit that follows it in the same tick.
     */
    public function testTheCurrentAgentIsClearedAfterAFailureIsContained(): void
    {
        $manager = new WorkerManagerTickGuardTestManager();
        ExecutionContext::setCurrentAgentId('unit_tick_guard:1');

        $contain = Closure::bind(
            static function (WorkerManager $manager): void {
                $manager->containFailure(WorkerTickUnit::AGENT, 'unit_tick_guard:1', new RuntimeException('fell'));
            },
            null,
            WorkerManager::class,
        );
        $contain($manager);

        $this->assertNull(ExecutionContext::currentAgentId());
    }

    public function testTheProjectTickIsContainedWithoutStoppingTheAgentsBehindIt(): void
    {
        $manager = new WorkerManagerTickGuardTestManager();
        $manager->workerTickFails = true;
        $agent = new WorkerManagerTickGuardTestAgent('1');
        $manager->addTestAgent($agent);

        $manager->run();

        $this->assertSame(1, $agent->ticks);
        $this->assertStringContainsString('contained a failure in worker tick (onTick)', $this->logged());
    }

    /**
     * @return string Everything the tick put in the journal
     */
    private function logged(): string
    {
        return (string)file_get_contents($this->logFile);
    }
}

/**
 * Worker manager driven through exactly one loop iteration, without a daemon socket.
 */
final class WorkerManagerTickGuardTestManager extends WorkerManager
{
    /** Type of the daemon message the handler refuses, or null when it refuses none. */
    public ?string $failingMessageType = null;

    /** Whether the project's own tick hook raises. */
    public bool $workerTickFails = false;

    /** Whether the project's failure hook raises while answering. */
    public bool $hookFails = false;

    /** @var list<string> Types of the messages the handler saw through to the end. */
    public array $handledMessageTypes = [];

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

    /**
     * Puts an agent in the manager the loop ticks.
     *
     * @param AgentInterface $agent Agent to tick
     */
    public function addTestAgent(AgentInterface $agent): void
    {
        $this->agentManager->addAgent($agent->getId(), $agent);
    }

    /**
     * @param AgentInterface $agent Agent to look for
     * @return bool True while the manager still holds that agent
     */
    public function hasTestAgent(AgentInterface $agent): bool
    {
        return $this->agentManager->hasAgent($agent->getId());
    }

    public function handleDaemonMessage(WorkerDTO $data): void
    {
        if ($data->getType() === $this->failingMessageType) {
            throw new RuntimeException('daemon message refused');
        }

        $this->handledMessageTypes[] = $data->getType();
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new WorkerManagerTickGuardTestAgentManager();
    }

    protected function connectToDaemon(): void
    {
        $this->daemonClient = new WorkerManagerTickGuardTestClient($this->messages);
    }

    protected function onTick(): void
    {
        if ($this->workerTickFails) {
            throw new RuntimeException('project tick refused');
        }
    }

    protected function onTickFailure(ContainedFailure $failure): void
    {
        $this->containedFailures[] = $failure;

        if ($this->hookFails && $failure->unit !== WorkerTickUnit::FAILURE_HOOK) {
            throw new RuntimeException('project hook refused');
        }
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
 * Agent manager stub: the tick guard never creates agents, it is handed them.
 */
final class WorkerManagerTickGuardTestAgentManager extends AgentManager
{
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        throw new RuntimeException('The tick guard test never starts an agent.');
    }
}

/**
 * Agent that can be told to fail its tick, and remembers what it raised.
 */
final class WorkerManagerTickGuardTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_tick_guard';

    /** Whether this agent's tick raises. */
    public bool $failsOnTick = false;

    /** How many times the loop ticked this agent. */
    public int $ticks = 0;

    /** @var ?Throwable What the last failing tick raised. */
    public ?Throwable $raised = null;

    /**
     * @param string $agentIndex Index telling this agent from its neighbour
     */
    public function __construct(string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }

    public function onTick(): void
    {
        $this->ticks++;

        if ($this->failsOnTick) {
            $this->raised = new RuntimeException('agent tick refused');
            throw $this->raised;
        }
    }

    public function onStop(): void
    {
    }
}

/**
 * Daemon client stub: connected, silent, handing out scripted messages once.
 */
final class WorkerManagerTickGuardTestClient extends WorkerDaemonClient
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
 * Daemon message that is nothing but its type.
 */
final class WorkerManagerTickGuardTestMessage extends WorkerDTO
{
    /**
     * @param string $type Type this message reports
     */
    public function __construct(private string $type)
    {
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array{type: string} This message as the wire carries it
     */
    public function toArray(): array
    {
        return [self::TYPE => $this->type];
    }

    /**
     * @param array{type: string} $data Wire payload
     * @return static Message carrying the payload's type
     */
    public static function fromArray(array $data): static
    {
        return new static($data[self::TYPE]);
    }
}
