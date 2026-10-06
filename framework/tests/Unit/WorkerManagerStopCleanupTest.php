<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Analytics\AnalyticsJournalRecord;
use Hilos\Core\Daemon\BaseManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalType;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Socket\Exception\Base\BrokenPipeException;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\Worker\DaemonConnectionState;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartFailedDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceReleasedDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Hilos;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;
use ErrorException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Socket;

/**
 * Unit tests for worker-side agent stop cleanup.
 */
final class WorkerManagerStopCleanupTest extends TestCase
{
    /** @var array<int, Socket> Daemon ends of the socket pairs opened by a test */
    private array $daemonEnds = [];

    /** Error reporting level to restore with the error handler a test installed, or null while none is. */
    private ?int $reportingBeforeHandler = null;

    public function tearDown(): void
    {
        foreach (['', ':1', ':2'] as $indexSuffix) {
            TruthSourceRegistry::unregisterAgent(WorkerManagerStopCleanupTestAgent::AGENT_TYPE . $indexSuffix);
            RtTruthSourceRegistry::unregisterAgent(WorkerManagerStopCleanupTestAgent::AGENT_TYPE . $indexSuffix);
        }
        foreach ($this->daemonEnds as $daemonEnd) {
            socket_close($daemonEnd);
        }
        $this->daemonEnds = [];
        if ($this->reportingBeforeHandler !== null) {
            restore_error_handler();
            error_reporting($this->reportingBeforeHandler);
            $this->reportingBeforeHandler = null;
        }

        parent::tearDown();
    }

    public function testAgentStopUnregistersTruthSourcesAfterStopHookThrows(): void
    {
        $agent = new WorkerManagerStopCleanupTestAgent();
        $manager = new WorkerManagerStopCleanupTestManager($agent);

        $manager->handleDaemonMessage(new AgentStartDTO(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));

        $this->assertTrue(TruthSourceRegistry::hasTruthSource($agent->dbCollection()));
        $this->assertTrue(RtTruthSourceRegistry::hasTruthSource($agent->rtCollection()));

        $manager->handleDaemonMessage(new AgentStopDTO(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));

        $this->assertTrue($agent->sawDbTruthSourceOnStop);
        $this->assertTrue($agent->sawRtTruthSourceOnStop);
        $this->assertFalse(TruthSourceRegistry::hasTruthSource($agent->dbCollection()));
        $this->assertFalse(RtTruthSourceRegistry::hasTruthSource($agent->rtCollection()));
    }

    public function testAgentStopRemovesAgentWhenStopHookThrows(): void
    {
        $agent = new WorkerManagerStopCleanupTestAgent();
        $manager = new WorkerManagerStopCleanupTestManager($agent);

        $manager->handleDaemonMessage(new AgentStartDTO(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));
        $manager->handleDaemonMessage(new AgentStopDTO(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));

        $this->assertFalse($manager->hostsAgent(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));
    }

    public function testCleanupStopsRemainingAgentsAndClosesTransportAfterStopHookThrows(): void
    {
        $failing = new WorkerManagerStopCleanupTestAgent('1');
        $surviving = new WorkerManagerStopCleanupTestAgent('2', throwOnStop: false);
        $manager = new WorkerManagerStopCleanupTestManager($failing, $surviving);
        $client = new WorkerManagerStopCleanupTestClient();
        $manager->attachClient($client);

        $manager->handleDaemonMessage(new AgentStartDTO($failing->getId()));
        $manager->handleDaemonMessage(new AgentStartDTO($surviving->getId()));

        $manager->runCleanup();

        $this->assertTrue($surviving->stopHookCalled);
        $this->assertFalse($manager->hostsAgent($failing->getId()));
        $this->assertFalse($manager->hostsAgent($surviving->getId()));
        $this->assertSame(1, $client->closeCount);
    }

