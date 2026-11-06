<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\AppEnv;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentIdleTracker;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\DirectoryWatchTrait;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\DaemonSection\DTO\DaemonAgentCronSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartFailedDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronFireDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronFiredDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Worker idle allowances are explicit declarations checked before a start becomes live. */
final class WorkerManagerIdleWaitTest extends TestCase
{
    /** @var class-string<Hilos> Facade restored after each start attempt */
    private string $previousAppClass;

    protected function setUp(): void
    {
        $this->previousAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, IdleWaitTestHilos::class);
        SourceInterestRegistry::readsWhatItMounts();
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(IdleWaitTestAgent::AGENT_TYPE));
        TruthSourceRegistry::unregisterAgent(IdleWaitTestAgent::AGENT_TYPE);
        RtTruthSourceRegistry::unregisterAgent(IdleWaitTestAgent::AGENT_TYPE);
        Hilos::$sr = null;
        Hilos::$rt = null;
        ExecutionContext::clear();
        parent::tearDown();
    }

    /**
     * @param IdleWaitTestAgent $agent Invalid declaration returned by the factory
     * @param string $reason Expected diagnostic reason
     */
    #[DataProvider('invalidAllowances')]
    public function testInvalidAllowanceRefusesTheRealStartWithoutLeavingState(IdleWaitTestAgent $agent, string $reason): void
    {
        $manager = new IdleWaitTestManager($agent);
        try {
            $manager->handleDaemonMessage(new AgentStartDTO($agent->getId()));
            self::fail('An invalid idle allowance must refuse the start');
        } catch (AgentException $failure) {
            self::assertStringContainsString($agent::class, $failure->getMessage());
            self::assertStringContainsString($reason, $failure->getMessage());
            $reports = array_values(array_filter(
                $manager->client->sent,
                static fn (WorkerDTO|array $message): bool => $message instanceof WorkerAgentStartFailedDTO,
            ));
            self::assertCount(1, $reports);
            self::assertSame($agent->getId(), $reports[0]->agentId);
            self::assertSame($failure->getMessage(), $reports[0]->reason);
        }
        self::assertFalse($agent->started);
        self::assertFalse($agent->stopped);
        self::assertFalse($manager->agents->registered);
        self::assertFalse($manager->agents->hasAgent($agent->getId()));
        self::assertFalse(SourceInterestRegistry::hasConsumers(SourceChange::KIND_DB, IdleWaitTestAgent::READ_COLLECTION));
        self::assertFalse(TruthSourceRegistry::hasTruthSource(IdleWaitTestAgent::OWNED_COLLECTION));
        self::assertFalse(RtTruthSourceRegistry::hasTruthSource(IdleWaitTestAgent::OWNED_COLLECTION));
        $tracker = new ReflectionProperty(WorkerManager::class, 'agentIdleTracker')->getValue($manager);
        self::assertInstanceOf(AgentIdleTracker::class, $tracker);
        self::assertFalse($tracker->isIdle($agent->getId(), 1, PHP_FLOAT_MAX));
        self::assertSame([], new ReflectionProperty(AgentIdleTracker::class, 'lastEventAt')->getValue($tracker));
        self::assertSame([], new ReflectionProperty(AgentIdleTracker::class, 'subscribers')->getValue($tracker));
    }

    /** @return iterable<string, array{IdleWaitTestAgent, string}> Invalid raw values and inherited watcher */
    public static function invalidAllowances(): iterable
    {
        yield 'string' => [new class extends IdleWaitTestAgent {
            protected const MAX_IDLE_SLEEP_SECONDS = '600';
        }, 'finite int or float'];
        yield 'bool' => [new class extends IdleWaitTestAgent {
            protected const MAX_IDLE_SLEEP_SECONDS = true;
        }, 'finite int or float'];
        yield 'null' => [new class extends IdleWaitTestAgent {
            protected const MAX_IDLE_SLEEP_SECONDS = null;
        }, 'finite int or float'];
        yield 'array' => [new class extends IdleWaitTestAgent {
            protected const MAX_IDLE_SLEEP_SECONDS = [];
        }, 'finite int or float'];
        yield 'nan' => [new class extends IdleWaitTestAgent {
            protected const MAX_IDLE_SLEEP_SECONDS = NAN;
        }, 'finite int or float'];
        yield 'infinity' => [new class extends IdleWaitTestAgent {
            protected const MAX_IDLE_SLEEP_SECONDS = INF;
        }, 'finite int or float'];
        yield 'negative infinity' => [new class extends IdleWaitTestAgent {
            protected const MAX_IDLE_SLEEP_SECONDS = -INF;
        }, 'finite int or float'];
        yield 'below minimum' => [new class extends IdleWaitTestAgent {
            protected const MAX_IDLE_SLEEP_SECONDS = 0.099;
        }, 'finite int or float'];
        yield 'above maximum' => [new class extends IdleWaitTestAgent {
            protected const MAX_IDLE_SLEEP_SECONDS = 600.001;
        }, 'finite int or float'];
        yield 'inherited directory watch' => [new class extends IdleWaitTestWatcher {
            protected const MAX_IDLE_SLEEP_SECONDS = 600;
        }, 'directory watching'];
    }

    /**
     * @param IdleWaitTestAgent $agent Valid declaration returned by the factory
     * @param float $seconds Expected allowance
     */
    #[DataProvider('validAllowances')]
    public function testValidAllowanceStartsNormally(IdleWaitTestAgent $agent, float $seconds): void
    {
        $manager = new IdleWaitTestManager($agent);
        $manager->handleDaemonMessage(new AgentStartDTO($agent->getId()));
        self::assertTrue($agent->started);
        self::assertTrue($manager->agents->registered);
        self::assertSame($agent, $manager->agents->getAgent($agent->getId()));
        self::assertSame($seconds, $agent->getMaxIdleSleepSeconds());
        self::assertTrue(SourceInterestRegistry::hasConsumers(SourceChange::KIND_DB, IdleWaitTestAgent::READ_COLLECTION));
    }

    /** @return iterable<string, array{IdleWaitTestAgent, float}> Both inclusive bounds and integer declarations */
    public static function validAllowances(): iterable
    {
        yield 'default' => [new IdleWaitTestAgent(), 0.1];
        yield 'minimum' => [new class extends IdleWaitTestAgent {
            protected const MAX_IDLE_SLEEP_SECONDS = 0.1;
        }, 0.1];
        yield 'integer' => [new class extends IdleWaitTestAgent {
            protected const MAX_IDLE_SLEEP_SECONDS = 1;
        }, 1.0];
        yield 'integer maximum' => [new class extends IdleWaitTestAgent {
            protected const MAX_IDLE_SLEEP_SECONDS = 600;
        }, 600.0];
        yield 'float maximum' => [new class extends IdleWaitTestAgent {
            protected const MAX_IDLE_SLEEP_SECONDS = 600.0;
        }, 600.0];
        yield 'watcher default' => [new IdleWaitTestWatcher(), 0.1];
    }

    public function testOnePostTickObservationPreparesTheDeadlineAndPublishesBeforeTheForcedFireAck(): void
    {
        $previousEnv = Hilos::$env;
        $previousAppEnv = getenv(EnvConstants::APP_ENV->name);
        $previousProjectZone = getenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);

        try {
            putenv(EnvConstants::APP_ENV->name . '=' . AppEnv::TEST->value);
            putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=' . TimeConstants::UTC_TIME_ZONE);
            Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
            $agent = new IdleWaitTestCronAgent();
            $manager = new IdleWaitTestManager($agent);
            $manager->handleDaemonMessage(new AgentStartDTO($agent->getId()));
            $manager->handleDaemonMessage(new WorkerTestCronFireDTO('idle-wait-fire', $agent->getId(), 'daily'));
            self::assertTrue($agent->rule->isArmedForNextCheck());
            $agent->observations = 0;
            $manager->client->sent = [];

            $manager->tickAndFlushTestAgents();

            self::assertSame(1, $agent->observations);
            self::assertFalse($agent->rule->isArmedForNextCheck());
            self::assertNotNull($agent->getCronDeadline());
            self::assertCount(2, $manager->client->sent);
            $report = $manager->client->sent[0];
            self::assertInstanceOf(WorkerAgentMessageDTO::class, $report);
            self::assertSame(HilosSignalConstants::DAEMON_AGENT_CRON, $report->signal->signalName->getName());
            $payload = $report->signal->data?->data;
            self::assertInstanceOf(DaemonAgentCronSignalData::class, $payload);
            self::assertSame(1_767_225_600_000, $payload->rules[0]->lastRunAt);
            $ack = $manager->client->sent[1];
            self::assertInstanceOf(WorkerTestCronFiredDTO::class, $ack);
            self::assertTrue($ack->ok);
            self::assertSame('idle-wait-fire', $ack->correlationId);
            self::assertSame(1_767_225_600_000, $ack->firedAt);
        } finally {
            Hilos::$env = $previousEnv;
            putenv(EnvConstants::APP_ENV->name . ($previousAppEnv === false ? '' : '=' . $previousAppEnv));
            putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . ($previousProjectZone === false ? '' : '=' . $previousProjectZone));
        }
    }
}

class IdleWaitTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_idle_wait';
    public const string READ_COLLECTION = 'unitIdleWaitRead';
    public const string OWNED_COLLECTION = 'unitIdleWaitOwned';
    public const array READS_DB = [self::READ_COLLECTION];
    public const array OWNS_DB = [self::OWNED_COLLECTION => TruthSourceOperation::BY_KIND];
    public const array OWNS_RT = [self::OWNED_COLLECTION => TruthSourceOperation::BY_KIND];

    public bool $started = false;
    public bool $stopped = false;

    /** Records the real start hook. */
    public function onStart(): void
    {
        $this->started = true;
    }

    /** Records any attempt to close resources. */
    public function onStop(): void
    {
        $this->stopped = true;
    }
}

class IdleWaitTestWatcher extends IdleWaitTestAgent
{
    use DirectoryWatchTrait;
}

final class IdleWaitTestCronAgent extends IdleWaitTestAgent
{
    protected const MAX_IDLE_SLEEP_SECONDS = 600;

    public CronRule $rule;
    public int $observations = 0;

    public function __construct()
    {
        $this->rule = new class('daily', '0 3 * * *') extends CronRule {
            /** @return int Controlled time of the forced check */
            protected function currentTimestamp(): int
            {
                return 1_767_225_600;
            }
        };
    }

