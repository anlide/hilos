<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\DbSyncMesh;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerDbReHydratedDTO;
use Hilos\Cluster\Peer\DTO\PeerDbReHydrateDTO;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Sync\DTO\DbReHydrateSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DTO\ReHydrateVerdict;
use Hilos\Database\DatabaseException;
use Hilos\Database\ReHydrateRound;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\ProtectedMode\DaemonProtectedModeExecutor;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DbReHydrateCompleteDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydratedDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydrateMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Tests\Unit\Cluster\Peer\ProtectedModePeerFixture;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
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
    use ProtectedModePeerFixture;

    /** @var string Agent that announced the swap in these cases */
    private const string INITIATOR = 'hilos_backup';

    /** Round another node was already waiting under when it told this one about a swap. */
    private const int ANNOUNCER_ROUND = 11;

    /** User action key the master's analytics collector holds from before the swap. */
    private const string CAPTURED_USER_ACTION_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mountProtectedModePeers();
        // Long enough that nothing expires unless a case asks for it by shortening the wait.
        putenv(EnvConstants::HILOS_DB_REHYDRATE_TIMEOUT->name . '=3600');
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$ac = null;
        putenv(EnvConstants::HILOS_DB_REHYDRATE_TIMEOUT->name);

        $this->restoreProtectedModePeers();
        parent::tearDown();
    }

    /** @param NodeRole $role Role of the node announcing the database swap */
    #[DataProvider('announcerRoles')]
    public function testAnAnnouncerOfEitherRoleWaitsForEveryLinkedNodeAndItsOwnWorkers(NodeRole $role): void
    {
        $server = $this->protectedModePeerServer($role);
        $master = $this->protectedModePeerLink($server, 'master', NodeRole::Master);
        $slave = $this->protectedModePeerLink($server, 'slave', NodeRole::Slave);
        $this->protectedModePeerFrames($master);
        $this->protectedModePeerFrames($slave);
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);
        $daemon->connectPeers($server);

        $daemon->announce(self::INITIATOR);
        $round = $daemon->currentRound();
        foreach ([$master, $slave] as $pair) {
            $frames = $this->protectedModePeerFrames($pair);
            $this->assertCount(1, $frames);
            $this->assertInstanceOf(PeerDbReHydrateDTO::class, $frames[0]);
            $this->assertSame($round, $frames[0]->round);
        }
        $server->onDbReHydratedReceived($master[0], new PeerDbReHydratedDTO('master', $round, true, []));
        $daemon->workerAnswers(1, ok: true);
        $daemon->tickBarrier();
        $this->assertSame([], $daemon->workerServer->verdicts, 'The slave has not re-read yet');

        $server->onDbReHydratedReceived($slave[0], new PeerDbReHydratedDTO('slave', $round + 1, true, []));
        $daemon->tickBarrier();
        $this->assertSame([], $daemon->workerServer->verdicts, 'An answer to another round does not close this one');

        $server->onDbReHydratedReceived($slave[0], new PeerDbReHydratedDTO('slave', $round, true, []));
        $daemon->tickBarrier();
        $this->assertTrue($daemon->singleVerdict()->complete);
        $this->assertSame([], $daemon->singleVerdict()->problems);
    }

    /** @return iterable<string, array{NodeRole}> Local announcer roles */
    public static function announcerRoles(): iterable
    {
        yield 'master' => [NodeRole::Master];
        yield 'slave' => [NodeRole::Slave];
    }

    /** @param bool $departure Whether loss, rather than an answer, releases the last participant */
    #[DataProvider('completionFacts')]
    public function testARealCompletionFactWakesWithoutInputAndDeliversOnlyInTheGuardedPhase(bool $departure): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);
        $daemon->announce(self::INITIATOR);
        $daemon->tickBarrier();
        self::assertNotNull($daemon->barrierTimer()->getDeadline());
        self::assertFalse($daemon->barrierLoop()->hasReadyWork());

        if ($departure) {
            $daemon->workerLeaves(1);
        } else {
            $daemon->workerAnswers(1, true);
        }
        self::assertTrue($daemon->barrierLoop()->hasReadyWork());
        $daemon->barrierLoop()->runOnce();
        self::assertSame([], $daemon->workerServer->verdicts, 'Native dispatch only marks readiness');
        $daemon->tickBarrier();
        self::assertTrue($daemon->singleVerdict()->complete);
        self::assertNull($daemon->barrierTimer()->getDeadline());
        self::assertFalse($daemon->barrierLoop()->hasReadyWork());
        $daemon->workerAnswers(1, true);
        $daemon->tickBarrier();
        self::assertCount(1, $daemon->workerServer->verdicts);
        self::assertFalse($daemon->barrierLoop()->hasReadyWork());
    }

    /** @return iterable<string, array{bool}> Facts that end the wait */
    public static function completionFacts(): iterable
    {
        yield 'answer' => [false];
        yield 'departure' => [true];
    }

    public function testAFutureRoundKeepsItsDeadlineAcrossUnrelatedIoFactsAndRebind(): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1, 2);
        $daemon->announce(self::INITIATOR);
        $daemon->tickBarrier();
        $deadline = $daemon->barrierTimer()->getDeadline();
        $oldTimer = $daemon->barrierTimer();
        $oldWake = new ReflectionProperty(DaemonManager::class, 'reHydrateWake')->getValue($daemon);
        $loop = $daemon->barrierLoop();
        $sockets = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets));
        try {
            $reads = 0;
            $loop->registerRead($sockets[0], static function () use ($sockets, &$reads): void {
                socket_read($sockets[0], 1);
                ++$reads;
            });
            for ($pass = 0; $pass < 3; ++$pass) {
                socket_write($sockets[1], 'x');
                $loop->runOnce();
                $daemon->tickBarrier();
                self::assertSame($deadline, $daemon->barrierTimer()->getDeadline());
                self::assertFalse($loop->hasReadyWork());
            }
            self::assertSame(3, $reads);
            $daemon->workerAnswers(1, true);
            $daemon->tickBarrier();
            self::assertSame($deadline, $daemon->barrierTimer()->getDeadline());
            $daemon->workerAnswers(1, true);
            $daemon->workerAnswers(2, true, round: $daemon->currentRound() - 1);
            $daemon->workerLeaves(999);
            self::assertFalse($loop->hasReadyWork(), 'Duplicates, mismatches and unknown departures change no round fact');
            $daemon->bindBarrierLoop($loop);
            $daemon->tickBarrier();
            self::assertNull($oldTimer->getDeadline());
            self::assertSame($deadline, $daemon->barrierTimer()->getDeadline());
            $oldWake();
            self::assertFalse($loop->hasReadyWork(), 'An escaped binding cannot wake its replacement');
            self::assertSame([], $daemon->workerServer->verdicts);
            $daemon->workerAnswers(2, true);
            $daemon->tickBarrier();
            self::assertTrue($daemon->singleVerdict()->complete);
        } finally {
            $loop->unregister($sockets[0]);
            foreach ($sockets as $socket) {
                socket_close($socket);
            }
        }
    }

    public function testTheOriginalNativeDeadlineEndsAQuietRoundWithoutInput(): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager();
        $deadline = hrtime(true) / 1e9 - 1.0;
        $daemon->barrierOwner()->openReHydrateRound(self::INITIATOR, null, null,
            [ReHydrateRound::workerParticipant(1)], $deadline);
        // Observe before the original deadline, then let the actual due-now native event wake it.
        $daemon->tickBarrier($deadline - 1.0);
        self::assertSame($deadline, $daemon->barrierTimer()->getDeadline());
        self::assertFalse($daemon->barrierLoop()->hasReadyWork());
        $daemon->barrierLoop()->runOnce();
        self::assertSame([], $daemon->workerServer->verdicts);
        $daemon->tickBarrier();
        self::assertFalse($daemon->singleVerdict()->complete);
        self::assertSame(['worker #1: timeout'], $daemon->singleVerdict()->problems);
        self::assertNull($daemon->barrierTimer()->getDeadline());
        self::assertFalse($daemon->barrierLoop()->hasReadyWork());
    }

    public function testExpiryDuringIncompleteDispatchSleepsUntilCompletionWithoutRenewingTheDeadline(): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager();
        $owner = $daemon->barrierOwner();
        $deadline = hrtime(true) / 1e9 - 1.0;
        $round = $owner->beginReHydrateDispatch(self::INITIATOR, null, null, $deadline);
        $owner->ackReHydrateParticipant($round, ReHydrateRound::daemonParticipant(), true, null);
        $daemon->tickBarrier($deadline - 1.0);
        $daemon->barrierLoop()->runOnce();
        $daemon->tickBarrier();
        self::assertSame($round, $daemon->currentRound(), 'An empty partial roster cannot complete');
        self::assertSame([], $daemon->workerServer->verdicts);
        self::assertNull($daemon->barrierTimer()->getDeadline(), 'A past deadline cannot rearm a ready spin');
        self::assertFalse($daemon->barrierLoop()->hasReadyWork());
        $owner->dropReHydrateParticipant(ReHydrateRound::workerParticipant(2));
        $owner->includeReHydrateParticipant($round, ReHydrateRound::workerParticipant(2));
        $owner->includeReHydrateParticipant($round, ReHydrateRound::workerParticipant(1));
        $daemon->tickBarrier();
        self::assertSame($deadline, $owner->reHydrateDeadline());
        self::assertFalse($daemon->barrierLoop()->hasReadyWork());
        self::assertNull($daemon->barrierTimer()->getDeadline());
        $owner->completeReHydrateDispatch($round);
        self::assertTrue($daemon->barrierLoop()->hasReadyWork());
        $daemon->barrierLoop()->runOnce();
        $daemon->tickBarrier();
        self::assertFalse($daemon->singleVerdict()->complete);
        self::assertSame(['worker #1: timeout'], $daemon->singleVerdict()->problems);
        self::assertFalse($daemon->barrierLoop()->hasReadyWork());
    }

    public function testAnActivatedOldDeadlineCannotConsumeTheSupersedingRound(): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);
        $deadline = hrtime(true) / 1e9 - 1.0;
        $first = $daemon->barrierOwner()->openReHydrateRound(self::INITIATOR, null, null,
            [ReHydrateRound::workerParticipant(1)], $deadline);
        $daemon->tickBarrier($deadline - 1.0);
        $timer = $daemon->barrierTimer();
        $activated = false;
        $daemon->barrierLoop()->createReadyWork(function () use ($daemon, $timer, &$activated): void {
            $activated = $timer->getDeadline() === null;
            $daemon->announce(self::INITIATOR);
            $daemon->tickBarrier();
        })->markReady();
        $daemon->barrierLoop()->runOnce();
        self::assertTrue($activated, 'Native activation precedes replacement of the queued deadline callback');
        $newDeadline = $daemon->barrierOwner()->reHydrateDeadline();
        self::assertSame($newDeadline, $timer->getDeadline());
        self::assertFalse($daemon->singleVerdict()->complete);
        self::assertSame(["round #{$first}: superseded by a newer re-hydrate announcement"], $daemon->singleVerdict()->problems);
        $daemon->workerAnswers(1, true, round: $first);
        $daemon->barrierOwner()->completeReHydrateDispatch($first);
        $daemon->barrierLoop()->tick();
        $daemon->tickBarrier();
        self::assertSame($newDeadline, $timer->getDeadline());
        self::assertCount(1, $daemon->workerServer->verdicts);
        self::assertFalse($daemon->barrierLoop()->hasReadyWork());
        $daemon->workerAnswers(1, true);
        $daemon->tickBarrier();
        self::assertCount(2, $daemon->workerServer->verdicts);
        self::assertTrue($daemon->workerServer->verdicts[1]->complete);
    }

    public function testACompletionMayReenterWithANewContinuationAndRebindWithoutLosingEitherResult(): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);
        $verdicts = [];
        $daemon->catchUpProtectedModeDatabase(function (ReHydrateVerdict $verdict) use ($daemon, &$verdicts): void {
            $verdicts[] = $verdict;
            $daemon->catchUpProtectedModeDatabase(static function (ReHydrateVerdict $next) use (&$verdicts): void {
                $verdicts[] = $next;
            });
            $daemon->bindBarrierLoop($daemon->barrierLoop());
        });
        $first = $daemon->currentRound();
        $daemon->workerAnswers(1, true);
        $daemon->tickBarrier();
        self::assertCount(1, $verdicts);
        self::assertTrue($verdicts[0]->complete);
        self::assertGreaterThan($first, $daemon->currentRound());
        $deadline = $daemon->barrierOwner()->reHydrateDeadline();
        $daemon->tickBarrier();
        self::assertSame($deadline, $daemon->barrierTimer()->getDeadline());
        self::assertFalse($daemon->barrierLoop()->hasReadyWork());
        $daemon->workerAnswers(1, true, round: $first);
        $daemon->workerAnswers(1, true);
        $daemon->tickBarrier();
        $daemon->tickBarrier();
        self::assertCount(2, $verdicts);
        self::assertTrue($verdicts[1]->complete);
        self::assertSame([], $daemon->workerServer->verdicts);
        self::assertNull($daemon->barrierTimer()->getDeadline());
        self::assertFalse($daemon->barrierLoop()->hasReadyWork());
    }

    public function testASupersededContinuationMayOpenAnotherRoundBeforeTheOldDispatchReturns(): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);
        $verdicts = [];
        $daemon->catchUpProtectedModeDatabase(function (ReHydrateVerdict $verdict) use ($daemon, &$verdicts): void {
            $verdicts[] = $verdict;
            $daemon->catchUpProtectedModeDatabase(static function (ReHydrateVerdict $next) use (&$verdicts): void {
                $verdicts[] = $next;
            });
        });
        $first = $daemon->currentRound();
        $daemon->announce(self::INITIATOR);
        while ($daemon->hasDispatchWork()) {
            $daemon->drainAnnouncements();
        }
        $last = $daemon->currentRound();
        self::assertGreaterThan($first + 1, $last);
        self::assertCount(1, $verdicts);
        self::assertFalse($verdicts[0]->complete);
        self::assertSame(["round #{$first}: superseded by a newer re-hydrate announcement"], $verdicts[0]->problems);
        self::assertFalse($daemon->singleVerdict()->complete, 'The intervening ordinary round gets its own refusal');
        $daemon->tickBarrier();
        $deadline = $daemon->barrierTimer()->getDeadline();
        $daemon->workerAnswers(1, true, round: $first);
        $daemon->barrierOwner()->completeReHydrateDispatch($first + 1);
        $daemon->tickBarrier();
        self::assertSame($deadline, $daemon->barrierTimer()->getDeadline());
        $daemon->workerAnswers(1, true);
        $daemon->tickBarrier();
        self::assertCount(2, $verdicts);
        self::assertTrue($verdicts[1]->complete);
        self::assertCount(1, $daemon->workerServer->verdicts);
        self::assertNull($daemon->barrierTimer()->getDeadline());
        self::assertFalse($daemon->barrierLoop()->hasReadyWork());
    }

    public function testAnOldOwnerBindingCannotRemoveItsReplacementOrWakeForDuplicateFacts(): void
    {
        $owner = new DaemonManagerDbReHydrateBarrierTestAgentManagerDaemon();
        $oldCalls = 0;
        $newCalls = 0;
        $oldWake = static function () use (&$oldCalls): void { ++$oldCalls; };
        $newWake = static function () use (&$newCalls): void { ++$newCalls; };
        $owner->setReHydrateWake($oldWake);
        $round = $owner->beginReHydrateDispatch(self::INITIATOR, null, null, PHP_FLOAT_MAX);
        self::assertSame(1, $oldCalls);
        $owner->setReHydrateWake($newWake);
        self::assertSame(1, $newCalls, 'Rebind observes a live round');
        $owner->removeReHydrateWake($oldWake);
        $owner->ackReHydrateParticipant($round, ReHydrateRound::daemonParticipant(), true, null);
        self::assertSame(2, $newCalls);
        $owner->ackReHydrateParticipant($round, ReHydrateRound::daemonParticipant(), true, null);
        $owner->completeReHydrateDispatch($round + 1);
        self::assertSame(2, $newCalls);
        $owner->completeReHydrateDispatch($round);
        self::assertSame(3, $newCalls);
        self::assertTrue($owner->pollReHydrateVerdict(0.0)->complete);
        self::assertSame(4, $newCalls, 'Verdict consumption is a round mutation');
        self::assertNull($owner->pollReHydrateVerdict(0.0));
        self::assertSame(4, $newCalls);
        self::assertSame(1, $oldCalls);
    }

    /**
     * @param bool $replacePeer Whether the original peer is replaced during participant preparation
     * @param bool $renumberWorker Whether an original connection registers a different worker index
     */
    #[DataProvider('peerChangesDuringPreparation')]
    public function testPortionsKeepTheOriginalRegistrationAndConnectionCohorts(bool $replacePeer, bool $renumberWorker): void
    {
        $server = $this->protectedModePeerServer(NodeRole::Master);
        $original = $this->protectedModePeerLink($server, 'original', NodeRole::Master);
        $this->protectedModePeerFrames($original);
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(...range(1, 130));
        $loop = new EventLoop();
        $daemon->bindBarrierLoop($loop);
        try {
            $daemon->connectPeers($server);
            $daemon->addUnregisteredWorker(131);
            $clients = $daemon->workerServer->getClients();
            $daemon->announce(self::INITIATOR);
            $round = $daemon->currentRound();
            self::assertGreaterThan(0, $round);
            self::assertSame([], $daemon->workerServer->announcedRounds(), 'Preparation itself must yield before physical fanout');
            $daemon->tickBarrier(PHP_FLOAT_MAX);
            self::assertSame([], $daemon->workerServer->verdicts, 'A partially populated round cannot expire or settle');

            $clients[130]->finishRegistration($daemon->workerServer);
            $clients[129]->markShouldClose();
            $daemon->workerServer->addWorker(130, registered: true);
            if ($renumberWorker) {
                $clients[0]->setWorkerIndex(999);
                $clients[0]->finishRegistration($daemon->workerServer);
            }
            if ($replacePeer) {
                $server->removeClient($original[0]);
            }
            $later = $this->protectedModePeerLink($server, $replacePeer ? 'original' : 'later', NodeRole::Master);
            $this->protectedModePeerFrames($original);
            $this->protectedModePeerFrames($later);
            for ($portion = 0; $portion < 1000 && $daemon->hasDispatchWork(); $portion++) {
                $before = count($daemon->workerServer->announcedRounds());
                $daemon->drainAnnouncements();
                self::assertLessThanOrEqual(64, count($daemon->workerServer->announcedRounds()) - $before);
            }
            self::assertFalse($daemon->hasDispatchWork());
            self::assertSame(1, $daemon->dbAnnouncements, 'The existing project announcement hook still runs once');
            self::assertSame(array_fill(0, $renumberWorker ? 129 : 130, $round), $daemon->workerServer->announcedRounds());
            if ($renumberWorker) {
                self::assertSame([], $clients[0]->announcedRounds(), 'A changed registered identity cannot receive the old announcement');
            }
            self::assertSame([], $clients[129]->announcedRounds(), 'Closed original worker does not receive the old announcement');
            self::assertSame([$round], $clients[130]->announcedRounds(), 'The original unregistered connection still receives the frame');
            $currentClients = $daemon->workerServer->getClients();
            self::assertSame([], $currentClients[131]->announcedRounds(), 'A replacement with the same index is outside the original cohort');
            self::assertSame([], $this->protectedModePeerFrames($later), 'A late neighbor or replacement receives no old fanout');
            $peerFrames = $this->protectedModePeerFrames($original);
            self::assertCount($replacePeer ? 0 : 1, $peerFrames);
            if (!$replacePeer) {
                self::assertInstanceOf(PeerDbReHydrateDTO::class, $peerFrames[0]);
                self::assertSame($round, $peerFrames[0]->round);
                $server->onDbReHydratedReceived($original[0], new PeerDbReHydratedDTO('original', $round, true, []));
            }
            for ($index = 1; $index < 130; $index++) {
                if (!$renumberWorker || $index !== 1) {
                    $daemon->workerAnswers($index, true);
                }
            }
            $daemon->tickBarrier();
            self::assertTrue($daemon->singleVerdict()->complete, 'Late registration and replacement do not add new participants');
            self::assertSame([], $daemon->singleVerdict()->problems);
        } finally {
            $loop->cleanup();
        }
    }

    /** @return iterable<string, array{bool, bool}> Connection and registration changes after cohort capture */
    public static function peerChangesDuringPreparation(): iterable
    {
        yield 'new neighbor' => [false, false];
        yield 'same-node replacement' => [true, false];
        yield 'changed worker index' => [false, true];
    }

    public function testAStaleDispatchCompletionCannotReleaseTheNextRound(): void
    {
        $owner = new DaemonManagerDbReHydrateBarrierTestAgentManagerDaemon();
        $first = $owner->beginReHydrateDispatch(self::INITIATOR, null, null, 0.0);
        $owner->ackReHydrateParticipant($first, ReHydrateRound::daemonParticipant(), true, null);
        self::assertNull($owner->pollReHydrateVerdict(PHP_FLOAT_MAX));
        self::assertFalse($owner->supersedeReHydrateRound()->complete);
        $second = $owner->beginReHydrateDispatch(self::INITIATOR, null, null, PHP_FLOAT_MAX);
        $owner->includeReHydrateParticipant($second, ReHydrateRound::workerParticipant(2));
        $owner->includeReHydrateParticipant($first, 'stale participant');
        $owner->ackReHydrateParticipant($second, ReHydrateRound::daemonParticipant(), true, null);
        $owner->completeReHydrateDispatch($first);
        self::assertNull($owner->pollReHydrateVerdict(PHP_FLOAT_MAX));
        $owner->completeReHydrateDispatch($second);
        self::assertNull($owner->pollReHydrateVerdict(0.0));
        $owner->ackReHydrateParticipant($second, ReHydrateRound::workerParticipant(2), true, null);
        $verdict = $owner->pollReHydrateVerdict(0.0);
        self::assertTrue($verdict->complete);
        self::assertSame([], $verdict->problems);
    }

    public function testAnAnnouncementFailureReleasesPreparationWithoutCreditingTheUnappliedDaemon(): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);
        $client = $daemon->workerServer->getClients()[0];
        $failure = new RuntimeException('worker announcement refused');
        $client->sendFailure = $failure;
        try {
            $daemon->announce(self::INITIATOR);
            self::fail('The actual send failure must reach the existing guard');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame([], $daemon->workerServer->announcedRounds());
        $daemon->tickBarrier(PHP_FLOAT_MAX);
        self::assertFalse($daemon->singleVerdict()->complete);
        self::assertSame(['daemon: timeout', 'worker #1: timeout'], $daemon->singleVerdict()->problems);
        self::assertSame(0, $daemon->currentRound());
    }

    public function testADepartureBeforeItsRosterVisitCannotBeReintroducedDuringPreparation(): void
    {
        $owner = new DaemonManagerDbReHydrateBarrierTestAgentManagerDaemon();
        $round = $owner->beginReHydrateDispatch(self::INITIATOR, null, null, PHP_FLOAT_MAX);
        $owner->dropReHydrateParticipant(ReHydrateRound::workerParticipant(1));
        $owner->includeReHydrateParticipant($round, ReHydrateRound::workerParticipant(1));
        $owner->ackReHydrateParticipant($round, ReHydrateRound::daemonParticipant(), true, null);
        $owner->completeReHydrateDispatch($round);
        $verdict = $owner->pollReHydrateVerdict(0.0);
        self::assertNotNull($verdict, 'A later visit cannot undo the actual departure fact');
        self::assertTrue($verdict->complete);
        self::assertSame([], $verdict->problems);
    }

    public function testSlaveExecutorWaitsForEveryLocalWorkerWithoutRebroadcastingOrReplyingToAnAgent(): void
    {
        $server = $this->protectedModePeerServer(NodeRole::Slave);
        $leader = $this->protectedModePeerLink($server, 'leader', NodeRole::Master);
        $this->protectedModePeerFrames($leader);
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1, 2);
        $daemon->connectPeers($server);
        Hilos::$cluster->registerDbSyncSink($daemon);
        $verdicts = [];
        new DaemonProtectedModeExecutor()->catchUpSlaveDatabase('leader', static function (ReHydrateVerdict $verdict) use (&$verdicts): void {
            $verdicts[] = $verdict;
        });
        $round = $daemon->currentRound();
        $this->assertSame([$round, $round], $daemon->workerServer->announcedRounds());
        $daemon->workerAnswers(1, true);
        $daemon->workerAnswers(2, true, round: $round - 1);
        $daemon->tickBarrier();
        $this->assertSame([], $verdicts);
        $daemon->workerAnswers(2, true);
        $daemon->tickBarrier();
        $daemon->tickBarrier();
        $this->assertCount(1, $verdicts);
        $this->assertTrue($verdicts[0]->complete);
        $this->assertSame([], $verdicts[0]->problems);
        $this->assertSame([], $daemon->workerServer->verdicts);
        $this->assertSame([], $this->protectedModePeerFrames($leader));
    }

    public function testLocalCatchUpCannotCompleteBeforeTheDaemonsOwnReadReturns(): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);
        $previous = Hilos::$db;
        $db = $this->createMock(HilosDbContext::class);
        $verdicts = [];
        $db->expects($this->once())->method('reHydrateDbBackedCollections')->willReturnCallback(function () use ($daemon, &$verdicts): void {
            $daemon->workerAnswers(1, true);
            $daemon->barrierLoop()->tick();
            $daemon->tickBarrier();
            $this->assertSame([], $verdicts, 'Workers cannot stand in for the daemon participant');
        });
        Hilos::$db = $db;
        try {
            $daemon->catchUpProtectedModeDatabase(static function (ReHydrateVerdict $verdict) use (&$verdicts): void {
                $verdicts[] = $verdict;
            });
            $this->assertSame([], $verdicts);
            $daemon->tickBarrier();
            $this->assertCount(1, $verdicts);
            $this->assertTrue($verdicts[0]->complete);
        } finally {
            Hilos::$db = $previous;
        }
    }

    /** @param string $failure Source that must leave the local continuation unsuccessful */
    #[DataProvider('localCatchUpFailures')]
    public function testLocalCatchUpReportsFailureExpiryAndSupersessionWithoutCreditingLateSuccess(string $failure): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);
        $previous = Hilos::$db;
        if ($failure === 'daemon' || $failure === 'daemon-contract') {
            $db = $this->createMock(HilosDbContext::class);
            $db->expects($this->once())->method('reHydrateDbBackedCollections')->willThrowException(
                $failure === 'daemon' ? new DatabaseException('read refused') : new HilosException('collection refused the read'),
            );
            Hilos::$db = $db;
        }
        $verdicts = [];
        try {
            $daemon->catchUpProtectedModeDatabase(static function (ReHydrateVerdict $verdict) use (&$verdicts): void {
                $verdicts[] = $verdict;
            });
            $round = $daemon->currentRound();
            if ($failure === 'supersession') {
                $daemon->announce(self::INITIATOR);
                $daemon->workerAnswers(1, true, round: $round);
            } elseif ($failure === 'worker') {
                $daemon->workerAnswers(1, false, 'read refused');
            } elseif ($failure === 'daemon' || $failure === 'daemon-contract') {
                $daemon->workerAnswers(1, true);
            }
            $daemon->tickBarrier($failure === 'timeout' ? PHP_FLOAT_MAX : null);
            $this->assertCount(1, $verdicts);
            $this->assertFalse($verdicts[0]->complete);
            $this->assertNotEmpty($verdicts[0]->problems);
            $daemon->workerAnswers(1, true, round: $round);
            $daemon->tickBarrier();
            $this->assertCount(1, $verdicts, 'A late success cannot change or redeliver the failed local result');
            if ($failure === 'supersession') {
                $this->assertSame([], $daemon->workerServer->verdicts, 'The newer ordinary round still waits for its own worker answer');
                $daemon->workerAnswers(1, true);
                $daemon->tickBarrier();
                $this->assertTrue($daemon->singleVerdict()->complete);
            } else {
                $this->assertSame([], $daemon->workerServer->verdicts);
            }
        } finally {
            Hilos::$db = $previous;
        }
    }

    /** @return iterable<string, array{string}> Every non-success exit of the local barrier */
    public static function localCatchUpFailures(): iterable
    {
        foreach (['daemon', 'daemon-contract', 'worker', 'timeout', 'supersession'] as $failure) {
            yield $failure => [$failure];
        }
    }

    public function testASlaveParticipantAggregatesItsWorkersAndAnswersTheSlaveAnnouncerWithoutRebroadcast(): void
    {
        $server = $this->protectedModePeerServer(NodeRole::Slave);
        $master = $this->protectedModePeerLink($server, 'master', NodeRole::Master);
        $announcer = $this->protectedModePeerLink($server, 'announcer', NodeRole::Slave);
        $this->protectedModePeerFrames($master);
        $this->protectedModePeerFrames($announcer);
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);
        $daemon->connectPeers($server);

        $server->onDbReHydrateReceived($announcer[0], new PeerDbReHydrateDTO(self::ANNOUNCER_ROUND));
        $daemon->drainAnnouncements();
        $daemon->tickBarrier();

        $this->assertSame([null], $daemon->workerServer->announcedTo());
        $this->assertSame([], $this->protectedModePeerFrames($master));
        $this->assertSame([], $this->protectedModePeerFrames($announcer), 'The local worker still owes an answer');

        $daemon->workerAnswers(1, ok: true);
        $daemon->tickBarrier();

        $this->assertSame([], $daemon->workerServer->verdicts);
        $this->assertSame([], $this->protectedModePeerFrames($master));
        $frames = $this->protectedModePeerFrames($announcer);
        $this->assertCount(1, $frames);
        $this->assertInstanceOf(PeerDbReHydratedDTO::class, $frames[0]);
        $this->assertSame(self::ANNOUNCER_ROUND, $frames[0]->round);
        $this->assertSame('node-a', $frames[0]->nodeId);
        $this->assertTrue($frames[0]->ok);
        $this->assertSame([], $frames[0]->problems);
    }

    public function testTheAnnouncementReachesEveryWorkerAndNamesTheAgentAwaitingTheVerdict(): void
    {
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);

        $daemon->announce(self::INITIATOR);

        $this->assertSame([self::INITIATOR], $daemon->workerServer->announcedTo());
    }

    public function testTheDaemonsOwnAnswerMakesItsCollectorForgetTheReplacedDatabase(): void
    {
        // The master's collector forgets the captured key when the database is replaced.
        Hilos::$ac = new AnalyticsCollector();
        Hilos::$ac->startUserActionCapture(self::CAPTURED_USER_ACTION_KEY);
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);

        $daemon->announce(self::INITIATOR);

        $this->assertSame([], Hilos::$ac->captureSignalMeta());
    }

    public function testAFollowerToldOverTheMeshForgetsToo(): void
    {
        // The nodes share one database, so a swap announced elsewhere replaced this node's too.
        Hilos::$ac = new AnalyticsCollector();
        Hilos::$ac->startUserActionCapture(self::CAPTURED_USER_ACTION_KEY);
        $daemon = new DaemonManagerDbReHydrateBarrierTestManager(1);

        $daemon->announceFromNode('node-a', self::ANNOUNCER_ROUND);

        $this->assertSame([], Hilos::$ac->captureSignalMeta());
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

    public int $dbAnnouncements = 0;

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
        $this->bindBarrierLoop(new EventLoop());
    }

    /** Releases native and queued callbacks when this test manager leaves scope. */
    public function __destruct()
    {
        new ReflectionMethod(DaemonManager::class, 'detachReHydrateEventLoop')->invoke($this);
        $this->eventLoop->cleanup();
    }

    /** @param EventLoop $loop Replacement native base for the same live barrier */
    public function bindBarrierLoop(EventLoop $loop): void
    {
        $this->eventLoop = $loop;
        new ReflectionMethod(DaemonManager::class, 'attachReHydrateEventLoop')->invoke($this);
    }

    /** @return EventLoop Actual native base, without any socket input */
    public function barrierLoop(): EventLoop
    {
        return $this->eventLoop;
    }

    /** @return AgentManagerDaemon Actual round owner, shared by worker and peer answers */
    public function barrierOwner(): AgentManagerDaemon
    {
        return $this->agentManagerDaemon;
    }

    /** @return EventLoopTimer Actual deadline handle owned by the current loop binding */
    public function barrierTimer(): EventLoopTimer
    {
        return new ReflectionProperty(DaemonManager::class, 'reHydrateTimer')->getValue($this);
    }

    /** @param PeerServer $server Real mesh whose answers close this daemon's barrier */
    public function connectPeers(PeerServer $server): void
    {
        $this->registerServer($server);
        $server->registerReHydrateBarrier($this->agentManagerDaemon);
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
            hrtime(true) / 1e9 + self::BARRIER_TEST_DEADLINE_SECONDS,
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
            NodeIdentity::of($nodeId, 'Node ' . $nodeId, NodeRole::Master, []),
            online: false,
            lastSeen: 0.0,
        ));
    }

    /** @param ?float $now Explicit monotonic deadline observation, or a fact-driven guarded pass */
    public function tickBarrier(?float $now = null): void
    {
        $tick = Closure::bind(
            static function (DaemonManager $daemon) use ($now): void {
                if ($now !== null) {
                    $daemon->reHydrateRoundReady = true;
                }
                $daemon->dispatchReHydrateRound($now);
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

    /** Drains received mesh announcements through the same loop step as local announcements. */
    public function drainAnnouncements(): void
    {
        $drain = Closure::bind(
            static function (DaemonManager $daemon): void {
                $daemon->dispatchSignals();
            },
            null,
            DaemonManager::class,
        );

        $drain($this);
    }

    /** @return bool Actual production remainder predicate */
    public function hasDispatchWork(): bool
    {
        return Closure::bind(fn(): bool => $this->hasRunnableSignalDispatch(), $this, DaemonManager::class)();
    }

    /**
     * @param ?DbSyncMesh $mesh Original peer port
     * @param SignalDTO $signal Original sync envelope
     */
    protected function broadcastDbSyncToPeers(?DbSyncMesh $mesh, SignalDTO $signal): void
    {
        $this->dbAnnouncements++;
        parent::broadcastDbSyncToPeers($mesh, $signal);
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

        $this->drainAnnouncements();
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
        $client = new DaemonManagerDbReHydrateBarrierTestWorkerClient($workerIndex, $registered);
        $this->addClient($client);
        if ($registered) {
            $this->noteWorkerRegistered($client);
        }
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
    public ?RuntimeException $sendFailure = null;

    /** @var list<string> Raw frames the daemon wrote to this link, in order */
    private array $written = [];

    /**
     * @param int $workerIndex Index this worker answers under
     * @param bool $registered Whether it finished registering that index
     */
    public function __construct(int $workerIndex, private bool $registered)
    {
        $this->setWorkerIndex($workerIndex);
    }

    public function isRegistered(): bool
    {
        return $this->registered;
    }

    /** @param WorkerServer $server Exact owner receiving the real registration fact */
    public function finishRegistration(WorkerServer $server): void
    {
        $this->registered = true;
        $server->noteWorkerRegistered($this);
    }

    /**
     * @param string $data Frame the daemon wants written to this worker
     */
    public function send(string $data): void
    {
        if ($this->sendFailure !== null) {
            throw $this->sendFailure;
        }
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