    /**
     * A worker leaving the node sends the master what its agents' stop hooks queued, and the
     * release of their RT sources with it, before the connection closes (HIL-1136).
     */
    public function testCleanupSendsWhatTheStopHooksQueuedBeforeItClosesTheTransport(): void
    {
        $agent = new WorkerManagerStopCleanupTestAgent(throwOnStop: false);
        $agent->goodbyeAcceptKey = 'unit-stop-ak';
        $manager = new WorkerManagerStopCleanupTestManager($agent);
        $manager->attachClient($this->connectedClient($daemonEnd));
        $manager->handleDaemonMessage(new AgentStartDTO(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));

        $manager->runCleanup();

        $frames = $this->framesAt($daemonEnd);
        $released = array_values(array_filter(
            $frames,
            static fn(array $frame): bool => $frame[WorkerDTO::TYPE] === WorkerRtSourceReleasedDTO::MESSAGE_TYPE
                && $frame[AgentConstants::FIELD_AGENT_ID] === WorkerManagerStopCleanupTestAgent::AGENT_TYPE,
        ));
        $this->assertCount(1, $released);
        $goodbyes = array_values(array_filter(
            $frames,
            static fn(array $frame): bool => $frame[WorkerDTO::TYPE] === WorkerAgentMessageDTO::MESSAGE_TYPE,
        ));
        $this->assertCount(1, $goodbyes);
        $goodbye = WorkerDTO::factoryWorkerDTO((string)json_encode($goodbyes[0]));
        $this->assertInstanceOf(WorkerAgentMessageDTO::class, $goodbye);
        $this->assertSame(WorkerManagerStopCleanupTestAgent::GOODBYE_SIGNAL, $goodbye->signal->signalName->getName());
    }

    /**
     * On an orderly shutdown, each agent's stop report follows every stop-hook message.
     */
    public function testShutdownReportsEachStoppedAgentAfterStopHookFrames(): void
    {
        $first = new WorkerManagerStopCleanupTestAgent('1', throwOnStop: false);
        $second = new WorkerManagerStopCleanupTestAgent('2', throwOnStop: false);
        $first->goodbyeAcceptKey = 'unit-stop-ak';
        $manager = new WorkerManagerStopCleanupTestManager($first, $second);
        $manager->attachClient($this->connectedClient($daemonEnd));
        $manager->handleDaemonMessage(new AgentStartDTO($first->getId()));
        $manager->handleDaemonMessage(new AgentStartDTO($second->getId()));

        $manager->handleShutdown();
        $manager->runCleanup();

        $frames = $this->framesAt($daemonEnd);
        $goodbyePosition = null;
        $stopPositions = [];
        $stoppedIds = [];
        foreach ($frames as $position => $frame) {
            if ($frame[WorkerDTO::TYPE] === WorkerAgentMessageDTO::MESSAGE_TYPE) {
                $goodbyePosition = $position;
            }
            if ($frame[WorkerDTO::TYPE] === WorkerConstants::MESSAGE_AGENT_STOPPED) {
                $stopPositions[] = $position;
                $stoppedIds[] = $frame[AgentConstants::FIELD_AGENT_ID];
            }
        }

        $this->assertNotNull($goodbyePosition);
        $this->assertSame([$first->getId(), $second->getId()], $stoppedIds);
        $this->assertGreaterThan($goodbyePosition, $stopPositions[0]);
        $this->assertGreaterThan($goodbyePosition, $stopPositions[1]);
    }

    /**
     * Cleanup after an error still sends stop-hook frames, but leaves worker-loss reports to the master.
     */
    public function testCleanupWithoutShutdownSignalDoesNotReportAgentsStopped(): void
    {
        $agent = new WorkerManagerStopCleanupTestAgent(throwOnStop: false);
        $manager = new WorkerManagerStopCleanupTestManager($agent);
        $manager->attachClient($this->connectedClient($daemonEnd));
        $manager->handleDaemonMessage(new AgentStartDTO($agent->getId()));

        $manager->runCleanup();

        $this->assertSame([], array_values(array_filter(
            $this->framesAt($daemonEnd),
            static fn(array $frame): bool => $frame[WorkerDTO::TYPE] === WorkerConstants::MESSAGE_AGENT_STOPPED,
        )));
    }

