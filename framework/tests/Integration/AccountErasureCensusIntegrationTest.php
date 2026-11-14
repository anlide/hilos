<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\AccountOperation\AccountErasureCensus;
use Hilos\Auth\AccountOperation\AccountErasureOpening;
use Hilos\Auth\AccountOperation\AccountErasureFixedOwner;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationCircle;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationExpectedSet;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\AccountOperation as EntityAccountOperation;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\PhpType;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Verification\VerificationType;
use Hilos\Database\View\Item\AccountDeletion;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\DataExport\DataExportState;
use Hilos\Hilos;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Notification\Delivery\NotificationDeliveryIdentity;
use Hilos\Users\SecondFactorFate;
use Throwable;

/** The erasure coordinator's census readers and the census itself against real tables, written as the session holder. */
final class AccountErasureCensusIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = [
        'hilos_user',
        'hilos_account_deletion',
        'hilos_identity',
        'hilos_user_merge',
        'hilos_user_verification',
        'hilos_notification',
        'hilos_notification_delivery',
        'hilos_account_operation',
        'hilos_account_operation_assignment',
    ];
    /** Raised by a case when it needs a participant activated, and dropped around every case. */
    private const array CASE_TABLES = [
        'hilos_data_export',
        'hilos_access_log',
        'hilos_second_factor_trust_restriction',
    ];
    private const string COORDINATOR_ID = 'erasure-census-coordinator';
    private const string FOREIGN_ID = 'erasure-census-foreign';
    private const int DEEPEST_ID = 41;
    private const int FOLDED_ID = 42;
    private const int ROOT_ID = 43;
    private const int OUTSIDER_ID = 44;

    private ?HilosDbContext $previousDb;
    private string $previousApp;
    private int $frameToken;

    /** Raises the census tables, mounts a fresh context and acts as the session holder. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousApp = Hilos::appClass();
        self::dropCaseTables();
        $this->stubs(down: true);
        $this->stubs(down: false);
        Schema::reset();
        Schema::initialize();
        $this->mountFreshContext();
        OwnershipDeclaration::claimDb(AccountErasureCensusCoordinator::class, self::COORDINATOR_ID);
        $this->frameToken = ExecutionContext::push(new ExecutionFrame(agentId: self::COORDINATOR_ID));
        foreach ([self::DEEPEST_ID, self::FOLDED_ID, self::ROOT_ID, self::OUTSIDER_ID] as $userId) {
            Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Census person')", [$userId]);
        }
    }

    /** Ends transactions before DDL, releases the claim and returns the previous context and application. */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            self::dropCaseTables();
            $this->stubs(down: true);
            Schema::reset();
        } finally {
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();
            ExecutionContext::pop($this->frameToken);
            TruthSourceRegistry::unregisterAgent(self::COORDINATOR_ID);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::COORDINATOR_ID));
            Hilos::$db = $this->previousDb;
            parent::tearDown();
        }
    }

    /** A chain is walked down to its deepest account, which comes first; an unmerged account is alone. */
    public function testErasureCircleListsFoldedAccountsDeepestFirstAndTheRootLast(): void
    {
        self::seedMerge(self::DEEPEST_ID, self::FOLDED_ID);
        self::seedMerge(self::FOLDED_ID, self::ROOT_ID);

        $circle = Hilos::$db->userMerges->erasureCircleOf(self::ROOT_ID);

        self::assertSame([self::DEEPEST_ID, self::FOLDED_ID, self::ROOT_ID], $circle->toArray());
        self::assertSame(self::ROOT_ID, $circle->rootUserId);
        self::assertSame([self::DEEPEST_ID, self::FOLDED_ID], Hilos::$db->userMerges->erasureCircleOf(self::FOLDED_ID)->toArray());
        self::assertSame([self::OUTSIDER_ID], Hilos::$db->userMerges->erasureCircleOf(self::OUTSIDER_ID)->toArray());
    }

    /** Rows written outside the merge rules may close a loop; the walk still ends with each account once. */
    public function testErasureCircleWalkEndsOnALoopOfMergeRows(): void
    {
        self::seedMerge(self::FOLDED_ID, self::ROOT_ID);
        self::seedMerge(self::ROOT_ID, self::FOLDED_ID);

        self::assertSame([self::FOLDED_ID, self::ROOT_ID], Hilos::$db->userMerges->erasureCircleOf(self::ROOT_ID)->toArray());
    }

    /** Only an open erasure counts: a merge over the same accounts does not, and a finished erasure stops counting. */
    public function testOpenErasureCoveringFindsTheOpenErasureWhoseCircleHoldsTheAccount(): void
    {
        $other = $this->recordErasure(501, [self::OUTSIDER_ID]);
        Hilos::$db->accountOperations->actions->recordMerge(
            self::DEEPEST_ID,
            self::FOLDED_ID,
            new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, true, false),
        );
        self::assertNull(Hilos::$db->accountOperations->openErasureCovering(self::DEEPEST_ID));

        $erasure = $this->recordErasure(502, [self::DEEPEST_ID, self::FOLDED_ID, self::ROOT_ID]);

        foreach ([self::DEEPEST_ID, self::FOLDED_ID, self::ROOT_ID] as $userId) {
            self::assertSame($erasure->id, Hilos::$db->accountOperations->openErasureCovering($userId)?->id);
        }
        self::assertSame($other->id, Hilos::$db->accountOperations->openErasureCovering(self::OUTSIDER_ID)?->id);
        self::assertNull(Hilos::$db->accountOperations->openErasureCovering(45));

        self::assertTrue($erasure->actions->finish($erasure->stage, AccountOperationOutcome::Completed));

        self::assertNull(Hilos::$db->accountOperations->openErasureCovering(self::FOLDED_ID));
        self::assertSame($other->id, Hilos::$db->accountOperations->openErasureCovering(self::OUTSIDER_ID)?->id);
    }

    /** Every stage of one operation comes back in primary id order, and nothing of another operation. */
    public function testForOperationReadsTheAssignmentsOfEveryStage(): void
    {
        $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)self::ROOT_ID);
        $erasure = $this->recordErasure(601, [self::FOLDED_ID, self::ROOT_ID]);
        $other = $this->recordErasure(602, [self::OUTSIDER_ID]);
        $first = Hilos::$db->accountOperationAssignments->actions->record(
            $erasure->id, EntityAccountOperation::INITIAL_STAGE, 'one', $owner, self::ROOT_ID, AccountOperationAssignmentPayload::fromJson('{}'),
        );
        Hilos::$db->accountOperationAssignments->actions->record(
            $other->id, EntityAccountOperation::INITIAL_STAGE, 'one', $owner, self::OUTSIDER_ID, AccountOperationAssignmentPayload::fromJson('{}'),
        );
        self::assertTrue($erasure->actions->advance(EntityAccountOperation::INITIAL_STAGE, 'census-test'));
        $second = Hilos::$db->accountOperationAssignments->actions->record(
            $erasure->id, 'census-test', 'two', $owner, self::FOLDED_ID, AccountOperationAssignmentPayload::fromJson('{}'),
        );

        self::assertSame(
            [$first->id, $second->id],
            array_keys(iterator_to_array(Hilos::$db->accountOperationAssignments->forOperation($erasure->id))),
        );
        self::assertCount(0, Hilos::$db->accountOperationAssignments->forOperation(603));
    }

    /** A recipient's deliveries of every status come back by delivery id, paged by the cursor and the limit. */
    public function testRecipientIdentitiesAfterReadsOnlyTheRecipientsDeliveries(): void
    {
        $firstNotification = self::seedNotification(self::ROOT_ID);
        $neighbourNotification = self::seedNotification(self::OUTSIDER_ID);
        $secondNotification = self::seedNotification(self::ROOT_ID);
        $first = self::seedDelivery($firstNotification, 'email', null, DeliveryStatus::PENDING);
        self::seedDelivery($neighbourNotification, 'email', null, DeliveryStatus::PENDING);
        $second = self::seedDelivery($firstNotification, 'push', 7, DeliveryStatus::SENT);
        $third = self::seedDelivery($secondNotification, 'email', null, DeliveryStatus::FAILED);

        $identities = Hilos::$db->notificationDeliveries->recipientIdentitiesAfter(self::ROOT_ID, 0, 10)->toArray();

        self::assertSame([$first, $second, $third], self::deliveryIds($identities));
        self::assertSame($firstNotification, $identities[1]->notificationId);
        self::assertSame('push', $identities[1]->channel);
        self::assertSame(7, $identities[1]->routingKey);
        self::assertNull($identities[0]->routingKey);
        self::assertSame([$second, $third], self::deliveryIds(Hilos::$db->notificationDeliveries->recipientIdentitiesAfter(
            self::ROOT_ID, $first, 10,
        )->toArray()));
        self::assertSame([$first, $second], self::deliveryIds(Hilos::$db->notificationDeliveries->recipientIdentitiesAfter(
            self::ROOT_ID, 0, 2,
        )->toArray()));
        self::assertCount(0, Hilos::$db->notificationDeliveries->recipientIdentitiesAfter(self::DEEPEST_ID, 0, 10));

        $this->expectException(InvalidArgumentException::class);
        Hilos::$db->notificationDeliveries->recipientIdentitiesAfter(self::ROOT_ID, 0, 0);
    }

    /** A challenge counts by the person's id or by any of the person's addresses, whoever it was issued to. */
    public function testAnyForUserOrIdentifiersAsksByUserIdAndByAddress(): void
    {
        self::seedVerification(self::ROOT_ID, 'root@example.test');
        self::seedVerification(self::OUTSIDER_ID, 'shared@example.test');
        self::seedVerification(null, 'anonymous@example.test');

        self::assertTrue(Hilos::$db->verifications->anyForUserOrIdentifiers(self::ROOT_ID, []));
        self::assertTrue(Hilos::$db->verifications->anyForUserOrIdentifiers(self::FOLDED_ID, ['shared@example.test']));
        self::assertTrue(Hilos::$db->verifications->anyForUserOrIdentifiers(
            self::FOLDED_ID,
            ['nobody@example.test', 'anonymous@example.test', 'anonymous@example.test'],
        ));
        self::assertFalse(Hilos::$db->verifications->anyForUserOrIdentifiers(self::FOLDED_ID, ['nobody@example.test']));
        self::assertFalse(Hilos::$db->verifications->anyForUserOrIdentifiers(self::FOLDED_ID, []));
    }

    /** A registered owner is expected without rows once a table of its participant is in the schema, and not before. */
    public function testCensusAsksOnlyParticipantsWithAnActivatedTable(): void
    {
        AccountErasureCensusHilos::initBrowser();
        $circle = Hilos::$db->userMerges->erasureCircleOf(self::ROOT_ID);

        self::assertSame(
            ['expect:personal:hilos_user:43:43', 'expect:users_library:hilos_users_library:43'],
            self::assignmentKeys(AccountErasureCensus::of($circle, AccountErasureParticipantCatalog::framework())),
        );

        self::raise('hilos_data_export', 'hilos_access_log');

        self::assertSame(
            [
                'expect:personal:hilos_user:43:43',
                'expect:users_library:hilos_users_library:43',
                'expect:sessions_holder:hilos_sessions_library:43',
                'expect:data_export:hilos_data_export:43',
            ],
            self::assignmentKeys(AccountErasureCensus::of($circle, AccountErasureParticipantCatalog::framework())),
        );
    }

    /** With no owner registered, an account that still has rows is waited for, and one without rows expects nothing. */
    public function testOwnerlessParticipantWaitsOnlyForAnAccountWithRows(): void
    {
        AccountErasureCensusOwnerlessHilos::initBrowser();
        self::raise('hilos_data_export');
        self::seedDataExport(self::ROOT_ID);
        self::seedIdentity(self::ROOT_ID, 'root@example.test');
        self::seedVerification(null, 'root@example.test');
        $catalog = AccountErasureParticipantCatalog::framework();

        $root = AccountErasureCensus::of(Hilos::$db->userMerges->erasureCircleOf(self::ROOT_ID), $catalog);

        self::assertSame(['wait:personal:43', 'wait:users_library:43', 'wait:data_export:43'], self::assignmentKeys($root));
        self::assertTrue($root[2]->isWait());
        self::assertSame(self::ROOT_ID, $root[2]->userId);
        self::assertSame(
            ['wait:personal:44'],
            self::assignmentKeys(AccountErasureCensus::of(Hilos::$db->userMerges->erasureCircleOf(self::OUTSIDER_ID), $catalog)),
        );
    }

    /** A project participant joins after the framework's; an owner registered without the index it needs is no owner. */
    public function testProjectParticipantJoinsTheCensusThroughWith(): void
    {
        self::raiseNoteTable();
        $catalog = AccountErasureParticipantCatalog::framework()->with(new AccountErasureFixedOwner(
            'census_notes',
            [AccountErasureCensusNotes::COLLECTION_KEY],
            AccountErasureCensusOwnerAgent::NOTE_KEEPER,
            true,
            static fn (int $userId): bool => $userId === self::ROOT_ID,
        ));
        $root = Hilos::$db->userMerges->erasureCircleOf(self::ROOT_ID);
        $outsider = Hilos::$db->userMerges->erasureCircleOf(self::OUTSIDER_ID);

        AccountErasureCensusHilos::initBrowser();
        self::assertSame(
            [
                'expect:personal:hilos_user:43:43',
                'expect:users_library:hilos_users_library:43',
                'expect:census_notes:census_note_keeper:43:43',
            ],
            self::assignmentKeys(AccountErasureCensus::of($root, $catalog)),
        );
        self::assertSame(
            [
                'expect:personal:hilos_user:44:44',
                'expect:users_library:hilos_users_library:44',
                'expect:census_notes:census_note_keeper:44:44',
            ],
            self::assignmentKeys(AccountErasureCensus::of($outsider, $catalog)),
        );

        AccountErasureCensusOwnerlessHilos::initBrowser();
        self::assertSame(['wait:personal:43', 'wait:census_notes:43'], self::assignmentKeys(AccountErasureCensus::of($root, $catalog)));
        self::assertSame(['wait:personal:44'], self::assignmentKeys(AccountErasureCensus::of($outsider, $catalog)));

        AccountErasureCensusUnindexedKeeperHilos::initBrowser();
        self::assertSame(['wait:personal:43', 'wait:census_notes:43'], self::assignmentKeys(AccountErasureCensus::of($root, $catalog)));
    }

    /** A trust restriction is the installation's rule, so its rows bring no participant into the census. */
    public function testTrustRestrictionRowsAddNoParticipant(): void
    {
        AccountErasureCensusOwnerlessHilos::initBrowser();
        self::raise('hilos_second_factor_trust_restriction');
        Database::sqlRun('INSERT INTO `hilos_second_factor_trust_restriction` (`saved_at`, `term_days`) VALUES (1790416800000, 30)');

        self::assertSame(['wait:personal:43'], self::assignmentKeys(AccountErasureCensus::of(
            Hilos::$db->userMerges->erasureCircleOf(self::ROOT_ID),
            AccountErasureParticipantCatalog::framework(),
        )));
    }

    /** Each channel owner of the account's deliveries is expected once, and a channel with no owner is one wait. */
    public function testDeliveriesExpectEachShardOwnerOnceAndWaitForAnUnknownChannel(): void
    {
        AccountErasureCensusHilos::initBrowser();
        $notification = self::seedNotification(self::ROOT_ID);
        self::seedDelivery($notification, 'census_letter', 5, DeliveryStatus::SENT);
        self::seedDelivery($notification, 'census_pigeon', null, DeliveryStatus::PENDING);
        self::seedDelivery($notification, 'census_letter', 9, DeliveryStatus::FAILED);
        self::seedDelivery($notification, 'census_text', 7, DeliveryStatus::PENDING);
        self::seedDelivery($notification, 'census_letter', 6, DeliveryStatus::PENDING);
        self::seedDelivery($notification, 'census_pigeon', 3, DeliveryStatus::PENDING);
        self::seedDelivery(self::seedNotification(self::OUTSIDER_ID), 'census_text', 1, DeliveryStatus::PENDING);

        $expectations = AccountErasureCensus::of(
            Hilos::$db->userMerges->erasureCircleOf(self::ROOT_ID),
            AccountErasureParticipantCatalog::framework(),
        );

        self::assertSame(
            [
                'expect:personal:hilos_user:43:43',
                'expect:users_library:hilos_users_library:43',
                'expect:delivery:census_letter:census_letter_sender:2:43',
                'wait:delivery:census_pigeon:43',
                'expect:delivery:census_text:census_text_sender:4:43',
                'expect:delivery:census_letter:census_letter_sender:3:43',
            ],
            self::assignmentKeys($expectations),
        );
        self::assertSame('{"participant":"delivery:census_letter"}', $expectations[2]->payload()->toJson());
        self::assertSame('{"participant":"delivery:census_pigeon"}', $expectations[3]->payload()->toJson());
    }

    /** A chain A to B to C is erased as the circle [A, B, C], each account asked of every participant in turn. */
    public function testChainCensusAsksEveryAccountOfTheCircleInOrder(): void
    {
        AccountErasureCensusHilos::initBrowser();
        self::seedMerge(self::DEEPEST_ID, self::FOLDED_ID);
        self::seedMerge(self::FOLDED_ID, self::ROOT_ID);
        self::seedDelivery(self::seedNotification(self::FOLDED_ID), 'census_text', 2, DeliveryStatus::SENT);

        $circle = Hilos::$db->userMerges->erasureCircleOf(self::ROOT_ID);
        $expectations = AccountErasureCensus::of($circle, AccountErasureParticipantCatalog::framework());

        self::assertSame([self::DEEPEST_ID, self::FOLDED_ID, self::ROOT_ID], $circle->toArray());
        self::assertSame(
            [
                'expect:personal:hilos_user:41:41',
                'expect:users_library:hilos_users_library:41',
                'expect:personal:hilos_user:42:42',
                'expect:users_library:hilos_users_library:42',
                'expect:delivery:census_text:census_text_sender:3:42',
                'expect:personal:hilos_user:43:43',
                'expect:users_library:hilos_users_library:43',
            ],
            self::assignmentKeys($expectations),
        );
        self::assertSame(
            [41, 41, 42, 42, 42, 43, 43],
            array_map(static fn (AccountOperationExpectation $expectation): int => $expectation->userId, $expectations),
        );
    }

    /** A wait written at the opening is lifted once its owner is registered and reconciled; a second pass writes nothing. */
    public function testReconcileRecordsARegisteredOwnerAndTheExpectedSetLiftsItsWait(): void
    {
        AccountErasureCensusOwnerlessHilos::initBrowser();
        $opened = AccountErasureOpening::open(self::requestDeletion(self::ROOT_ID), AccountErasureParticipantCatalog::framework());
        $operation = $opened->operation;

        self::assertNotNull($operation);
        self::assertNull($opened->deferredByOperationId);
        self::assertSame(['wait:personal:43'], self::keysOf($opened->recorded));
        self::assertSame(['wait:personal:43'], self::keysOf(AccountOperationExpectedSet::of($operation->id)->waiting()));
        self::assertSame([], AccountOperationExpectedSet::of($operation->id)->expected());

        AccountErasureCensusHilos::initBrowser();
        $reconciled = AccountErasureOpening::reconcile($operation, AccountErasureParticipantCatalog::framework());

        self::assertSame(['expect:personal:hilos_user:43:43', 'expect:users_library:hilos_users_library:43'], self::keysOf($reconciled));
        $set = AccountOperationExpectedSet::of($operation->id);
        self::assertSame([], $set->waiting());
        self::assertSame(self::idsOf($reconciled), self::idsOf($set->expected()));
        $stored = self::storedRows();
        self::assertSame([], AccountErasureOpening::reconcile($operation, AccountErasureParticipantCatalog::framework()));
        self::assertSame($stored, self::storedRows());
    }

    /** The opening fixes a chain A to B to C as the circle [A, B, C] and writes the composition of every account. */
    public function testOpenRecordsTheCompositionOfEveryAccountOfAChain(): void
    {
        AccountErasureCensusHilos::initBrowser();
        self::seedMerge(self::DEEPEST_ID, self::FOLDED_ID);
        self::seedMerge(self::FOLDED_ID, self::ROOT_ID);
        $request = self::requestDeletion(self::ROOT_ID);

        $opened = AccountErasureOpening::open($request, AccountErasureParticipantCatalog::framework());

        self::assertSame($request->id, $opened->operation?->requestId);
        self::assertSame(self::ROOT_ID, $opened->operation->rootUserId);
        self::assertSame([self::DEEPEST_ID, self::FOLDED_ID, self::ROOT_ID], $opened->operation->circle->toArray());
        self::assertSame(
            [
                'expect:personal:hilos_user:41:41',
                'expect:users_library:hilos_users_library:41',
                'expect:personal:hilos_user:42:42',
                'expect:users_library:hilos_users_library:42',
                'expect:personal:hilos_user:43:43',
                'expect:users_library:hilos_users_library:43',
            ],
            self::keysOf($opened->recorded),
        );
        foreach ($opened->recorded as $assignment) {
            self::assertSame(EntityAccountOperation::INITIAL_STAGE, $assignment->stage);
        }
        self::assertSame(
            [41, 41, 42, 42, 43, 43],
            array_map(static fn (AccountOperationAssignment $assignment): ?int => $assignment->userId, $opened->recorded),
        );
        self::assertSame(self::idsOf($opened->recorded), self::idsOf(AccountOperationExpectedSet::of($opened->operation->id)->expected()));
    }

    /** Opening the same request again finds the same operation and its assignments, and stores nothing new. */
    public function testRepeatedOpenKeepsTheOperationAndItsAssignments(): void
    {
        AccountErasureCensusHilos::initBrowser();
        $request = self::requestDeletion(self::ROOT_ID);
        $first = AccountErasureOpening::open($request, AccountErasureParticipantCatalog::framework());
        $stored = self::storedRows();

        $repeat = AccountErasureOpening::open($request, AccountErasureParticipantCatalog::framework());

        self::assertSame($first->operation?->id, $repeat->operation?->id);
        self::assertNull($repeat->deferredByOperationId);
        self::assertSame([], $repeat->recorded);
        self::assertSame(self::idsOf($first->recorded), self::idsOf(AccountOperationExpectedSet::of($first->operation->id)->expected()));
        self::assertSame($stored, self::storedRows());
    }

    /** An account of the circle in another open erasure defers the opening without a write, until that erasure is closed. */
    public function testOpenIsDeferredBehindAnOpenErasureOfAnAccountOfTheCircle(): void
    {
        AccountErasureCensusHilos::initBrowser();
        $covering = $this->recordErasure(701, [self::FOLDED_ID]);
        self::seedMerge(self::FOLDED_ID, self::ROOT_ID);
        $request = self::requestDeletion(self::ROOT_ID);
        $stored = self::storedRows();

        $deferred = AccountErasureOpening::open($request, AccountErasureParticipantCatalog::framework());

        self::assertNull($deferred->operation);
        self::assertSame($covering->id, $deferred->deferredByOperationId);
        self::assertSame([], $deferred->recorded);
        self::assertNull(Hilos::$db->accountOperations->byRequest($request->id));
        self::assertSame($stored, self::storedRows());

        self::assertTrue($covering->actions->finish($covering->stage, AccountOperationOutcome::NotStarted));
        $opened = AccountErasureOpening::open($request, AccountErasureParticipantCatalog::framework());

        self::assertNull($opened->deferredByOperationId);
        self::assertSame([self::FOLDED_ID, self::ROOT_ID], $opened->operation?->circle->toArray());
        self::assertCount(4, $opened->recorded);
    }

    /** A failure on an assignment rolls the operation back together with the assignments written before it. */
    public function testFailureOnAnAssignmentRollsTheOperationBack(): void
    {
        AccountErasureCensusHilos::initBrowser();
        $request = self::requestDeletion(self::ROOT_ID);
        Database::sqlRun('ALTER TABLE `hilos_account_operation_assignment` ADD UNIQUE KEY `test_one_assignment` (`operation_id`)');
        try {
            self::assertInstanceOf(DuplicateEntryException::class, self::refusal(
                static fn () => AccountErasureOpening::open($request, AccountErasureParticipantCatalog::framework()),
            ));
            self::assertFalse(Database::hasOpenTransactions());
            self::assertNull(Hilos::$db->accountOperations->byRequest($request->id));
            self::assertSame([[], []], self::storedRows());
        } finally {
            Database::sqlRun('ALTER TABLE `hilos_account_operation_assignment` DROP KEY `test_one_assignment`');
        }

        self::assertCount(2, AccountErasureOpening::open($request, AccountErasureParticipantCatalog::framework())->recorded);
    }

    /** After the operation moves on, an earlier stage's key is not written again, new ones go to the current stage, the set reads every stage. */
    public function testReconcileAfterAdvanceRecordsAtTheCurrentStageAndTheSetReadsAcrossStages(): void
    {
        AccountErasureCensusOwnerlessHilos::initBrowser();
        $operation = AccountErasureOpening::open(self::requestDeletion(self::ROOT_ID), AccountErasureParticipantCatalog::framework())->operation;
        self::assertNotNull($operation);
        self::assertTrue($operation->actions->advance(EntityAccountOperation::INITIAL_STAGE, 'census-test'));
        self::assertSame('census-test', $operation->stage);
        self::assertSame([], AccountErasureOpening::reconcile($operation, AccountErasureParticipantCatalog::framework()));

        AccountErasureCensusHilos::initBrowser();
        $reconciled = AccountErasureOpening::reconcile($operation, AccountErasureParticipantCatalog::framework());

        self::assertSame(['expect:personal:hilos_user:43:43', 'expect:users_library:hilos_users_library:43'], self::keysOf($reconciled));
        self::assertSame(
            ['census-test', 'census-test'],
            array_map(static fn (AccountOperationAssignment $assignment): string => $assignment->stage, $reconciled),
        );
        $set = AccountOperationExpectedSet::of($operation->id);
        self::assertSame([], $set->waiting());
        self::assertSame(self::idsOf($reconciled), self::idsOf($set->expected()));
        self::assertSame(
            EntityAccountOperation::INITIAL_STAGE,
            Hilos::$db->accountOperationAssignments->forOperation($operation->id)->first()?->stage,
        );
    }

    /** A canceled request opens nothing, and only an open erasure is reconciled. */
    public function testRequestNoLongerLiveAndAClosedOperationAreRefused(): void
    {
        AccountErasureCensusHilos::initBrowser();
        $request = self::requestDeletion(self::ROOT_ID);
        self::assertTrue($request->actions->cancel());

        $refusal = self::refusal(static fn () => AccountErasureOpening::open($request, AccountErasureParticipantCatalog::framework()));

        self::assertInstanceOf(ValidationException::class, $refusal);
        self::assertSame('The deletion request is no longer live.', $refusal->getMessage());
        self::assertSame([[], []], self::storedRows());

        $finished = $this->recordErasure(801, [self::OUTSIDER_ID]);
        self::assertTrue($finished->actions->finish($finished->stage, AccountOperationOutcome::NotStarted));
        $merge = Hilos::$db->accountOperations->actions->recordMerge(
            self::DEEPEST_ID,
            self::FOLDED_ID,
            new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, true, false),
        );
        foreach ([$finished, $merge] as $operation) {
            $refusal = self::refusal(static fn () => AccountErasureOpening::reconcile($operation, AccountErasureParticipantCatalog::framework()));
            self::assertInstanceOf(ValidationException::class, $refusal);
            self::assertSame('Only an open erasure can be reconciled.', $refusal->getMessage());
        }
    }

    /** A frame that is not the coordinator's is refused by the existing write guard, and nothing is stored. */
    public function testForeignFrameIsRefusedByTheWriteGuard(): void
    {
        AccountErasureCensusHilos::initBrowser();
        $request = self::requestDeletion(self::ROOT_ID);
        $open = $this->recordErasure(901, [self::OUTSIDER_ID]);
        $stored = self::storedRows();

        ExecutionContext::run(new ExecutionFrame(agentId: self::FOREIGN_ID), static function () use ($request, $open): void {
            self::assertInstanceOf(CreateNotAllowedException::class, self::refusal(
                static fn () => AccountErasureOpening::open($request, AccountErasureParticipantCatalog::framework()),
            ));
            self::assertInstanceOf(CreateNotAllowedException::class, self::refusal(
                static fn () => AccountErasureOpening::reconcile($open, AccountErasureParticipantCatalog::framework()),
            ));
        });

        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame($stored, self::storedRows());
    }

    /**
     * @param int $requestId Deletion request the erasure answers
     * @param list<int> $circleUserIds Fixed account order with the root last
     * @return AccountOperation Recorded open erasure
     */
    private function recordErasure(int $requestId, array $circleUserIds): AccountOperation
    {
        $rootUserId = $circleUserIds[array_key_last($circleUserIds)];

        return Hilos::$db->accountOperations->actions->recordErasure(
            $requestId,
            $rootUserId,
            AccountOperationCircle::fromArray($circleUserIds, $rootUserId),
        );
    }

    /**
     * @param int $userId Person asking for the deletion
     * @return AccountDeletion Live request
     */
    private static function requestDeletion(int $userId): AccountDeletion
    {
        Database::sql(
            'INSERT INTO `hilos_account_deletion` (`user_id`, `requested_at`, `effective_at`) VALUES (?, 1790416800000, 1790416800000)',
            [$userId],
        );

        return Hilos::$db->accountDeletions[Database::lastInsertId()];
    }

    /** @return list<list<array<string, mixed>>> Stored operations and assignments, each in id order */
    private static function storedRows(): array
    {
        return [
            Database::sql('SELECT * FROM `hilos_account_operation` ORDER BY `id`')->rows(),
            Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows(),
        ];
    }

    /**
     * @param Closure(): mixed $attempt Call expected to be refused
     * @return Throwable What refused it
     */
    private static function refusal(Closure $attempt): Throwable
    {
        try {
            $attempt();
        } catch (Throwable $failure) {
            return $failure;
        }
        self::fail('The call was not refused');
    }

    /**
     * @param list<AccountOperationAssignment> $assignments Stored assignments
     * @return list<string> Their keys in order
     */
    private static function keysOf(array $assignments): array
    {
        return array_map(static fn (AccountOperationAssignment $assignment): string => $assignment->assignmentKey, $assignments);
    }

    /**
     * @param list<AccountOperationAssignment> $assignments Stored assignments
     * @return list<?int> Their ids in order
     */
    private static function idsOf(array $assignments): array
    {
        return array_map(static fn (AccountOperationAssignment $assignment): ?int => $assignment->id, $assignments);
    }

    /** Creates fresh Object and View instances without an ORM reset. */
    private function mountFreshContext(): void
    {
        Hilos::$db = new AccountErasureCensusContext();
        Hilos::$db->configure();
    }

    /**
     * @param int $userId Folded account
     * @param int $survivorUserId Account it was folded into
     */
    private static function seedMerge(int $userId, int $survivorUserId): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, 1790416800000)',
            [$userId, $survivorUserId],
        );
    }

    /**
     * @param int $userId Recipient
     * @return int Notification id
     */
    private static function seedNotification(int $userId): int
    {
        Database::sql(
            'INSERT INTO `hilos_notification` (`user_id`, `type`, `title`, `created_at`) VALUES (?, ?, ?, 1790416800000)',
            [$userId, 'account.census', 'Census notification'],
        );

        return Database::lastInsertId();
    }

    /**
     * @param int $notificationId Notification delivered
     * @param string $channel Delivery channel
     * @param ?int $routingKey Saved routing key
     * @param string $status Saved delivery status
     * @return int Delivery id
     */
    private static function seedDelivery(int $notificationId, string $channel, ?int $routingKey, string $status): int
    {
        Database::sql(
            'INSERT INTO `hilos_notification_delivery` (`notification_id`, `channel`, `routing_key`, `status`, `created_at`, `updated_at`) '
            . 'VALUES (?, ?, ?, ?, 1790416800000, 1790416800000)',
            [$notificationId, $channel, $routingKey, $status],
        );

        return Database::lastInsertId();
    }

    /**
     * @param ?int $userId Person the challenge carries, or null
     * @param string $identifier Address the challenge was issued on
     */
    private static function seedVerification(?int $userId, string $identifier): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_user_verification` (`user_id`, `type`, `identifier`, `expires_at`, `created_at`) '
            . 'VALUES (?, ?, ?, 2082758400000, 1790416800000)',
            [$userId, VerificationType::ACCOUNT_DELETION, $identifier],
        );
    }

    /**
     * @param list<NotificationDeliveryIdentity> $identities Read page
     * @return list<int> Delivery ids in page order
     */
    private static function deliveryIds(array $identities): array
    {
        return array_map(static fn (NotificationDeliveryIdentity $identity): int => $identity->deliveryId, $identities);
    }

    /**
     * @param string ...$tables Stub tables a case activates, in dependency order
     */
    private static function raise(string ...$tables): void
    {
        foreach ($tables as $table) {
            Database::sqlRun((string)file_get_contents(self::stubPath($table, '')));
        }
        Schema::reset();
        Schema::initialize();
    }

    /** Raises the project table behind the census notes collection. */
    private static function raiseNoteTable(): void
    {
        Database::sqlRun('CREATE TABLE `' . AccountErasureCensusNote::_table . '` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`))');
        Schema::reset();
        Schema::initialize();
    }

    /** Drops whatever a case raised, before the census tables it may point at. */
    private static function dropCaseTables(): void
    {
        Database::sqlRun('DROP TABLE IF EXISTS `' . AccountErasureCensusNote::_table . '`');
        foreach (array_reverse(self::CASE_TABLES) as $table) {
            Database::sqlRun((string)file_get_contents(self::stubPath($table, '_down')));
        }
    }

    /** @param int $userId Person whose data copy is being prepared */
    private static function seedDataExport(int $userId): void
    {
        Database::sqlRun(
            "INSERT INTO `hilos_data_export` (`user_id`, `state`, `requested_at`) VALUES (?, ?, 1794470400000)",
            [$userId, DataExportState::PREPARING],
        );
    }

    /**
     * @param int $userId Person the address belongs to
     * @param string $identifier Address
     */
    private static function seedIdentity(int $userId, string $identifier): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_identity` (`user_id`, `type`, `identifier`, `created_at`, `updated_at`) '
            . 'VALUES (?, ?, ?, 1790416800000, 1790416800000)',
            [$userId, IdentityType::MAGIC_LINK, $identifier],
        );
    }

    /**
     * @param list<AccountOperationExpectation> $expectations Census result
     * @return list<string> Assignment keys in census order
     */
    private static function assignmentKeys(array $expectations): array
    {
        return array_map(static fn (AccountOperationExpectation $expectation): string => $expectation->assignmentKey(), $expectations);
    }

    /**
     * @param string $table Stub table
     * @param string $suffix Empty for the up stub, `_down` for the down stub
     * @return string Stub file path
     */
    private static function stubPath(string $table, string $suffix): string
    {
        return dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql';
    }

    /** @param bool $down Whether to drop in reverse dependency order */
    private function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            // external-boundary: up stub filenames carry no direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun((string)file_get_contents(self::stubPath($table, $suffix)));
        }
    }
}

/** The framework's collections and one project collection a project participant covers. */
final class AccountErasureCensusContext extends HilosDbContext
{
    /** Mounts the framework's collections, then the project's census notes. */
    public function configure(): void
    {
        parent::configure();
        $this->mountObjectCollection(AccountErasureCensusNotes::class, Objects::LAZY_STRATEGY_KEY);
    }
}

/** The session holder: the census readers are asked by the erasure coordinator it carries. */
final class AccountErasureCensusCoordinator extends AbstractSessionsLibraryAgent
{
}

/** Every owner the framework catalog names, a project keeper per person and two pooled channel senders. */
abstract class AccountErasureCensusHilos extends Hilos
{
    protected const string NOTIFICATION_CHANNEL_REGISTRY = AccountErasureCensusChannels::class;

    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [AgentRegistryKey::WORKER => AccountErasureCensusOwnerAgent::class, AgentRegistryKey::INDEXED => true],
        HilosAgentType::HILOS_USERS_LIBRARY => [AgentRegistryKey::WORKER => AccountErasureCensusOwnerAgent::class],
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [AgentRegistryKey::WORKER => AccountErasureCensusOwnerAgent::class],
        HilosAgentType::HILOS_DATA_EXPORT => [AgentRegistryKey::WORKER => AccountErasureCensusOwnerAgent::class],
        HilosAgentType::HILOS_LEGAL => [AgentRegistryKey::WORKER => AccountErasureCensusOwnerAgent::class],
        AccountErasureCensusOwnerAgent::NOTE_KEEPER => [
            AgentRegistryKey::WORKER => AccountErasureCensusOwnerAgent::class,
            AgentRegistryKey::INDEXED => true,
        ],
        AccountErasureCensusLetterAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => AccountErasureCensusLetterAgent::class,
            AgentRegistryKey::INDEXED => true,
        ],
        AccountErasureCensusTextAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => AccountErasureCensusTextAgent::class,
            AgentRegistryKey::INDEXED => true,
        ],
    ];
}

/** The same channels with no agent registered at all. */
abstract class AccountErasureCensusOwnerlessHilos extends AccountErasureCensusHilos
{
    public const array AGENTS = [];
}

/** The project keeper registered as a single agent although its participant is kept per person. */
abstract class AccountErasureCensusUnindexedKeeperHilos extends AccountErasureCensusHilos
{
    public const array AGENTS = [
        AccountErasureCensusOwnerAgent::NOTE_KEEPER => [AgentRegistryKey::WORKER => AccountErasureCensusOwnerAgent::class],
    ];
}

/** Two pooled channels; `census_pigeon` deliveries are saved but no descriptor knows the channel. */
abstract class AccountErasureCensusChannels extends DeliveryChannelRegistry
{
    /** @return array<string, AbstractDeliveryChannel> Letter and text channels by name */
    protected static function channels(): array
    {
        return [
            'census_letter' => new AccountErasureCensusChannel('census_letter', AccountErasureCensusLetterAgent::SIGNAL),
            'census_text' => new AccountErasureCensusChannel('census_text', AccountErasureCensusTextAgent::SIGNAL),
        ];
    }
}

/** A pooled channel four senders wide, routed by the saved key alone. */
final class AccountErasureCensusChannel extends AbstractDeliveryChannel
{
    /**
     * @param string $channel Channel name
     * @param string $signal Delivery signal of its sender
     */
    public function __construct(private readonly string $channel, private readonly string $signal)
    {
    }

    /** @return string Channel name */
    public function name(): string
    {
        return $this->channel;
    }

    /** @return string Delivery signal of its sender */
    public function deliverSignalName(): string
    {
        return $this->signal;
    }

    /** @return string Retry signal of its sender */
    public function retrySignalName(): string
    {
        return $this->signal . '_retry';
    }

    /**
     * @param int $userId Recipient
     * @return ?string No address: the census never resolves one
     */
    public function resolveAddress(int $userId): ?string
    {
        return null;
    }

    /** @return bool Always pooled */
    public function isPooled(): bool
    {
        return true;
    }

    /** @return int Four senders */
    public function poolSize(): int
    {
        return 4;
    }
}

/** A registered owner with no signals: the census only asks whether its type is registered. */
final class AccountErasureCensusOwnerAgent extends AbstractAgent
{
    public const string NOTE_KEEPER = 'census_note_keeper';

    /** Nothing to release. */
    public function onStop(): void
    {
    }
}

/** The letter channel's indexed sender. */
final class AccountErasureCensusLetterAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'census_letter_sender';
    public const string SIGNAL = 'census_letter_deliver';
    public const array AGENT_SIGNALS = [
        self::SIGNAL => [AgentSignalConfigKey::INDEX_FIELD => NotificationDeliverSignalData::shardKey],
    ];

    /** Nothing to release. */
    public function onStop(): void
    {
    }
}

/** The text channel's indexed sender. */
final class AccountErasureCensusTextAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'census_text_sender';
    public const string SIGNAL = 'census_text_deliver';
    public const array AGENT_SIGNALS = [
        self::SIGNAL => [AgentSignalConfigKey::INDEX_FIELD => NotificationDeliverSignalData::shardKey],
    ];

    /** Nothing to release. */
    public function onStop(): void
    {
    }
}

/** A project row the census notes participant covers; only its table's presence matters here. */
final class AccountErasureCensusNote extends Entity
{
    public const string id = 'id';

    public const string _table = 'hilos_fw_test_census_note';
    public const string _primary = self::id;
    public const array _columns = [self::id];
    public const array _types = [self::id => PhpType::INTEGER->value];

    public ?int $id = null;
}

/** Stored census note. */
final class AccountErasureCensusNoteObject extends Object_
{
    public const string ENTITY_CLASS = AccountErasureCensusNote::class;
}

/**
 * @extends Objects<AccountErasureCensusNoteObject>
 */
final class AccountErasureCensusNotes extends Objects
{
    public const string OBJECT_CLASS = AccountErasureCensusNoteObject::class;
    public const string COLLECTION_KEY = 'censusNotes';
}
