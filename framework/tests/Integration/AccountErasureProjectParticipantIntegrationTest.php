<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\AccountOperation\AccountDataOwner;
use Hilos\Auth\AccountOperation\AccountDataOwnerDirectory;
use Hilos\Auth\AccountOperation\AccountErasureCensus;
use Hilos\Auth\AccountOperation\AccountErasureExemption;
use Hilos\Auth\AccountOperation\AccountErasurePart;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountErasureProjectOwner;
use Hilos\Auth\AccountOperation\AccountErasureProjectParticipant;
use Hilos\Auth\AccountOperation\AccountErasureProjectResult;
use Hilos\Auth\AccountOperation\AccountErasureProjectStep;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountOperationAsks;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationStepRefusal;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Topology\AgentSignalRouteRegistry;
use Hilos\Core\Topology\TopologyValidator;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\PhpType;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Files\DTO\FileRemoveSignalData;
use Hilos\Files\HilosFiles;
use Hilos\Files\Storage\LocalFilesStorage;
use Hilos\Hilos;
use Hilos\Users\AccountErasure;
use Hilos\Users\SecondFactorFate;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/** A project owner taken into the erasure census by its registration, and its part carried as a participant step. */
final class AccountErasureProjectParticipantIntegrationTest extends FrameworkIntegrationTestCase
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
        'hilos_account_operation_result',
    ];
    private const string COORDINATOR_ID = 'erasure-project-coordinator';
    private const int PEER_INDEX = 2;
    private const int REQUEST_ID = 101;
    private const int USER_ID = 43;
    private const int NEIGHBOUR_ID = 44;

    private ?HilosDbContext $previousDb;
    private string $previousApp;
    private int $frameToken;
    private AccountErasureProjectNoteKeeper $keeper;
    private AccountErasureProjectStrayOwner $stray;

    /** Raises the operation tables and the project's notes, mounts them and acts as the session holder. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousApp = Hilos::appClass();
        self::dropNoteTable();
        $this->stubs(down: true);
        $this->stubs(down: false);
        Database::sqlRun(
            'CREATE TABLE `' . AccountErasureProjectNote::_table . '` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . '`user_id` INT UNSIGNED NOT NULL, `file_id` INT UNSIGNED NULL, PRIMARY KEY (`id`))',
        );
        Schema::reset();
        Schema::initialize();
        Hilos::$db = new AccountErasureProjectContext();
        Hilos::$db->configure();
        $this->keeper = new AccountErasureProjectNoteKeeper();
        $this->stray = new AccountErasureProjectStrayOwner();
        OwnershipDeclaration::claimAll($this->keeper);
        OwnershipDeclaration::claimAll($this->stray);
        OwnershipDeclaration::claimDb(AccountErasureProjectCoordinator::class, self::COORDINATOR_ID);
        $this->frameToken = ExecutionContext::push(new ExecutionFrame(agentId: self::COORDINATOR_ID));
        AccountErasureProjectHilos::initBrowser();
        $this->connectPeer();
    }

    /** Ends transactions before DDL, releases every claim and returns the previous context and application. */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            self::dropNoteTable();
            $this->stubs(down: true);
            Schema::reset();
        } finally {
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();
            ExecutionContext::pop($this->frameToken);
            foreach ([$this->keeper->getId(), $this->stray->getId(), self::COORDINATOR_ID] as $agentId) {
                TruthSourceRegistry::unregisterAgent($agentId);
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
            }
            Hilos::$db = $this->previousDb;
            parent::tearDown();
        }
    }

    /** Registered erasure owners have typed, addressable step declarations beside their real handlers. */
    public function testProjectFixtureAccountOperationRoutesAreValid(): void
    {
        $agents = [
            'notes_owner' => [AgentRegistryKey::WORKER => AccountErasureProjectNoteKeeper::class],
            'other_owner' => [AgentRegistryKey::WORKER => AccountErasureProjectStrayOwner::class],
        ];
        $errors = (function () use ($agents): array {
            $errors = [];
            $this->validateAccountOperationRoutes(
                $agents,
                AgentSignalRouteRegistry::routes($agents),
                AgentSignalRouteRegistry::dtoRoutes($agents),
                AgentSignalRouteRegistry::indexFields($agents),
                $errors,
            );

            return $errors;
        })->call(new TopologyValidator());
        self::assertSame([], $errors);
    }

    /** The installation takes the owner after the framework's participants and the exemption beside the framework's. */
    public function testInstallationTakesTheOwnerAndTheExemptionFromTheDirectory(): void
    {
        $installation = AccountErasureParticipantCatalog::installation();

        $participants = $installation->participants();
        $owner = $participants[array_key_last($participants)];
        self::assertInstanceOf(AccountErasureProjectParticipant::class, $owner);
        self::assertSame(AccountErasureProjectDirectory::NOTES, $owner->key());
        self::assertSame([AccountErasureProjectNotes::COLLECTION_KEY], $owner->collections());
        self::assertSame(
            [...AccountErasureParticipantCatalog::EXEMPT, AccountErasureProjectDirectory::TEMPLATES => AccountErasureExemption::NotPersonal],
            $installation->exempt(),
        );
    }

    /** A registered owner is expected for every account by its agent id, whether the account has notes or not. */
    public function testRegisteredOwnerIsExpectedForEveryAccount(): void
    {
        self::seedNote(self::USER_ID, null);

        self::assertSame(['expect:erasure_notes:erasure_note_keeper:43'], self::notesCensus(self::USER_ID));
        self::assertSame(['expect:erasure_notes:erasure_note_keeper:44'], self::notesCensus(self::NEIGHBOUR_ID));
    }

    /** @return array<string, array{class-string<Hilos>}> Projects registering the notes keeper wrongly, one mistake each */
    public static function wrongRegistrations(): array
    {
        return [
            'not in the agents' => [AccountErasureProjectUnlistedHilos::class],
            'indexed per person' => [AccountErasureProjectIndexedHilos::class],
            'worker without the owner interface' => [AccountErasureProjectPlainWorkerHilos::class],
        ];
    }

    /** @param class-string<Hilos> $project Project whose registration of the keeper is wrong */
    #[DataProvider('wrongRegistrations')]
    public function testWronglyRegisteredOwnerIsWaitedForOnlyWhileTheAccountHasNotes(string $project): void
    {
        $project::initBrowser();
        self::seedNote(self::USER_ID, null);

        self::assertSame(['wait:erasure_notes:43'], self::notesCensus(self::USER_ID));
        self::assertSame([], self::notesCensus(self::NEIGHBOUR_ID));
    }

    /** The owner deletes only its account's notes, and the result commits with the deletion. */
    public function testStepErasesOnlyItsAccountAndCommitsTheResultWithIt(): void
    {
        self::seedNote(self::USER_ID, null);
        self::seedNote(self::NEIGHBOUR_ID, null);
        self::seedNote(self::USER_ID, null);
        $assignmentId = self::keeperAssignment(self::openErasure());

        $record = $this->asAgent($this->keeper, fn () => AccountErasureProjectStep::run($this->keeper, $assignmentId));

        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame([self::NEIGHBOUR_ID], self::storedNoteOwners());
        $results = self::storedResults();
        self::assertCount(1, $results);
        self::assertSame($assignmentId, (int)$results[0]['assignment_id']);
        self::assertSame($this->keeper->getId(), $results[0]['owner_agent_id']);
        self::assertSame('{"rows":{"notes":2},"files":[]}', $results[0]['payload_json']);
        self::assertSame($record->payloadJson, $results[0]['payload_json']);
        self::assertSame(['notes' => 2], AccountErasureProjectResult::erasureOf($record->payload)->rowsErased);
        self::assertSame([self::USER_ID], $this->keeper->parts);
    }

    /** An owner failing after its deletion leaves neither the deletion nor a result, and the retry commits once. */
    public function testOwnerFailureRollsBackTheDeletionAndTheResultAndTheRetryCommitsOnce(): void
    {
        self::seedNote(self::USER_ID, null);
        self::seedNote(self::NEIGHBOUR_ID, null);
        $assignmentId = self::keeperAssignment(self::openErasure());
        $failure = new LogicException('The notes keeper failed after deleting');
        $this->keeper->failure = $failure;

        try {
            $this->asAgent($this->keeper, fn () => AccountErasureProjectStep::run($this->keeper, $assignmentId));
            self::fail('A failed erasure returned a result');
        } catch (LogicException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame([self::USER_ID, self::NEIGHBOUR_ID], self::storedNoteOwners());
        self::assertSame([], self::storedResults());

        $this->keeper->failure = null;
        $record = $this->asAgent($this->keeper, fn () => AccountErasureProjectStep::run($this->keeper, $assignmentId));
        $repeat = $this->asAgent($this->keeper, fn () => AccountErasureProjectStep::run($this->keeper, $assignmentId));

        self::assertSame($record->id, $repeat->id);
        self::assertSame([self::USER_ID, self::USER_ID], $this->keeper->parts);
        self::assertSame([self::NEIGHBOUR_ID], self::storedNoteOwners());
        self::assertCount(1, self::storedResults());
    }

    /** A repeat returns the saved result and leaves a note written since then alone: the owner is not asked again. */
    public function testRepeatReturnsTheSavedResultWithoutAskingTheOwner(): void
    {
        self::seedNote(self::USER_ID, null);
        $assignmentId = self::keeperAssignment(self::openErasure());
        $record = $this->asAgent($this->keeper, fn () => AccountErasureProjectStep::run($this->keeper, $assignmentId));
        self::seedNote(self::USER_ID, null);

        $repeat = $this->asAgent($this->keeper, fn () => AccountErasureProjectStep::run($this->keeper, $assignmentId));

        self::assertSame($record->id, $repeat->id);
        self::assertSame('{"rows":{"notes":1},"files":[]}', $repeat->payloadJson);
        self::assertSame([self::USER_ID], $this->keeper->parts);
        self::assertSame([self::USER_ID], self::storedNoteOwners());
        self::assertCount(1, self::storedResults());
    }

    /** Another owner taking the keeper's assignment is refused before any work, and the notes stay. */
    public function testAnotherOwnerIsRefusedTheAssignmentBeforeWork(): void
    {
        self::seedNote(self::USER_ID, null);
        $assignmentId = self::keeperAssignment(self::openErasure());

        try {
            $this->asAgent($this->stray, fn () => AccountErasureProjectStep::run($this->stray, $assignmentId));
            self::fail('Another owner carried the keeper\'s part');
        } catch (AccountOperationStepRefusedException $failure) {
            self::assertSame(AccountOperationStepRefusal::ForeignAssignment, $failure->reason);
        }
        self::assertSame([], $this->stray->parts);
        self::assertSame([], $this->keeper->parts);
        self::assertSame([self::USER_ID], self::storedNoteOwners());
        self::assertSame([], self::storedResults());
    }

    /** The named files leave for the files library once the step commits, and not while the owner fails. */
    public function testNamedFilesLeaveOnceTheStepCommits(): void
    {
        $this->withFilesLibrary(function (): void {
            self::seedNote(self::USER_ID, 41);
            self::seedNote(self::NEIGHBOUR_ID, 43);
            self::seedNote(self::USER_ID, 42);
            $assignmentId = self::keeperAssignment(self::openErasure());
            $this->keeper->failure = new LogicException('The notes keeper failed after naming its files');

            try {
                $this->asAgent($this->keeper, fn () => AccountErasureProjectStep::run($this->keeper, $assignmentId));
                self::fail('A failed erasure returned a result');
            } catch (LogicException) {
                self::assertSame([], self::removedFiles());
            }

            $this->keeper->failure = null;
            $record = $this->asAgent($this->keeper, fn () => AccountErasureProjectStep::run($this->keeper, $assignmentId));

            self::assertSame([[41, 42]], self::removedFiles());
            self::assertSame('{"rows":{"notes":2},"files":[41,42]}', $record->payloadJson);
            self::assertSame([self::NEIGHBOUR_ID], self::storedNoteOwners());
        });
    }

    /** An answered erasure whose result loses to a competing attempt rolls back, and its files stay. */
    public function testFilesStayWhenTheAnsweredErasureRollsBack(): void
    {
        $this->withFilesLibrary(function (): void {
            self::seedNote(self::USER_ID, 41);
            self::seedNote(self::USER_ID, 42);
            $assignmentId = self::keeperAssignment(self::openErasure());
            $winning = '{"rows":{"notes":2},"files":[41,42]}';
            $this->keeper->afterErasing = fn () => Database::sql(
                'INSERT INTO `hilos_account_operation_result` (`assignment_id`, `owner_agent_id`, `payload_json`, `created_at`)'
                . ' VALUES (?, ?, ?, 1790416800000)',
                [$assignmentId, $this->keeper->getId(), $winning],
                index: self::PEER_INDEX,
            );

            $record = $this->asAgent($this->keeper, fn () => AccountErasureProjectStep::run($this->keeper, $assignmentId));

            self::assertSame($winning, $record->payloadJson);
            self::assertSame([], self::removedFiles());
            self::assertSame([self::USER_ID, self::USER_ID], self::storedNoteOwners());
            self::assertCount(1, self::storedResults());
        });
    }

    /** An erasure step refuses a merge assignment before asking the owner or changing its rows. */
    public function testAMergeAssignmentCannotEraseTheProjectPart(): void
    {
        self::seedNote(self::USER_ID, null);
        $operation = Hilos::$db->accountOperations->actions->recordMerge(
            self::NEIGHBOUR_ID,
            self::USER_ID,
            new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, true, false),
        );
        $assignmentId = self::keeperAssignment($operation);

        try {
            $this->asAgent($this->keeper, fn () => AccountErasureProjectStep::run($this->keeper, $assignmentId));
            self::fail('A merge assignment erased project rows');
        } catch (ValidationException $failure) {
            self::assertSame('An account erasure part must belong to an erasure.', $failure->getMessage());
        }
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame([], $this->keeper->parts);
        self::assertSame([self::USER_ID], self::storedNoteOwners());
        self::assertSame([], self::storedResults());
    }

    /** An assignment naming no account is refused inside the step, and nothing is deleted or recorded. */
    public function testAssignmentWithoutAccountIsRefusedAndWritesNothing(): void
    {
        self::seedNote(self::USER_ID, null);
        $operation = self::openErasure();
        $assignmentId = Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            $operation->stage,
            'expect:erasure_notes:erasure_note_keeper:circle',
            new AccountOperationOwnerAddress(AccountErasureProjectNoteKeeper::AGENT_TYPE),
            null,
            AccountOperationAssignmentPayload::fromJson('{"participant":"erasure_notes"}'),
        )->id;

        try {
            $this->asAgent($this->keeper, fn () => AccountErasureProjectStep::run($this->keeper, $assignmentId));
            self::fail('A part without an account was erased');
        } catch (ValidationException $failure) {
            self::assertSame('An account erasure part must name its account.', $failure->getMessage());
        }
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame([], $this->keeper->parts);
        self::assertSame([self::USER_ID], self::storedNoteOwners());
        self::assertSame([], self::storedResults());
    }

    /**
     * @param int $userId Account erased alone
     * @return list<string> Assignment keys the installation's census expects of the notes owner
     */
    private static function notesCensus(int $userId): array
    {
        $expectations = AccountErasureCensus::of(
            Hilos::$db->userMerges->erasureCircleOf($userId),
            AccountErasureParticipantCatalog::installation(),
        );

        return array_values(array_map(
            static fn (AccountOperationExpectation $expectation): string => $expectation->assignmentKey(),
            array_filter(
                $expectations,
                static fn (AccountOperationExpectation $expectation): bool => $expectation->participantKey === AccountErasureProjectDirectory::NOTES,
            ),
        ));
    }

    /** @return AccountOperation Erasure of the account alone, written by the session holder */
    private static function openErasure(): AccountOperation
    {
        return Hilos::$db->accountOperations->actions->recordErasure(
            self::REQUEST_ID,
            self::USER_ID,
            Hilos::$db->userMerges->erasureCircleOf(self::USER_ID),
        );
    }

    /**
     * @param AccountOperation $operation Open erasure of the account
     * @return int Id of the assignment the census expects of the notes owner, written by the session holder
     */
    private static function keeperAssignment(AccountOperation $operation): int
    {
        $circle = Hilos::$db->userMerges->erasureCircleOf(self::USER_ID);
        foreach (AccountErasureCensus::of($circle, AccountErasureParticipantCatalog::installation()) as $expectation) {
            if ($expectation->participantKey === AccountErasureProjectDirectory::NOTES) {
                return Hilos::$db->accountOperationAssignments->actions->record(
                    $operation->id,
                    $operation->stage,
                    $expectation->assignmentKey(),
                    $expectation->assignmentOwner(),
                    $expectation->userId,
                    $expectation->payload(),
                )->id;
            }
        }

        self::fail('The census expected nothing of the notes owner');
    }

    /**
     * @param int $userId Account the note belongs to
     * @param ?int $fileId Registry file the note points at, or null
     */
    private static function seedNote(int $userId, ?int $fileId): void
    {
        Database::sqlRun(
            'INSERT INTO `' . AccountErasureProjectNote::_table . '` (`user_id`, `file_id`) VALUES (?, ?)',
            [$userId, $fileId],
        );
    }

    /** @return list<int> Accounts of the stored notes in id order, as a separate connection sees them */
    private static function storedNoteOwners(): array
    {
        return array_map(
            static fn (array $row): int => (int)$row['user_id'],
            Database::sql('SELECT `user_id` FROM `' . AccountErasureProjectNote::_table . '` ORDER BY `id`', index: self::PEER_INDEX)->rows(),
        );
    }

    /** @return list<array<string, mixed>> Stored results in id order, as a separate connection sees them */
    private static function storedResults(): array
    {
        return Database::sql(
            'SELECT `assignment_id`, `owner_agent_id`, `payload_json` FROM `hilos_account_operation_result` ORDER BY `id`',
            index: self::PEER_INDEX,
        )->rows();
    }

    /** @return list<list<int>> File ids of every removal queued since the last call, in queue order */
    private static function removedFiles(): array
    {
        $removals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_FILE_REMOVE) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertInstanceOf(FileRemoveSignalData::class, $signal->data->data);
                $removals[] = $signal->data->data->fileIds;
            }
        }

        return $removals;
    }

    /** @param Closure(): void $case Case run with a files library whose removals are queued, not sent */
    private function withFilesLibrary(Closure $case): void
    {
        $previousRouter = Hilos::$sr;
        $previousFiles = Hilos::$files;
        Hilos::$sr = new SignalRouter();
        Hilos::$files = new HilosFiles(new LocalFilesStorage());
        try {
            $case();
        } finally {
            Hilos::$files = $previousFiles;
            Hilos::$sr = $previousRouter;
        }
    }

    /**
     * @param AbstractAgent $agent Identity whose declared rights apply
     * @param Closure $work Work performed in its execution frame
     * @return mixed Work's result
     */
    private function asAgent(AbstractAgent $agent, Closure $work): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $agent->getId()), $work);
    }

    /** Connects a separate SQL observer, which sees only what was committed. */
    private function connectPeer(): void
    {
        $primary = Database::getConnectionConfig(0);
        Database::configure(self::PEER_INDEX, $primary->host, $primary->user, $primary->password,
            $primary->database, $primary->port, $primary->charset, $primary->socket);
        Database::connect(self::PEER_INDEX);
    }

    /** Drops the project's notes table. */
    private static function dropNoteTable(): void
    {
        Database::sqlRun('DROP TABLE IF EXISTS `' . AccountErasureProjectNote::_table . '`');
    }

    /** @param bool $down Whether to drop in reverse dependency order */
    private function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            // external-boundary: up stub filenames carry no direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
        }
    }
}

