<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\AccountOperation\AccountDataOwner;
use Hilos\Auth\AccountOperation\AccountDataOwnerDirectory;
use Hilos\Auth\AccountOperation\AccountErasurePart;
use Hilos\Auth\AccountOperation\AccountErasureProjectOwner;
use Hilos\Auth\AccountOperation\AccountErasureProjectStep;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountMergePart;
use Hilos\Auth\AccountOperation\AccountMergeProjectCensus;
use Hilos\Auth\AccountOperation\AccountMergeProjectOwner;
use Hilos\Auth\AccountOperation\AccountMergeProjectParticipant;
use Hilos\Auth\AccountOperation\AccountMergeProjectResult;
use Hilos\Auth\AccountOperation\AccountMergeProjectStep;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAsks;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationCircle;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationExpectedSet;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationParticipantStep;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Auth\AccountOperation\AccountOperationStepRefusal;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Topology\AgentSignalRouteRegistry;
use Hilos\Core\Topology\TopologyValidator;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
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
use Hilos\Hilos;
use Hilos\Users\AccountErasure;
use Hilos\Users\SecondFactorFate;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * A project owner taken into the merge's composition by its registration, and its part carried as
 * a participant step after the loser's fact.
 */
final class AccountMergeProjectParticipantIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = ['hilos_account_operation', 'hilos_account_operation_assignment', 'hilos_account_operation_result'];
    private const string COORDINATOR_ID = 'merge-project-coordinator';
    /** Assignment key of the owner's part in these tests; HIL-2000 chooses the real key. */
    private const string PART_KEY = 'merge_part:merge_notes';
    private const int PEER_INDEX = 2;
    private const int REQUEST_ID = 101;
    private const int SURVIVOR_ID = 7;
    private const int LOSER_ID = 9;
    private const int THIRD_ID = 11;

    private ?HilosDbContext $previousDb;
    private string $previousApp;
    private int $frameToken;
    private AccountMergeProjectNoteKeeper $keeper;
    private AccountMergeProjectStrayOwner $stray;
    private AccountMergeProjectLoser $loser;

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
            'CREATE TABLE `' . AccountMergeProjectNote::_table . '` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . '`user_id` INT UNSIGNED NOT NULL, PRIMARY KEY (`id`))',
        );
        Schema::reset();
        Schema::initialize();
        Hilos::$db = new AccountMergeProjectContext();
        Hilos::$db->configure();
        $this->keeper = new AccountMergeProjectNoteKeeper();
        $this->stray = new AccountMergeProjectStrayOwner();
        $this->loser = new AccountMergeProjectLoser(self::LOSER_ID);
        OwnershipDeclaration::claimAll($this->keeper);
        OwnershipDeclaration::claimAll($this->stray);
        OwnershipDeclaration::claimAll($this->loser);
        OwnershipDeclaration::claimDb(AccountMergeProjectCoordinator::class, self::COORDINATOR_ID);
        $this->frameToken = ExecutionContext::push(new ExecutionFrame(agentId: self::COORDINATOR_ID));
        AccountMergeProjectHilos::initBrowser();
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
            foreach ([$this->keeper->getId(), $this->stray->getId(), $this->loser->getId(), self::COORDINATOR_ID] as $agentId) {
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
            'notes_owner' => [AgentRegistryKey::WORKER => AccountMergeProjectNoteKeeper::class],
            'other_owner' => [AgentRegistryKey::WORKER => AccountMergeProjectErasingKeeper::class],
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

    /** The keeper registered for the merge is expected for the loser by its agent id, whether the loser has notes or not. */
    public function testRegisteredOwnerIsExpectedForTheLoserWithOrWithoutNotes(): void
    {
        $participant = new AccountMergeProjectParticipant(AccountMergeProjectDirectory::declaredOwners()[0]);
        self::assertSame(AccountMergeProjectDirectory::NOTES, $participant->key());
        self::assertSame([AccountMergeProjectNotes::COLLECTION_KEY], $participant->collections());

        self::assertSame(['expect:merge_notes:merge_note_keeper:9'], self::censusOf(self::LOSER_ID));
        self::seedNote(self::LOSER_ID);
        self::assertSame(['expect:merge_notes:merge_note_keeper:9'], self::censusOf(self::LOSER_ID));
    }

    /** Only the loser is asked: the survivor's notes put nothing of the survivor into the opened composition. */
    public function testOpeningAsksTheOwnerOnlyAboutTheLoser(): void
    {
        self::seedNote(self::SURVIVOR_ID);

        $composition = AccountOperationExpectedSet::of(AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, self::choice())->id);

        self::assertSame(['expect:merge_notes:merge_note_keeper:9'], self::keysOf($composition->expected()));
        self::assertSame([], $composition->waiting());
    }

    /** @return array<string, array{class-string<Hilos>}> Projects whose keeper is not registered for the merge, one mistake each */
    public static function wrongRegistrations(): array
    {
        return [
            'not in the agents' => [AccountMergeProjectUnlistedHilos::class],
            'registered with an index' => [AccountMergeProjectIndexedHilos::class],
            'declared per person' => [AccountMergeProjectPerPersonHilos::class],
            'worker erasing only' => [AccountMergeProjectErasingWorkerHilos::class],
        ];
    }

    /** @param class-string<Hilos> $project Project whose keeper is not registered for the merge */
    #[DataProvider('wrongRegistrations')]
    public function testOwnerNotRegisteredForTheMergeIsWaitedForOnlyWhileTheLoserHasNotes(string $project): void
    {
        $project::initBrowser();

        self::assertSame([], self::censusOf(self::LOSER_ID));
        self::seedNote(self::LOSER_ID);
        self::assertSame(['wait:merge_notes:9'], self::censusOf(self::LOSER_ID));
    }

    /** The preparations and the composition commit together, a failing census rolls all back, and an exact repeat adds nothing. */
    public function testOpeningCommitsThePreparationsAndTheCompositionInOneTransaction(): void
    {
        self::seedNote(self::LOSER_ID);
        AccountMergeProjectTwiceDeclaredHilos::initBrowser();
        $before = self::snapshot();

        try {
            AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, self::choice());
            self::fail('A merge opened over a malformed directory');
        } catch (InvalidTopologyException $failure) {
            self::assertStringContainsString('Account data owner [merge_notes] is declared twice', $failure->getMessage());
        }
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame($before, self::snapshot());

        AccountMergeProjectHilos::initBrowser();
        $operation = AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, self::choice());
        $opened = self::snapshot();
        $repeat = AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, self::choice());

        self::assertSame($operation->id, $repeat->id);
        self::assertSame($opened, self::snapshot());
        self::assertSame(
            [
                [AccountMergeStage::Preparing->value, AccountMergeDuty::PrepareSurvivor->value],
                [AccountMergeStage::Preparing->value, AccountMergeDuty::PrepareLoser->value],
                [AccountMergeStage::Preparing->value, 'expect:merge_notes:merge_note_keeper:9'],
            ],
            self::storedAssignments($operation->id),
        );
    }

    /** A keeper not registered for the merge leaves an open wait: the loser's notes stay, and no result closes the composition. */
    public function testOwnerNotRegisteredForTheMergeLeavesAWaitAndTheLosersNotes(): void
    {
        AccountMergeProjectUnlistedHilos::initBrowser();
        self::seedNote(self::LOSER_ID);
        self::seedNote(self::SURVIVOR_ID);

        $composition = AccountOperationExpectedSet::of(AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, self::choice())->id);

        self::assertSame([], $composition->expected());
        self::assertSame(['wait:merge_notes:9'], self::keysOf($composition->waiting()));
        self::assertSame([self::LOSER_ID, self::SURVIVOR_ID], self::storedNoteOwners());
        self::assertSame([], self::snapshot()['hilos_account_operation_result']);
    }

    /** Reconciling once the keeper is registered expects it at the current stage, keeps the wait and leaves nothing waiting. */
    public function testReconcileExpectsANewlyRegisteredOwnerAtTheCurrentStage(): void
    {
        AccountMergeProjectUnlistedHilos::initBrowser();
        self::seedNote(self::LOSER_ID);
        $operation = AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, self::choice());
        self::assertTrue($operation->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Go->value));
        AccountMergeProjectHilos::initBrowser();

        $reconciled = AccountMergeOpening::reconcile($operation);

        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame(['expect:merge_notes:merge_note_keeper:9'], self::keysOf($reconciled));
        self::assertSame(
            [
                [AccountMergeStage::Preparing->value, AccountMergeDuty::PrepareSurvivor->value],
                [AccountMergeStage::Preparing->value, AccountMergeDuty::PrepareLoser->value],
                [AccountMergeStage::Preparing->value, 'wait:merge_notes:9'],
                [AccountMergeStage::Go->value, 'expect:merge_notes:merge_note_keeper:9'],
            ],
            self::storedAssignments($operation->id),
        );
        self::assertSame([], AccountOperationExpectedSet::of($operation->id)->waiting());
        self::assertSame([], AccountMergeOpening::reconcile($operation));
    }

    /** Only an open merge is reconciled: an erasure and a finished merge are refused, and nothing is written. */
    public function testReconcileRefusesAnErasureAndAFinishedMerge(): void
    {
        AccountMergeProjectUnlistedHilos::initBrowser();
        self::seedNote(self::LOSER_ID);
        $finished = AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, self::choice());
        self::assertTrue($finished->actions->finish($finished->stage, AccountOperationOutcome::NotStarted));
        $erasure = Hilos::$db->accountOperations->actions->recordErasure(
            self::REQUEST_ID,
            self::THIRD_ID,
            AccountOperationCircle::fromArray([self::THIRD_ID], self::THIRD_ID),
        );
        AccountMergeProjectHilos::initBrowser();
        $before = self::snapshot();

        foreach ([$finished, $erasure] as $operation) {
            try {
                AccountMergeOpening::reconcile($operation);
                self::fail('An operation other than an open merge was reconciled');
            } catch (ValidationException $failure) {
                self::assertSame('Only an open merge can be reconciled.', $failure->getMessage());
            }
        }
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame($before, self::snapshot());
    }

    /** After the fact the owner moves only the loser's notes, with the pair the operation records, and the result commits with the move. */
    public function testStepMovesOnlyTheLosersRowsAndCommitsTheResultWithThem(): void
    {
        self::seedNote(self::LOSER_ID);
        self::seedNote(self::SURVIVOR_ID);
        self::seedNote(self::THIRD_ID);
        self::seedNote(self::LOSER_ID);
        $operation = $this->mergeAfterFact();
        $assignmentId = self::partAssignment($operation, self::LOSER_ID);

        $record = $this->asAgent($this->keeper, fn () => AccountMergeProjectStep::run($this->keeper, $assignmentId));

        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame([self::SURVIVOR_ID, self::SURVIVOR_ID, self::THIRD_ID, self::SURVIVOR_ID], self::storedNoteOwners());
        self::assertEquals([new AccountMergePart($operation->id, self::SURVIVOR_ID, self::LOSER_ID)], $this->keeper->parts);
        $results = self::storedResultsOf($assignmentId);
        self::assertCount(1, $results);
        self::assertSame($this->keeper->getId(), $results[0]['owner_agent_id']);
        self::assertSame('{"rows":{"notes":2}}', $results[0]['payload_json']);
        self::assertSame($record->payloadJson, $results[0]['payload_json']);
        self::assertSame(['notes' => 2], AccountMergeProjectResult::rowsMovedOf($record->payload));
    }

    /** An owner failing after its move leaves neither the move nor a result, and the retry commits once. */
    public function testOwnerFailureRollsBackTheMoveAndTheResultAndTheRetryCommitsOnce(): void
    {
        self::seedNote(self::LOSER_ID);
        self::seedNote(self::THIRD_ID);
        $assignmentId = self::partAssignment($this->mergeAfterFact(), self::LOSER_ID);
        $failure = new LogicException('The notes keeper failed after moving');
        $this->keeper->failure = $failure;

        try {
            $this->asAgent($this->keeper, fn () => AccountMergeProjectStep::run($this->keeper, $assignmentId));
            self::fail('A failed merge part returned a result');
        } catch (LogicException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame([self::LOSER_ID, self::THIRD_ID], self::storedNoteOwners());
        self::assertSame([], self::storedResultsOf($assignmentId));

        $this->keeper->failure = null;
        $record = $this->asAgent($this->keeper, fn () => AccountMergeProjectStep::run($this->keeper, $assignmentId));
        $repeat = $this->asAgent($this->keeper, fn () => AccountMergeProjectStep::run($this->keeper, $assignmentId));

        self::assertSame($record->id, $repeat->id);
        self::assertCount(2, $this->keeper->parts);
        self::assertSame([self::SURVIVOR_ID, self::THIRD_ID], self::storedNoteOwners());
        self::assertCount(1, self::storedResultsOf($assignmentId));
    }

    /** A repeat returns the saved result and leaves a note written since then alone: the owner is not asked again. */
    public function testRepeatReturnsTheSavedResultWithoutAskingTheOwner(): void
    {
        self::seedNote(self::LOSER_ID);
        $assignmentId = self::partAssignment($this->mergeAfterFact(), self::LOSER_ID);
        $record = $this->asAgent($this->keeper, fn () => AccountMergeProjectStep::run($this->keeper, $assignmentId));
        self::seedNote(self::LOSER_ID);

        $repeat = $this->asAgent($this->keeper, fn () => AccountMergeProjectStep::run($this->keeper, $assignmentId));

        self::assertSame($record->id, $repeat->id);
        self::assertSame('{"rows":{"notes":1}}', $repeat->payloadJson);
        self::assertCount(1, $this->keeper->parts);
        self::assertSame([self::SURVIVOR_ID, self::LOSER_ID], self::storedNoteOwners());
        self::assertCount(1, self::storedResultsOf($assignmentId));
    }

    /** Another owner taking the keeper's assignment is refused before any work, and the notes stay. */
    public function testAnotherOwnerIsRefusedTheAssignmentBeforeWork(): void
    {
        self::seedNote(self::LOSER_ID);
        $assignmentId = self::partAssignment($this->mergeAfterFact(), self::LOSER_ID);

        try {
            $this->asAgent($this->stray, fn () => AccountMergeProjectStep::run($this->stray, $assignmentId));
            self::fail('Another owner carried the keeper\'s part');
        } catch (AccountOperationStepRefusedException $failure) {
            self::assertSame(AccountOperationStepRefusal::ForeignAssignment, $failure->reason);
        }
        self::assertSame([], $this->stray->parts);
        self::assertSame([], $this->keeper->parts);
        self::assertSame([self::LOSER_ID], self::storedNoteOwners());
        self::assertSame([], self::storedResultsOf($assignmentId));
    }

    /** An assignment of an erasure is refused before any work, and nothing is written. */
    public function testAssignmentOfAnErasureIsRefusedAndWritesNothing(): void
    {
        self::seedNote(self::LOSER_ID);
        $erasure = Hilos::$db->accountOperations->actions->recordErasure(
            self::REQUEST_ID,
            self::LOSER_ID,
            AccountOperationCircle::fromArray([self::LOSER_ID], self::LOSER_ID),
        );

        $this->assertRefusedBeforeWork(self::partAssignment($erasure, self::LOSER_ID), 'An account merge part must belong to a merge.');
    }

    /** An assignment naming the survivor rather than the loser is refused before any work, and nothing is written. */
    public function testAssignmentOfTheSurvivorIsRefusedAndWritesNothing(): void
    {
        self::seedNote(self::LOSER_ID);
        self::seedNote(self::SURVIVOR_ID);

        $this->assertRefusedBeforeWork(
            self::partAssignment($this->mergeAfterFact(), self::SURVIVOR_ID),
            'An account merge part must name the loser of its merge.',
        );
    }

    /** An assignment at the go stage before the loser's give result is refused before any work, and nothing is written. */
    public function testAssignmentBeforeTheLosersFactIsRefusedAndWritesNothing(): void
    {
        self::seedNote(self::LOSER_ID);
        $operation = AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, self::choice());
        self::giveAssignment($operation);

        $this->assertRefusedBeforeWork(
            self::partAssignment($operation, self::LOSER_ID),
            'An account merge part waits for the loser to give its rows away.',
        );
    }

    /**
     * @param int $assignmentId Keeper's assignment the step must refuse
     * @param string $message Refusal the step must give before any work
     */
    private function assertRefusedBeforeWork(int $assignmentId, string $message): void
    {
        $before = self::snapshot();

        try {
            $this->asAgent($this->keeper, fn () => AccountMergeProjectStep::run($this->keeper, $assignmentId));
            self::fail('A refused merge part was carried');
        } catch (ValidationException $failure) {
            self::assertSame($message, $failure->getMessage());
        }
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame([], $this->keeper->parts);
        self::assertSame($before, self::snapshot());
    }

    /** @return AccountOperation Merge at the go stage whose loser has given its rows away */
    private function mergeAfterFact(): AccountOperation
    {
        $operation = AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, self::choice());
        $giveId = self::giveAssignment($operation);
        $this->asAgent($this->loser, static fn () => AccountOperationParticipantStep::run(
            $giveId,
            static fn () => AccountOperationResultPayload::fromJson('{"transferred":true}'),
        ));
        self::assertNotNull($operation->mergeFact());

        return $operation;
    }

    /**
     * @param AccountOperation $operation Open preparing merge
     * @return int Id of the loser's give obligation at the go stage, written by the session holder
     */
    private static function giveAssignment(AccountOperation $operation): int
    {
        self::assertTrue($operation->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Go->value));

        return Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            AccountMergeStage::Go->value,
            AccountMergeDuty::Give->value,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)self::LOSER_ID),
            self::LOSER_ID,
            AccountOperationAssignmentPayload::fromJson('{}'),
        )->id;
    }

    /**
     * @param AccountOperation $operation Operation the keeper is asked to carry a part of
     * @param int $userId Account the assignment names
     * @return int Id of the keeper's assignment at the operation's current stage, written by the session holder
     */
    private static function partAssignment(AccountOperation $operation, int $userId): int
    {
        return Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            $operation->stage,
            self::PART_KEY,
            new AccountOperationOwnerAddress(AccountMergeProjectNoteKeeper::AGENT_TYPE),
            $userId,
            AccountOperationAssignmentPayload::fromJson('{}'),
        )->id;
    }

    /** @return AccountMergeChoice Complete immutable merge choice */
    private static function choice(): AccountMergeChoice
    {
        return new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, true, false);
    }

    /**
     * @param int $loserUserId Loser asked about
     * @return list<string> Assignment keys the project census expects about the loser, in directory order
     */
    private static function censusOf(int $loserUserId): array
    {
        return array_map(
            static fn (AccountOperationExpectation $expectation): string => $expectation->assignmentKey(),
            AccountMergeProjectCensus::of($loserUserId),
        );
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
     * @param int $operationId Operation whose assignments are read
     * @return list<list<string>> Stage and key of each stored assignment in id order, as a separate connection sees them
     */
    private static function storedAssignments(int $operationId): array
    {
        return array_map(
            static fn (array $row): array => [$row['stage'], $row['assignment_key']],
            Database::sql(
                'SELECT `stage`, `assignment_key` FROM `hilos_account_operation_assignment` WHERE `operation_id` = ? ORDER BY `id`',
                [$operationId],
                index: self::PEER_INDEX,
            )->rows(),
        );
    }

    /** @param int $userId Account the note belongs to */
    private static function seedNote(int $userId): void
    {
        Database::sqlRun('INSERT INTO `' . AccountMergeProjectNote::_table . '` (`user_id`) VALUES (?)', [$userId]);
    }

    /** @return list<int> Accounts of the stored notes in id order, as a separate connection sees them */
    private static function storedNoteOwners(): array
    {
        return array_map(
            static fn (array $row): int => (int)$row['user_id'],
            Database::sql('SELECT `user_id` FROM `' . AccountMergeProjectNote::_table . '` ORDER BY `id`', index: self::PEER_INDEX)->rows(),
        );
    }

    /**
     * @param int $assignmentId Assignment whose results are read
     * @return list<array<string, mixed>> Stored results of the assignment in id order, as a separate connection sees them
     */
    private static function storedResultsOf(int $assignmentId): array
    {
        return Database::sql(
            'SELECT `owner_agent_id`, `payload_json` FROM `hilos_account_operation_result` WHERE `assignment_id` = ? ORDER BY `id`',
            [$assignmentId],
            index: self::PEER_INDEX,
        )->rows();
    }

    /** @return array<string, list<array<string, mixed>>> Operation tables and the notes in primary id order */
    private static function snapshot(): array
    {
        $snapshot = [];
        foreach ([...self::TABLES, AccountMergeProjectNote::_table] as $table) {
            $snapshot[$table] = Database::sql('SELECT * FROM `' . $table . '` ORDER BY `id`', index: self::PEER_INDEX)->rows();
        }

        return $snapshot;
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
        Database::sqlRun('DROP TABLE IF EXISTS `' . AccountMergeProjectNote::_table . '`');
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
final class AccountMergeProjectContext extends HilosDbContext
{
    /** Mounts the framework's collections, then the project's notes. */
    public function configure(): void
    {
        parent::configure();
        $this->mountObjectCollection(AccountMergeProjectNotes::class, Objects::LAZY_STRATEGY_KEY);
    }
}

/** The session holder, which opens the merge and writes its assignments. */
final class AccountMergeProjectCoordinator extends AbstractSessionsLibraryAgent
{
}

/** The merge loser, which records its give result as the merge fact. */
final class AccountMergeProjectLoser extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_USER;
    public const array OWNS_DB_SET = [HilosDbContext::accountOperationResults => [TruthSourceOperation::Add]];
    public const array READS_DB = [HilosDbContext::accountOperations, HilosDbContext::accountOperationAssignments];

    /** @param int $userId Account represented by this participant */
    public function __construct(int $userId)
    {
        $this->agentIndex = (string)$userId;
    }

    /**
     * @param string $collection Declared result collection
     * @return string Participant's canonical durable address
     */
    public function ownedDbSetKey(string $collection): string
    {
        return $this->getId();
    }

    /** Nothing to release. */
    public function onStop(): void
    {
    }
}

/** Project A: the notes keeper registered as one agent for everybody. */
abstract class AccountMergeProjectHilos extends Hilos
{
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = AccountMergeProjectDirectory::class;

    public const array AGENTS = [
        AccountMergeProjectNoteKeeper::AGENT_TYPE => [AgentRegistryKey::WORKER => AccountMergeProjectNoteKeeper::class],
    ];
}

/** Project B, first mistake: the keeper's type is not registered at all. */
abstract class AccountMergeProjectUnlistedHilos extends AccountMergeProjectHilos
{
    public const array AGENTS = [];
}

/** Project B, second mistake: the keeper's type is registered per person although the keeper is one for everybody. */
abstract class AccountMergeProjectIndexedHilos extends AccountMergeProjectHilos
{
    public const array AGENTS = [
        AccountMergeProjectNoteKeeper::AGENT_TYPE => [
            AgentRegistryKey::WORKER => AccountMergeProjectNoteKeeper::class,
            AgentRegistryKey::INDEXED => true,
        ],
    ];
}

/** Project B, third mistake: the keeper is declared per person, which no merge owner may be. */
abstract class AccountMergeProjectPerPersonHilos extends AccountMergeProjectHilos
{
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = AccountMergeProjectPerPersonDirectory::class;
}

/** Project B, fourth mistake: the keeper's type runs a worker that erases its part but does not move it. */
abstract class AccountMergeProjectErasingWorkerHilos extends AccountMergeProjectHilos
{
    public const array AGENTS = [
        AccountMergeProjectNoteKeeper::AGENT_TYPE => [AgentRegistryKey::WORKER => AccountMergeProjectErasingKeeper::class],
    ];
}

/** A project whose directory declares the keeper twice. */
abstract class AccountMergeProjectTwiceDeclaredHilos extends AccountMergeProjectHilos
{
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = AccountMergeProjectTwiceDirectory::class;
}

/** The project's one owner, its notes keeper. */
abstract class AccountMergeProjectDirectory extends AccountDataOwnerDirectory
{
    public const string NOTES = 'merge_notes';

    /** @return list<AccountDataOwner> The notes keeper, one agent for everybody */
    protected static function owners(): array
    {
        return [new AccountDataOwner(
            self::NOTES,
            [AccountMergeProjectNotes::COLLECTION_KEY],
            AccountMergeProjectNoteKeeper::AGENT_TYPE,
            false,
            self::hasNotes(...),
        )];
    }

    /**
     * @param int $userId Account asked about
     * @return bool Whether the account still has notes
     */
    private static function hasNotes(int $userId): bool
    {
        $notes = Hilos::$db->getObjectCollection(AccountMergeProjectNotes::COLLECTION_KEY);

        return $notes !== null && $notes->countByColumn(AccountMergeProjectNote::user_id, $userId) > 0;
    }
}

/** The notes keeper declared per person. */
abstract class AccountMergeProjectPerPersonDirectory extends AccountMergeProjectDirectory
{
    /** @return list<AccountDataOwner> The notes keeper, indexed by the account id */
    protected static function owners(): array
    {
        return array_map(
            static fn (AccountDataOwner $owner): AccountDataOwner => new AccountDataOwner(
                $owner->key,
                $owner->collections,
                $owner->ownerType,
                true,
                $owner->hasRowsOf,
            ),
            parent::owners(),
        );
    }
}

/** The notes keeper declared twice, a mistake the directory names. */
abstract class AccountMergeProjectTwiceDirectory extends AccountMergeProjectDirectory
{
    /** @return list<AccountDataOwner> The notes keeper, twice */
    protected static function owners(): array
    {
        return [...parent::owners(), ...parent::owners()];
    }
}

/**
 * The notes keeper, one agent for the whole notes table: moves the loser's notes to the survivor
 * through its collection, and erases an account's notes as an erasure owner of its own.
 */
final class AccountMergeProjectNoteKeeper extends AbstractAgent implements AccountMergeProjectOwner, AccountErasureProjectOwner
{
    public const string ACCOUNT_OPERATION_SIGNAL = 'accountmergeprojectnotekeeper_account_operation_step';
    public const array AGENT_SIGNALS = [self::ACCOUNT_OPERATION_SIGNAL => AccountOperationAskSignalData::class];

    public const string AGENT_TYPE = 'merge_note_keeper';
    public const string FAMILY = 'notes';
    public const array OWNS_DB = [AccountMergeProjectNotes::COLLECTION_KEY => [TruthSourceOperation::Update, TruthSourceOperation::Remove]];
    public const array OWNS_DB_SET = [HilosDbContext::accountOperationResults => [TruthSourceOperation::Add]];
    public const array READS_DB = [HilosDbContext::accountOperationAssignments, HilosDbContext::accountOperations];

    /** @var list<AccountMergePart> Merge parts asked of the keeper, in order */
    public array $parts = [];
    /** Failure thrown after the notes are moved, or null to answer. */
    public ?Throwable $failure = null;

    /**
     * @param string $collection Declared result collection
     * @return string The keeper's agent id
     */
    public function ownedDbSetKey(string $collection): string
    {
        return $this->getId();
    }

    /**
     * @param AccountMergePart $part Operation, survivor and loser of the merge
     * @return array<string, int> Notes moved from the loser to the survivor
     */
    public function mergeAccountPart(AccountMergePart $part): array
    {
        $this->parts[] = $part;
        $notes = self::notes();
        $keys = iterator_to_array($notes->loadByColumn(AccountMergeProjectNote::user_id, $part->loserUserId), false);
        foreach ($keys as $key) {
            $note = $notes[$key];
            $note->userId = $part->survivorUserId;
            $note->sync();
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return [self::FAMILY => count($keys)];
    }

    /**
     * @param AccountErasurePart $part Operation and account to erase
     * @return AccountErasure Notes deleted, which point at no files
     */
    public function eraseAccountPart(AccountErasurePart $part): AccountErasure
    {
        $notes = self::notes();
        $keys = iterator_to_array($notes->loadByColumn(AccountMergeProjectNote::user_id, $part->userId), false);
        foreach ($keys as $key) {
            $notes[$key]->delete();
        }

        return new AccountErasure([self::FAMILY => count($keys)], []);
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

    /** @return AccountMergeProjectNotes The mounted notes */
    private static function notes(): AccountMergeProjectNotes
    {
        $notes = Hilos::$db->getObjectCollection(AccountMergeProjectNotes::COLLECTION_KEY);

        return $notes instanceof AccountMergeProjectNotes ? $notes : throw new LogicException('The notes are not mounted');
    }
}

/** Another owner with its own result set, which no assignment of the keeper is written to. */
final class AccountMergeProjectStrayOwner extends AbstractAgent implements AccountMergeProjectOwner
{
    public const string AGENT_TYPE = 'merge_stray_keeper';
    public const array OWNS_DB_SET = [HilosDbContext::accountOperationResults => [TruthSourceOperation::Add]];
    public const array READS_DB = [HilosDbContext::accountOperationAssignments, HilosDbContext::accountOperations];

    /** @var list<AccountMergePart> Merge parts asked of it, in order */
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
     * @param AccountMergePart $part Operation, survivor and loser of the merge
     * @return array<string, int> Nothing moved
     */
    public function mergeAccountPart(AccountMergePart $part): array
    {
        $this->parts[] = $part;

        return [];
    }

    /** Nothing to release. */
    public function onStop(): void
    {
    }
}

/** An agent registered under the keeper's type that erases its part and does not move it. */
final class AccountMergeProjectErasingKeeper extends AbstractAgent implements AccountErasureProjectOwner
{
    public const string ACCOUNT_OPERATION_SIGNAL = 'accountmergeprojecterasingkeeper_account_operation_step';
    public const array AGENT_SIGNALS = [self::ACCOUNT_OPERATION_SIGNAL => AccountOperationAskSignalData::class];

    /**
     * @param AccountErasurePart $part Operation and account to erase
     * @return AccountErasure Nothing erased
     */
    public function eraseAccountPart(AccountErasurePart $part): AccountErasure
    {
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

/** A project note of one account. */
final class AccountMergeProjectNote extends Entity
{
    public const string id = 'id';
    public const string user_id = 'user_id';

    public const string _table = 'hilos_fw_test_merge_project_note';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::user_id];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
    ];

    public ?int $id = null;
    public int $user_id;
}

/**
 * Stored project note, whose account the keeper may change.
 *
 * @extends Object_<AccountMergeProjectNote>
 * @property int $userId
 */
final class AccountMergeProjectNoteObject extends Object_
{
    public const string ENTITY_CLASS = AccountMergeProjectNote::class;
    public const string userId = 'userId';

    /**
     * @param string $property Stored field name
     * @return mixed Stored value
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::userId => $this->entity->user_id,
            default => parent::__get($property),
        };
    }

    /**
     * @param string $property Stored field name
     * @param mixed $value New value
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = (int)$value,
            default => parent::__set($property, $value),
        };
    }
}

/**
 * @extends Objects<AccountMergeProjectNoteObject>
 */
final class AccountMergeProjectNotes extends Objects
{
    public const string OBJECT_CLASS = AccountMergeProjectNoteObject::class;
    public const string COLLECTION_KEY = 'mergeProjectNotes';
}
