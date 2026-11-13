<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccessLog\AccessLogEvent;
use Hilos\Auth\AccountOperation\AccountErasureOpening;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\SecondFactor\SecondFactorPendingMode;
use Hilos\Auth\Session\SessionToastSeverity;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\AccessLogEntriesActions;
use Hilos\Database\Context\FrameworkExtension;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Collection\AccessLogEntries;
use Hilos\Database\View\Item\AccountDeletion;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt;
use Hilos\Runtime\State\Item\HilosProfileFlow;
use Hilos\Runtime\State\Item\HilosProfileNameChange;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\SecondFactorFate;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * The session holder's part of an account erasure, carried as its participant step (HIL-1943).
 *
 * The step is called the way the coordinator will call it: in the holder's own frame, with the
 * holder's expected assignment of an erasure opened for a live deletion request. One transaction
 * takes the person out of every session they stand in, deletes their access log and records the
 * two counts; the tabs are told only once it commits. Every session of a case has one live tab,
 * so each frame the holder sends to a browser is caught in the queue. The project draws a sign-in
 * surface, as every project with accounts to erase does, so a sign-out also clears the session's
 * profile windows, name change and send-progress line.
 */
final class AccountErasureSessionsParticipantIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const int USER_ID = 1943;
    private const int NEIGHBOUR_ID = 1944;
    private const int ADMIN_ID = 1945;

    /** Tables of the account operations, raised over the base's. */
    private const array TABLES = [
        'hilos_account_operation',
        'hilos_account_operation_assignment',
        'hilos_account_operation_result',
    ];

    /** Session signed in as the person. */
    private const string SIGNED_IN_TOKEN = '19430000000000000000000000000001';

    /** A second browser signed in as the person. */
    private const string SIGNED_IN_AGAIN_TOKEN = '19430000000000000000000000000002';

    /** Session where an administrator takes the person over. */
    private const string ADMIN_TAKEOVER_TOKEN = '19430000000000000000000000000003';

    /** Session where the person, an administrator, takes the neighbour over. */
    private const string PERSON_TAKEOVER_TOKEN = '19430000000000000000000000000004';

    /** Session whose proven sign-in waits on the person's second factor. */
    private const string WAITING_TOKEN = '19430000000000000000000000000005';

    /** Session showing the "Access closed" card about the person. */
    private const string BLOCKED_TOKEN = '19430000000000000000000000000006';

    /** Session signed in as the neighbour. */
    private const string NEIGHBOUR_TOKEN = '19440000000000000000000000000001';

    /** The columns through which a session row names a person. */
    private const array PERSON_COLUMNS = ['user_id', 'impersonator_user_id', 'pending_second_factor_user_id', 'blocked_user_id'];

    /** The session rows keep epoch milliseconds. */
    private const int SESSION_CREATED_AT = 1790416800000;

    /** 2036-01-01 00:00:00 UTC: the wait and the deletion are still ahead. */
    private const int FUTURE = 2082758400000;

    private const string ADDRESS = '203.0.113.7';

    private ErasureSessionsTestHolder $holder;

    private ErasureSessionsTestRtContext $rt;

    private string $previousApp;

    private ?SignalRouter $previousRouter = null;

    private ?RtContext $previousRt = null;

    /**
     * @throws HilosException When the schema, the runtime context or the holder's claims cannot be prepared
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::stubs(down: true);
        self::stubs(down: false);
        Schema::reset();
        Schema::initialize();

        $this->previousApp = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        Hilos::$sr = new SignalRouter();
        $this->rt = new ErasureSessionsTestRtContext();
        $this->rt->mountFeatureRuntime([new AuthFeature()]);
        $this->rt->configure();
        $this->rt->bindStateCollectionNames();
        Hilos::$rt = $this->rt;
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        ErasureSessionsTestHilos::initBrowser();
        $this->holder = new ErasureSessionsTestHolder();
        OwnershipDeclaration::claimAll($this->holder);
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            self::stubs(down: true);
            Schema::reset();
        } finally {
            TruthSourceRegistry::unregisterAgent($this->holder->getId());
            RtTruthSourceRegistry::unregisterAgent($this->holder->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->holder->getId()));
            SourceChangeBus::reset();
            Hilos::$rt = $this->previousRt;
            Hilos::$sr = $this->previousRouter;
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();

            parent::tearDown();
        }
    }

    /**
     * Both browsers the person signed in on become guests, and both session rows stay.
     *
     * @throws HilosException When a fixture row cannot be written or the step fails
     */
    public function testTwoSessionsThePersonSignedInOnBecomeGuests(): void
    {
        $first = $this->signIn(self::SIGNED_IN_TOKEN, self::USER_ID);
        $second = $this->signIn(self::SIGNED_IN_AGAIN_TOKEN, self::USER_ID);
        $assignmentId = $this->holderPart($this->openErasure());

        $result = $this->erase($assignmentId);

        self::assertSame('{"sessions":2,"accessLog":0}', $result->payloadJson);
        foreach ([$first, $second] as $sessionId) {
            self::assertSame(self::nobody(), self::sessionPeople($sessionId), 'The session stays, as a guest');
        }
        $frames = $this->framesBySession();
        self::assertSame([$first, $second], array_keys($frames));
        foreach ($frames as $sessionId => $states) {
            self::assertNull(end($states)->userId, "The tabs of session {$sessionId} are told they are a guest now");
        }
    }

    /**
     * A session where an administrator takes the person over is ended completely: no person, no takeover.
     *
     * @throws HilosException When a fixture row cannot be written or the step fails
     */
    public function testATakeoverOfThePersonByAnAdministratorBecomesAGuest(): void
    {
        $sessionId = $this->signIn(self::ADMIN_TAKEOVER_TOKEN, self::USER_ID, self::ADMIN_ID);
        $assignmentId = $this->holderPart($this->openErasure());

        $result = $this->erase($assignmentId);

        self::assertSame('{"sessions":1,"accessLog":0}', $result->payloadJson);
        self::assertSame(self::nobody(), self::sessionPeople($sessionId));
        $frames = $this->framesBySession();
        self::assertSame([$sessionId], array_keys($frames));
        self::assertNull(end($frames[$sessionId])->userId);
    }

    /**
     * A session where the person takes somebody else over is ended too, and not given back to the
     * person they took over (P-1050).
     *
     * @throws HilosException When a fixture row cannot be written or the step fails
     */
    public function testATakeoverThePersonRanBecomesAGuest(): void
    {
        $sessionId = $this->signIn(self::PERSON_TAKEOVER_TOKEN, self::NEIGHBOUR_ID, self::USER_ID);
        $assignmentId = $this->holderPart($this->openErasure());

        $result = $this->erase($assignmentId);

        self::assertSame('{"sessions":1,"accessLog":0}', $result->payloadJson);
        self::assertSame(self::nobody(), self::sessionPeople($sessionId));
        $frames = $this->framesBySession();
        self::assertSame([$sessionId], array_keys($frames));
        self::assertNull(end($frames[$sessionId])->userId);
    }

    /**
     * A browser waiting on the person's second factor is let go, and its tabs go back to the address field.
     *
     * @throws HilosException When a fixture row cannot be written or the step fails
     */
    public function testAWaitOnThePersonsSecondFactorIsLetGo(): void
    {
        $sessionId = $this->waitOnSecondFactor(self::WAITING_TOKEN, self::USER_ID);
        $assignmentId = $this->holderPart($this->openErasure());

        $result = $this->erase($assignmentId);

        self::assertSame('{"sessions":1,"accessLog":0}', $result->payloadJson);
        self::assertSame(self::nobody(), self::sessionPeople($sessionId));
        $frames = $this->framesBySession();
        self::assertSame([$sessionId], array_keys($frames));
        $state = end($frames[$sessionId]);
        self::assertNull($state->userId);
        self::assertSame(AuthFlowStep::IDENTIFIER, $state->pendingAuthStep[HandshakeResponseSignalData::step] ?? null);
    }

    /**
     * The "Access closed" card about the person is lowered on the browser that shows it.
     *
     * @throws HilosException When a fixture row cannot be written or the step fails
     */
    public function testACardAboutThePersonsClosedAccessIsLowered(): void
    {
        $sessionId = $this->showBlockedCard(self::BLOCKED_TOKEN, self::USER_ID);
        $assignmentId = $this->holderPart($this->openErasure());

        $result = $this->erase($assignmentId);

        self::assertSame('{"sessions":1,"accessLog":0}', $result->payloadJson);
        self::assertSame(self::nobody(), self::sessionPeople($sessionId));
        self::assertSame(0, (int)self::sessionRowById($sessionId)['blocked_signed_in'], 'The card is not kept as signed in either');
        $frames = $this->framesBySession();
        self::assertSame([$sessionId], array_keys($frames));
        self::assertNull(end($frames[$sessionId])->accountBlocked, 'The tabs are told the card is down');
    }

    /**
     * After the step no session row names the person by any of its four columns and no log row of
     * theirs is left; the neighbour's session and log are untouched, and the result counts the
     * different sessions changed and the log rows deleted.
     *
     * @throws HilosException When a fixture row cannot be written or the step fails
     */
    public function testNothingOfThePersonStaysInTheSessionsOrTheLog(): void
    {
        $this->signIn(self::SIGNED_IN_TOKEN, self::USER_ID);
        $this->signIn(self::SIGNED_IN_AGAIN_TOKEN, self::USER_ID);
        $this->signIn(self::ADMIN_TAKEOVER_TOKEN, self::USER_ID, self::ADMIN_ID);
        $this->signIn(self::PERSON_TAKEOVER_TOKEN, self::NEIGHBOUR_ID, self::USER_ID);
        $this->waitOnSecondFactor(self::WAITING_TOKEN, self::USER_ID);
        $this->showBlockedCard(self::BLOCKED_TOKEN, self::USER_ID);
        $neighbourSessionId = $this->signIn(self::NEIGHBOUR_TOKEN, self::NEIGHBOUR_ID);
        self::logAccess(self::USER_ID);
        self::logAccess(self::USER_ID);
        self::logAccess(self::NEIGHBOUR_ID);
        $neighbourSession = self::sessionRowById($neighbourSessionId);
        $neighbourLog = self::accessLogOf(self::NEIGHBOUR_ID);
        $assignmentId = $this->holderPart($this->openErasure());

        $result = $this->erase($assignmentId);

        self::assertSame('{"sessions":6,"accessLog":2}', $result->payloadJson);
        self::assertSame([[$assignmentId, $this->holder->getId(), '{"sessions":6,"accessLog":2}']], self::storedResults());
        foreach (self::PERSON_COLUMNS as $column) {
            self::assertSame(
                [],
                Database::sql("SELECT `id` FROM `hilos_session` WHERE `{$column}` = ?", [self::USER_ID])->rows(),
                "No session names the person by {$column}",
            );
        }
        self::assertSame([], self::accessLogOf(self::USER_ID));
        self::assertSame($neighbourSession, self::sessionRowById($neighbourSessionId), "The neighbour's own session is untouched");
        self::assertSame($neighbourLog, self::accessLogOf(self::NEIGHBOUR_ID), "The neighbour's log is untouched");
        self::assertArrayNotHasKey($neighbourSessionId, $this->framesBySession(), "The neighbour's tab is told nothing");
    }

    /**
     * A repeat answers with the saved result and does not do the work again: a log row written
     * since stays, and nothing is sent or written.
     *
     * @throws HilosException When a fixture row cannot be written or the step fails
     */
    public function testARepeatAnswersTheSavedResultAndDoesNothing(): void
    {
        $this->signIn(self::SIGNED_IN_TOKEN, self::USER_ID);
        self::logAccess(self::USER_ID);
        $assignmentId = $this->holderPart($this->openErasure());
        $first = $this->erase($assignmentId);
        $this->drainQueue();
        self::logAccess(self::USER_ID);
        $sessions = self::allSessionRows();
        $log = self::accessLogOf(self::USER_ID);
        $results = self::storedResults();

        $repeat = $this->erase($assignmentId);

        self::assertSame($first->id, $repeat->id);
        self::assertSame('{"sessions":1,"accessLog":1}', $repeat->payloadJson);
        self::assertSame([], $this->drainQueue(), 'Nothing is sent');
        self::assertSame($sessions, self::allSessionRows());
        self::assertSame($log, self::accessLogOf(self::USER_ID), 'The work is not done again');
        self::assertSame($results, self::storedResults());
    }

    /**
     * A failure after the sessions were released rolls the whole step back: the sessions are the
     * person's again, the wait and the card stand, the log is whole and no result is saved. Nothing
     * at all is queued: the signed-in browser had a toast, a profile window, a name change and a
     * send-progress line for the sign-out to clear, and its tabs hear neither of the sign-out nor of
     * any of the four.
     *
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testAFailureAfterTheSessionsRollsEverythingBack(): void
    {
        Hilos::$db = new ErasureSessionsFailingLogContext();
        Hilos::$db->configure();
        $signedIn = $this->signIn(self::SIGNED_IN_TOKEN, self::USER_ID);
        $this->busyBrowser(self::SIGNED_IN_TOKEN);
        $waiting = $this->waitOnSecondFactor(self::WAITING_TOKEN, self::USER_ID);
        $blocked = $this->showBlockedCard(self::BLOCKED_TOKEN, self::USER_ID);
        self::logAccess(self::USER_ID);
        $sessions = self::allSessionRows();
        $log = self::accessLogOf(self::USER_ID);
        $assignmentId = $this->holderPart($this->openErasure());
        $this->drainQueue();

        try {
            $this->erase($assignmentId);
            self::fail('The failing log did not fail the step');
        } catch (DatabaseException $failure) {
            self::assertSame(ErasureSessionsFailingLogActions::FAILURE, $failure->getMessage());
        }

        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame($sessions, self::allSessionRows(), 'Every session is as it was');
        self::assertSame(self::USER_ID, self::sessionPeople($signedIn)['user_id']);
        self::assertSame(self::USER_ID, self::sessionPeople($waiting)['pending_second_factor_user_id']);
        self::assertSame(self::USER_ID, self::sessionPeople($blocked)['blocked_user_id']);
        self::assertSame($log, self::accessLogOf(self::USER_ID));
        self::assertSame([], self::storedResults());
        self::assertSame([], $this->queuedNames(), 'No tab was told of a sign-out the database never kept, nor of what it cleared');
    }

    /**
     * A wait the holder keeps for a participant with no owner registered is not its part, even
     * when the person still has rows there.
     *
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testAWaitOnTheHolderIsRefused(): void
    {
        $this->signIn(self::SIGNED_IN_TOKEN, self::USER_ID);
        self::logAccess(self::USER_ID);
        $operation = $this->openErasure();
        $waits = array_values(array_filter(
            iterator_to_array(Hilos::$db->accountOperationAssignments->forOperation($operation->id), false),
            static fn (AccountOperationAssignment $assignment): bool => AccountOperationExpectation::isWaitKey($assignment->assignmentKey),
        ));
        self::assertCount(1, $waits);
        self::assertSame(AccountErasureParticipantCatalog::PERSONAL, AccountOperationExpectation::participantOf($waits[0]->payload));
        self::assertSame(self::USER_ID, $waits[0]->userId);
        self::assertSame($this->holder->getId(), $waits[0]->owner->agentId(), 'The coordinator keeps the wait');

        $this->assertRefused($waits[0]->id, 'This assignment is not the session holder\'s erasure part.');
    }

    /**
     * The wait the census writes for the holder's own part, when the project registers no holder it
     * expects, is the holder's to keep and not its part to carry: it names the holder as participant
     * and is written to the holder, and is still refused.
     *
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testAWaitForTheHoldersOwnPartIsRefused(): void
    {
        $this->signIn(self::SIGNED_IN_TOKEN, self::USER_ID);
        self::logAccess(self::USER_ID);
        $operation = $this->openErasure();
        $assignmentId = $this->asHolder(fn (): int => $this->assignToHolder($operation, self::USER_ID, owner: null));
        $wait = Hilos::$db->accountOperationAssignments[$assignmentId];
        self::assertTrue(AccountOperationExpectation::isWaitKey($wait->assignmentKey));
        self::assertSame(AccountErasureParticipantCatalog::SESSIONS_HOLDER, AccountOperationExpectation::participantOf($wait->payload));
        self::assertSame($this->holder->getId(), $wait->owner->agentId());

        $this->assertRefused($assignmentId, 'This assignment is not the session holder\'s erasure part.');
    }

    /**
     * An assignment given to the holder inside a merge is refused before any work.
     *
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testAPartAssignedInAMergeIsRefused(): void
    {
        $this->signIn(self::SIGNED_IN_TOKEN, self::USER_ID);
        $this->signIn(self::NEIGHBOUR_TOKEN, self::NEIGHBOUR_ID);
        self::logAccess(self::USER_ID);
        $assignmentId = $this->asHolder(function (): int {
            $merge = Hilos::$db->accountOperations->actions->recordMerge(
                self::NEIGHBOUR_ID,
                self::USER_ID,
                new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, true, false),
            );

            return $this->assignToHolder($merge, self::USER_ID);
        });

        $this->assertRefused($assignmentId, 'An account erasure part must belong to an erasure.');
    }

    /**
     * The holder's part written without an account is refused: there is nobody to sign out.
     *
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testAPartNamingNoAccountIsRefused(): void
    {
        $this->signIn(self::SIGNED_IN_TOKEN, self::USER_ID);
        self::logAccess(self::USER_ID);
        $request = $this->requestDeletion();
        $assignmentId = $this->asHolder(function () use ($request): int {
            $erasure = Hilos::$db->accountOperations->actions->recordErasure(
                $request->id,
                self::USER_ID,
                Hilos::$db->userMerges->erasureCircleOf(self::USER_ID),
            );

            return $this->assignToHolder($erasure, null);
        });

        $this->assertRefused($assignmentId, 'An account erasure part must name its account.');
    }

    /**
     * @return AccountDeletion A live deletion request of the person, falling due ahead
     * @throws HilosException When the request cannot be written or read
     */
    private function requestDeletion(): AccountDeletion
    {
        Database::sqlRun(
            'INSERT INTO `hilos_account_deletion` (`user_id`, `requested_at`, `effective_at`) VALUES (?, ?, ?)',
            [self::USER_ID, TimeHelper::nowMs(), self::FUTURE],
        );
        $request = Hilos::$db->accountDeletions->liveOf(self::USER_ID);
        self::assertNotNull($request);

        return $request;
    }

    /**
     * Requests the person's deletion and opens its erasure in the holder's frame, as the coordinator will.
     *
     * @return AccountOperation The open erasure
     * @throws HilosException When the request cannot be written or the opening refuses
     */
    private function openErasure(): AccountOperation
    {
        $request = $this->requestDeletion();
        $operation = $this->asHolder(
            static fn (): ?AccountOperation => AccountErasureOpening::open($request, AccountErasureParticipantCatalog::framework())->operation,
        );
        self::assertNotNull($operation);

        return $operation;
    }

    /**
     * @param AccountOperation $operation Open erasure
     * @return int Id of the holder's own expected part for the person
     * @throws HilosException When the assignments cannot be read
     */
    private function holderPart(AccountOperation $operation): int
    {
        $parts = [];
        foreach (Hilos::$db->accountOperationAssignments->forOperation($operation->id) as $assignment) {
            if (
                AccountOperationExpectation::isExpectKey($assignment->assignmentKey)
                && AccountOperationExpectation::participantOf($assignment->payload) === AccountErasureParticipantCatalog::SESSIONS_HOLDER
            ) {
                $parts[] = $assignment;
            }
        }
        self::assertCount(1, $parts);
        self::assertSame(self::USER_ID, $parts[0]->userId);

        return $parts[0]->id;
    }

    /**
     * Records the holder's expected part of the person on an operation, or the wait for it, with the
     * key and payload the census writes, and the account the case asks for; runs in the holder's frame.
     *
     * @param AccountOperation $operation Operation to record on, at its current stage
     * @param ?int $userId Account the part names, or null for none
     * @param ?AccountOperationOwnerAddress $owner Owner the census expects, or null for the wait it writes instead
     * @return int Id of the assignment
     * @throws HilosException When the record refuses
     */
    private function assignToHolder(
        AccountOperation $operation,
        ?int $userId,
        ?AccountOperationOwnerAddress $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY),
    ): int {
        $expectation = new AccountOperationExpectation(AccountErasureParticipantCatalog::SESSIONS_HOLDER, self::USER_ID, $owner);

        return Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            $operation->stage,
            $expectation->assignmentKey(),
            $expectation->assignmentOwner(),
            $userId,
            $expectation->payload(),
        )->id;
    }

    /**
     * @param int $assignmentId Assignment the holder is asked to carry
     * @param string $words Words of the refusal
     * @throws HilosException When a row cannot be read
     */
    private function assertRefused(int $assignmentId, string $words): void
    {
        $sessions = self::allSessionRows();
        $log = self::accessLogOf(self::USER_ID);
        $this->drainQueue();

        try {
            $this->erase($assignmentId);
            self::fail('The part was not refused');
        } catch (ValidationException $refusal) {
            self::assertSame($words, $refusal->getMessage());
        }

        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame($sessions, self::allSessionRows());
        self::assertSame($log, self::accessLogOf(self::USER_ID));
        self::assertNotSame([], $log, 'The person still had rows to lose');
        self::assertSame([], self::storedResults());
        self::assertSame([], $this->framesBySession());
    }

    /**
     * @param int $assignmentId Assignment the holder carries
     * @return AccountOperationResult The recorded or saved result
     * @throws HilosException When the step refuses or fails
     */
    private function erase(int $assignmentId): AccountOperationResult
    {
        return $this->asHolder(fn (): AccountOperationResult => $this->holder->erasePart($assignmentId));
    }

    /**
     * Runs one step in the holder's own frame, the way its worker would.
     *
     * @template T
     * @param callable(): T $step Step to run as the holder
     * @return T Whatever the step returns
     */
    private function asHolder(callable $step): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), $step);
    }

    /**
     * Writes a session signed in as one person, or taken over by another, with one live tab.
     *
     * @param string $token Session token
     * @param int $userId Person the session acts as
     * @param ?int $impersonatorUserId Administrator behind a takeover, or null for an ordinary session
     * @return int Id of the session row
     * @throws HilosException When the row cannot be written
     */
    private function signIn(string $token, int $userId, ?int $impersonatorUserId = null): int
    {
        self::seedSession($token, $userId, self::SESSION_CREATED_AT, null, $impersonatorUserId);

        return $this->openTab($token, $userId);
    }

    /**
     * Writes a guest session whose proven sign-in waits on one person's second factor, with one live tab.
     *
     * @param string $token Session token
     * @param int $userId Person the sign-in waits on
     * @return int Id of the session row
     * @throws HilosException When the row cannot be written
     */
    private function waitOnSecondFactor(string $token, int $userId): int
    {
        self::seedPersonRow($userId);
        self::seedSession($token, null, self::SESSION_CREATED_AT, null);
        Database::sqlRun(
            'UPDATE `hilos_session` SET `pending_second_factor_user_id` = ?, `pending_second_factor_mode` = ?,'
            . ' `pending_second_factor_until` = ? WHERE `token` = ?',
            [$userId, SecondFactorPendingMode::VERIFY, self::FUTURE, $token],
        );

        return $this->openTab($token, null);
    }

    /**
     * Writes a guest session showing the "Access closed" card about one person, with one live tab.
     *
     * @param string $token Session token
     * @param int $userId Person the card is about
     * @return int Id of the session row
     * @throws HilosException When the row cannot be written
     */
    private function showBlockedCard(string $token, int $userId): int
    {
        self::seedPersonRow($userId);
        self::seedSession($token, null, self::SESSION_CREATED_AT, null);
        Database::sqlRun(
            'UPDATE `hilos_session` SET `blocked_user_id` = ?, `blocked_signed_in` = 1 WHERE `token` = ?',
            [$userId, $token],
        );

        return $this->openTab($token, null);
    }

    /**
     * Gives a browser signed in as the person something under way in each of the four things a
     * sign-out clears and tells its tabs about: a toast, a profile window, a name change and a
     * send-progress line; runs in the holder's frame, and drops the frames the writes queued.
     *
     * @param string $token Session token of a browser signed in as the person
     * @throws HilosException When a runtime row cannot be written
     */
    private function busyBrowser(string $token): void
    {
        $hash = ProtectedModeRuntime::hashSessionToken($token);
        $this->asHolder(static function () use ($hash, $token): void {
            Hilos::$rt->hilosSessionToastStacks->actions->raise(
                $hash,
                'erasure-toast',
                'Your copy of the data is ready.',
                SessionToastSeverity::SUCCESS,
                'Data export',
                '/profile',
            );
            Hilos::$rt->hilosProfileFlows->actions->put(
                $hash,
                StepUpOperationKey::CHANGE_EMAIL,
                self::USER_ID,
                HilosProfileFlow::STEP_CURRENT_SENT,
                'person@example.test',
                null,
                self::FUTURE,
            );
            Hilos::$rt->hilosProfileNameChanges->actions->open(
                $hash,
                self::USER_ID,
                str_repeat('a', 32),
                "accept-{$token}",
                'New name',
                TimeHelper::nowMs(),
                null,
            );
            Hilos::$rt->hilosCodeSendAttempts->actions->start(
                $hash,
                'erasure-ticket',
                HilosCodeSendAttempt::CHANNEL_EMAIL,
                StepUpOperationKey::CHANGE_EMAIL,
            );
        });
        $this->drainQueue();
    }

    /**
     * @param string $token Session token
     * @param ?int $userId Person the tab is signed in as, or null for a guest
     * @return int Id of the session row the tab belongs to
     * @throws DatabaseException When the session cannot be read
     */
    private function openTab(string $token, ?int $userId): int
    {
        $sessionId = (int)Database::sql('SELECT `id` FROM `hilos_session` WHERE `token` = ?', [$token])->rows()[0]['id'];
        $this->rt->addConnection(ErasureSessionsTestConnection::create("accept-{$token}", $userId, $token, $sessionId));

        return $sessionId;
    }

    /**
     * @param int $userId Person whose row the case needs, written once
     * @throws DatabaseException When the insert fails
     */
    private static function seedPersonRow(int $userId): void
    {
        Database::sqlRun("INSERT IGNORE INTO `hilos_user` (`id`, `name`) VALUES (?, 'Fixture person')", [$userId]);
    }

    /**
     * @param int $userId Person
     * @throws DatabaseException When the insert fails
     */
    private static function logAccess(int $userId): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_access_log` (`user_id`, `event`, `ip_address`, `occurred_at`) VALUES (?, ?, ?, ?)',
            [$userId, AccessLogEvent::SIGN_IN->value, self::ADDRESS, TimeHelper::nowMs()],
        );
    }

    /**
     * @return list<SignalDTO> Every queued signal, in queue order; the queue is empty afterwards
     */
    private function drainQueue(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }

        return $signals;
    }

    /**
     * @return list<string> Name of every queued signal, in queue order; the queue is empty afterwards
     */
    private function queuedNames(): array
    {
        return array_map(static fn (SignalDTO $signal): string => $signal->signalName->getName(), $this->drainQueue());
    }

    /**
     * @return array<int, non-empty-list<SessionStateSignalData>> Session-state frames the queue holds, by session, in queue order
     */
    private function framesBySession(): array
    {
        $frames = [];
        foreach ($this->drainQueue() as $signal) {
            if (
                $signal->signalName->getName() === HilosSignalConstants::HILOS_SESSION_STATE
                && $signal->data instanceof AgentSignalData
                && $signal->data->data instanceof SessionStateSignalData
            ) {
                $frames[(int)$signal->data->data->sessionId][] = $signal->data->data;
            }
        }
        ksort($frames);

        return $frames;
    }

    /**
     * @return array{user_id: null, impersonator_user_id: null, pending_second_factor_user_id: null, blocked_user_id: null}
     *     A session row naming nobody
     */
    private static function nobody(): array
    {
        return array_fill_keys(self::PERSON_COLUMNS, null);
    }

    /**
     * Reads the people a session row names straight from the database, past every in-memory collection.
     *
     * @param int $sessionId Session row
     * @return array<string, ?int> Person per column of {@see self::PERSON_COLUMNS}
     * @throws DatabaseException When the query fails
     */
    private static function sessionPeople(int $sessionId): array
    {
        $row = self::sessionRowById($sessionId);
        $people = [];
        foreach (self::PERSON_COLUMNS as $column) {
            $people[$column] = $row[$column] === null ? null : (int)$row[$column];
        }

        return $people;
    }

    /**
     * @param int $sessionId Session row
     * @return array<string, mixed> The whole row as stored
     * @throws DatabaseException When the query fails
     */
    private static function sessionRowById(int $sessionId): array
    {
        $rows = Database::sql('SELECT * FROM `hilos_session` WHERE `id` = ?', [$sessionId])->rows();
        self::assertCount(1, $rows, 'The session row stays');

        return $rows[0];
    }

    /**
     * @return list<array<string, mixed>> Every session row as stored, in id order
     * @throws DatabaseException When the query fails
     */
    private static function allSessionRows(): array
    {
        return Database::sql('SELECT * FROM `hilos_session` ORDER BY `id`')->rows();
    }

    /**
     * @param int $userId Person
     * @return list<array<string, mixed>> The person's log rows as stored, in id order
     * @throws DatabaseException When the query fails
     */
    private static function accessLogOf(int $userId): array
    {
        return Database::sql('SELECT * FROM `hilos_access_log` WHERE `user_id` = ? ORDER BY `id`', [$userId])->rows();
    }

    /**
     * @return list<array{int, string, string}> Assignment, owner and payload of every stored result, in id order
     * @throws DatabaseException When the query fails
     */
    private static function storedResults(): array
    {
        return array_map(
            static fn (array $row): array => [(int)$row['assignment_id'], (string)$row['owner_agent_id'], (string)$row['payload_json']],
            Database::sql('SELECT `assignment_id`, `owner_agent_id`, `payload_json` FROM `hilos_account_operation_result` ORDER BY `id`')->rows(),
        );
    }

    /**
     * @param bool $down Drop in reverse dependency order
     * @throws DatabaseException When a stub statement fails
     */
    private static function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            // external-boundary: up stub filenames carry no direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
        }
    }
}

/** The framework's session holder, with its erasure part opened to the case. */
final class ErasureSessionsTestHolder extends AbstractSessionsLibraryAgent
{
    /**
     * The sign-in surface's runtime a sign-out clears, which a project with that surface declares on its holder.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_RT = [
        HilosCodeSendAttempt::RT_COLLECTION => TruthSourceOperation::BY_KIND,
        HilosProfileFlow::RT_COLLECTION => TruthSourceOperation::BY_KIND,
        HilosProfileNameChange::RT_COLLECTION => TruthSourceOperation::BY_KIND,
    ];

    public function onStop(): void
    {
    }

    /**
     * @param int $assignmentId Assignment the coordinator gave the holder
     * @return AccountOperationResult Committed result or the saved outcome of an earlier attempt
     * @throws HilosException When the step refuses or fails
     */
    public function erasePart(int $assignmentId): AccountOperationResult
    {
        return $this->eraseAccountSessionsPart($assignmentId);
    }
}

/** The project draws a sign-in surface and registers the session holder, so the census expects the holder's part from it. */
abstract class ErasureSessionsTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH];

    public const array AGENTS = [
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [AgentRegistryKey::WORKER => ErasureSessionsTestHolder::class],
    ];
}

/** The framework's collections with an access log that fails to forget anybody. */
final class ErasureSessionsFailingLogContext extends HilosDbContext
{
    /**
     * @return array<string, FrameworkExtension> The framework's declarations plus the failing log
     */
    protected function frameworkExtensions(): array
    {
        return [
            ...parent::frameworkExtensions(),
            self::accessLogEntries => new FrameworkExtension(ErasureSessionsFailingLog::class, ErasureSessionsFailingLogActions::class),
        ];
    }
}

/** The framework's access log under the failing chain. */
final class ErasureSessionsFailingLog extends AccessLogEntries
{
}

/** Access log actions whose erasure delete fails, after the sessions were already released. */
final class ErasureSessionsFailingLogActions extends AccessLogEntriesActions
{
    public const string FAILURE = 'The access log could not forget the person';

    /**
     * @param int $userId Person
     * @return int Never returns
     * @throws DatabaseException Always
     */
    public function deleteForUser(int $userId): int
    {
        throw new DatabaseException(self::FAILURE);
    }
}

/** Runtime holding the case's live tabs. */
final class ErasureSessionsTestRtContext extends RtContext
{
    private ErasureSessionsTestConnections $connections;

    public function configure(): void
    {
        $this->connections = ErasureSessionsTestConnections::init();
        $this->_stateCollections[ErasureSessionsTestConnections::RT_COLLECTION] = $this->connections;
    }

    /**
     * @param ErasureSessionsTestConnection $connection Live tab to mount
     */
    public function addConnection(ErasureSessionsTestConnection $connection): void
    {
        $this->connections->add($connection);
    }
}

/** Session-stage connection collection of the case. */
final class ErasureSessionsTestConnections extends HilosSessionConnections
{
    public const string RT_COLLECTION = 'erasureSessionsTestConnections';
    public const string STATE_CLASS = ErasureSessionsTestConnection::class;
}

/** Session-stage connection row adding no project fields. */
final class ErasureSessionsTestConnection extends HilosSessionConnection
{
    protected function initOwn(): void
    {
    }

    /** @param array<string, mixed> $row Serialized runtime row */
    protected function hydrateOwn(array $row): void
    {
    }

    /** @return array<string, mixed> No project fields */
    protected function ownToArray(): array
    {
        return [];
    }

    /** @param array<string, mixed> $diff Incoming field changes */
    protected function applyOwnDiff(array $diff): void
    {
    }
}