/** The framework's collections and the project's notes. */
final class AccountErasureProjectContext extends HilosDbContext
{
    /** Mounts the framework's collections, then the project's notes. */
    public function configure(): void
    {
        parent::configure();
        $this->mountObjectCollection(AccountErasureProjectNotes::class, Objects::LAZY_STRATEGY_KEY);
    }
}

/** The session holder, which opens the erasure and writes its assignments. */
final class AccountErasureProjectCoordinator extends AbstractSessionsLibraryAgent
{
}

/** Project A: the notes keeper registered as one agent for everybody, with the files registry. */
abstract class AccountErasureProjectHilos extends Hilos
{
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = AccountErasureProjectDirectory::class;
    protected const array FEATURES = [HilosFeature::FILES];

    public const array AGENTS = [
        AccountErasureProjectNoteKeeper::AGENT_TYPE => [AgentRegistryKey::WORKER => AccountErasureProjectNoteKeeper::class],
    ];
}

/** Project B, first mistake: the keeper's type is not registered at all. */
abstract class AccountErasureProjectUnlistedHilos extends AccountErasureProjectHilos
{
    public const array AGENTS = [];
}

/** Project B, second mistake: the keeper is registered per person although it is one for everybody. */
abstract class AccountErasureProjectIndexedHilos extends AccountErasureProjectHilos
{
    public const array AGENTS = [
        AccountErasureProjectNoteKeeper::AGENT_TYPE => [
            AgentRegistryKey::WORKER => AccountErasureProjectNoteKeeper::class,
            AgentRegistryKey::INDEXED => true,
        ],
    ];
}

