<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Socket\Server\AbstractServer;
use Hilos\Cluster\ClusterContext;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentIdleTracker;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\CronFireTestRequests;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\SignalRouter;
use Hilos\DaemonSection\DTO\DaemonAgentCronSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DaemonConnectionState;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronFireDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronFiredDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronScheduleDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use LogicException;
use PHPUnit\Framework\Attributes\Depends;
use ReflectionMethod;
use ReflectionProperty;
use Socket;

/**
 * An agent's cron rule fired once now travels master -> worker -> the agent's own tick -> master.
 *
 * Real socket pairs carry the console request, the worker registration, the arming frame and
 * the worker's outcome; the actual worker handler arms the rule, and the agent fires it in its
 * own onTick(). The worker's half of one loop pass is driven step by step in the order its loop
 * takes them, so the test can see that the cron report of the firing tick reaches the master
 * before the outcome the console is answered with.
 */
final class CronFireTransportIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string AGENT = 'cron_probe';
    private const string RULE = 'nightly';
    private const int WORKER_ID = 3;

    /** @var class-string<Hilos> */
    private string $previousAppClass;
    private ?EnvAccessor $previousEnv;
    private ?SignalRouter $previousRouter;
    private ?ClusterContext $previousCluster;
    private ?string $previousAgentId;
    private string|false $previousAppEnv;
    /** @var list<Socket> Open test sockets */
    private array $sockets = [];
    private CronTransportMaster $master;
    private CommandClient $caller;
    private Socket $terminal;
    private WorkerClient $receiver;
    private CronTransportSocket $sender;
    private CronTransportWorker $worker;
    private CronTransportProbeAgent $probe;
    /** @var list<array{WorkerAgentMessageDTO, bool}> Agent frames the master took, each with whether the console was answered by then */
    private array $reports = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousAppClass = Hilos::appClass();
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        $this->previousCluster = Hilos::$cluster;
        $this->previousAgentId = ExecutionContext::currentAgentId();
        $this->previousAppEnv = getenv('APP_ENV');
        $this->environment('test');
        Hilos::$cluster = null;

        $agents = $this->createMock(AgentManagerDaemon::class);
        $agents->method('getAgentWorkerId')->willReturnCallback(
            static fn (string $agentId): ?int => in_array($agentId, [self::AGENT, 'auditor'], true) ? self::WORKER_ID : null,
        );
        $agents->method('handleAgentMessage')->willReturnCallback(function (WorkerAgentMessageDTO $message): void {
            $this->reports[] = [$message, $this->caller->hasPendingWrite()];
        });
        $this->master = new CronTransportMaster($agents);
        $console = new CommandServer('127.0.0.1', 0);
        $this->master->registerServer($console);
        [$socket, $this->terminal] = $this->pair();
        $this->caller = new CommandClient($socket, $console);

        [$masterSocket, $workerSocket] = $this->pair();
        $this->receiver = new WorkerClient($masterSocket, $agents, $this->master->workers);
        $this->sender = new CronTransportSocket($workerSocket);
        $this->master->workers->attach($this->receiver);
        $this->sender->send(new WorkerRegisterDTO(self::WORKER_ID, false));
        $this->sender->write();
        $this->receiver->read();
        self::assertTrue($this->receiver->isRegistered());
        $this->receiver->write();
        $this->sender->read();
        self::assertInstanceOf(WorkerRegisteredDTO::class, $this->sender->getNextMessage());

        $this->probe = new CronTransportProbeAgent();
        $this->probe->rules = [new CronRule(self::RULE, '0 3 1 1 *')];
        $this->worker = new CronTransportWorker($this->sender, new CronTransportAgents($this->probe));
        // The agent reports its schedule only where the Daemon section is on.
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, CronTransportHilos::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        $this->sockets = [];
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
        Hilos::$cluster = $this->previousCluster;
        // The worker's tick leaves the agent it ran as current; the loop closes that unit, this case must.
        ExecutionContext::setCurrentAgentId($this->previousAgentId);
        putenv($this->previousAppEnv === false ? 'APP_ENV' : 'APP_ENV=' . $this->previousAppEnv);
        parent::tearDown();
    }

    public function testTheRuleFiresOnTheAgentsTickAndItsReportReachesTheMasterBeforeTheAnswer(): void
    {
        $this->request(self::AGENT, self::RULE);
        self::assertFalse($this->caller->hasPendingWrite());
        self::assertSame(1, $this->pendingCount());

        $this->armOnWorker();
        self::assertTrue($this->probe->rules[0]->isArmedForNextCheck());
        self::assertFalse($this->sender->hasPendingWrite(), 'Arming answers nothing yet');

        $this->worker->tickAgents();
        self::assertSame([self::RULE], $this->probe->fired);
        self::assertSame([self::AGENT], $this->probe->ranAs, 'The tick ran under the agent it ticked');
        self::assertFalse($this->sender->hasPendingWrite(), 'The firing tick queues its report and holds the outcome back');
        $this->worker->dispatchSignals();
        $this->worker->sendCronFiredAcks();
        $this->deliverToMaster();

        self::assertCount(1, $this->reports);
        [$report, $answeredByThen] = $this->reports[0];
        self::assertFalse($answeredByThen, 'The report arrived before the console was answered');
        self::assertSame(HilosSignalConstants::DAEMON_AGENT_CRON, $report->signal->signalName->getName());
        $schedule = $report->signal->data?->data;
        self::assertInstanceOf(DaemonAgentCronSignalData::class, $schedule);
        $firedAt = $this->probe->rules[0]->lastFiredAt * 1000;
        self::assertSame(self::AGENT, $schedule->agentId);
        self::assertSame($firedAt, $schedule->rules[0]->lastRunAt);

        $reply = $this->readReply();
        self::assertTrue($reply->isOk());
        self::assertSame('fire-1', $reply->correlationId);
        self::assertSame(['firedAt' => $firedAt], $reply->payload);
        self::assertSame(0, $this->pendingCount());
        self::assertSame('0 3 1 1 *', $this->probe->rules[0]->expression);

        $this->worker->tickAgents();
        $this->worker->dispatchSignals();
        $this->worker->sendCronFiredAcks();
        self::assertSame([self::RULE], $this->probe->fired, 'Fired once');
        self::assertFalse($this->sender->hasPendingWrite(), 'Answered once');
    }

    /**
     * The tick above ran as the probe agent, and the case after it starts with nobody current:
     * a write from a test is judged against the agent a worker tick left behind, not against none.
     */
    #[Depends('testTheRuleFiresOnTheAgentsTickAndItsReportReachesTheMasterBeforeTheAnswer')]
    public function testTheAgentTheTickRanAsIsNotLeftCurrentForTheNextCase(): void
    {
        self::assertNull($this->previousAgentId, 'The case before left an agent current for this one');
        self::assertNull(ExecutionContext::currentAgentId());
    }

    public function testARuleTheAgentDoesNotHoldIsTheWorkersRefusal(): void
    {
        $this->request(self::AGENT, 'weekly');
        $this->armOnWorker();
        $this->deliverToMaster();

        $this->assertRefusal('Agent cron_probe holds no cron rule weekly.');
        self::assertFalse($this->probe->rules[0]->isArmedForNextCheck());
    }

    public function testAnAgentTheMasterPlacesButTheWorkerDoesNotRunIsTheWorkersRefusal(): void
    {
        $this->request('auditor', self::RULE);
        $this->armOnWorker();
        $this->deliverToMaster();

        $this->assertRefusal('No agent auditor runs on this worker.');
    }

    public function testAnAgentNotOnThisNodeIsRefusedWithoutAFrame(): void
    {
        $this->request('reporter', self::RULE);

        $this->assertRefusal('No agent reporter runs on this node.');
        self::assertFalse($this->receiver->hasPendingWrite());
    }

    public function testTheWorkerAsksTheGateAgain(): void
    {
        $this->request(self::AGENT, self::RULE);
        $this->environment('prod');
        $this->armOnWorker();
        $this->environment('test');
        $this->deliverToMaster();

        $this->assertRefusal(TestOnlyCommandOnProductionException::message(CliCommands::CRON_TEST_FIRE));
        self::assertFalse($this->probe->rules[0]->isArmedForNextCheck());
    }

    public function testAnAgentStoppedBeforeItsTickIsAnsweredByTheStop(): void
    {
        $this->request(self::AGENT, self::RULE);
        $this->armOnWorker();

        $this->worker->handleDaemonMessage(new AgentStopDTO(self::AGENT));
        $this->worker->tickAgents();
        $this->worker->sendCronFiredAcks();

        self::assertSame([], $this->probe->fired);
        $outcomes = array_values(array_filter(
            $this->framesToMaster(),
            static fn (WorkerDTO $frame): bool => $frame instanceof WorkerTestCronFiredDTO,
        ));
        self::assertCount(1, $outcomes);
        self::assertSame(['fire-1', false, null, 'Agent cron_probe stopped before the rule fired.'], [
            $outcomes[0]->correlationId, $outcomes[0]->ok, $outcomes[0]->firedAt, $outcomes[0]->error,
        ]);
        $this->master->acceptCronFired($this->receiver, $outcomes[0]);
        $this->assertRefusal('Agent cron_probe stopped before the rule fired.');
    }

    public function testAWorkerThatWentDownEndsTheWait(): void
    {
        $this->request(self::AGENT, self::RULE);
        $this->armOnWorker();

        $socket = $this->receiver->getSocket();
        $this->receiver->close();
        $this->master->workers->detach($this->receiver);
        $this->sockets = array_values(array_filter($this->sockets, static fn (Socket $open): bool => $open !== $socket));

        $this->assertRefusal('The worker running agent cron_probe went down before the rule fired.');
        self::assertSame(0, $this->pendingCount());
    }

    public function testACallerWhoLeftIsNotAnsweredWhenTheRuleFires(): void
    {
        $this->request(self::AGENT, self::RULE);
        $this->armOnWorker();
        $socket = $this->caller->getSocket();
        $this->caller->close();
        $this->sockets = array_values(array_filter($this->sockets, static fn (Socket $open): bool => $open !== $socket));
        self::assertSame(0, $this->pendingCount());

        $this->worker->tickAgents();
        $this->worker->dispatchSignals();
        $this->worker->sendCronFiredAcks();
        $this->deliverToMaster();

        self::assertSame([self::RULE], $this->probe->fired, 'Work already sent still runs');
        self::assertFalse($this->caller->hasPendingWrite());
    }

    public function testAgentSchedulePausesAreAppliedAndCanceledBeforeAnyTickOrReport(): void
    {
        $this->probe->rules = [new CronRule(self::RULE, '* * * * *')];
        $this->probe->rules[0]->lastRun = 0.0;
        $rule = $this->probe->rules[0];
        $pauseId = $this->pauseSchedule();
        self::assertFalse($rule->shouldRun());
        self::assertNull($rule->lastFiredAt);
        self::assertSame([], $this->reports);
        self::assertSame([], $this->probe->ranAs, 'No agent tick was needed to answer');
        self::assertSame([], $this->probe->fired);

        $this->requestSchedule(CliCommands::CRON_TEST_CANCEL_PAUSE, ['pauseId' => $pauseId]);
        $this->applyScheduleOnWorker();
        $this->deliverToMaster();
        $reply = $this->readReply();
        self::assertTrue($reply->isOk());
        self::assertSame([], $reply->payload);
        self::assertSame(0, $this->pendingCount());
        self::assertNull($rule->lastFiredAt);
        self::assertFalse($rule->isArmedForNextCheck());
        self::assertSame([], $this->reports);
        self::assertTrue($rule->shouldRun(), 'The next natural check resumes');
    }

    public function testUnknownRepeatedAndReplacedRulePauseKeysAreRefused(): void
    {
        $this->probe->rules = [new CronRule(self::RULE, '* * * * *')];
        $this->probe->rules[0]->lastRun = 0.0;
        $firstId = $this->pauseSchedule();
        $secondId = $this->pauseSchedule();
        self::assertNotSame($firstId, $secondId);
        $selector = json_decode(base64_decode(strtr($firstId, '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);
        $selector[2] = str_repeat('0', 32);
        $unknownId = rtrim(strtr(base64_encode(json_encode($selector, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $this->requestSchedule(CliCommands::CRON_TEST_CANCEL_PAUSE, ['pauseId' => $unknownId]);
        $this->applyScheduleOnWorker();
        $this->deliverToMaster();
        $this->assertRefusal("No pause {$unknownId}");
        self::assertFalse($this->probe->rules[0]->shouldRun());

        $this->requestSchedule(CliCommands::CRON_TEST_CANCEL_PAUSE, ['pauseId' => $firstId]);
        $this->applyScheduleOnWorker();
        $this->deliverToMaster();
        self::assertTrue($this->readReply()->isOk());
        self::assertFalse($this->probe->rules[0]->shouldRun(), 'A different pause stays');
        $this->requestSchedule(CliCommands::CRON_TEST_CANCEL_PAUSE, ['pauseId' => $firstId]);
        $this->applyScheduleOnWorker();
        $this->deliverToMaster();
        $this->assertRefusal("No pause {$firstId}");

        $this->probe->rules = [new CronRule(self::RULE, '* * * * *')];
        $this->probe->rules[0]->lastRun = 0.0;
        $currentId = $this->pauseSchedule();
        $this->requestSchedule(CliCommands::CRON_TEST_CANCEL_PAUSE, ['pauseId' => $secondId]);
        $this->applyScheduleOnWorker();
        $this->deliverToMaster();
        $this->assertRefusal("No pause {$secondId}");
        self::assertFalse($this->probe->rules[0]->shouldRun(), 'An old instance key cannot clear the new instance pause');
        $this->requestSchedule(CliCommands::CRON_TEST_CANCEL_PAUSE, ['pauseId' => $currentId]);
        $this->applyScheduleOnWorker();
        $this->deliverToMaster();
        self::assertTrue($this->readReply()->isOk());
        self::assertTrue($this->probe->rules[0]->shouldRun());
    }

    public function testTheWorkerRechecksEnvironmentForBothScheduleOperations(): void
    {
        $this->probe->rules = [new CronRule(self::RULE, '* * * * *')];
        $this->probe->rules[0]->lastRun = 0.0;
        $this->requestSchedule(CliCommands::CRON_TEST_PAUSE, ['agentId' => self::AGENT, 'ruleName' => self::RULE]);
        $this->environment('prod');
        $this->applyScheduleOnWorker();
        $this->environment('test');
        $this->deliverToMaster();
        $this->assertRefusal(TestOnlyCommandOnProductionException::message(CliCommands::CRON_TEST_PAUSE));
        self::assertTrue($this->probe->rules[0]->shouldRun(), 'The refused pause was never installed');

        $this->probe->rules = [new CronRule(self::RULE, '* * * * *')];
        $this->probe->rules[0]->lastRun = 0.0;
        $pauseId = $this->pauseSchedule();
        $this->requestSchedule(CliCommands::CRON_TEST_CANCEL_PAUSE, ['pauseId' => $pauseId]);
        $this->environment('staging');
        $this->applyScheduleOnWorker();
        $this->environment('test');
        $this->deliverToMaster();
        $this->assertRefusal(TestOnlyCommandOnProductionException::message(CliCommands::CRON_TEST_CANCEL_PAUSE));
        self::assertFalse($this->probe->rules[0]->shouldRun(), 'The refused cancellation left the pause in place');
    }

    /** @return string Pause key delivered over the command socket after the owner applied it */
    private function pauseSchedule(): string
    {
        $this->requestSchedule(CliCommands::CRON_TEST_PAUSE, ['agentId' => self::AGENT, 'ruleName' => self::RULE]);
        self::assertFalse($this->caller->hasPendingWrite());
        self::assertSame(1, $this->pendingCount());
        $this->applyScheduleOnWorker();
        self::assertTrue($this->sender->hasPendingWrite(), 'The owner answers without a tick');
        $this->deliverToMaster();
        $reply = $this->readReply();
        self::assertTrue($reply->isOk());
        self::assertSame(['pauseId'], array_keys($reply->payload));
        self::assertIsString($reply->payload['pauseId']);
        self::assertSame(0, $this->pendingCount());
        return $reply->payload['pauseId'];
    }

    /**
     * @param string $command Test-only schedule command
     * @param array<string, mixed> $payload Real command wire payload
     */
    private function requestSchedule(string $command, array $payload): void
    {
        $frame = new CommandRequestDTO('schedule-1', $command, $payload)->toJson() . "\n";
        self::assertSame(strlen($frame), socket_write($this->terminal, $frame));
        $this->caller->read();
        $this->caller->onTick();
        self::assertFalse($this->caller->hasPendingWrite(), 'The owner read/tick portion must keep waiting for the asynchronous ACK');
        self::assertSame(1, $this->pendingCount());
    }

    /** Delivers the schedule frame to the real worker handler without ticking any agent. */
    private function applyScheduleOnWorker(): void
    {
        $this->receiver->write();
        $this->sender->read();
        $frame = $this->sender->getNextMessage();
        self::assertInstanceOf(WorkerTestCronScheduleDTO::class, $frame);
        $this->worker->handleDaemonMessage($frame);
        self::assertNull($this->sender->getNextMessage());
    }

    /** @param string $env Environment of the process being exercised */
    private function environment(string $env): void
    {
        putenv('APP_ENV=' . $env);
        Hilos::$env = new EnvAccessor();
    }

    /** @return array{Socket, Socket} Nonblocking endpoints retained for cleanup */
    private function pair(): array
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        foreach ($pair as $socket) {
            self::assertTrue(socket_set_nonblock($socket));
            $this->sockets[] = $socket;
        }
        return $pair;
    }

    /**
     * @param string $agentId Owner on the real console wire
     * @param string $ruleName Rule on the real console wire
     */
    private function request(string $agentId, string $ruleName): void
    {
        $frame = json_encode(
            new CommandRequestDTO('fire-1', CliCommands::CRON_TEST_FIRE, ['agentId' => $agentId, 'ruleName' => $ruleName])->toArray(),
            JSON_THROW_ON_ERROR,
        ) . "\n";
        self::assertSame(strlen($frame), socket_write($this->terminal, $frame));
        $this->caller->read();
    }

    /** Carries the master's frame to the worker and has the real handler take it. */
    private function armOnWorker(): void
    {
        $this->receiver->write();
        $this->sender->read();
        $frame = $this->sender->getNextMessage();
        self::assertInstanceOf(WorkerTestCronFireDTO::class, $frame);
        $this->worker->handleDaemonMessage($frame);
        self::assertNull($this->sender->getNextMessage());
    }

    private function deliverToMaster(): void
    {
        $this->sender->write();
        $this->receiver->read();
    }

    /** @return list<WorkerDTO> Frames the worker wrote, read off the master's end without handling them */
    private function framesToMaster(): array
    {
        $this->sender->write();
        $wire = socket_read($this->receiver->getSocket(), 65536);
        self::assertIsString($wire);
        return array_map(
            static fn (string $line): WorkerDTO => WorkerDTO::factoryWorkerDTO($line),
            array_values(array_filter(explode("\n", $wire), static fn (string $line): bool => $line !== '')),
        );
    }

    /** @return CommandReplyDTO Outcome delivered over the held console socket */
    private function readReply(): CommandReplyDTO
    {
        self::assertTrue($this->caller->hasPendingWrite());
        $this->caller->write();
        $wire = socket_read($this->terminal, 65536);
        self::assertIsString($wire);
        self::assertSame(1, substr_count($wire, "\n"));
        return CommandReplyDTO::fromJson(trim($wire));
    }

    /** @param string $reason Exact refusal expected on the console */
    private function assertRefusal(string $reason): void
    {
        $reply = $this->readReply();
        self::assertFalse($reply->isOk());
        self::assertSame(['message' => $reason], $reply->payload);
        self::assertSame(0, $this->pendingCount());
    }

    /** @return int Waits the master still holds */
    private function pendingCount(): int
    {
        return count(new ReflectionProperty(CronFireTestRequests::class, 'workers')->getValue($this->master->waits()));
    }
}

/** The real master wiring with a mocked agent roster and no process startup. */
final class CronTransportMaster extends DaemonManager
{
    public CronTransportServer $workers;

    /** @param AgentManagerDaemon $agents Roster naming the worker of each agent */
    public function __construct(private readonly AgentManagerDaemon $agents)
    {
        parent::__construct();
        $this->workers = new CronTransportServer();
        $this->registerServer($this->workers);
    }

    /** @return CronFireTestRequests Production waits */
    public function waits(): CronFireTestRequests
    {
        return new ReflectionProperty(DaemonManager::class, 'cronFireTestRequests')->getValue($this);
    }

    /** @return SignalRouter Real router; the worker in this process drains it too */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Test roster */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return $this->agents;
    }
}

/** Supplies an already connected client instead of starting worker processes. */
final class CronTransportServer extends WorkerServer
{
    /** Bypasses process startup; the test supplies already connected workers. */
    public function __construct()
    {
    }

    /** @param WorkerClient $worker Test socket accepted by this server */
    public function attach(WorkerClient $worker): void
    {
        $this->addClient($worker);
    }

    /** @param WorkerClient $worker Channel taken out of the registry */
    public function detach(WorkerClient $worker): void
    {
        new ReflectionMethod(AbstractServer::class, 'removeClient')->invoke($this, $worker);
    }

    /** No listening socket or child process is needed by the test. */
    protected function onStart(): void
    {
    }
}

/** The real worker handler and the steps of one loop pass, bound to a connected IPC socket. */
final class CronTransportWorker extends WorkerManager
{
    /**
     * @param WorkerDaemonClient $sender Actual transport to the master
     * @param AgentManager $agents Agents this worker runs
     */
    public function __construct(WorkerDaemonClient $sender, AgentManager $agents)
    {
        $this->workerIndex = 3;
        $this->isMonopolistic = false;
        $this->daemonClient = $sender;
        $this->agentManager = $agents;
        new ReflectionProperty(WorkerManager::class, 'agentIdleTracker')->setValue($this, new AgentIdleTracker());
    }

    /** The agents' tick of one loop pass. */
    public function tickAgents(): void
    {
        new ReflectionMethod(WorkerManager::class, 'tickAgents')->invoke($this);
    }

    /** The signal dispatch that follows the agents' tick in the loop. */
    public function dispatchSignals(): void
    {
        new ReflectionMethod(WorkerManager::class, 'dispatchSignals')->invoke($this);
    }

    /** The outcomes of fired rules, sent after the dispatch. */
    public function sendCronFiredAcks(): void
    {
        new ReflectionMethod(WorkerManager::class, 'sendCronFiredAcks')->invoke($this);
    }

    /** @return SignalRouter Unused; the process-wide router is the master's */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /**
     * @return AgentManager Never: the test hands the agents in
     * @throws LogicException On unexpected initialization
     */
    protected function createAgentManager(): AgentManager
    {
        throw new LogicException('The cron fire transport test hands its agents in');
    }
}

/** One probe agent, already started on the worker. */
final class CronTransportAgents extends AgentManager
{
    /** @param CronTransportProbeAgent $probe Agent the worker runs */
    public function __construct(CronTransportProbeAgent $probe)
    {
        $this->addAgent($probe->getId(), $probe);
    }

    /**
     * @param string $agentType Unused agent type
     * @param ?string $agentIndex Unused instance index
     * @return AgentInterface Never returned
     * @throws LogicException Always: no agent starts in this test
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        throw new LogicException('The cron fire transport test starts no agents');
    }
}

/** Checks its rule in its own tick, as an agent with a schedule does. */
final class CronTransportProbeAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'cron_probe';

    /** @var list<CronRule> */
    public array $rules = [];

    /** @var list<string> Names of the rules this agent's tick ran */
    public array $fired = [];

    /** @var list<?string> Agent the worker had current in each tick this agent ran */
    public array $ranAs = [];

    public function onTick(): void
    {
        $this->ranAs[] = ExecutionContext::currentAgentId();
        foreach ($this->rules as $rule) {
            if ($rule->shouldRun()) {
                $this->fired[] = $rule->name;
            }
        }
    }

    public function onStop(): void
    {
    }

    /** @return list<CronRule> Current rules */
    protected function cronRules(): array
    {
        return $this->rules;
    }
}

/** Real worker socket reader and writer over an already connected pair. */
final class CronTransportSocket extends WorkerDaemonClient
{
    /** @param Socket $socket Worker side of the pair */
    public function __construct(Socket $socket)
    {
        $this->socket = $socket;
        $this->state = DaemonConnectionState::CONNECTED;
    }
}

abstract class CronTransportHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::DAEMON];
}