    /**
     * A contained stop-hook failure still completes that agent's stop and report.
     */
    public function testShutdownReportsAgentWhoseStopHookThrows(): void
    {
        $agent = new WorkerManagerStopCleanupTestAgent();
        $manager = new WorkerManagerStopCleanupTestManager($agent);
        $manager->attachClient($this->connectedClient($daemonEnd));
        $manager->handleDaemonMessage(new AgentStartDTO($agent->getId()));

        $manager->handleShutdown();
        $manager->runCleanup();

        $stopped = array_values(array_filter(
            $this->framesAt($daemonEnd),
            static fn(array $frame): bool => $frame[WorkerDTO::TYPE] === WorkerConstants::MESSAGE_AGENT_STOPPED,
        ));
        $this->assertTrue($agent->stopHookCalled);
        $this->assertCount(1, $stopped);
        $this->assertSame($agent->getId(), $stopped[0][AgentConstants::FIELD_AGENT_ID]);
    }

    /**
     * The worker's last analytics - the stops of its agents and of itself, and whatever the batch
     * still held - leave with the same dispatch as the stop hooks' frames, before the connection
     * closes (HIL-1154); called after the close, they had nowhere to go.
     */
    public function testCleanupSendsTheLastAnalyticsBatchBeforeItClosesTheTransport(): void
    {
        $previousCollector = Hilos::$ac;
        Hilos::$ac = new AnalyticsCollector();
        try {
            $agent = new WorkerManagerStopCleanupTestAgent(throwOnStop: false);
            $manager = new WorkerManagerStopCleanupTestManager($agent);
            $manager->attachClient($this->connectedClient($daemonEnd));
            Hilos::$ac->openWorkerSession(1, false);
            $manager->handleDaemonMessage(new AgentStartDTO(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));

            $manager->runCleanup();

            $batches = array_values(array_filter(
                array_map(static fn(array $frame): string => (string)json_encode($frame), $this->framesAt($daemonEnd)),
                static fn(string $frame): bool => str_contains($frame, HilosSignalConstants::ANALYTICS_JOURNAL_APPEND),
            ));
            $this->assertCount(1, $batches);
            $this->assertStringContainsString(AnalyticsJournalRecord::TYPE_AGENT_SESSION_STOP, $batches[0]);
            $this->assertStringContainsString(AnalyticsJournalRecord::TYPE_WORKER_SESSION_STOP, $batches[0]);
        } finally {
            Hilos::$ac = $previousCollector;
        }
    }

    /**
     * A master that went before the worker takes the goodbye with it, and the worker still leaves.
     *
     * The failed write is a socket exception: the call is suppressed, so the warning handler
     * a running worker installs does not turn it into an ErrorException. The worker logs the
     * frames it could not send once, runs the stop hook, and closes the client.
     */
    public function testCleanupLeavesQuietlyWhenTheDaemonWentFirst(): void
    {
        $logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-stop-cleanup');
        Logger::setLogFile($logFile);
        try {
            $agent = new WorkerManagerStopCleanupTestAgent(throwOnStop: false);
            $agent->goodbyeAcceptKey = 'unit-stop-ak';
            $manager = new WorkerManagerStopCleanupTestManager($agent);
            $client = $this->connectedClient($daemonEnd);
            $manager->attachClient($client);
            $manager->handleDaemonMessage(new AgentStartDTO(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));
            socket_close($daemonEnd);
            array_pop($this->daemonEnds);
            $this->installManagerErrorHandler();

            $manager->runCleanup();

            $this->assertTrue($agent->stopHookCalled);
            $this->assertFalse($manager->hostsAgent(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));
            $this->assertTrue($manager->releasedDaemonClient());
            $this->assertSame(DaemonConnectionState::IDLE, $client->currentState());
            $logged = (string)file_get_contents($logFile);
            $this->assertSame(
                1,
                substr_count($logged, "the daemon went before the stop hooks' frames were written"),
            );
            $this->assertStringContainsString(BrokenPipeException::class, $logged);
            $this->assertStringNotContainsString(ErrorException::class, $logged);
        } finally {
            Logger::resetLogFile();
            if (is_file($logFile)) {
                unlink($logFile);
            }
        }
    }

    /**
     * An orphan - its master gone without the connection closing (HIL-520) - writes nothing on
     * the way out, rather than waiting on a socket nobody reads.
     */
    public function testAnOrphanedWorkerLeavesWithoutWritingItsGoodbye(): void
    {
        $agent = new WorkerManagerStopCleanupTestAgent(throwOnStop: false);
        $agent->goodbyeAcceptKey = 'unit-stop-ak';
        $manager = new WorkerManagerStopCleanupTestManager($agent);
        $manager->attachClient($this->connectedClient($daemonEnd));
        $manager->parentPid = 4242;
        $manager->rememberDaemonPid();
        $manager->parentPid = 1;
        $manager->handleDaemonMessage(new AgentStartDTO(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));

        $manager->runCleanup();

        $this->assertTrue($agent->stopHookCalled);
        $this->assertSame([], $this->framesAt($daemonEnd));
    }