/** Project B, third mistake: the keeper's type runs a worker that does not erase its part. */
abstract class AccountErasureProjectPlainWorkerHilos extends AccountErasureProjectHilos
{
    public const array AGENTS = [
        AccountErasureProjectNoteKeeper::AGENT_TYPE => [AgentRegistryKey::WORKER => AccountErasureProjectPlainAgent::class],
    ];
}

/** The project's one owner, its notes keeper, and one collection it leaves out. */
abstract class AccountErasureProjectDirectory extends AccountDataOwnerDirectory
{
    public const string NOTES = 'erasure_notes';
    public const string TEMPLATES = 'erasureProjectTemplates';

    /** @return list<AccountDataOwner> The notes keeper, one agent for everybody */
    protected static function owners(): array
    {
        return [new AccountDataOwner(
            self::NOTES,
            [AccountErasureProjectNotes::COLLECTION_KEY],
            AccountErasureProjectNoteKeeper::AGENT_TYPE,
            false,
            self::hasNotes(...),
        )];
    }

    /** @return array<string, AccountErasureExemption> Note templates, which hold nothing personal */
    protected static function exempt(): array
    {
        return [self::TEMPLATES => AccountErasureExemption::NotPersonal];
    }

    /**
     * @param int $userId Account asked about
     * @return bool Whether the account still has notes
     */
    private static function hasNotes(int $userId): bool
    {
        $notes = Hilos::$db->getObjectCollection(AccountErasureProjectNotes::COLLECTION_KEY);

        return $notes !== null && $notes->countByColumn(AccountErasureProjectNote::user_id, $userId) > 0;
    }
}

