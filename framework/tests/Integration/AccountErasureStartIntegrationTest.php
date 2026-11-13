<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountOperation\AccountErasureOpening;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountErasureStart;
use Hilos\Auth\AccountOperation\AccountErasureStartResult;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\View\Item\AccountDeletion;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Users\AddressablePerson;
use Hilos\Users\DTO\UserAccountDeletionCancelDoneSignalData;
use Hilos\Users\DTO\UserAccountDeletionCancelSignalData;
use Hilos\Users\DTO\UserBlockWriteSignalData;
use Hilos\Users\DTO\UserBrowserTrustRenewSignalData;
use Hilos\Users\DTO\UserLegalHoldForgetSignalData;
use Hilos\Users\DTO\UserPushEndpointGoneSignalData;
use Hilos\Users\DTO\UserPushReleaseSignalData;
use Hilos\Users\DTO\UserPushSubscribeSignalData;
use Hilos\Users\DTO\UserRenameSignalData;
use Hilos\Users\DTO\UserSecondFactorTrustRestrictSignalData;
use Hilos\Users\DTO\UserThemePickWriteSignalData;
use Hilos\Users\SecondFactorFate;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The start of an account's erasure on its deletion request, and the refusal that follows it (HIL-1939).
 *
 * The person's agent begins the erasure with a conditional write that races "Keep my account":
 * exactly one of the two wins, and the start wins once. From the start on, the common check on the
 * way to the person's agent refuses the account in its own words, after the refusals of a missing
 * and of a merged account. The root account's agent carries the start as its participant step of
 * an erasure the session holder opened, and the agent of a folded account marks its own request
 * as the first write of its part. Every write runs in the frame of the agent whose class declares
 * it, and commits for real.
 */