    /**
     * The master holds every frame for an agent until its start is reported one way or the other,
     * so a start that refuses says so before the failure goes on to the loop's containment (HIL-629).
     */
    public function testAStartThatThrowsReportsItsFailureBeforeTheThrowTravelsOn(): void
    {
        $manager = new WorkerManagerStopCleanupTestManager();
        $client = new WorkerManagerStopCleanupTestClient();
        $manager->attachClient($client);
        $agentId = WorkerManagerStopCleanupTestAgent::AGENT_TYPE . ':9';

        try {
            $manager->handleDaemonMessage(new AgentStartDTO($agentId));
            $this->fail('The start of an agent the factory cannot build must throw');
        } catch (RuntimeException $failure) {
            $this->assertCount(1, $client->sent);
            $report = $client->sent[0];
            $this->assertInstanceOf(WorkerAgentStartFailedDTO::class, $report);
            $this->assertSame($agentId, $report->agentId);
            $this->assertSame('9', $report->agentIndex);
            $this->assertSame($failure->getMessage(), $report->reason);
        }

        $this->assertFalse($manager->hostsAgent($agentId));
    }

    /**
     * An agent whose onStart() throws is one this worker keeps, so it is reported as STARTED and
     * the throw does not leave the start at all (HIL-1040).
     *
     * A master told "the start failed" would be told something untrue - the agent is here, holds
     * its claims and takes messages - and would answer with a refusal every frame it is holding
     * for it, which before HIL-629 that agent simply received. Reported as failed OR not reported
     * at all, the frames wait on a report that never comes, because the hold has no deadline
     * behind it any more.
     */
    public function testAStartHookThatThrowsIsReportedAsAStartedAgentAndDoesNotEscape(): void
    {
        $agent = new WorkerManagerStopCleanupTestAgent(throwOnStop: false);
        $agent->startException = new RuntimeException('start hook failed');
        $manager = new WorkerManagerStopCleanupTestManager($agent);
        $client = new WorkerManagerStopCleanupTestClient();
        $manager->attachClient($client);

        $manager->handleDaemonMessage(new AgentStartDTO(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));

        $this->assertSame([], array_values(array_filter(
            $client->sent,
            static fn(WorkerDTO|array $sent): bool => $sent instanceof WorkerAgentStartFailedDTO,
        )));
        // The report goes out as a raw array rather than a DTO, so it is read by its type field.
        $started = array_values(array_filter(
            $client->sent,
            static fn(WorkerDTO|array $sent): bool => is_array($sent)
                && isset($sent[WorkerDTO::TYPE])
                && $sent[WorkerDTO::TYPE] === WorkerConstants::MESSAGE_AGENT_STARTED,
        ));
        $this->assertCount(1, $started);
        $this->assertSame(
            WorkerManagerStopCleanupTestAgent::AGENT_TYPE,
            $started[0][AgentConstants::FIELD_AGENT_ID],
        );
        $this->assertTrue($manager->hostsAgent(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));
    }