/** The notes keeper: deletes an account's notes through its collection and names the files they pointed at. */
final class AccountErasureProjectNoteKeeper extends AbstractAgent implements AccountErasureProjectOwner
{
    public const string ACCOUNT_OPERATION_SIGNAL = 'accounterasureprojectnotekeeper_account_operation_step';
    public const array AGENT_SIGNALS = [self::ACCOUNT_OPERATION_SIGNAL => AccountOperationAskSignalData::class];

    public const string AGENT_TYPE = 'erasure_note_keeper';
    public const string FAMILY = 'notes';
    public const array OWNS_DB = [AccountErasureProjectNotes::COLLECTION_KEY => [TruthSourceOperation::Remove]];
    public const array OWNS_DB_SET = [HilosDbContext::accountOperationResults => [TruthSourceOperation::Add]];
    public const array READS_DB = [HilosDbContext::accountOperationAssignments, HilosDbContext::accountOperations];

    /** @var list<int> Accounts of the parts asked of the keeper, in order */
    public array $parts = [];
    /** Failure thrown after the notes are deleted, or null to answer. */
    public ?Throwable $failure = null;
    /** Work done after the notes are deleted and before the keeper answers, or null for none. */
    public ?Closure $afterErasing = null;

    /**
     * @param string $collection Declared result collection
     * @return string The keeper's agent id
     */
    public function ownedDbSetKey(string $collection): string
    {
        return $this->getId();
    }

