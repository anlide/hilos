<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountOperation\AccountErasureOpening;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Item\AccountOperation as EntityAccountOperation;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\AccountDeletion;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Hilos;
use Hilos\Users\AccountErasureProgress;
use Hilos\Users\AccountErasureProgressChangeSubscriber;
use Hilos\Users\AccountErasureProgressResolver;

/** The erasure read model over committed DB facts and the mirrors that repair its process memory. */
final class AccountErasureProgressIntegrationTest extends FrameworkIntegrationTestCase
{
    use PersonAgentFrames;

    private const array TABLES = [
        'hilos_user',
        'hilos_account_deletion',
        'hilos_identity',
        'hilos_user_merge',
        'hilos_user_verification',
        'hilos_account_operation',
        'hilos_account_operation_assignment',
        'hilos_account_operation_result',
    ];
    private const string COORDINATOR_ID = HilosAgentType::HILOS_SESSIONS_LIBRARY;
    private const string INDEX_ID = HilosAgentType::HILOS_INDEX;
    private const int FOLDED_ID = 41;
    private const int ROOT_ID = 43;
    private const int OUTSIDER_ID = 44;

    private ?HilosDbContext $previousDb;
    private string $previousApp;
    private int $frameToken;
    /** @var list<string> Feature interests this case must release after worker-mode reads */
    private array $processWideReads = [];

