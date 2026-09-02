<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Sync\DTO\DbReHydrateSignalData;
use Hilos\Database\ReHydrateRound;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DbReHydrateCompleteDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydratedDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydrateMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The barrier the daemon holds over a database swap (HIL-436, HIL-694).
 *
 * Announcing that the database was replaced used to be a shout: the daemon passed the fact on to
 * its workers and moved on, so the node could be opened to verifiers while some process still
 * answered out of caches of a database that no longer existed - a verifier reading those confirms
 * a fiction. The daemon now counts the answers, and these cases pin the three things the count is
 * worth nothing without: who is counted, when the verdict is sent, and what it says when somebody
 * never answers.
 *
 * The verdict is fail-closed on every unhappy path. That is deliberate and not a cost-free
 * default: a node that stays shut costs an operator one command, while a node opened over a
 * process holding stale caches costs whatever was concluded from what it served.
 *
 * Counting answers is only worth something while the answers belong to the round doing the
 * counting. Every round is opened over the same labels - 'daemon', 'worker #1' - so a restore
 * started while a previous round is still out would otherwise be closed by that round's
 * stragglers, which is what the round number is here to prevent.
 */
final class DaemonManagerDbReHydrateBarrierTest extends TestCase
{
    /** @var string Agent that announced the swap in these cases */
    private const string INITIATOR = 'hilos_backup';

    /** Round another node was already waiting under when it told this one about a swap. */
    private const int ANNOUNCER_ROUND = 11;

    /** @var ?EnvAccessor Env accessor to restore after the test */
    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$env = new EnvAccessor();
        // Long enough that nothing expires unless a case asks for it by shortening the wait.
        putenv(EnvConstants::HILOS_DB_REHYDRATE_TIMEOUT->name . '=3600');
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$env = $this->previousEnv;
        putenv(EnvConstants::HILOS_DB_REHYDRATE_TIMEOUT->name);