    /** Runs the agent's ordinary check, including a test-only arm. */
    public function onTick(): void
    {
        $this->rule->shouldRun();
    }

    /** @return list<CronRule> The current schedule, counted once per worker unit */
    protected function cronRules(): array
    {
        $this->observations++;
        return [$this->rule];
    }
}

abstract class IdleWaitTestHilos extends Hilos
{
    public const array AGENTS = [
        IdleWaitTestAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => IdleWaitTestAgent::class],
    ];

    protected const array FEATURES = [HilosFeature::DAEMON];
}

final class IdleWaitTestManager extends WorkerManager
{
    public IdleWaitTestAgentManager $agents;
    public IdleWaitTestClient $client;

    /** @param IdleWaitTestAgent $agent Factory result for the start attempt */
    public function __construct(private readonly IdleWaitTestAgent $agent)
    {
        parent::__construct(1);
        $this->client = new IdleWaitTestClient();
        $this->daemonClient = $this->client;
    }

    /** Runs the real tick and its ordered report and acknowledgement drains without waiting. */
    public function tickAndFlushTestAgents(): void
    {
        Closure::bind(function (): void {
            $this->tickAgents();
            $this->dispatchSignals();
            $this->sendCronFiredAcks();
        }, $this, WorkerManager::class)();
    }

    /** @return SignalRouter Ordinary router for the real start path */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManager Factory that records registration separately from creation */
    protected function createAgentManager(): AgentManager
    {
        return $this->agents = new IdleWaitTestAgentManager($this->agent);
    }
}

final class IdleWaitTestAgentManager extends AgentManager
{
    public bool $registered = false;

    /** @param IdleWaitTestAgent $agent Factory result */
    public function __construct(private readonly IdleWaitTestAgent $agent)
    {
    }

    /**
     * @param string $agentId Agent being registered
     * @param AgentInterface $agent Created instance
     */
    public function addAgent(string $agentId, AgentInterface $agent): void
    {
        $this->registered = true;
        parent::addAgent($agentId, $agent);
    }

    /**
     * @param string $agentType Requested type
     * @param string|null $agentIndex Requested index
     * @return AgentInterface Factory result, with the raised interest already present
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        TestCase::assertTrue(SourceInterestRegistry::hasConsumers(SourceChange::KIND_DB, IdleWaitTestAgent::READ_COLLECTION));
        return $this->agent;
    }
}

final class IdleWaitTestClient extends WorkerDaemonClient
{
    /** @var list<WorkerDTO|array<string, mixed>> Start-path frames */
    public array $sent = [];

    public function __construct()
    {
    }

    /** @return bool The start has a reporting channel */
    public function isConnected(): bool
    {
        return true;
    }

    /** @param WorkerDTO|array<string, mixed> $data Frame emitted by the real worker */
    public function send(WorkerDTO|array $data): void
    {
        $this->sent[] = $data;
    }
}