    /**
     * @param AccountErasurePart $part Operation and account to erase
     * @return AccountErasure Notes deleted and the files they pointed at
     */
    public function eraseAccountPart(AccountErasurePart $part): AccountErasure
    {
        $this->parts[] = $part->userId;
        $notes = Hilos::$db->getObjectCollection(AccountErasureProjectNotes::COLLECTION_KEY)
            ?? throw new LogicException('The notes are not mounted');
        $fileIds = [];
        $keys = iterator_to_array($notes->loadByColumn(AccountErasureProjectNote::user_id, $part->userId), false);
        foreach ($keys as $key) {
            $note = $notes[$key];
            if ($note->fileId !== null) {
                $fileIds[] = $note->fileId;
            }
            $note->delete();
        }
        if ($this->afterErasing !== null) {
            ($this->afterErasing)();
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new AccountErasure([self::FAMILY => count($keys)], $fileIds);
    }

    /**
     * @param AgentSignalData $data Wrapped account operation step
     * @param string $sender Sending coordinator, unused
     * @param string $name Declared step name
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case self::ACCOUNT_OPERATION_SIGNAL:
                $ask = $data->data;
                if (!$ask instanceof AccountOperationAskSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, AccountOperationAskSignalData::class, $ask);
                }
                $this->sendToAgent(HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER, AccountOperationAsks::answer(
                    $ask,
                    fn (AccountOperationAssignment $assignment): AccountOperationResult =>
                        AccountErasureProjectStep::run($this, $assignment->id),
                    $this->getId(),
                ));

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /** Nothing to release. */
    public function onStop(): void
    {
    }
}

/** Another owner with its own result set, which no assignment of the keeper is written to. */
final class AccountErasureProjectStrayOwner extends AbstractAgent implements AccountErasureProjectOwner
{
    public const string ACCOUNT_OPERATION_SIGNAL = 'accounterasureprojectstrayowner_account_operation_step';
    public const array AGENT_SIGNALS = [self::ACCOUNT_OPERATION_SIGNAL => AccountOperationAskSignalData::class];