        parent::tearDown();
    }

    public function testTheAnnouncementReachesEveryWorkerAndNamesTheAgentAwaitingTheVerdict(): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);

        $daemon->announce(self::INITIATOR);

        $this->assertSame([self::INITIATOR], $daemon->workerServer->announcedTo());
    }

    public function testNothingIsReportedWhileAWorkerStillOwesAnAnswer(): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);

        $daemon->announce(self::INITIATOR);
        $daemon->tickBarrier();

        $this->assertSame([], $daemon->workerServer->verdicts, 'A barrier that still waits reports nothing');
    }

    public function testTheVerdictGoesToTheAnnouncingAgentOnceEveryProcessHasAnswered(): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);
        $daemon->announce(self::INITIATOR);

        $daemon->workerAnswers(1, ok: true);
        $daemon->tickBarrier();

        $verdict = $daemon->singleVerdict();
        $this->assertSame(self::INITIATOR, $verdict->agentId);
        $this->assertTrue($verdict->complete);
        $this->assertSame([], $verdict->problems);
    }

    public function testASettledBarrierIsReportedOnceAndNotOnEveryFollowingTick(): void
    {
        // The tick runs on every iteration of the daemon loop, so a round left in place after it
        // was answered would re-finish the same restore several thousand times a minute.
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);
        $daemon->announce(self::INITIATOR);
        $daemon->workerAnswers(1, ok: true);

        $daemon->tickBarrier();
        $daemon->tickBarrier();

        $this->assertCount(1, $daemon->workerServer->verdicts);
    }

    public function testAWorkerThatCouldNotReReadKeepsTheNodeClosedAndIsNamed(): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);
        $daemon->announce(self::INITIATOR);

        $daemon->workerAnswers(1, ok: false, error: 'table hilos_users is missing');
        $daemon->tickBarrier();

        $verdict = $daemon->singleVerdict();
        $this->assertFalse($verdict->complete, 'One process holding stale caches is enough to keep the node shut');
        $this->assertSame(['worker #1: read failed: table hilos_users is missing'], $verdict->problems);
    }

    public function testTheDeadlineEndsTheWaitAndNamesWhoeverWentQuiet(): void
    {
        putenv(EnvConstants::HILOS_DB_REHYDRATE_TIMEOUT->name . '=0');
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1, 2);
        $daemon->announce(self::INITIATOR);

        $daemon->workerAnswers(1, ok: true);
        $daemon->tickBarrier();

        $verdict = $daemon->singleVerdict();
        $this->assertFalse($verdict->complete, 'Silence is not a confirmation');
        $this->assertSame(['worker #2: timeout'], $verdict->problems);
    }

    public function testAWorkerThatNeverRegisteredIsNotWaitedFor(): void
    {
        // It has no index to answer under, and by the time it finishes registering it is opening
        // the database that is already in place - so counting it would only spend the deadline.
        putenv(EnvConstants::HILOS_DB_REHYDRATE_TIMEOUT->name . '=0');
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);
        $daemon->addUnregisteredWorker(2);
        $daemon->announce(self::INITIATOR);

        $daemon->workerAnswers(1, ok: true);
        $daemon->tickBarrier();

        $this->assertTrue($daemon->singleVerdict()->complete);
    }

    public function testAWorkerThatLeftMidRoundIsTakenOffTheCountRatherThanWaitedFor(): void
    {
        // A dead worker cannot answer with a fiction, and whatever starts in its place opens the
        // database that is already in place. This is why it is not a problem line either.
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1, 2);
        $daemon->announce(self::INITIATOR);

        $daemon->workerAnswers(1, ok: true);
        $daemon->workerLeaves(2);
        $daemon->tickBarrier();

        $verdict = $daemon->singleVerdict();
        $this->assertTrue($verdict->complete);
        $this->assertSame([], $verdict->problems);
    }

    public function testANodeThatLeftMidRoundIsTakenOffTheCountToo(): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager();
        $daemon->openRoundAcrossNodes(self::INITIATOR, 'node-b');

        $daemon->nodeLeaves('node-b');
        $daemon->tickBarrier();

        $this->assertTrue($daemon->singleVerdict()->complete);
    }

    public function testASwapAnnouncedByAnotherNodeIsAnsweredToThatNodeAndNotToAnAgent(): void
    {
        // The two addresses are exclusive: on the node where the swap happened the verdict goes
        // to the announcing agent, on a node that was told about it over the mesh it goes back to
        // the teller. Sending it to a worker here would report somebody else's node as answered.
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);

        $daemon->announceFromNode('node-a', self::ANNOUNCER_ROUND);
        $daemon->workerAnswers(1, ok: true);
        $daemon->tickBarrier();

        $this->assertSame([], $daemon->workerServer->verdicts);
    }

    public function testTheAnnouncementCarriesTheNumberOfTheRoundItOpened(): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);

        $daemon->announce(self::INITIATOR);

        $this->assertSame(
            [$daemon->currentRound()],
            $daemon->workerServer->announcedRounds(),
            'A worker answers under the number it was asked under, so it has to be told it',
        );
    }

    public function testConsecutiveRestoresAreAnnouncedUnderDifferentNumbers(): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);

        $daemon->announce(self::INITIATOR);
        $first = $daemon->currentRound();
        $daemon->announce(self::INITIATOR);

        $this->assertNotSame($first, $daemon->currentRound(), 'A round opened over a live one is a new round');
        $this->assertSame([$first, $daemon->currentRound()], $daemon->workerServer->announcedRounds());
    }

    public function testAnAnswerLeftOverFromTheRoundBeforeDoesNotCloseThisOne(): void
    {
        // The scenario the number exists for: round #1 was still out when the operator started a
        // second restore, and its straggler names the very worker round #2 is waiting for. Left
        // uncounted it is nothing; credited, it opens the node over a worker that never re-read
        // the database now on disk.
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);
        $daemon->announce(self::INITIATOR);
        $abandoned = $daemon->currentRound();

        $daemon->announce(self::INITIATOR);
        // One verdict is already out - the abandoned round's own refusal - and everything below
        // counts from there.
        $daemon->workerAnswers(1, ok: true, round: $abandoned);
        $daemon->tickBarrier();

        $this->assertCount(1, $daemon->workerServer->verdicts, 'The current round is still waiting for worker #1');

        $daemon->workerAnswers(1, ok: true);
        $daemon->tickBarrier();

        $this->assertCount(2, $daemon->workerServer->verdicts);
        $this->assertTrue($daemon->workerServer->verdicts[1]->complete);
    }

    public function testARoundSupersededByANewerAnnouncementAnswersItsOwnInitiator(): void
    {
        // The abandoned round used to be left to the initiator's own deadline, and that deadline
        // is gone (HIL-694): nothing else would ever end its wait, so the round leaves loudly.
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);
        $daemon->announce(self::INITIATOR);
        $abandoned = $daemon->currentRound();

        $daemon->announce(self::INITIATOR);

        $verdict = $daemon->singleVerdict();
        $this->assertSame(self::INITIATOR, $verdict->agentId);
        $this->assertFalse($verdict->complete, 'A round nobody finished did not close');
        $this->assertSame(
            ["round #{$abandoned}: superseded by a newer re-hydrate announcement"],
            $verdict->problems,
            'The operator reads which round was dropped and why',
        );
    }

    public function testTheSupersessionNamesBothRoundsAndWhoWasRefused(): void
    {
        // Two overlapping restores is what an operator reading this line is looking at, and the
        // line has to say which one displaced which; the verdict's own error names neither.
        $logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-rehydrate-supersede');
        Logger::setLogFile($logFile);

        try {
            $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);
            $daemon->announce(self::INITIATOR);
            $abandoned = $daemon->currentRound();

            $daemon->announce(self::INITIATOR);

            $written = (string)file_get_contents($logFile);
            $this->assertStringContainsString('superseded by a newer announcement', $written);
            $this->assertStringContainsString("\"superseded\":{$abandoned}", $written);
            $this->assertStringContainsString("\"opened\":{$daemon->currentRound()}", $written);
            $this->assertStringContainsString(self::INITIATOR, $written, 'And who the refusal went to');
        } finally {
            Logger::resetLogFile();
            if (is_file($logFile)) {
                unlink($logFile);
            }
        }
    }

    public function testTheRoundThatSupersededTheOldOneIsStillOpenAfterwards(): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);
        $daemon->announce(self::INITIATOR);

        $daemon->announce(self::INITIATOR);
        $daemon->workerAnswers(1, ok: true);
        $daemon->tickBarrier();

        $this->assertCount(2, $daemon->workerServer->verdicts, 'One verdict for the abandoned round, one for this');
        $this->assertTrue($daemon->workerServer->verdicts[1]->complete);
    }

    public function testNothingIsSupersededWhenNoRoundWasOpen(): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);

        $daemon->announce(self::INITIATOR);

        $this->assertSame([], $daemon->workerServer->verdicts, 'A first announcement abandons nobody');
    }

    public function testAnAnswerWithNoNumberOnItIsNotCounted(): void
    {
        // The tolerant reading of the worker link: a frame that lost its number reads as round 0,
        // and 0 is no round, so the answer is dropped rather than credited to whatever is open.
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);
        $daemon->announce(self::INITIATOR);

        $daemon->workerAnswers(1, ok: true, round: 0);
        $daemon->tickBarrier();

        $this->assertSame([], $daemon->workerServer->verdicts);
    }

    public function testAnAnnouncementFromAnotherNodeStillReachesThisNodesWorkers(): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);

        $daemon->announceFromNode('node-a', self::ANNOUNCER_ROUND);

        $this->assertSame(
            [null],
            $daemon->workerServer->announcedTo(),
            'The workers re-read for a swap made elsewhere as well; no agent here is waiting for it',
        );
    }
}