final class AccountErasureStartIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    /** Accept key standing in for the person's browser. */
    private const string ACCEPT_KEY = 'accept-erasure-start';

    /** A person id no row carries. */
    private const int MISSING_USER_ID = 939939;

    /** Tables of the account operations and of the push devices, raised over the base's. */
    private const array TABLES = [
        'hilos_account_operation',
        'hilos_account_operation_assignment',
        'hilos_account_operation_result',
        'hilos_push_subscription',
    ];

    /** Agent id the session holder writes the operations under. */
    private const string HOLDER_ID = 'erasure-start-holder';

    /** Stage the case gives the start at: the start's own stage is not this leaf's. */
    private const string START_STAGE = 'starting';

    /** Prefix of the start's assignment key, followed by the account. */
    private const string START_KEY = 'start:';

    /** Name every frame of a case is answered under. */
    private const string REPLY = 'integration_erasure_start_reply';

    private ErasureStartTestUsersLibrary $users;

    private ?SignalRouter $previousRouter = null;

    /** Application class to restore, when a case switched to the one with a legal catalog. */
    private ?string $previousApp = null;

    /**
     * @throws HilosException When the schema reset or the context build fails
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        self::stubs(down: true);
        self::stubs(down: false);
        $this->users = new ErasureStartTestUsersLibrary();
        OwnershipDeclaration::claimDb($this->users::class, $this->users->getId());
        OwnershipDeclaration::claimDb(ErasureStartTestSessionsHolder::class, self::HOLDER_ID);
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            self::stubs(down: true);
        } finally {
            if ($this->previousApp !== null) {
                $this->previousApp::initBrowser();
                Hilos::resetBrowser();
            }
            $this->releasePersonAgents();
            foreach ([$this->users->getId(), self::HOLDER_ID] as $agentId) {
                TruthSourceRegistry::unregisterAgent($agentId);
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
            }
            Hilos::$sr = $this->previousRouter;
            SourceChangeBus::reset();

            parent::tearDown();
        }
    }

    /**
     * A cancel that landed first leaves nothing to start: the start answers false and writes nothing.
     *
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testCancelFirstMakesTheStartLose(): void
    {
        $userId = $this->seedPerson();
        $request = $this->requestDeletion($userId);

        self::assertTrue($this->asPerson($userId, static fn () => $request->actions->cancel()));
        self::assertFalse($this->asPerson($userId, static fn () => $request->actions->start()));

        $row = self::requestRow($request);
        self::assertNull($row['started_at']);
        self::assertNotNull($row['canceled_at']);
        self::assertNull(Hilos::$db->accountDeletions->startedOf($userId));
    }

    /**
     * A start that landed first makes the cancel lose, and a second start answers false on the begun request.
     *
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testStartFirstMakesTheCancelLoseAndStartsOnce(): void
    {
        $userId = $this->seedPerson();
        $request = $this->requestDeletion($userId);

        self::assertTrue($this->asPerson($userId, static fn () => $request->actions->start()));
        $startedAt = self::requestRow($request)['started_at'];
        self::assertNotNull($startedAt);
        self::assertSame($startedAt, $request->startedAt);

        self::assertFalse($this->asPerson($userId, static fn () => $request->actions->cancel()));
        self::assertNull(self::requestRow($request)['canceled_at']);

        self::assertFalse($this->asPerson($userId, static fn () => $request->actions->start()));
        $row = self::requestRow($request);
        self::assertSame($startedAt, $row['started_at']);
        self::assertNull($row['canceled_at']);
        self::assertNull($row['completed_at']);
    }

    /**
     * "Keep my account" handed to the person's agent after the start is refused in the erasing words.
     *
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testKeepMyAccountAfterTheStartIsRefusedAndTheRequestStaysBegun(): void
    {
        $userId = $this->seedPerson();
        $request = $this->requestDeletion($userId);
        self::assertTrue($this->asPerson($userId, static fn () => $request->actions->start()));

        $answer = $this->carryCancel($userId);

        self::assertFalse($answer->canceled);
        self::assertSame('This account is being deleted.', $answer->error);
        self::assertNull(self::requestRow($request)['canceled_at']);
        self::assertSame($request->id, Hilos::$db->accountDeletions->startedOf($userId)?->id);
    }

    /**
     * A person with a standing request that has not begun is addressable as before.
     *
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testRequireLetsAStandingRequestThatHasNotBegunThrough(): void
    {
        $userId = $this->seedPerson();
        $this->requestDeletion($userId);

        AddressablePerson::require($userId);

        $this->addToAssertionCount(1);
    }

    /**
     * There being no person at all is refused before anything else is asked.
     *
     * @throws HilosException When the lookup fails
     */
    public function testRequireRefusesAMissingPerson(): void
    {
        $this->expectException(ItemNotFoundForUpdateException::class);

        AddressablePerson::require(self::MISSING_USER_ID);
    }

    /**
     * @return iterable<string, array{bool, bool, string}> Merged, begun, refusal words
     */
    public static function refusedPeople(): iterable
    {
        yield 'merged' => [true, false, 'This account was merged into another one'];
        yield 'erasure begun' => [false, true, 'This account is being deleted.'];
        yield 'merged with the erasure begun' => [true, true, 'This account was merged into another one'];
    }

    /**
     * A merged account is refused in the words of the merge, whether its erasure began or not.
     *
     * @param bool $merged Whether the account was folded into another one
     * @param bool $begun Whether the erasure of its request has begun
     * @param string $words Words of the refusal
     * @throws HilosException When a fixture row cannot be written or read
     */
    #[DataProvider('refusedPeople')]
    public function testRequireRefusesInItsOrder(bool $merged, bool $begun, string $words): void
    {
        $userId = $this->seedPerson();
        if ($merged) {
            self::seedMerge($userId, $this->seedPerson());
        }
        $request = $this->requestDeletion($userId);
        if ($begun) {
            self::assertTrue($this->asPerson($userId, static fn () => $request->actions->start()));
        }

        try {
            AddressablePerson::require($userId);
            self::fail('The person must be refused');
        } catch (ValidationException $refusal) {
            self::assertSame($words, $refusal->getMessage());
        }
    }

    /**
     * Only a begun request that still stands is the begun one: none, a request not yet begun, and
     * a begun request carried out already answer null.
     *
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testStartedOfAnswersOnlyABegunRequestThatStillStands(): void
    {
        $userId = $this->seedPerson();
        self::assertNull(Hilos::$db->accountDeletions->startedOf($userId));

        $request = $this->requestDeletion($userId);
        self::assertNull(Hilos::$db->accountDeletions->startedOf($userId));

        self::assertTrue($this->asPerson($userId, static fn () => $request->actions->start()));
        self::assertSame($request->id, Hilos::$db->accountDeletions->startedOf($userId)?->id);

        // The completion still takes the old path and does not look at the start.
        self::assertTrue($request->actions->complete());
        self::assertNotNull(self::requestRow($request)['completed_at']);
        self::assertNull(Hilos::$db->accountDeletions->startedOf($userId));
    }

    /**
     * The step that wins first marks the request begun and records "started", both seen past every
     * in-memory collection; "Keep my account" carried to the agent afterwards is refused, and the
     * request stays begun.
     *
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testTheStartStepFirstRecordsStartedAndKeepMyAccountIsThenRefused(): void
    {
        $userId = $this->seedPerson();
        $request = $this->requestDeletion($userId);
        $assignmentId = $this->startAssignment($request, $userId);

        $result = $this->runStart($userId, $assignmentId);

        self::assertFalse(Database::hasOpenTransactions());
        self::assertTrue(AccountErasureStartResult::startedOf($result->payload));
        $startedAt = self::requestRow($request)['started_at'];
        self::assertNotNull($startedAt);
        self::assertSame([[$assignmentId, HilosAgentType::HILOS_USER . ':' . $userId, '{"start":"started"}']], self::storedResults());

        $answer = $this->carryCancel($userId);

        self::assertFalse($answer->canceled);
        self::assertSame('This account is being deleted.', $answer->error);
        $row = self::requestRow($request);
        self::assertSame($startedAt, $row['started_at']);
        self::assertNull($row['canceled_at']);
    }

    /**
     * "Keep my account" that lands between the opening and the step makes the step record "lost"
     * and leaves the request canceled and not begun.
     *
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testKeepMyAccountFirstMakesTheStartStepRecordLost(): void
    {
        $userId = $this->seedPerson();
        $request = $this->requestDeletion($userId);
        $assignmentId = $this->startAssignment($request, $userId);
        $answer = $this->carryCancel($userId);
        self::assertTrue($answer->canceled);

        $result = $this->runStart($userId, $assignmentId);

        self::assertFalse(AccountErasureStartResult::startedOf($result->payload));
        $row = self::requestRow($request);
        self::assertNull($row['started_at']);
        self::assertNotNull($row['canceled_at']);
        self::assertSame([[$assignmentId, HilosAgentType::HILOS_USER . ':' . $userId, '{"start":"lost"}']], self::storedResults());
    }

    /**
     * A repeated step answers the saved result and writes nothing more; that a start on a begun
     * request answers false is held at the request's level above.
     *
     * @throws HilosException When a fixture row cannot be written or the step fails
     */
    public function testARepeatedStartStepAnswersTheSavedResultAndWritesNothingMore(): void
    {
        $userId = $this->seedPerson();
        $request = $this->requestDeletion($userId);
        $assignmentId = $this->startAssignment($request, $userId);
        $first = $this->runStart($userId, $assignmentId);
        $startedAt = self::requestRow($request)['started_at'];
        $stored = self::storedResults();

        $repeat = $this->runStart($userId, $assignmentId);

        self::assertSame($first->id, $repeat->id);
        self::assertTrue(AccountErasureStartResult::startedOf($repeat->payload));
        self::assertSame($startedAt, self::requestRow($request)['started_at']);
        self::assertSame($stored, self::storedResults());
    }

    /**
     * A request begun by a fact the step did not write leaves the step nothing to begin: it records
     * "lost" and the moment of that fact stays.
     *
     * @throws HilosException When a fixture row cannot be written or the step fails
     */
    public function testARequestBegunWithoutAResultMakesTheStartStepRecordLost(): void
    {
        $userId = $this->seedPerson();
        $request = $this->requestDeletion($userId);
        $assignmentId = $this->startAssignment($request, $userId);
        $begunAt = TimeHelper::nowMs() - TimeConstants::MS_PER_SECOND;
        Database::sqlRun('UPDATE `hilos_account_deletion` SET `started_at` = ? WHERE `id` = ?', [$begunAt, $request->id]);

        $result = $this->runStart($userId, $assignmentId);

        self::assertFalse(AccountErasureStartResult::startedOf($result->payload));
        self::assertSame($begunAt, self::requestRow($request)['started_at']);
        self::assertSame([[$assignmentId, HilosAgentType::HILOS_USER . ':' . $userId, '{"start":"lost"}']], self::storedResults());
    }

    /**
     * A start handed out inside a merge is refused, and nothing is begun or recorded.
     *
     * @throws HilosException When a fixture row cannot be written
     */
    public function testAStartAssignedInAMergeIsRefused(): void
    {
        $survivorId = $this->seedPerson();
        $loserId = $this->seedPerson();
        $request = $this->requestDeletion($survivorId);
        $assignmentId = $this->asHolder(static function () use ($survivorId, $loserId): int {
            $merge = Hilos::$db->accountOperations->actions->recordMerge(
                $survivorId,
                $loserId,
                new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, true, false),
            );

            return self::assignStart($merge, $survivorId);
        });

        $this->assertStartRefused($survivorId, $assignmentId, 'An account erasure start must belong to an erasure.', $request);
    }

    /**
     * A start handed to an account folded into the root is refused: only the root starts the request.
     *
     * @throws HilosException When a fixture row cannot be written
     */
    public function testAStartAssignedToAFoldedAccountIsRefused(): void
    {
        $rootId = $this->seedPerson();
        $foldedId = $this->seedPerson();
        self::seedMerge($foldedId, $rootId);
        $request = $this->requestDeletion($rootId);
        $assignmentId = $this->startAssignment($request, $foldedId);

        $this->assertStartRefused($foldedId, $assignmentId, 'Only the root account starts its erasure request.', $request);
    }

    /**
     * An erasure naming another person's request is refused, and that request is not begun.
     *
     * @throws HilosException When a fixture row cannot be written
     */
    public function testAnErasureNamingAnotherPersonsRequestIsRefused(): void
    {
        $rootId = $this->seedPerson();
        $otherId = $this->seedPerson();
        $foreign = $this->requestDeletion($otherId);
        $assignmentId = $this->asHolder(static function () use ($foreign, $rootId): int {
            $erasure = Hilos::$db->accountOperations->actions->recordErasure(
                $foreign->id,
                $rootId,
                Hilos::$db->userMerges->erasureCircleOf($rootId),
            );

            return self::assignStart($erasure, $rootId);
        });

        $this->assertStartRefused($rootId, $assignmentId, 'The erasure names no deletion request of its root account.', $foreign);
    }

    /**
     * A folded account's agent begins its standing request as the first write of its part.
     *
     * @throws HilosException When a fixture row cannot be written or the start fails
     */
    public function testAFoldedAccountBeginsItsOwnStandingRequest(): void
    {
        $foldedId = $this->seedPerson();
        $request = $this->requestDeletion($foldedId);
        self::seedMerge($foldedId, $this->seedPerson());

        self::assertTrue($this->startOwn($foldedId));

        self::assertNotNull(self::requestRow($request)['started_at']);
    }

    /**
     * A person with no standing request gets none: the answer is false and no row appears.
     *
     * @throws HilosException When a fixture row cannot be written or the start fails
     */
    public function testAnOwnStartWithoutAStandingRequestWritesNothing(): void
    {
        $userId = $this->seedPerson();

        self::assertFalse($this->startOwn($userId));

        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_account_deletion` WHERE `user_id` = ?', [$userId])->rows());
    }

    /**
     * Outside the part's transaction the own start is refused before anything is read or written.
     *
     * @throws HilosException When a fixture row cannot be written
     */
    public function testAnOwnStartOutsideATransactionIsRefused(): void
    {
        $userId = $this->seedPerson();
        $request = $this->requestDeletion($userId);

        try {
            $this->asPerson($userId, static fn (): bool => AccountErasureStart::startOwnRequest($userId));
            self::fail('The start ran outside a transaction');
        } catch (DatabaseException $refusal) {
            self::assertSame('An account erasure start must run inside its participant transaction', $refusal->getMessage());
        }
        self::assertNull(self::requestRow($request)['started_at']);
    }

    /**
     * After the start the ordinary frames are refused in the erasing words and write nothing, the
     * service frames are served and answered, and the agent stays alive for its last write.
     *
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testAfterTheStartOrdinaryFramesAreRefusedAndServiceFramesServed(): void
    {
        $this->previousApp = Hilos::appClass();
        ErasureStartTestHilos::initBrowser();
        $now = TimeHelper::nowMs();
        $userId = $this->seedPerson();
        $otherId = $this->seedPerson();
        $request = $this->requestDeletion($userId);
        self::assertTrue(AccountErasureStartResult::startedOf($this->runStart($userId, $this->startAssignment($request, $userId))->payload));
        $gone = self::seedPushDevice($userId, 'https://push.example.test/gone');
        $released = self::seedPushDevice($userId, 'https://push.example.test/released');
        Database::sqlRun(
            'INSERT INTO `hilos_legal_acceptance` (`user_id`, `document`, `revision_id`, `accepted_at`) VALUES (?, ?, ?, NOW())',
            [$userId, LegalDocument::TERMS->value, 'second'],
        );
        Database::sql('INSERT INTO `hilos_second_factor_trust_restriction` (`saved_at`, `term_days`) VALUES (?, 0)', [$now]);
        $restrictionId = Database::lastInsertId();
        $before = self::personRows($userId);

        $ordinary = [
            HilosSignalConstants::HILOS_USER_RENAME => new UserRenameSignalData(
                $userId, 'Renamed', null, self::REPLY, self::ACCEPT_KEY, null, 'rename', null, null,
            ),
            HilosSignalConstants::HILOS_USER_THEME_PICK_WRITE => new UserThemePickWriteSignalData(
                $userId, 'dark', self::REPLY, self::ACCEPT_KEY, null, 'theme_pick', null,
            ),
            HilosSignalConstants::HILOS_USER_BLOCK_WRITE => new UserBlockWriteSignalData(
                $userId, true, $otherId, self::REPLY, self::ACCEPT_KEY, null, 'block', null, self::REPLY,
            ),
            HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW => new UserBrowserTrustRenewSignalData(
                $userId, 1, $now + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND, $now, self::REPLY,
            ),
            HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL => new UserAccountDeletionCancelSignalData(
                $userId, null, self::REPLY, self::ACCEPT_KEY, null, HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL, null, null,
            ),
            HilosSignalConstants::HILOS_USER_PUSH_SUBSCRIBE => new UserPushSubscribeSignalData(
                $userId, $released, 'https://push.example.test/released', 'p256dh-renewed', 'auth-renewed', null,
                self::REPLY, self::ACCEPT_KEY, null, 'push_subscribe', null,
            ),
        ];
        foreach ($ordinary as $name => $ask) {
            // A refused rename keeps the sentence its card has always shown around the refusal.
            $words = $name === HilosSignalConstants::HILOS_USER_RENAME
                ? 'Failed to update user: This account is being deleted.'
                : 'This account is being deleted.';
            self::assertSame($words, self::errorOf($this->carry($name, $ask), $name), $name);
        }
        self::assertSame($before, self::personRows($userId));

        $restricted = $this->carry(
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT,
            new UserSecondFactorTrustRestrictSignalData($userId, $restrictionId, self::REPLY),
        );
        self::assertNull(self::errorOf($restricted, 'trust restrict'));
        self::assertNull($this->carry(
            HilosSignalConstants::HILOS_USER_PUSH_ENDPOINT_GONE,
            new UserPushEndpointGoneSignalData($userId, $gone, 1),
        ));
        self::assertNotNull(Database::sql('SELECT `gone_at` FROM `hilos_push_subscription` WHERE `id` = ?', [$gone])->row()['gone_at']);
        $release = $this->carry(HilosSignalConstants::HILOS_USER_PUSH_RELEASE, new UserPushReleaseSignalData(
            $userId, $released, 'https://push.example.test/released', $otherId, 'p256dh', 'auth', null,
            self::REPLY, self::ACCEPT_KEY, null, 'push_subscribe', null,
        ));
        self::assertNull(self::errorOf($release, 'push release'));
        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_push_subscription` WHERE `id` = ?', [$released])->rows());
        $forget = $this->carry(
            HilosSignalConstants::HILOS_USER_LEGAL_HOLD_FORGET,
            new UserLegalHoldForgetSignalData($userId, LegalDocument::TERMS->value, 'first', self::REPLY, 'erasure-start-hold'),
        );
        self::assertNull(self::errorOf($forget, 'legal hold forget'));
        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_legal_acceptance` WHERE `user_id` = ?', [$userId])->rows());
        self::assertFalse($this->personAgent($userId)->shouldStop());
    }

    /**
     * Opens a request the way the users library does, a day ahead.
     *
     * @param int $userId Person whose account is to be deleted
     * @return AccountDeletion The request
     * @throws HilosException When the request cannot be written or read back
     */
    private function requestDeletion(int $userId): AccountDeletion
    {
        $at = TimeHelper::nowMs() + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
        $this->inLibrary(static fn () => Hilos::$db->accountDeletions->actions->request($userId, $at));
        $request = Hilos::$db->accountDeletions->liveOf($userId);
        self::assertNotNull($request);

        return $request;
    }

    /**
     * Sends "Keep my account" the way the users library does, hands it to the person's agent and takes its answer.
     *
     * @param int $userId Person whose request would be canceled
     * @return UserAccountDeletionCancelDoneSignalData The agent's answer
     * @throws HilosException When the frame cannot be queued or the agent fails
     */
    private function carryCancel(int $userId): UserAccountDeletionCancelDoneSignalData
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $this->users->sendToAgent(HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL, new UserAccountDeletionCancelSignalData(
            userId: $userId,
            by: null,
            replySignal: HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL_DONE,
            acceptKey: self::ACCEPT_KEY,
            requestId: 'req-erasure-start',
            action: HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL,
            successMessage: null,
            answerSignal: null,
        ));
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        $this->deliverToPerson($signal);

        $answer = null;
        while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($answer === null && $queued->signalName->getName() === HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL_DONE) {
                self::assertInstanceOf(AgentSignalData::class, $queued->data);
                $answer = $queued->data->data;
            }
        }
        self::assertInstanceOf(UserAccountDeletionCancelDoneSignalData::class, $answer);

        return $answer;
    }

    /**
     * Runs one step in the library's own frame, the way its worker would.
     *
     * @template T
     * @param callable(): T $step Step to run as the library
     * @return T Whatever the step returns
     */
    private function inLibrary(callable $step): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->users->getId()), $step);
    }

    /**
     * @return int Id of a new person row
     * @throws DatabaseException When the insert fails
     */
    private function seedPerson(): int
    {
        Database::sql("INSERT INTO `hilos_user` (`name`, `admin`) VALUES ('Person', 0)");

        return Database::lastInsertId();
    }

    /**
     * Reads a request straight from the database, past every in-memory collection.
     *
     * @param AccountDeletion $request The request
     * @return array{started_at: ?int, canceled_at: ?int, completed_at: ?int} Its three state moments
     * @throws DatabaseException When the query fails
     */
    private static function requestRow(AccountDeletion $request): array
    {
        Database::sql(
            'SELECT `started_at`, `canceled_at`, `completed_at` FROM `hilos_account_deletion` WHERE `id` = ?',
            [$request->id],
        );
        $row = Database::row();
        self::assertNotNull($row);

        return [
            'started_at' => $row['started_at'] === null ? null : (int)$row['started_at'],
            'canceled_at' => $row['canceled_at'] === null ? null : (int)$row['canceled_at'],
            'completed_at' => $row['completed_at'] === null ? null : (int)$row['completed_at'],
        ];
    }

    /**
     * Opens the erasure of a request the way the session holder does, then moves it to the start's
     * stage and gives the start to the agent of one account.
     *
     * @param AccountDeletion $request Live request the erasure answers
     * @param int $userId Account whose agent is given the start
     * @return int Id of the start's assignment
     * @throws HilosException When the opening or a record refuses
     */
    private function startAssignment(AccountDeletion $request, int $userId): int
    {
        return $this->asHolder(static function () use ($request, $userId): int {
            $erasure = AccountErasureOpening::open($request, AccountErasureParticipantCatalog::framework())->operation;
            self::assertNotNull($erasure);

            return self::assignStart($erasure, $userId);
        });
    }

    /**
     * Moves an open operation to the start's stage and records the start for one account's agent;
     * runs in the session holder's frame.
     *
     * @param AccountOperation $operation Open operation
     * @param int $userId Account whose agent is given the start
     * @return int Id of the start's assignment
     * @throws HilosException When the stage or the record refuses
     */
    private static function assignStart(AccountOperation $operation, int $userId): int
    {
        self::assertTrue($operation->actions->advance($operation->stage, self::START_STAGE));

        return Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            self::START_STAGE,
            self::START_KEY . $userId,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId),
            $userId,
            AccountOperationAssignmentPayload::fromJson('{}'),
        )->id;
    }

    /**
     * Runs one step in the session holder's own frame, the way its worker would.
     *
     * @template T
     * @param callable(): T $step Step to run as the holder
     * @return T Whatever the step returns
     */
    private function asHolder(callable $step): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: self::HOLDER_ID), $step);
    }

    /**
     * @param int $userId Account whose agent carries the step
     * @param int $assignmentId Start's assignment
     * @return AccountOperationResult The recorded or saved result
     * @throws HilosException When the step refuses or fails
     */
    private function runStart(int $userId, int $assignmentId): AccountOperationResult
    {
        return $this->asPerson($userId, static fn (): AccountOperationResult => AccountErasureStart::run($assignmentId));
    }

    /**
     * @param int $userId Account whose agent carries the step
     * @param int $assignmentId Start's assignment
     * @param string $words Words of the refusal
     * @param AccountDeletion $request Request that must stay not begun
     * @throws HilosException When a row cannot be read
     */
    private function assertStartRefused(int $userId, int $assignmentId, string $words, AccountDeletion $request): void
    {
        try {
            $this->runStart($userId, $assignmentId);
            self::fail('The start was not refused');
        } catch (ValidationException $refusal) {
            self::assertSame($words, $refusal->getMessage());
        }
        self::assertFalse(Database::hasOpenTransactions());
        self::assertNull(self::requestRow($request)['started_at']);
        self::assertSame([], self::storedResults());
    }

    /**
     * Runs the own start in the person's agent's frame, inside a transaction of its part.
     *
     * @param int $userId Person whose agent runs its part
     * @return bool Whether this call began the standing request
     * @throws HilosException When the start fails
     */
    private function startOwn(int $userId): bool
    {
        return $this->asPerson($userId, static fn (): bool => Database::transaction(
            DatabaseNames::PRIMARY,
            static fn (): bool => AccountErasureStart::startOwnRequest($userId),
        ));
    }

    /**
     * Sends one frame to the person's agent the way a library does, hands it over and takes its answer.
     *
     * @param string $name Frame name
     * @param SignalDataInterface $ask Frame payload, answered under {@see self::REPLY} if at all
     * @return ?SignalDataInterface The one answer, or null when the frame is not answered
     * @throws HilosException When the frame cannot be queued or the agent fails
     */
    private function carry(string $name, SignalDataInterface $ask): ?SignalDataInterface
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $this->users->sendToAgent($name, $ask);
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        $this->deliverToPerson($signal);

        $answers = [];
        while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($queued->signalName->getName() === self::REPLY) {
                self::assertInstanceOf(AgentSignalData::class, $queued->data);
                $answers[] = $queued->data->data;
            }
        }
        self::assertLessThanOrEqual(1, count($answers), "{$name} is answered once at most");

        return $answers[0] ?? null;
    }

    /**
     * @param ?SignalDataInterface $answer Answer of the person's agent
     * @param string $what Frame the answer is to, for the failure message
     * @return ?string Words of its refusal, or null when it went through
     */
    private static function errorOf(?SignalDataInterface $answer, string $what): ?string
    {
        self::assertNotNull($answer, "{$what} was not answered");
        self::assertTrue(property_exists($answer, 'error'), "{$what} answers without a refusal field");

        return $answer->error;
    }

    /**
     * @param int $userId Account folded into another one
     * @param int $survivorId Account it was folded into
     * @throws DatabaseException When the insert fails
     */
    private static function seedMerge(int $userId, int $survivorId): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, ?)',
            [$userId, $survivorId, TimeHelper::nowMs()],
        );
    }

    /**
     * @param int $userId Person the device belongs to
     * @param string $endpoint Device's push endpoint
     * @return int Id of the device row, at its first revision
     * @throws DatabaseException When the insert fails
     */
    private static function seedPushDevice(int $userId, string $endpoint): int
    {
        Database::sql(
            'INSERT INTO `hilos_push_subscription` (`user_id`, `endpoint`, `p256dh`, `auth`, `endpoint_hash`, `created_at`, `revision`)'
                . ' VALUES (?, ?, ?, ?, ?, ?, 1)',
            [$userId, $endpoint, 'p256dh', 'auth', hash('sha256', $endpoint), TimeHelper::nowMs()],
        );

        return Database::lastInsertId();
    }

    /**
     * Reads every row an ordinary frame to the person's agent could write, past every in-memory collection.
     *
     * @param int $userId Person
     * @return array<string, list<array<string, mixed>>> Rows by table
     * @throws DatabaseException When a query fails
     */
    private static function personRows(int $userId): array
    {
        $rows = [];
        foreach ([
            'hilos_user' => 'id',
            'hilos_user_rename' => 'user_id',
            'hilos_second_factor_trust' => 'user_id',
            'hilos_account_deletion' => 'user_id',
            'hilos_legal_acceptance' => 'user_id',
            'hilos_push_subscription' => 'user_id',
        ] as $table => $column) {
            $rows[$table] = Database::sql("SELECT * FROM `{$table}` WHERE `{$column}` = ? ORDER BY `id`", [$userId])->rows();
        }

        return $rows;
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

/** The framework's users library under a test name, with nothing overridden. */
final class ErasureStartTestUsersLibrary extends AbstractUsersLibraryAgent
{
    public const string AGENT_TYPE = 'integration_erasure_start_users_library';
}

/** The framework's session holder, writing the account operations of a case. */
final class ErasureStartTestSessionsHolder extends AbstractSessionsLibraryAgent
{
}

/** The framework under a test name, with a terms document of two revisions for the legal hold. */
abstract class ErasureStartTestHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = ErasureStartTestCatalog::class;
}

/** Two terms revisions, so a hold on the first forgets an acceptance of the second. */
final class ErasureStartTestCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture declarations */
    public static function revisions(): array
    {
        return [
            LegalDocument::TERMS->value => [
                new LegalRevision(LegalDocument::TERMS, 'first', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
                new LegalRevision(LegalDocument::TERMS, 'second', '2026-02-01', 1, LegalSignificance::SUBSTANTIAL, '2026-03-01', []),
            ],
            LegalDocument::PRIVACY->value => [
                new LegalRevision(LegalDocument::PRIVACY, 'privacy', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
            ],
        ];
    }
}