    public const string AGENT_TYPE = 'erasure_stray_keeper';
    public const array OWNS_DB_SET = [HilosDbContext::accountOperationResults => [TruthSourceOperation::Add]];
    public const array READS_DB = [HilosDbContext::accountOperationAssignments, HilosDbContext::accountOperations];

    /** @var list<int> Accounts of the parts asked of it, in order */
    public array $parts = [];

    /**
     * @param string $collection Declared result collection
     * @return string Its agent id
     */
    public function ownedDbSetKey(string $collection): string
    {
        return $this->getId();
    }

    /**
     * @param AccountErasurePart $part Operation and account to erase
     * @return AccountErasure Nothing erased
     */
    public function eraseAccountPart(AccountErasurePart $part): AccountErasure
    {
        $this->parts[] = $part->userId;

        return new AccountErasure([], []);
    }

    /**
     * @param AgentSignalData $data Wrapped account operation step
     * @param string $sender Sending coordinator, unused
     * @param string $name Declared step name
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case self::ACCOUNT_OPERATION_SIGNAL:
                $ask = $data->data;
                if (!$ask instanceof AccountOperationAskSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, AccountOperationAskSignalData::class, $ask);
                }
                $this->sendToAgent(HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER, AccountOperationAsks::answer(
                    $ask,
                    fn (AccountOperationAssignment $assignment): AccountOperationResult =>
                        AccountErasureProjectStep::run($this, $assignment->id),
                    $this->getId(),
                ));

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /** Nothing to release. */
    public function onStop(): void
    {
    }
}

/** An agent registered under the keeper's type that does not erase its part. */
final class AccountErasureProjectPlainAgent extends AbstractAgent
{
    /** Nothing to release. */
    public function onStop(): void
    {
    }
}

/** A project note of one account, which may point at a registry file. */
final class AccountErasureProjectNote extends Entity
{
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const string file_id = 'file_id';

    public const string _table = 'hilos_fw_test_erasure_project_note';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::user_id, self::file_id];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::file_id => PhpType::INTEGER->value,
    ];

    public ?int $id = null;
    public int $user_id;
    public ?int $file_id = null;
}

/**
 * Stored project note.
 *
 * @extends Object_<AccountErasureProjectNote>
 * @property-read ?int $fileId
 */
final class AccountErasureProjectNoteObject extends Object_
{
    public const string ENTITY_CLASS = AccountErasureProjectNote::class;
    public const string fileId = 'fileId';

    /**
     * @param string $property Stored field name
     * @return mixed Stored value
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::fileId => $this->entity->file_id,
            default => parent::__get($property),
        };
    }
}

/**
 * @extends Objects<AccountErasureProjectNoteObject>
 */
final class AccountErasureProjectNotes extends Objects
{
    public const string OBJECT_CLASS = AccountErasureProjectNoteObject::class;
    public const string COLLECTION_KEY = 'erasureProjectNotes';
}