/**
 * Daemon manager carrying a stand-in worker server, with the two private loop steps of the
 * barrier - the signal drain that opens it and the tick that reports it - reachable from a test.
 */
final class DaemonManagerDbReHydrateBarrierTestManager extends DaemonManager
{
    /** Deadline of a barrier a test opens itself: far enough away that only the test ends it. */
    private const float BARRIER_TEST_DEADLINE_SECONDS = 3600.0;

    /** The stand-in worker server the announcement and the verdict travel through */
    public readonly DaemonManagerDbReHydrateBarrierTestWorkerServer $workerServer;

    /**
     * @param int ...$registeredWorkerIndexes Indexes of the workers registered on this node
     */
    public function __construct(int ...$registeredWorkerIndexes)
    {
        parent::__construct();

        $this->workerServer = new DaemonManagerDbReHydrateBarrierTestWorkerServer();
        foreach ($registeredWorkerIndexes as $workerIndex) {
            $this->workerServer->addWorker($workerIndex, registered: true);
        }
        $this->registerServer($this->workerServer);
    }

    /**
     * Adds a worker that has connected but has not registered its index yet.
     *
     * @param int $workerIndex Index the worker will register under later
     */
    public function addUnregisteredWorker(int $workerIndex): void
    {
        $this->workerServer->addWorker($workerIndex, registered: false);
    }

    /**
     * Announces on this node that the database underneath it was replaced.
     *
     * @param string $agentId Agent that replaced it and awaits the verdict
     * @throws AgentException When routing the announcement fails
     */
    public function announce(string $agentId): void
    {
        $this->queueAnnouncement($agentId, null);
    }