    /** Raises just the read model's tables and writes operations under the real session-holder declarations. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousApp = Hilos::appClass();
        self::stubs(down: true);
        self::stubs(down: false);
        Schema::reset();
        Schema::initialize();
        Hilos::$db = new AccountErasureProgressContext();
        Hilos::$db->configure();
        AccountErasureProgressHilos::initBrowser();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe(new AccountErasureProgressChangeSubscriber());
        AccountErasureProgressResolver::forgetAll();
        $coordinator = new AccountErasureProgressCoordinator();
        OwnershipDeclaration::claimDb($coordinator::class, $coordinator->getId());
        OwnershipDeclaration::claimDbSet($coordinator);
        $this->frameToken = ExecutionContext::push(new ExecutionFrame(agentId: self::COORDINATOR_ID));
        foreach ([self::FOLDED_ID, self::ROOT_ID, self::OUTSIDER_ID] as $userId) {
            Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Erasure progress person')", [$userId]);
        }
    }

    /** Releases the mode, interests, claims and memory before returning the previous application and context. */
    protected function tearDown(): void
    {
        try {
            SourceInterestRegistry::readsWhatItMounts();
            Database::handlerEnd();
            self::stubs(down: true);
            Schema::reset();
        } finally {
            AccountErasureProgressResolver::forgetAll();
            SourceChangeBus::reset();
            $this->releasePersonAgents();
            TruthSourceRegistry::unregisterAgent(self::COORDINATOR_ID);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::COORDINATOR_ID));
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::INDEX_ID));
            foreach ($this->processWideReads as $key) {
                SourceInterestRegistry::releaseConsumer(SourceConsumer::feature($key));
            }
            ExecutionContext::pop($this->frameToken);
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();
            Hilos::$db = $this->previousDb;
            parent::tearDown();
        }
    }

    public function testNoOperationAndAnUnstartedOperationHaveNoProgress(): void
    {
        self::assertNull(AccountErasureProgressResolver::of(self::ROOT_ID));
        $request = self::requestDeletion();
        $operation = self::open($request);

        self::assertNull(AccountErasureProgressResolver::of(self::ROOT_ID));
        self::assertTrue($this->asPerson(self::ROOT_ID, static fn (): bool => $request->actions->start()));
        $progress = AccountErasureProgressResolver::of(self::ROOT_ID);

        self::assertNotNull($progress);
        self::assertSame($request->startedAt, $progress->startedAt);
        self::assertSame($progress, AccountErasureProgressResolver::of(self::ROOT_ID));
        self::assertTrue($operation->actions->finish($operation->stage, AccountOperationOutcome::Completed));
        self::assertNull(AccountErasureProgressResolver::of(self::ROOT_ID));
    }

    public function testBegunErasureShowsItsOrderedCompositionForEveryAccountOfTheCircle(): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, 1790416800000)',
            [self::FOLDED_ID, self::ROOT_ID],
        );
        $request = self::requestDeletion();
        $operation = self::open($request);
        self::recordExpectation($operation, new AccountOperationExpectation('delivery:mail', self::FOLDED_ID, null));
        self::assertTrue($this->asPerson(self::ROOT_ID, static fn (): bool => $request->actions->start()));

        $expected = [
            'startedAt' => $request->startedAt,
            'parts' => [
                ['participant' => 'personal', 'owner' => HilosAgentType::HILOS_USER, 'userId' => 41, 'done' => false],
                ['participant' => 'users_library', 'owner' => HilosAgentType::HILOS_USERS_LIBRARY, 'userId' => 41, 'done' => false],
                ['participant' => 'personal', 'owner' => HilosAgentType::HILOS_USER, 'userId' => 43, 'done' => false],
                ['participant' => 'users_library', 'owner' => HilosAgentType::HILOS_USERS_LIBRARY, 'userId' => 43, 'done' => false],
            ],
            'waits' => [['participant' => 'delivery:mail', 'userId' => 41]],
        ];
        self::assertSame($expected, AccountErasureProgressResolver::of(self::ROOT_ID)?->toArray());
        self::assertSame($expected, AccountErasureProgressResolver::of(self::FOLDED_ID)?->toArray());
        self::assertNull(AccountErasureProgressResolver::of(self::OUTSIDER_ID));
    }

    public function testAssignmentsAcrossStagesAndReconciliationDoNotDuplicatePartsOrWaits(): void
    {
        $request = self::requestDeletion();
        $operation = self::open($request);
        $wait = new AccountOperationExpectation('delivery:mail', self::ROOT_ID, null);
        self::recordExpectation($operation, $wait);
        Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            $operation->stage,
            'capture:progress-test',
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY),
            self::ROOT_ID,
            AccountOperationAssignmentPayload::fromJson('{}'),
        );
        self::assertTrue($this->asPerson(self::ROOT_ID, static fn (): bool => $request->actions->start()));
        $before = AccountErasureProgressResolver::of(self::ROOT_ID)->toArray();
        $first = Hilos::$db->accountOperationAssignments->forOperation($operation->id)->first();

        self::assertTrue($operation->actions->advance(EntityAccountOperation::INITIAL_STAGE, 'progress-reconcile'));
        Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id, $operation->stage, $first->assignmentKey, $first->owner, $first->userId, $first->payload,
        );
        self::recordExpectation($operation, $wait);
        self::assertSame([], AccountErasureOpening::reconcile($operation, AccountErasureParticipantCatalog::framework()));
        self::assertSame([], AccountErasureOpening::reconcile($operation, AccountErasureParticipantCatalog::framework()));

        self::assertSame($before, AccountErasureProgressResolver::of(self::ROOT_ID)->toArray());
        self::recordExpectation($operation, new AccountOperationExpectation(
            'delivery:mail', self::ROOT_ID, new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY),
        ));
        self::assertSame([], AccountErasureProgressResolver::of(self::ROOT_ID)->waits);
        self::assertCount(3, AccountErasureProgressResolver::of(self::ROOT_ID)->parts);
    }

    public function testNullIsRememberedAndAnOperationWriteDropsIt(): void
    {
        self::assertNull(AccountErasureProgressResolver::of(self::ROOT_ID));
        // Hold the mirrors back to prove that even absence was remembered, then restore them.
        SourceChangeBus::reset();
        try {
            $request = self::requestDeletion();
            $operation = self::open($request);
            self::assertTrue($this->asPerson(self::ROOT_ID, static fn (): bool => $request->actions->start()));
            self::assertNull(AccountErasureProgressResolver::of(self::ROOT_ID));
        } finally {
            SourceChangeBus::subscribe(new ViewCacheSubscriber());
            SourceChangeBus::subscribe(new AccountErasureProgressChangeSubscriber());
        }
        self::assertTrue($operation->actions->advance($operation->stage, 'progress-refresh'));

        self::assertNotNull(AccountErasureProgressResolver::of(self::ROOT_ID));
    }

    public function testResultsAndAssignmentsDropMemoryWithoutCompletingAPart(): void
    {
        $request = self::requestDeletion();
        $operation = self::open($request);
        self::assertTrue($this->asPerson(self::ROOT_ID, static fn (): bool => $request->actions->start()));
        $before = AccountErasureProgressResolver::of(self::ROOT_ID);
        $assignment = self::recordExpectation($operation, new AccountOperationExpectation(
            'delivery:mail', self::ROOT_ID, new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY),
        ));
        $assigned = AccountErasureProgressResolver::of(self::ROOT_ID);
        self::assertNotSame($before, $assigned);
        self::assertCount(3, $assigned->parts);

        Database::transaction(DatabaseNames::PRIMARY, static function () use ($assignment, $assigned): void {
            Hilos::$db->accountOperationResults->actions->record($assignment, AccountOperationResultPayload::fromJson('{}'));
            self::assertNotSame($assigned, AccountErasureProgressResolver::of(self::ROOT_ID));
        });
        $recorded = AccountErasureProgressResolver::of(self::ROOT_ID);

        self::assertNotSame($assigned, $recorded);
        self::assertSame($assigned->toArray(), $recorded->toArray());
        self::assertSame([false, false, false], array_column($recorded->parts, AccountErasureProgress::done));
        self::assertNotNull(Hilos::$db->accountOperationResults->byAssignment($assignment->id));
    }

    public function testBrokenExpectationPayloadIsNotHidden(): void
    {
        $request = self::requestDeletion();
        $operation = self::open($request);
        self::assertTrue($this->asPerson(self::ROOT_ID, static fn (): bool => $request->actions->start()));
        Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            $operation->stage,
            'expect:broken',
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY),
            self::ROOT_ID,
            AccountOperationAssignmentPayload::fromJson('{}'),
        );

        $this->expectException(InvalidFormatException::class);
        AccountErasureProgressResolver::of(self::ROOT_ID);
    }

    public function testWorkerReadsUseOnlyTheIndexDeclarationAndProcessWideSources(): void
    {
        $request = self::requestDeletion();
        $operation = self::open($request);
        $this->personAgent(self::ROOT_ID);
        // Keep the real writers' claims for the writes below, but remove their reader interests.
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::COORDINATOR_ID));
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->personAgent(self::ROOT_ID)->getId()));
        SourceInterestRegistry::readsWhatIsDelivered();
        $this->processWideReads = Hilos::$db->processWideReadKeys();
        Hilos::$db->declareProcessWideReads();
        foreach ($this->processWideReads as $key) {
            SourceInterestRegistry::markReady(SourceChange::KIND_DB, $key);
        }
        foreach (AbstractHilosIndexAgent::readDbCollections() as $key) {
            SourceInterestRegistry::register(SourceChange::KIND_DB, $key, SourceConsumer::agent(self::INDEX_ID));
            SourceInterestRegistry::markReady(SourceChange::KIND_DB, $key);
        }

        self::assertSame(
            AbstractHilosIndexAgent::readDbCollections(),
            SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::agent(self::INDEX_ID), SourceChange::KIND_DB),
        );
        self::assertSame([], SourceInterestRegistry::collectionsOfConsumer(
            SourceConsumer::agent(self::COORDINATOR_ID), SourceChange::KIND_DB,
        ));
        foreach ([HilosDbContext::accountOperations, HilosDbContext::accountOperationAssignments, HilosDbContext::accountOperationResults] as $key) {
            self::assertNotContains($key, $this->processWideReads);
        }

        self::assertNull(AccountErasureProgressResolver::of(self::OUTSIDER_ID));
        self::assertNull(AccountErasureProgressResolver::of(self::ROOT_ID));
        self::assertTrue($this->asPerson(self::ROOT_ID, static fn (): bool => $request->actions->start()));
        $begun = AccountErasureProgressResolver::of(self::ROOT_ID);
        self::assertNotNull($begun);
        self::assertCount(2, $begun->parts);
        $assignment = self::recordExpectation($operation, new AccountOperationExpectation(
            'delivery:mail', self::ROOT_ID, new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY),
        ));
        $assigned = AccountErasureProgressResolver::of(self::ROOT_ID);
        self::assertNotSame($begun, $assigned);
        self::assertCount(3, $assigned->parts);

        self::recordResult($assignment);
        $recorded = AccountErasureProgressResolver::of(self::ROOT_ID);
        self::assertNotSame($assigned, $recorded);
        self::assertSame($assigned->toArray(), $recorded->toArray());
        self::assertNotNull(Hilos::$db->accountOperationResults->byAssignment($assignment->id));
    }

    /** @return AccountDeletion A live request that has not started */
    private static function requestDeletion(): AccountDeletion
    {
        Database::sql(
            'INSERT INTO `hilos_account_deletion` (`user_id`, `requested_at`, `effective_at`) VALUES (?, 1790416800000, 1790416800000)',
            [self::ROOT_ID],
        );

        return Hilos::$db->accountDeletions[Database::lastInsertId()];
    }

    /**
     * @param AccountDeletion $request Live request
     * @return AccountOperation Open erasure with its census
     */
    private static function open(AccountDeletion $request): AccountOperation
    {
        $opened = AccountErasureOpening::open($request, AccountErasureParticipantCatalog::framework());
        self::assertNotNull($opened->operation);

        return $opened->operation;
    }

    /**
     * @param AccountOperation $operation Open erasure
     * @param AccountOperationExpectation $expectation Expected owner or wait
     * @return AccountOperationAssignment Recorded expectation at the current stage
     */
    private static function recordExpectation(AccountOperation $operation, AccountOperationExpectation $expectation): AccountOperationAssignment
    {
        return Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            $operation->stage,
            $expectation->assignmentKey(),
            $expectation->assignmentOwner(),
            $expectation->userId,
            $expectation->payload(),
        );
    }

    /** @param AccountOperationAssignment $assignment The session holder's participant duty */
    private static function recordResult(AccountOperationAssignment $assignment): void
    {
        Database::transaction(DatabaseNames::PRIMARY, static function () use ($assignment): void {
            Hilos::$db->accountOperationResults->actions->record($assignment, AccountOperationResultPayload::fromJson('{}'));
        });
    }

    /** @param bool $down Whether to drop the stub tables in reverse dependency order */
    private static function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            // external-boundary: up stub filenames carry no direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun((string)file_get_contents(
                dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql',
            ));
        }
    }
}

/** The framework's normal View collections, including the operations read by the index agent. */
final class AccountErasureProgressContext extends HilosDbContext
{
}

/** The real session-holder claims, including the set of its own operation results. */
final class AccountErasureProgressCoordinator extends AbstractSessionsLibraryAgent
{
}

/** The users-library registration makes its expected owner available to the census. */
final class AccountErasureProgressUsersLibrary extends AbstractUsersLibraryAgent
{
}

/** The two participants needed for each account's minimal census. */
abstract class AccountErasureProgressHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [AgentRegistryKey::WORKER => IntegrationUserAgent::class, AgentRegistryKey::INDEXED => true],
        HilosAgentType::HILOS_USERS_LIBRARY => [AgentRegistryKey::WORKER => AccountErasureProgressUsersLibrary::class],
    ];
}