    public function testHandshakeValidationExceptionDoesNotEscapeWorkerMessage(): void
    {
        $agent = new WorkerManagerStopCleanupTestAgent();
        $agent->handshakeException = new ValidationException('bad handshake');
        $manager = new WorkerManagerStopCleanupTestManager($agent);

        $manager->handleDaemonMessage(new AgentStartDTO(WorkerManagerStopCleanupTestAgent::AGENT_TYPE));
        $manager->handleDaemonMessage(new DaemonAgentMessageDTO(
            WorkerManagerStopCleanupTestAgent::AGENT_TYPE,
            new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::HANDSHAKE),
                new SignalName(SignalTypeConstants::HANDSHAKE),
                new WebSocketHandshakeSignalDTO(
                    headers: [],
                    acceptKey: 'unit-handshake-ak',
                    cookies: [],
                    clientIp: '127.0.0.1',
                    queryParams: RequestQueryParams::empty(),
                ),
            ),
        ));

        $this->assertSame(1, $agent->handshakeCallCount);
    }

    /**
     * Builds a client sitting on a live socket pair, as if connect() had succeeded.
     *
     * @param ?Socket $daemonEnd Receives the daemon end of the pair
     * @return WorkerManagerStopCleanupTestSocketClient Client on the worker end
     */
    private function connectedClient(?Socket &$daemonEnd): WorkerManagerStopCleanupTestSocketClient
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$workerEnd, $daemonEnd] = $pair;
        socket_set_nonblock($workerEnd);
        socket_set_nonblock($daemonEnd);
        $this->daemonEnds[] = $daemonEnd;

        $client = new WorkerManagerStopCleanupTestSocketClient();
        $client->adoptConnectedSocket($workerEnd);

        return $client;
    }

    /**
     * Reads every frame the worker wrote so far to the daemon end.
     *
     * @param Socket $daemonEnd Daemon end of the pair
     * @return list<array<string, mixed>> Decoded frames, in the order written
     */
    private function framesAt(Socket $daemonEnd): array
    {
        $bytes = '';
        while (($chunk = socket_read($daemonEnd, 8192, PHP_BINARY_READ)) !== false && $chunk !== '') {
            $bytes .= $chunk;
        }

        $frames = [];
        foreach (explode("\n", $bytes) as $line) {
            if ($line !== '') {
                $frames[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $frames;
    }

    /**
     * Installs the error handler every Hilos manager installs around its work, warnings reported.
     *
     * The same shape as {@see BaseManager::errorHandler()}: an active warning becomes an
     * ErrorException, a suppressed one is left to PHP. The suite runs with warnings left out of
     * error_reporting, which a worker does not, so they are reported again for the case. Both
     * are restored in tearDown().
     */
    private function installManagerErrorHandler(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        $this->reportingBeforeHandler = error_reporting();
        error_reporting($this->reportingBeforeHandler | E_WARNING);
    }
}

final class WorkerManagerStopCleanupTestManager extends WorkerManager
{
    /** Parent pid reported to the manager instead of the real one. */
    public int $parentPid = 1;

    /** @var list<WorkerManagerStopCleanupTestAgent> Agents this manager hands out, in start order */
    private readonly array $testAgents;

    public function __construct(WorkerManagerStopCleanupTestAgent ...$testAgents)
    {
        $this->testAgents = $testAgents;

        parent::__construct(1);
    }

    /**
     * Puts a daemon client in place without opening a real connection.
     *
     * @param WorkerDaemonClient $client Client stub counting close() calls
     */
    public function attachClient(WorkerDaemonClient $client): void
    {
        $this->daemonClient = $client;
    }

    /**
     * @return bool True once cleanup has dropped the daemon client
     */
    public function releasedDaemonClient(): bool
    {
        return $this->daemonClient === null;
    }

    /**
     * @param string $agentId Agent id to look for
     * @return bool True while the manager still hosts that agent
     */
    public function hostsAgent(string $agentId): bool
    {
        return $this->agentManager->hasAgent($agentId);
    }

    /**
     * Runs the worker shutdown path the main loop runs after it exits.
     */
    public function runCleanup(): void
    {
        $this->cleanup();
    }

    /**
     * Captures the current parent pid the way run() does before connecting.
     */
    public function rememberDaemonPid(): void
    {
        $this->daemonPid = $this->currentParentPid();
    }

    protected function currentParentPid(): int
    {
        return $this->parentPid;
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new WorkerManagerStopCleanupTestAgentManager(...$this->testAgents);
    }
}

final class WorkerManagerStopCleanupTestAgentManager extends AgentManager
{
    /** @var array<string, WorkerManagerStopCleanupTestAgent> Agents keyed by their own agent index */
    private readonly array $testAgents;

    public function __construct(WorkerManagerStopCleanupTestAgent ...$testAgents)
    {
        $keyed = [];
        foreach ($testAgents as $agent) {
            $keyed[(string)$agent->getIndex()] = $agent;
        }
        $this->testAgents = $keyed;
    }

    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return $this->testAgents[(string)$agentIndex]
            ?? throw new RuntimeException("The test manager has no agent for index '{$agentIndex}'.");
    }
}

final class WorkerManagerStopCleanupTestAgent extends AbstractAgent
{
    /**
     * @var array<string, list<TruthSourceOperation>> The database collection this double holds
     *
     * Declared and not claimed from onStart(), because the resolver reads the constant off the
     * class before the instance exists - which is what the test drives here through
     * {@see WorkerManager::handleDaemonMessage()}.
     */
    public const array OWNS_DB = [self::DB_COLLECTION => TruthSourceOperation::BY_KIND];

    /** @var array<string, list<TruthSourceOperation>> The runtime collection this double holds */
    public const array OWNS_RT = [self::RT_COLLECTION => TruthSourceOperation::BY_KIND];

    public const string AGENT_TYPE = 'unit_stop_cleanup';
    public const string GOODBYE_SIGNAL = 'unit_stop_goodbye';
    public const string DB_COLLECTION = 'unit_stop_cleanup_db';
    public const string RT_COLLECTION = 'unit_stop_cleanup_rt';

    public bool $sawDbTruthSourceOnStop = false;
    public bool $sawRtTruthSourceOnStop = false;
    public bool $stopHookCalled = false;
    public int $handshakeCallCount = 0;
    public ?ValidationException $handshakeException = null;
    public ?RuntimeException $startException = null;

    /** Accept key the stop hook says goodbye to, or null for a stop hook that sends nothing. */
    public ?string $goodbyeAcceptKey = null;

    /**
     * @param ?string $agentIndex Agent index, so one test can host more than one instance
     * @param bool $throwOnStop Whether the stop hook fails, the case under containment
     */
    public function __construct(
        ?string $agentIndex = null,
        private readonly bool $throwOnStop = true,
    ) {
        $this->agentIndex = $agentIndex;
    }

    /**
     * @return string Truth-source collection this double holds, as declared on the class
     */
    public function dbCollection(): string
    {
        return self::DB_COLLECTION;
    }

    /**
     * @return string Runtime truth-source collection this double holds, as declared on the class
     */
    public function rtCollection(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * @throws RuntimeException When the case asked the start hook to fail
     */
    public function onStart(): void
    {
        if ($this->startException !== null) {
            throw $this->startException;
        }
    }

    public function onStop(): void
    {
        $this->stopHookCalled = true;
        if ($this->goodbyeAcceptKey !== null) {
            $this->sendToUser(self::GOODBYE_SIGNAL, $this->goodbyeAcceptKey, new SignalData());
        }
        $this->sawDbTruthSourceOnStop = TruthSourceRegistry::hasTruthSource($this->dbCollection());
        $this->sawRtTruthSourceOnStop = RtTruthSourceRegistry::hasTruthSource($this->rtCollection());

        if ($this->throwOnStop) {
            throw new RuntimeException('stop hook failed');
        }
    }

    public function onSignalHandshake(WebSocketHandshakeSignalDTO $data, string $source, string $name): void
    {
        $this->handshakeCallCount++;

        if ($this->handshakeException !== null) {
            throw $this->handshakeException;
        }
    }
}

/**
 * Daemon client stub that counts how often the worker closed it and keeps what it sent.
 */
final class WorkerManagerStopCleanupTestClient extends WorkerDaemonClient
{
    /** How many times cleanup closed this client. */
    public int $closeCount = 0;

    /** @var list<WorkerDTO|array<string, mixed>> Messages the worker sent to the daemon, in order */
    public array $sent = [];

    /**
     * @param WorkerDTO|array<string, mixed> $data Message the worker sent
     */
    public function send(WorkerDTO|array $data): void
    {
        $this->sent[] = $data;
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function close(): void
    {
        $this->closeCount++;
    }
}

/**
 * Client accepting a ready-made socket, so the worker writes to a daemon end the test reads.
 */
final class WorkerManagerStopCleanupTestSocketClient extends WorkerDaemonClient
{
    /**
     * Adopts an already established socket as the daemon connection.
     *
     * @param Socket $socket Worker end of a connected socket pair
     */
    public function adoptConnectedSocket(Socket $socket): void
    {
        $this->socket = $socket;
        $this->state = DaemonConnectionState::CONNECTED;
    }

    /**
     * @return DaemonConnectionState Current connection state
     */
    public function currentState(): DaemonConnectionState
    {
        return $this->state;
    }
}