    /**
     * Opens a barrier whose roster reaches another master, as a clustered announcement does.
     *
     * Stands in for the peer server's own roster, which needs a live mesh to report itself: what
     * is under test here is the daemon's reaction to that node going away, not how it was counted.
     *
     * @param string $agentId Agent that replaced the database and awaits the verdict
     * @param string $nodeId Other master counted in the round
     */
    public function openRoundAcrossNodes(string $agentId, string $nodeId): void
    {
        $round = $this->agentManagerDaemon->openReHydrateRound(
            $agentId,
            null,
            null,
            [ReHydrateRound::daemonParticipant(), ReHydrateRound::nodeParticipant($nodeId)],
            microtime(true) + self::BARRIER_TEST_DEADLINE_SECONDS,
        );
        $this->agentManagerDaemon->ackReHydrateParticipant($round, ReHydrateRound::daemonParticipant(), true, null);
    }

    /**
     * Announces a swap this node was told about over the mesh.
     *
     * The number is the announcing node's own, carried the way its announcement carries it: this
     * node keeps it as an opaque token and returns it with the answer it sends back.
     *
     * @param string $announcerNodeId Node that replaced the database and awaits this node's answer
     * @param int $replyToRound Round that node opened its own round under
     * @throws AgentException When routing the announcement fails
     */
    public function announceFromNode(string $announcerNodeId, int $replyToRound): void
    {
        $this->queueAnnouncement(null, $announcerNodeId, $replyToRound);
    }

    /**
     * Delivers one worker's answer, the way its own link would.
     *
     * The round defaults to the one currently open, which is what a worker answering the frame it
     * was just sent echoes back; a case about a straggler names the older number itself.
     *
     * @param int $workerIndex Index of the answering worker
     * @param bool $ok Whether that worker re-read its collections
     * @param ?string $error Failure text when it did not
     * @param ?int $round Round the answer names, or null for the one open now
     */
    public function workerAnswers(int $workerIndex, bool $ok, ?string $error = null, ?int $round = null): void
    {
        $this->agentManagerDaemon->handleWorkerDbReHydrated(
            $workerIndex,
            new WorkerDbReHydratedDTO($round ?? $this->currentRound(), $ok, $error),
        );
    }

    /**
     * @return int Number of the round open on this daemon right now, 0 when none is
     */
    public function currentRound(): int
    {
        return $this->agentManagerDaemon->currentReHydrateRound();
    }

    /**
     * Closes one worker's link mid-round, the way {@see WorkerClient::onClose()} does.
     *
     * @param int $workerIndex Index of the worker that went away
     */
    public function workerLeaves(int $workerIndex): void
    {
        $this->agentManagerDaemon->dropReHydrateParticipant(ReHydrateRound::workerParticipant($workerIndex));
    }

    /**
     * Reports a node leaving the mesh mid-round, through the daemon's own membership hook.
     *
     * @param string $nodeId Node that left the cluster
     */
    public function nodeLeaves(string $nodeId): void
    {
        $this->onNodeLeft(ClusterNode::fromIdentity(
            NodeIdentity::of($nodeId, NodeRole::Master, []),
            online: false,
            lastSeen: 0.0,
        ));
    }

    /**
     * Runs the loop step that reports a settled barrier.
     */
    public function tickBarrier(): void
    {
        $tick = Closure::bind(
            static function (DaemonManager $daemon): void {
                $daemon->tickReHydrateRound();
            },
            null,
            DaemonManager::class,
        );

        $tick($this);
    }

    /**
     * @return DbReHydrateCompleteDTO The one verdict this daemon sent
     */
    public function singleVerdict(): DbReHydrateCompleteDTO
    {
        if (count($this->workerServer->verdicts) !== 1) {
            throw new RuntimeException('A settled barrier reports exactly one verdict.');
        }

        return $this->workerServer->verdicts[0];
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerDbReHydrateBarrierTestAgentManagerDaemon();
    }

    /**
     * Queues the announcement and runs the private drain that opens the barrier over it.
     *
     * @param ?string $agentId Agent awaiting the verdict, null when another node announced the swap
     * @param ?string $replyToNodeId Node that announced the swap to this one, null when this node did
     * @param ?int $replyToRound Round that node is waiting under, null when this node announced the swap
     * @throws AgentException When routing the announcement fails
     */
    private function queueAnnouncement(?string $agentId, ?string $replyToNodeId, ?int $replyToRound = null): void
    {
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::DB),
            signalType: new SignalType(SignalTypeConstants::DB_REHYDRATE),
            signalName: new SignalName(SignalConstants::DB_REHYDRATE),
            signalData: new DbReHydrateSignalData($agentId, $replyToNodeId, $replyToRound),
        );

        $drain = Closure::bind(
            static function (DaemonManager $daemon): void {
                $daemon->dispatchSignals();
            },
            null,
            DaemonManager::class,
        );

        $drain($this);
    }
}

final class DaemonManagerDbReHydrateBarrierTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

/**
 * A worker server that records what was announced and what came back, instead of holding sockets.
 */
final class DaemonManagerDbReHydrateBarrierTestWorkerServer extends WorkerServer
{
    /** @var list<DbReHydrateCompleteDTO> Verdicts the daemon handed back for delivery, in order */
    public array $verdicts = [];

    public function __construct()
    {
    }

    /**
     * @param int $workerIndex Index this worker answers under
     * @param bool $registered Whether it finished registering that index
     */
    public function addWorker(int $workerIndex, bool $registered): void
    {
        $this->clients[] = new DaemonManagerDbReHydrateBarrierTestWorkerClient($workerIndex, $registered);
    }

    /**
     * @return list<?string> Agent id each announcement named, one entry per frame sent to a worker
     */
    public function announcedTo(): array
    {
        $named = [];
        foreach ($this->clients as $client) {
            if ($client instanceof DaemonManagerDbReHydrateBarrierTestWorkerClient) {
                $named = [...$named, ...$client->announcedAgentIds()];
            }
        }

        return $named;
    }

    /**
     * @return list<int> Round number each announcement carried, one entry per frame sent to a worker
     */
    public function announcedRounds(): array
    {
        $rounds = [];
        foreach ($this->clients as $client) {
            if ($client instanceof DaemonManagerDbReHydrateBarrierTestWorkerClient) {
                $rounds = [...$rounds, ...$client->announcedRounds()];
            }
        }

        return $rounds;
    }

    /**
     * @param DbReHydrateCompleteDTO $dto Verdict addressed to the agent that announced the swap
     */
    public function deliverDbReHydrateComplete(DbReHydrateCompleteDTO $dto): void
    {
        $this->verdicts[] = $dto;
    }

    protected function onStart(): void
    {
    }
}

/**
 * A worker link that keeps what was written to it, so the announcement can be read back.
 */
final class DaemonManagerDbReHydrateBarrierTestWorkerClient extends WorkerClient
{
    /** @var list<string> Raw frames the daemon wrote to this link, in order */
    private array $written = [];

    /**
     * @param int $workerIndex Index this worker answers under
     * @param bool $registered Whether it finished registering that index
     */
    public function __construct(int $workerIndex, private readonly bool $registered)
    {
        $this->setWorkerIndex($workerIndex);
    }

    public function isRegistered(): bool
    {
        return $this->registered;
    }

    /**
     * @param string $data Frame the daemon wants written to this worker
     */
    public function send(string $data): void
    {
        $this->written[] = $data;
    }

    /**
     * @return list<?string> Agent id each re-hydrate announcement on this link named
     */
    public function announcedAgentIds(): array
    {
        $named = [];
        foreach ($this->announcements() as $decoded) {
            $agentId = $decoded[AgentConstants::FIELD_AGENT_ID] ?? null;
            $named[] = $agentId === null ? null : (string)$agentId;
        }

        return $named;
    }

    /**
     * @return list<int> Round number each re-hydrate announcement on this link carried
     */
    public function announcedRounds(): array
    {
        $rounds = [];
        foreach ($this->announcements() as $decoded) {
            $rounds[] = (int)($decoded[WorkerDbReHydrateMessageDTO::FIELD_ROUND] ?? 0);
        }

        return $rounds;
    }

    /**
     * @return list<array<string, mixed>> Every re-hydrate announcement written to this link, decoded
     */
    private function announcements(): array
    {
        $announcements = [];
        foreach ($this->written as $frame) {
            $decoded = json_decode($frame, true);
            if (!is_array($decoded) || ($decoded[WorkerDTO::TYPE] ?? null) !== WorkerConstants::MESSAGE_DB_REHYDRATE) {
                continue;
            }

            $announcements[] = $decoded;
        }

        return $announcements;
    }
}
