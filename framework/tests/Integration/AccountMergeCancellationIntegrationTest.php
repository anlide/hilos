<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\AccountOperation\AccountMergeCancellation;
use Hilos\Auth\AccountOperation\AccountMergeCancellationState;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountMergePreparation;
use Hilos\Auth\AccountOperation\AccountMergePreparationResult;
use Hilos\Auth\AccountOperation\AccountMergeRelease;
use Hilos\Auth\AccountOperation\AccountMergeReleaseResult;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAnswerKind;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationCircle;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationParticipantStep;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
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
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\FrameworkExtension;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\View\Collection\AccountOperations;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\Users\Agent\AbstractUserAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/** Real commits through personal owners beside the holder and storage concurrency fixtures. */
final class AccountMergeCancellationIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames {
        asPerson as private asPersonalOwner;
    }

    private const array TABLES = ['hilos_account_operation', 'hilos_account_operation_assignment', 'hilos_account_operation_result'];
    private const int SURVIVOR_ID = 7;
    private const int LOSER_ID = 9;
    private const int PEER_INDEX = 2;
    private const string HOLDER_ID = 'merge-cancellation-holder';

    private ?HilosDbContext $previousDb;
    private int $frameToken;
    private AbstractUserAgent $loser;
    private AbstractUserAgent $survivor;
    private ?SignalRouter $previousRouter;

    /** Raises real carriers and separate holder and participant claims. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->stubs(true);
        $this->stubs(false);
        $this->mountFreshContext();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        OwnershipDeclaration::claimDb(MergeCancellationHolder::class, self::HOLDER_ID);
        foreach ([self::LOSER_ID, self::SURVIVOR_ID] as $userId) {
            self::seedIdentity($userId, IdentityType::PASSWORD, "person{$userId}@example.test");
        }
        $this->loser = $this->personAgent(self::LOSER_ID);
        $this->survivor = $this->personAgent(self::SURVIVOR_ID);
        $this->frameToken = ExecutionContext::push(new ExecutionFrame(agentId: self::HOLDER_ID));
    }

    /** Closes transactions before dropping the fixture and releases every claim. */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            $this->stubs(true);
        } finally {
            ExecutionContext::pop($this->frameToken);
            $this->releasePersonAgents();
            Hilos::$sr = $this->previousRouter;
            foreach ([self::HOLDER_ID] as $id) {
                TruthSourceRegistry::unregisterAgent($id);
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($id));
            }
            SourceChangeBus::reset();
            Hilos::$db = $this->previousDb;
            parent::tearDown();
        }
    }

    /**
     * @param string $userId Personal agent index
     * @return AbstractUserAgent Production owner with an observer at the existing send boundary
     */
    protected function newPersonAgent(string $userId): AbstractUserAgent
    {
        return new MergeCancellationAnswerPerson($userId);
    }

    /** @return array<string, array{string}> Every supported open stage */
    public static function cancellableStages(): array
    {
        return ['preparing' => ['preparing'], 'go' => ['go'], 'cancelling' => ['cancelling']];
    }

    /** @param string $stage Current open merge stage */
    #[DataProvider('cancellableStages')]
    public function testRequestRecordsExactlyTwoObligationsAndRepeatsTheirIds(string $stage): void
    {
        $operation = $this->open();
        if ($stage !== $operation->stage) {
            self::assertTrue($operation->actions->advance($operation->stage, $stage));
        }
        self::assertSame(AccountMergeCancellationState::WaitingOwners, AccountMergeCancellation::request($operation));
        self::assertSame(AccountMergeStage::Cancelling->value, $operation->stage);
        $ids = [];
        foreach ([[$this->loser, AccountMergeDuty::ReleaseLoser], [$this->survivor, AccountMergeDuty::ReleaseSurvivor]] as [$person, $duty]) {
            $assignment = $this->assignment($operation, $duty, AccountMergeStage::Cancelling);
            $ids[] = $assignment->id;
            self::assertSame($person->getId(), $assignment->owner->agentId());
            self::assertSame((int)$person->getIndex(), $assignment->userId);
            self::assertSame('{}', $assignment->payloadJson);
        }
        $before = $this->snapshot();
        self::assertSame(AccountMergeCancellationState::WaitingOwners, AccountMergeCancellation::request($operation));
        self::assertSame(AccountMergeCancellationState::WaitingOwners, AccountMergeCancellation::advance($operation));
        self::assertSame($before, $this->snapshot());
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotContains($signal->signalName->getName(), [
                HilosSignalConstants::HILOS_USER_ACCOUNT_OPERATION_STEP, HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER,
            ]);
        }
        self::assertNotSame($ids[0], $ids[1]);
    }

    /** @return iterable<string, array{int, bool, bool}> Preparation counts, release order and a refused preparation */
    public static function releaseOrders(): iterable
    {
        foreach ([0, 1, 2] as $prepared) {
            yield "{$prepared} loser first" => [$prepared, true, false];
            yield "{$prepared} survivor first" => [$prepared, false, false];
        }
        yield 'refused survivor, unanswered loser' => [1, true, true];
        yield 'refused survivor, reverse release' => [1, false, true];
    }

    /**
     * @param int $prepared Number of saved preparations
     * @param bool $loserFirst Which participant releases first
     * @param bool $refused Whether the first preparation was refused
     */
    #[DataProvider('releaseOrders')]
    public function testBothOwnerResultsAreRequiredAndPreparationRecordsStayImmutable(int $prepared, bool $loserFirst, bool $refused): void
    {
        $operation = $this->open(new AccountMergeChoice(null, null, $refused ? true : null, null));
        $business = $this->businessSnapshot();
        foreach (array_slice([$this->survivor, $this->loser], 0, $prepared) as $person) {
            $duty = $person === $this->loser ? AccountMergeDuty::PrepareLoser : AccountMergeDuty::PrepareSurvivor;
            $assignment = $this->assignment($operation, $duty);
            $result = $this->prepare($person, $assignment);
            self::assertSame(!$refused, AccountMergePreparationResult::of($result->payload)->isReserved());
            self::assertSame($refused ? null : $operation->id, $this->heldBy($person)?->id);
        }
        $preparations = Database::sql('SELECT * FROM `hilos_account_operation_result` ORDER BY `id`')->rows();
        $choice = $operation->mergeChoice->toJson();
        self::assertSame(AccountMergeCancellationState::WaitingOwners, AccountMergeCancellation::request($operation));
        $order = $loserFirst ? [$this->loser, $this->survivor] : [$this->survivor, $this->loser];
        $remaining = $this->heldBy($order[1]);
        $remainingReserved = !$refused && ($order[1] === $this->survivor ? $prepared >= 1 : $prepared === 2);
        self::assertSame($remainingReserved ? $operation->id : null, $remaining?->id);
        $first = $this->release($operation, $order[0]);
        self::assertNull($this->heldBy($order[0]));
        self::assertSame($remaining?->id, $this->heldBy($order[1])?->id);
        self::assertSame(AccountMergeCancellationState::WaitingOwners, AccountMergeCancellation::advance($operation));
        self::assertNull($operation->finishedAt);
        self::assertFalse(AccountMergeReleaseResult::forAccount($operation, (int)$order[1]->getIndex()));
        self::assertSame($business, $this->businessSnapshot());
        $before = $this->snapshot();
        self::assertSame(AccountMergeCancellationState::WaitingOwners, AccountMergeCancellation::request($operation));
        self::assertSame($first->id, $this->release($operation, $order[0])->id);
        self::assertSame($before, $this->snapshot());
        self::assertSame($business, $this->businessSnapshot());
        $second = $this->release($operation, $order[1]);
        self::assertSame(AccountMergeCancellationState::WaitingOwners, AccountMergeCancellation::request($operation));
        self::assertSame(AccountMergeCancellationState::NotStarted, AccountMergeCancellation::advance($operation));
        self::assertSame(AccountOperationOutcome::NotStarted->value, $operation->outcome);
        self::assertNotNull($operation->finishedAt);
        self::assertNull(Hilos::$db->accountOperations->openMergeOfLoser(self::LOSER_ID));
        self::assertSame(AccountMergeCancellationState::NotStarted, AccountMergeCancellation::request($operation));
        self::assertSame(AccountMergeCancellationState::NotStarted, AccountMergeCancellation::advance($operation));
        self::assertSame($second->id, $this->release($operation, $order[1])->id);
        self::assertSame($choice, $operation->mergeChoice->toJson());
        self::assertSame($preparations, Database::sql('SELECT r.* FROM `hilos_account_operation_result` r'
            . ' JOIN `hilos_account_operation_assignment` a ON a.`id` = r.`assignment_id` WHERE a.`stage` = ? ORDER BY r.`id`',
            [AccountMergeStage::Preparing->value])->rows());
        self::assertSame($business, $this->businessSnapshot());
        $new = $this->open();
        self::assertNotSame($operation->id, $new->id);
        $duty = $order[0] === $this->loser ? AccountMergeDuty::PrepareLoser : AccountMergeDuty::PrepareSurvivor;
        self::assertTrue(AccountMergePreparationResult::of($this->prepare($order[0], $this->assignment($new, $duty))->payload)->isReserved());
        self::assertSame($first->id, $this->release($operation, $order[0])->id);
        self::assertSame($new->id, $this->heldBy($order[0])?->id);
        self::assertSame($new->id, Hilos::$db->accountOperations->openMergeOfLoser(self::LOSER_ID)->id);
        $oldAssignment = $this->assignment($operation,
            $order[0] === $this->loser ? AccountMergeDuty::ReleaseLoser : AccountMergeDuty::ReleaseSurvivor, AccountMergeStage::Cancelling);
        $before = $this->snapshot();
        $this->assertRefused(AccountOperationStepRefusedException::class, fn () => $this->asPerson($order[1],
            static fn () => AccountMergeRelease::run($oldAssignment->id, (int)$order[1]->getIndex())));
        self::assertSame($before, $this->snapshot());
        self::assertSame($new->id, $this->heldBy($order[0])?->id);
    }

    /** A real participant commits the give fact before cancellation; no release obligation is added. */
    public function testCommittedGiveMakesRequestAndAdvanceTooLate(): void
    {
        $operation = $this->open();
        $business = $this->businessSnapshot();
        foreach ([$this->loser, $this->survivor] as $person) {
            $this->prepare($person, $this->assignment($operation,
                $person === $this->loser ? AccountMergeDuty::PrepareLoser : AccountMergeDuty::PrepareSurvivor));
        }
        $give = $this->giveAssignment($operation);
        $this->give($give);
        $before = $this->snapshot();
        self::assertSame(AccountMergeCancellationState::TooLate, AccountMergeCancellation::request($operation));
        self::assertSame(AccountMergeCancellationState::TooLate, AccountMergeCancellation::advance($operation));
        self::assertSame(AccountMergeStage::Go->value, $operation->stage);
        self::assertNull($operation->finishedAt);
        self::assertSame($before, $this->snapshot());
        self::assertSame($operation->id, $this->heldBy($this->loser)?->id);
        self::assertSame($operation->id, $this->heldBy($this->survivor)?->id);
        self::assertSame($business, $this->businessSnapshot());
    }

    /** @return array<string, array{bool}> The shared gate's work commits or rolls back */
    public static function giveOutcomes(): array
    {
        return ['commit' => [true], 'rollback' => [false]];
    }

    /** @param bool $commit Whether the real give participant finishes its transaction */
    #[DataProvider('giveOutcomes')]
    public function testSharedStorageGateExcludesExclusiveCancellationUntilCommitOrRollback(bool $commit): void
    {
        $operation = $this->open();
        $give = $this->giveAssignment($operation);
        $this->connectPeer();
        try {
            $this->asPerson($this->loser, function () use ($give, $commit): void {
                AccountOperationParticipantStep::run($give->id, function () use ($give, $commit): AccountOperationResultPayload {
                    $current = Hilos::$db->accountOperations->fresh($give->operationId);
                    self::assertSame(AccountMergeStage::Go->value, $current->stage);
                    $this->assertNowaitRefused('FOR UPDATE NOWAIT', $give->operationId);
                    if (!$commit) {
                        throw new RuntimeException('Rollback give work');
                    }
                    return AccountOperationResultPayload::fromJson('{}');
                });
            });
        } catch (RuntimeException $failure) {
            self::assertFalse($commit);
            self::assertSame('Rollback give work', $failure->getMessage());
        }
        Database::sql('SELECT `id` FROM `hilos_account_operation` WHERE `id` = ? FOR UPDATE NOWAIT',
            [$operation->id], index: self::PEER_INDEX);
        self::assertSame($commit ? AccountMergeCancellationState::TooLate : AccountMergeCancellationState::WaitingOwners,
            AccountMergeCancellation::request($operation));
        self::assertSame($commit, $operation->mergeFact() !== null);
    }

    /** Cancellation's exclusive lock rejects a shared gate, and a cached go cannot admit late work. */
    public function testExclusiveCancellationExcludesSharedGateAndRefreshRefusesCachedGo(): void
    {
        $operation = $this->open();
        $give = $this->giveAssignment($operation);
        $oldContext = Hilos::$db;
        $this->connectPeer();
        $this->mountFreshContext();
        $current = Hilos::$db->accountOperations[$operation->id];
        Database::transaction(DatabaseNames::PRIMARY, function () use ($current): void {
            self::assertTrue($current->actions->beginMergeCancellation());
            $this->assertNowaitRefused('LOCK IN SHARE MODE NOWAIT', $current->id);
        });
        $stored = Database::sql('SELECT `stage` FROM `hilos_account_operation` WHERE `id` = ?',
            [$operation->id], index: self::PEER_INDEX)->rows();
        self::assertSame(AccountMergeStage::Cancelling->value, $stored[0]['stage']);
        Hilos::$db = $oldContext;
        self::assertSame(AccountMergeStage::Go->value, $operation->stage);
        $this->assertRefused(ValidationException::class, fn () => $this->give($give));
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($give->id));
        // Rollback restores the cached input; a subsequent current read observes cancellation again.
        self::assertSame(AccountMergeStage::Go->value, $operation->stage);
        self::assertSame($operation->id, Hilos::$db->accountOperations->fresh($operation->id)->id);
        self::assertSame(AccountMergeStage::Cancelling->value, $operation->stage);
        $this->assertRefused(AccountOperationStepRefusedException::class, fn () => $this->give($give));
    }

    /** Only this old-snapshot fixture uses peer SQL: the participant API cannot nest or cross an application transaction. */
    public function testCurrentFactReadSeesPeerCommitBeyondAnOldRepeatableReadSnapshot(): void
    {
        $operation = $this->open();
        $give = $this->giveAssignment($operation);
        $this->connectPeer();
        Database::transaction(DatabaseNames::PRIMARY, function () use ($operation, $give): void {
            self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
            Database::sql('INSERT INTO `hilos_account_operation_result` (`assignment_id`, `owner_agent_id`, `payload_json`, `created_at`)'
                . ' VALUES (?, ?, ?, ?)', [$give->id, $this->loser->getId(), '{}', 1234], index: self::PEER_INDEX);
            self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
            self::assertFalse($operation->actions->beginMergeCancellation());
            self::assertSame(AccountMergeStage::Go->value, $operation->stage);
        });
        self::assertSame(AccountMergeCancellationState::TooLate, AccountMergeCancellation::request($operation));
        self::assertNull(Hilos::$db->accountOperationAssignments->byIdentity(
            $operation->id, AccountMergeStage::Cancelling->value, AccountMergeDuty::ReleaseLoser->value));
    }

    /** A current facade read retains issued View identity, with its memory restored on transaction rollback. */
    public function testFreshFacadeKeepsIdentityAndRollsBackItsCurrentMemory(): void
    {
        $operation = $this->open();
        $this->connectPeer();
        Database::sql('UPDATE `hilos_account_operation` SET `stage` = ? WHERE `id` = ?',
            [AccountMergeStage::Go->value, $operation->id], index: self::PEER_INDEX);
        $this->assertRefused(RuntimeException::class, static fn () => Database::transaction(DatabaseNames::PRIMARY,
            static function () use ($operation): void {
                self::assertSame($operation, Hilos::$db->accountOperations->fresh($operation->id));
                self::assertSame(AccountMergeStage::Go->value, $operation->stage);
                throw new RuntimeException('Undo current memory');
            }));
        self::assertSame(AccountMergeStage::Preparing->value, $operation->stage);
        self::assertSame($operation->id, Hilos::$db->accountOperations->fresh($operation->id)->id);
        self::assertSame(AccountMergeStage::Go->value, $operation->stage);
        self::assertNull(Hilos::$db->accountOperations->fresh($operation->id + 1));
    }

    /** Assignment failure rolls back both the stage and the earlier side, including issued object memory. */
    public function testSecondAssignmentFailureRollsBackTheWholeRequestAndCanRetry(): void
    {
        $operation = $this->open();
        $before = $this->snapshot();
        Database::sqlRun("ALTER TABLE `hilos_account_operation_assignment` ADD CONSTRAINT `reject_second_release`"
            . " CHECK (`assignment_key` <> 'release_survivor')");
        try {
            $this->assertRefused(DatabaseException::class, static fn () => AccountMergeCancellation::request($operation));
            self::assertSame($before, $this->snapshot());
            self::assertSame(AccountMergeStage::Preparing->value, $operation->stage);
        } finally {
            Database::sqlRun('ALTER TABLE `hilos_account_operation_assignment` DROP CONSTRAINT `reject_second_release`');
        }
        self::assertSame(AccountMergeCancellationState::WaitingOwners, AccountMergeCancellation::request($operation));
    }

    /** A failed release records nothing; a failed finish leaves both committed releases available to its retry. */
    public function testReleaseRollbackAndFinishFailurePreserveDurableProgressForRetry(): void
    {
        $operation = $this->open();
        $business = $this->businessSnapshot();
        $this->prepare($this->loser, $this->assignment($operation, AccountMergeDuty::PrepareLoser));
        $this->prepare($this->survivor, $this->assignment($operation, AccountMergeDuty::PrepareSurvivor));
        AccountMergeCancellation::request($operation);
        $assignment = $this->assignment($operation, AccountMergeDuty::ReleaseLoser, AccountMergeStage::Cancelling);
        $before = $this->snapshot();
        Database::sqlRun('ALTER TABLE `hilos_account_operation_result` ADD CONSTRAINT `reject_release`'
            . ' CHECK (`assignment_id` <> ' . $assignment->id . ')');
        try {
            $this->assertRefused(DatabaseException::class, fn () => $this->release($operation, $this->loser));
        } finally {
            Database::sqlRun('ALTER TABLE `hilos_account_operation_result` DROP CONSTRAINT `reject_release`');
        }
        self::assertSame($before, $this->snapshot());
        self::assertFalse(AccountMergeReleaseResult::forAccount($operation, self::LOSER_ID));
        self::assertSame($operation->id, $this->heldBy($this->loser)?->id);
        self::assertSame($operation->id, $this->heldBy($this->survivor)?->id);
        $loserResult = $this->release($operation, $this->loser);
        $survivorResult = $this->release($operation, $this->survivor);
        $before = $this->snapshot();
        Database::sqlRun("ALTER TABLE `hilos_account_operation` ADD CONSTRAINT `reject_finish` CHECK (`outcome` IS NULL)");
        try {
            $this->assertRefused(DatabaseException::class, static fn () => AccountMergeCancellation::advance($operation));
            self::assertSame($before, $this->snapshot());
            self::assertSame(AccountMergeStage::Cancelling->value, $operation->stage);
            self::assertNull($operation->finishedAt);
            self::assertTrue(AccountMergeReleaseResult::forAccount($operation, self::LOSER_ID));
            self::assertTrue(AccountMergeReleaseResult::forAccount($operation, self::SURVIVOR_ID));
            self::assertSame($loserResult->id, $this->release($operation, $this->loser)->id);
            self::assertSame($survivorResult->id, $this->release($operation, $this->survivor)->id);
            self::assertSame(AccountMergeCancellationState::WaitingOwners, AccountMergeCancellation::request($operation));
            self::assertSame($before, $this->snapshot());
        } finally {
            Database::sqlRun('ALTER TABLE `hilos_account_operation` DROP CONSTRAINT `reject_finish`');
        }
        self::assertSame(AccountMergeCancellationState::NotStarted, AccountMergeCancellation::advance($operation));
        self::assertSame($before['hilos_account_operation_assignment'], $this->snapshot()['hilos_account_operation_assignment']);
        self::assertSame($before['hilos_account_operation_result'], $this->snapshot()['hilos_account_operation_result']);
        self::assertSame($business, $this->businessSnapshot());
    }

    /** @return array<string, array{bool}> Either owner's first answer can be lost */
    public static function lostAnswerOrders(): array
    {
        return ['loser first' => [true], 'survivor first' => [false]];
    }

    /**
     * Discards each real owner's answer; only committed results survive reconnects and drive completion.
     *
     * @param bool $loserFirst Which owner's answer is lost first
     */
    #[DataProvider('lostAnswerOrders')]
    public function testReconnectContinuesTheSameOperationAndReleaseIds(bool $loserFirst): void
    {
        $operation = $this->open();
        $business = $this->businessSnapshot();
        foreach ([$this->loser, $this->survivor] as $person) {
            $this->prepare($person, $this->assignment($operation,
                $person === $this->loser ? AccountMergeDuty::PrepareLoser : AccountMergeDuty::PrepareSurvivor));
        }
        AccountMergeCancellation::request($operation);
        $assignments = Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows();
        $operationId = $operation->id;
        $order = $loserFirst ? [$this->loser, $this->survivor] : [$this->survivor, $this->loser];
        $released = [];
        $results = $this->snapshot()['hilos_account_operation_result'];
        foreach ($order as $person) {
            Database::close(0);
            Database::connect(0);
            $this->mountFreshContext();
            $operation = Hilos::$db->accountOperations[$operationId];
            $before = $this->snapshot();
            foreach ($released as [$owner, $saved]) {
                self::assertSame($saved, $this->release($operation, $owner)->toArray());
                self::assertSame(AccountOperationAnswerKind::Done, $this->deliver($owner,
                    $this->assignment($operation, $owner === $this->loser ? AccountMergeDuty::ReleaseLoser : AccountMergeDuty::ReleaseSurvivor,
                        AccountMergeStage::Cancelling))->answer);
                self::assertNull($this->heldBy($owner));
            }
            self::assertSame(AccountMergeCancellationState::WaitingOwners, AccountMergeCancellation::request($operation));
            self::assertSame(AccountMergeCancellationState::WaitingOwners, AccountMergeCancellation::advance($operation));
            self::assertSame($before, $this->snapshot());
            self::assertSame($operationId, $this->heldBy($person)?->id);
            self::assertNull($operation->finishedAt);
            $assignment = $this->assignment($operation,
                $person === $this->loser ? AccountMergeDuty::ReleaseLoser : AccountMergeDuty::ReleaseSurvivor, AccountMergeStage::Cancelling);
            // Consume the answer without passing it to any coordinator; advance must rely on storage alone.
            self::assertSame(AccountOperationAnswerKind::Done, $this->deliver($person, $assignment)->answer);
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
            $result = $this->release($operation, $person);
            $released[] = [$person, $result->toArray()];
            self::assertCount(count($results) + 1, $this->snapshot()['hilos_account_operation_result']);
            self::assertSame($results, array_slice($this->snapshot()['hilos_account_operation_result'], 0, count($results)));
            $results = $this->snapshot()['hilos_account_operation_result'];
            self::assertNull($this->heldBy($person));
            self::assertSame($business, $this->businessSnapshot());
        }
        Database::close(0);
        Database::connect(0);
        $this->mountFreshContext();
        $operation = Hilos::$db->accountOperations[$operationId];
        self::assertSame(AccountMergeCancellationState::NotStarted, AccountMergeCancellation::advance($operation));
        self::assertSame(AccountOperationOutcome::NotStarted->value, $operation->outcome);
        foreach ($released as [$person, $saved]) {
            self::assertSame($saved, $this->release($operation, $person)->toArray());
        }
        self::assertSame(AccountMergeCancellationState::NotStarted, AccountMergeCancellation::request($operation));
        self::assertSame(AccountMergeCancellationState::NotStarted, AccountMergeCancellation::advance($operation));
        self::assertSame($assignments, Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows());
        self::assertCount(4, $results);
        self::assertSame($results, $this->snapshot()['hilos_account_operation_result']);
        self::assertSame($business, $this->businessSnapshot());
    }

    /** Unknown, foreign-kind and unsupported-stage operations refuse; the driver never requests cancellation on advance. */
    public function testGateValidatesTransactionKindStageExistenceAndWriter(): void
    {
        $operation = $this->open();
        $before = $this->snapshot();
        $this->assertRefused(ValidationException::class, static fn () => $operation->actions->beginMergeCancellation());
        $this->assertRefused(ValidationException::class, static fn () => AccountMergeCancellation::advance($operation));
        $this->assertRefused(WriteNotAllowedException::class,
            fn () => $this->asPerson($this->loser, static fn () => AccountMergeCancellation::request($operation)));
        self::assertSame($before, $this->snapshot());
        $this->giveAssignment($operation);
        $this->assertRefused(ValidationException::class, static fn () => AccountMergeCancellation::advance($operation));
        self::assertTrue($operation->actions->advance($operation->stage, 'unsupported'));
        $this->assertRefused(ValidationException::class, static fn () => AccountMergeCancellation::request($operation));
        $erasure = Hilos::$db->accountOperations->actions->recordErasure(101, self::SURVIVOR_ID,
            AccountOperationCircle::fromArray([self::SURVIVOR_ID], self::SURVIVOR_ID));
        $this->assertRefused(ValidationException::class, static fn () => AccountMergeCancellation::request($erasure));
        $this->assertRefused(ValidationException::class, static fn () => AccountMergeCancellation::advance($erasure));
        Database::sql('DELETE FROM `hilos_account_operation_assignment` WHERE `operation_id` = ?', [$operation->id]);
        Database::sql('DELETE FROM `hilos_account_operation` WHERE `id` = ?', [$operation->id]);
        $this->assertRefused(ValidationException::class, static fn () => AccountMergeCancellation::request($operation));
        $this->assertRefused(ValidationException::class, static fn () => AccountMergeCancellation::advance($operation));
    }

    /** @return array<string, array{string, string, bool}> Closed outcomes and their internal cancellation answer */
    public static function closedOutcomes(): array
    {
        return ['completed' => ['completed', 'too_late', false], 'not started' => ['not_started', 'not_started', true]];
    }

    /**
     * @param string $outcome Stored terminal outcome
     * @param string $answer Internal driver answer
     * @param bool $notStarted Whether the merge ended without a fact
     */
    #[DataProvider('closedOutcomes')]
    public function testClosedOutcomesStayDistinctAndCreateNoReleaseAssignments(string $outcome, string $answer, bool $notStarted): void
    {
        $operation = $this->open();
        if (!$notStarted) {
            $this->give($this->giveAssignment($operation));
        }
        self::assertTrue($operation->actions->finish($operation->stage, AccountOperationOutcome::from($outcome)));
        $before = $this->snapshot();
        self::assertSame($answer, AccountMergeCancellation::request($operation)->value);
        self::assertSame($answer, AccountMergeCancellation::advance($operation)->value);
        self::assertSame($before, $this->snapshot());
    }

    /** @return array<string, array{string, string}> Invalid persisted release addresses or payloads */
    public static function malformedReleases(): array
    {
        return [
            'owner type' => ['owner_type', 'other_owner'],
            'owner index' => ['owner_index', '7'],
            'user' => ['user_id', '7'],
            'payload' => ['payload_json', '{}'],
        ];
    }

    /**
     * @param string $column Persisted fixture field to corrupt
     * @param string $value Invalid address or result
     */
    #[DataProvider('malformedReleases')]
    public function testReleaseReaderRefusesMalformedAddressAndPayload(string $column, string $value): void
    {
        $operation = $this->open();
        self::assertFalse(AccountMergeReleaseResult::forAccount($operation, self::LOSER_ID));
        self::assertFalse(AccountMergeReleaseResult::forAccount($operation, 123));
        AccountMergeCancellation::request($operation);
        self::assertFalse(AccountMergeReleaseResult::forAccount($operation, self::LOSER_ID));
        $assignment = $this->assignment($operation, AccountMergeDuty::ReleaseLoser, AccountMergeStage::Cancelling);
        if ($column === 'payload_json') {
            $result = $this->release($operation, $this->loser);
            Database::sql('UPDATE `hilos_account_operation_result` SET `payload_json` = ? WHERE `id` = ?', [$value, $result->id]);
        } else {
            Database::sql('UPDATE `hilos_account_operation_assignment` SET `' . $column . '` = ? WHERE `id` = ?', [$value, $assignment->id]);
        }
        $this->mountFreshContext();
        $operation = Hilos::$db->accountOperations[$operation->id];
        $before = $this->snapshot();
        $this->assertRefused(InvalidFormatException::class, static fn () => AccountMergeReleaseResult::forAccount($operation, self::LOSER_ID));
        $this->assertRefused(InvalidFormatException::class, static fn () => AccountMergeCancellation::advance($operation));
        self::assertSame($before, $this->snapshot());
    }

    /** Only the assigned participant writes its result; neither participant owns the operation or the other's result. */
    public function testHolderAndParticipantRightsRemainSeparate(): void
    {
        $operation = $this->open();
        AccountMergeCancellation::request($operation);
        $assignment = $this->assignment($operation, AccountMergeDuty::ReleaseLoser, AccountMergeStage::Cancelling);
        $before = $this->snapshot();
        $this->assertRefused(AccountOperationStepRefusedException::class,
            static fn () => AccountMergeRelease::run($assignment->id, self::LOSER_ID));
        $this->assertRefused(CreateNotAllowedException::class, static fn () => Database::transaction(DatabaseNames::PRIMARY,
            static fn () => Hilos::$db->accountOperationResults->actions->record($assignment, AccountMergeReleaseResult::released())));
        $this->assertRefused(AccountOperationStepRefusedException::class,
            fn () => $this->asPerson($this->survivor,
                static fn () => AccountMergeRelease::run($assignment->id, self::SURVIVOR_ID)));
        $this->assertRefused(WriteNotAllowedException::class, fn () => $this->asPerson($this->loser,
            static fn () => $operation->actions->finish(AccountMergeStage::Cancelling->value, AccountOperationOutcome::NotStarted)));
        self::assertSame($before, $this->snapshot());
    }

    /** Saved repeats bypass new current reads, even after the operation closes; absent participants keep their original refusal. */
    public function testSavedReleaseSkipsCurrentReadsAndNoParticipantStillRefuses(): void
    {
        $operation = $this->open();
        AccountMergeCancellation::request($operation);
        $saved = $this->release($operation, $this->loser);
        $this->release($operation, $this->survivor);
        AccountMergeCancellation::advance($operation);
        $assignment = $this->assignment($operation, AccountMergeDuty::ReleaseLoser, AccountMergeStage::Cancelling);
        Hilos::$db = new MergeCancellationInterleavingContext();
        Hilos::$db->configure();
        $operations = Hilos::$db->accountOperations;
        self::assertInstanceOf(MergeCancellationInterleavingOperations::class, $operations);
        $operations->beforeFresh = static function (): void {
            self::fail('A saved release attempted another current read');
        };
        self::assertSame($saved->id, $this->release($operation, $this->loser)->id);
        $this->assertRefused(AccountOperationStepRefusedException::class, static fn () => ExecutionContext::run(
            new ExecutionFrame(), static fn () => AccountMergeRelease::run($assignment->id, self::LOSER_ID),
        ));
    }

    /** A first late preparation refuses while an earlier saved reservation remains an immutable historical result. */
    public function testLatePreparationRefusesAndSavedPreparationDoesNotReserveAgain(): void
    {
        $operation = $this->open();
        $savedAssignment = $this->assignment($operation, AccountMergeDuty::PrepareSurvivor);
        $lateAssignment = $this->assignment($operation, AccountMergeDuty::PrepareLoser);
        $saved = $this->prepare($this->survivor, $savedAssignment);
        AccountMergeCancellation::request($operation);
        $this->release($operation, $this->survivor);
        $this->release($operation, $this->loser);
        $before = $this->snapshot();
        $this->assertRefused(ValidationException::class, fn () => $this->prepare($this->loser, $lateAssignment));
        self::assertSame($saved->id, $this->prepare($this->survivor, $savedAssignment)->id);
        self::assertNull($this->heldBy($this->survivor));
        self::assertNull($this->heldBy($this->loser));
        self::assertSame($before, $this->snapshot());
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($lateAssignment->id));
        self::assertSame(AccountMergeCancellationState::NotStarted, AccountMergeCancellation::advance($operation));
        self::assertSame($saved->id, $this->prepare($this->survivor, $savedAssignment)->id);
    }

    /** A stale preparing snapshot cannot prepare a closed operation or disturb a newer reservation on rollback. */
    public function testClosedPreparationRefreshPreservesANewerReservation(): void
    {
        $operation = $this->open();
        $assignment = $this->assignment($operation, AccountMergeDuty::PrepareLoser);
        $ownerContext = Hilos::$db;
        $this->mountFreshContext();
        $current = Hilos::$db->accountOperations[$operation->id];
        AccountMergeCancellation::request($current);
        $released = $this->release($current, $this->loser);
        $this->release($current, $this->survivor);
        AccountMergeCancellation::advance($current);
        $new = $this->open();
        $this->prepare($this->loser, $this->assignment($new, AccountMergeDuty::PrepareLoser));
        Hilos::$db = $ownerContext;
        self::assertSame(AccountMergeStage::Preparing->value, $operation->stage);
        $before = $this->snapshot();
        $business = $this->businessSnapshot();
        $this->assertRefused(ValidationException::class, fn () => $this->prepare($this->loser, $assignment));
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($assignment->id));
        self::assertSame($released->toArray(), $this->release($operation, $this->loser)->toArray());
        self::assertSame($new->id, $this->heldBy($this->loser)?->id);
        self::assertSame($before, $this->snapshot());
        self::assertSame($business, $this->businessSnapshot());
    }

    /** A cancellation committed on another SQL session is usable before the owner's cached preparing receives DB_SYNC. */
    public function testReleaseRefreshesCachedPreparingBeforeTheParticipantPrecheck(): void
    {
        $operation = $this->open();
        $ownerContext = Hilos::$db;
        $oldConnection = Database::sql('SELECT CONNECTION_ID()')->rows();
        Database::close(0);
        Database::connect(0);
        self::assertNotSame($oldConnection, Database::sql('SELECT CONNECTION_ID()')->rows());
        $this->mountFreshContext();
        $current = Hilos::$db->accountOperations[$operation->id];
        AccountMergeCancellation::request($current);
        Hilos::$db = $ownerContext;
        self::assertSame(AccountMergeStage::Preparing->value, $operation->stage);
        $result = $this->release($operation, $this->loser);
        self::assertTrue(AccountMergeReleaseResult::releasedOf($result->payload));
        self::assertSame(AccountMergeStage::Cancelling->value, $operation->stage);
    }

    /** Current preparation metadata refuses a first attempt even when the participant precheck still sees preparing. */
    public function testLatePreparationRefreshesCachedPreparingAndOwnReleaseIsFinal(): void
    {
        $operation = $this->open();
        $assignment = $this->assignment($operation, AccountMergeDuty::PrepareLoser);
        $ownerContext = Hilos::$db;
        $this->mountFreshContext();
        AccountMergeCancellation::request(Hilos::$db->accountOperations[$operation->id]);
        Hilos::$db = $ownerContext;
        self::assertSame(AccountMergeStage::Preparing->value, $operation->stage);
        $before = $this->snapshot();
        $this->assertRefused(ValidationException::class, fn () => $this->prepare($this->loser, $assignment));
        self::assertSame($before, $this->snapshot());
        $this->release($operation, $this->loser);
        // An inconsistent reopened metadata fixture cannot revive a side that already released this operation.
        Database::sql('UPDATE `hilos_account_operation` SET `stage` = ? WHERE `id` = ?',
            [AccountMergeStage::Preparing->value, $operation->id]);
        Hilos::$db->accountOperations->fresh($operation->id);
        try {
            $this->prepare($this->loser, $assignment);
            self::fail('A released preparation ran again');
        } catch (ValidationException $failure) {
            self::assertSame('This account merge preparation has been released.', $failure->getMessage());
        }
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($assignment->id));
    }

    /** @return array<string, array{bool}> The operation closes or leaves cancelling after preflight */
    public static function releasePreflightChanges(): array
    {
        return ['closed' => [true], 'go' => [false]];
    }

    /** @param bool $closed Whether a peer closes the merge instead of changing its stage */
    #[DataProvider('releasePreflightChanges')]
    public function testReleaseChecksCurrentMetadataAgainAfterItsPreflight(bool $closed): void
    {
        $operation = $this->open();
        AccountMergeCancellation::request($operation);
        $this->connectPeer();
        Hilos::$db = new MergeCancellationInterleavingContext();
        Hilos::$db->configure();
        $operations = Hilos::$db->accountOperations;
        self::assertInstanceOf(MergeCancellationInterleavingOperations::class, $operations);
        $checks = 0;
        $operations->beforeFresh = static function () use ($operation, $closed, &$checks): void {
            $checks++;
            if ($checks === 2) {
                self::assertTrue(Database::hasOpenTransactions());
                if ($closed) {
                    Database::sql('UPDATE `hilos_account_operation` SET `finished_at` = ?, `outcome` = ? WHERE `id` = ?',
                        [1234, AccountOperationOutcome::NotStarted->value, $operation->id], index: self::PEER_INDEX);
                } else {
                    Database::sql('UPDATE `hilos_account_operation` SET `stage` = ? WHERE `id` = ?',
                        [AccountMergeStage::Go->value, $operation->id], index: self::PEER_INDEX);
                }
            } else {
                self::assertFalse(Database::hasOpenTransactions());
            }
        };
        $this->assertRefused(ValidationException::class, fn () => $this->release($operation, $this->loser));
        self::assertSame(2, $checks);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
    }

    /** A real give committed after preflight still blocks release when cancelling metadata is deliberately restored. */
    public function testReleaseRechecksACommittedFactAfterItsPreflight(): void
    {
        $operation = $this->open();
        $give = $this->giveAssignment($operation);
        AccountMergeCancellation::request($operation);
        Hilos::$db = new MergeCancellationInterleavingContext();
        Hilos::$db->configure();
        $operations = Hilos::$db->accountOperations;
        self::assertInstanceOf(MergeCancellationInterleavingOperations::class, $operations);
        $checks = 0;
        $committed = null;
        $business = $this->businessSnapshot();
        $operations->afterFresh = function () use ($operation, $give, &$checks, &$committed): void {
            if (++$checks !== 1) {
                self::assertTrue(Database::hasOpenTransactions());
                return;
            }
            self::assertFalse(Database::hasOpenTransactions());
            $ownerContext = Hilos::$db;
            $this->mountFreshContext();
            try {
                $current = Hilos::$db->accountOperations[$operation->id];
                ExecutionContext::run(new ExecutionFrame(agentId: self::HOLDER_ID), static fn () =>
                    self::assertTrue($current->actions->advance(AccountMergeStage::Cancelling->value, AccountMergeStage::Go->value)));
                $this->give($give);
                // Keep the result from the real participant; only metadata is inconsistent in this refusal fixture.
                ExecutionContext::run(new ExecutionFrame(agentId: self::HOLDER_ID), static fn () =>
                    self::assertTrue($current->actions->advance(AccountMergeStage::Go->value, AccountMergeStage::Cancelling->value)));
                $committed = $this->snapshot();
            } finally {
                Hilos::$db = $ownerContext;
            }
        };
        $this->assertRefused(ValidationException::class, fn () => $this->release($operation, $this->loser));
        self::assertSame(2, $checks);
        self::assertNotNull($committed);
        self::assertSame($committed, $this->snapshot());
        self::assertNotNull(Hilos::$db->accountOperations[$operation->id]->mergeFact());
        self::assertFalse(AccountMergeReleaseResult::forAccount(Hilos::$db->accountOperations[$operation->id], self::LOSER_ID));
        self::assertSame($business, $this->businessSnapshot());
    }

    /** Preparation and release keep their real shared metadata lock through the participant's result write. */
    public function testPersonalStepsKeepTheSharedMetadataGateUntilCommit(): void
    {
        $operation = $this->open();
        $this->connectPeer();
        Hilos::$db = new MergeCancellationInterleavingContext();
        Hilos::$db->configure();
        $operations = Hilos::$db->accountOperations;
        self::assertInstanceOf(MergeCancellationInterleavingOperations::class, $operations);
        $locks = 0;
        $operations->afterFresh = function () use ($operation, &$locks): void {
            if (Database::hasOpenTransactions()) {
                $locks++;
                $this->assertNowaitRefused('FOR UPDATE NOWAIT', $operation->id);
            }
        };
        $this->prepare($this->loser, $this->assignment($operation, AccountMergeDuty::PrepareLoser));
        AccountMergeCancellation::request(Hilos::$db->accountOperations[$operation->id]);
        $this->release($operation, $this->loser);
        self::assertSame(2, $locks);
        Database::sql('SELECT `id` FROM `hilos_account_operation` WHERE `id` = ? FOR UPDATE NOWAIT',
            [$operation->id], index: self::PEER_INDEX);
    }

    /** @return iterable<string, array{string}> Invalid release addresses and operation contracts */
    public static function invalidOwnerReleases(): iterable
    {
        foreach (['owner type', 'owner index', 'user', 'caller user', 'side', 'key', 'kind', 'stage',
            'closed', 'fact', 'payload', 'array', 'unknown'] as $case) {
            yield $case => [$case];
        }
    }

    /** @param string $case One invalid assignment, address, payload or current operation */
    #[DataProvider('invalidOwnerReleases')]
    public function testInvalidOwnerReleaseWritesNothing(string $case): void
    {
        $operation = $this->open();
        $give = $case === 'fact' ? $this->giveAssignment($operation) : null;
        AccountMergeCancellation::request($operation);
        $assignment = $this->assignment($operation, AccountMergeDuty::ReleaseLoser, AccountMergeStage::Cancelling);
        [$table, $column, $value] = match ($case) {
            'owner type' => ['hilos_account_operation_assignment', 'owner_type', 'other_owner'],
            'owner index' => ['hilos_account_operation_assignment', 'owner_index', (string)self::SURVIVOR_ID],
            'user' => ['hilos_account_operation_assignment', 'user_id', self::SURVIVOR_ID],
            'side' => ['hilos_account_operation_assignment', 'assignment_key', AccountMergeDuty::ReleaseSurvivor->value],
            'key' => ['hilos_account_operation_assignment', 'assignment_key', 'unrelated'],
            'kind' => ['hilos_account_operation', 'kind', 'erasure'],
            'stage' => ['hilos_account_operation_assignment', 'stage', AccountMergeStage::Preparing->value],
            'closed' => ['hilos_account_operation', 'finished_at', 1234],
            'payload' => ['hilos_account_operation_assignment', 'payload_json', '{"extra":true}'],
            'array' => ['hilos_account_operation_assignment', 'payload_json', '[]'],
            default => [null, null, null],
        };
        if ($table !== null) {
            if ($case === 'side') {
                Database::sql('DELETE FROM `hilos_account_operation_assignment` WHERE `operation_id` = ? AND `assignment_key` = ?',
                    [$operation->id, AccountMergeDuty::ReleaseSurvivor->value]);
            }
            Database::sql('UPDATE `' . $table . '` SET `' . $column . '` = ? WHERE `id` = ?',
                [$value, $table === 'hilos_account_operation' ? $operation->id : $assignment->id]);
        }
        if ($give !== null) {
            // The fact uses the real participant; the deliberately inconsistent cancelling fixture follows its commit.
            Database::sql('UPDATE `hilos_account_operation` SET `stage` = ? WHERE `id` = ?',
                [AccountMergeStage::Go->value, $operation->id]);
            Hilos::$db->accountOperations->fresh($operation->id);
            $this->give($give);
            Database::sql('UPDATE `hilos_account_operation` SET `stage` = ? WHERE `id` = ?',
                [AccountMergeStage::Cancelling->value, $operation->id]);
        }
        $this->mountFreshContext();
        $before = $this->snapshot();
        $id = $case === 'unknown' ? $assignment->id + 100 : $assignment->id;
        $userId = $case === 'caller user' ? self::SURVIVOR_ID : self::LOSER_ID;
        $this->assertRefused(ValidationException::class, fn () => $this->asPerson($this->loser,
            static fn () => AccountMergeRelease::run($id, $userId)));
        self::assertSame($before, $this->snapshot());
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($id));
    }

    /** Both match keys answer only after commit, and a foreign assignment still answers refused. */
    public function testPersonalAgentReleaseFramesAnswerAfterCommitAndRepeatAfterClosure(): void
    {
        $operation = $this->open();
        AccountMergeCancellation::request($operation);
        $foreign = $this->assignment($operation, AccountMergeDuty::ReleaseSurvivor, AccountMergeStage::Cancelling);
        self::assertSame(AccountOperationAnswerKind::Refused, $this->deliver($this->loser, $foreign)->answer);
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($foreign->id));
        foreach ([$this->loser, $this->survivor] as $person) {
            $assignment = $this->assignment($operation,
                $person === $this->loser ? AccountMergeDuty::ReleaseLoser : AccountMergeDuty::ReleaseSurvivor, AccountMergeStage::Cancelling);
            self::assertSame(AccountOperationAnswerKind::Done, $this->deliver($person, $assignment)->answer);
        }
        self::assertSame(AccountMergeCancellationState::NotStarted, AccountMergeCancellation::advance($operation));
        $before = $this->snapshot();
        self::assertSame(AccountOperationAnswerKind::Done, $this->deliver($this->survivor, $foreign)->answer);
        self::assertSame($before, $this->snapshot());
    }

    /**
     * @param AbstractUserAgent $person Personal owner
     * @param AccountOperationAssignment $assignment Preparation obligation
     * @return AccountOperationResult Real owner's preparation
     */
    private function prepare(AbstractUserAgent $person, AccountOperationAssignment $assignment): AccountOperationResult
    {
        return $this->asPerson($person, static fn () => $person->prepareForMerge($assignment->id));
    }

    /**
     * @param AbstractUserAgent $person Personal owner
     * @return ?AccountOperation Its active reservation
     */
    private function heldBy(AbstractUserAgent $person): ?AccountOperation
    {
        return $this->asPerson($person, static fn () => AccountMergePreparation::heldBy((int)$person->getIndex()));
    }

    /**
     * @param AbstractUserAgent $person Owner receiving the existing frame
     * @param AccountOperationAssignment $assignment Immutable obligation
     * @return AccountOperationAnswerSignalData Correlated answer
     */
    private function deliver(AbstractUserAgent $person, AccountOperationAssignment $assignment): AccountOperationAnswerSignalData
    {
        self::assertInstanceOf(MergeCancellationAnswerPerson::class, $person);
        $person->observeAnswer = function (AccountOperationAnswerSignalData $answer): void {
            self::assertFalse(Database::hasOpenTransactions());
            if ($answer->answer === AccountOperationAnswerKind::Done) {
                $this->connectPeer();
                self::assertCount(1, Database::sql('SELECT `id` FROM `hilos_account_operation_result` WHERE `assignment_id` = ?',
                    [$answer->assignmentId], index: self::PEER_INDEX)->rows());
            }
        };
        $this->asPerson($person, static fn () => $person->onSignalAgent(
            new AgentSignalData(new AccountOperationAskSignalData(
                $assignment->operationId, $assignment->stage, $assignment->id, $person->getIndex(),
            )), self::HOLDER_ID, HilosSignalConstants::HILOS_USER_ACCOUNT_OPERATION_STEP,
        ));
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER) {
                continue;
            }
            self::assertFalse(Database::hasOpenTransactions());
            $answer = $signal->data->data;
            self::assertInstanceOf(AccountOperationAnswerSignalData::class, $answer);
            self::assertSame($assignment->id, $answer->assignmentId);
            self::assertSame($assignment->operationId, $answer->operationId);
            self::assertSame($assignment->stage, $answer->stage);
            return $answer;
        }
        self::fail('The personal owner did not answer the release');
    }

    /** @return array<string, list<array<string, mixed>>> The person's business rows, outside operation metadata and results */
    private function businessSnapshot(): array
    {
        $rows = [];
        foreach (['hilos_user', 'hilos_user_merge', 'hilos_identity', 'hilos_account_deletion', 'hilos_second_factor',
            'hilos_second_factor_backup_code', 'hilos_second_factor_trust', 'hilos_second_factor_trust_restriction',
            'hilos_second_factor_reset', 'hilos_second_factor_setting'] as $table) {
            $rows[$table] = Database::sql('SELECT * FROM `' . $table . '` ORDER BY 1')->rows();
        }
        return $rows;
    }

    /**
     * @param ?AccountMergeChoice $choice Supplied protection expectations
     * @return AccountOperation New preparing operation and its initial obligations
     */
    private function open(?AccountMergeChoice $choice = null): AccountOperation
    {
        return AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, $choice ?? new AccountMergeChoice(null, null, null, null));
    }

    /** @param AccountOperation $operation Open preparing merge
     * @return AccountOperationAssignment Give obligation after advancing to go
     */
    private function giveAssignment(AccountOperation $operation): AccountOperationAssignment
    {
        self::assertTrue($operation->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Go->value));
        return Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id, AccountMergeStage::Go->value, AccountMergeDuty::Give->value,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)self::LOSER_ID),
            self::LOSER_ID, AccountOperationAssignmentPayload::fromJson('{}'),
        );
    }

    /**
     * @param AccountOperationAssignment $assignment Give obligation whose callback uses the storage gate
     * @return AccountOperationResult Saved give fact
     */
    private function give(AccountOperationAssignment $assignment): AccountOperationResult
    {
        return $this->asPerson($this->loser, static fn () => AccountOperationParticipantStep::run($assignment->id,
            static function () use ($assignment): AccountOperationResultPayload {
                $current = Hilos::$db->accountOperations->fresh($assignment->operationId);
                if ($current === null || $current->stage !== AccountMergeStage::Go->value || $current->finishedAt !== null) {
                    throw new ValidationException('Give requires a current open go under its shared storage lock.');
                }
                return AccountOperationResultPayload::fromJson('{}');
            }));
    }

    /**
     * @param AccountOperation $operation Cancelling operation
     * @param AbstractUserAgent $person Assigned side
     * @return AccountOperationResult Immutable result from a real participant transaction
     */
    private function release(AccountOperation $operation, AbstractUserAgent $person): AccountOperationResult
    {
        $assignment = $this->assignment($operation,
            $person === $this->loser ? AccountMergeDuty::ReleaseLoser : AccountMergeDuty::ReleaseSurvivor, AccountMergeStage::Cancelling);
        return $this->asPerson($person, static fn () => $person->releaseMergePreparation($assignment->id));
    }

    /**
     * @param AccountOperation $operation Parent operation
     * @param AccountMergeDuty $duty Immutable duty
     * @param AccountMergeStage $stage Stage of this obligation
     * @return AccountOperationAssignment Required stored assignment
     */
    private function assignment(
        AccountOperation $operation,
        AccountMergeDuty $duty,
        AccountMergeStage $stage = AccountMergeStage::Preparing,
    ): AccountOperationAssignment {
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, $stage->value, $duty->value);
        self::assertNotNull($assignment);
        return $assignment;
    }

    /**
     * @param AbstractUserAgent $person Participant whose set rights apply
     * @param Closure $work Work in its execution frame
     * @return mixed Callback result
     */
    private function asPerson(AbstractUserAgent $person, Closure $work): mixed
    {
        return $this->asPersonalOwner((int)$person->getIndex(), $work);
    }

    /** Creates fresh Object and View collections without forgetting loaded ORM state. */
    private function mountFreshContext(): void
    {
        Hilos::$db = new MergeCancellationContext();
        Hilos::$db->configure();
    }

    /** Connects an independent SQL session for NOWAIT and the single old-snapshot SQL fixture. */
    private function connectPeer(): void
    {
        $primary = Database::getConnectionConfig(0);
        Database::configure(self::PEER_INDEX, $primary->host, $primary->user, $primary->password,
            $primary->database, $primary->port, $primary->charset, $primary->socket);
        Database::connect(self::PEER_INDEX);
        self::assertNotSame(Database::sql('SELECT CONNECTION_ID()')->rows(),
            Database::sql('SELECT CONNECTION_ID()', index: self::PEER_INDEX)->rows());
    }

    /**
     * @param string $lock Explicit immediate lock mode
     * @param int $operationId Locked operation
     */
    private function assertNowaitRefused(string $lock, int $operationId): void
    {
        try {
            Database::sql('SELECT `id` FROM `hilos_account_operation` WHERE `id` = ? ' . $lock,
                [$operationId], index: self::PEER_INDEX);
        } catch (DatabaseRuntimeException $failure) {
            self::assertSame(1205, $failure->getMysqlErrorCode());
            return;
        }
        self::fail('The peer acquired a conflicting NOWAIT lock');
    }

    /** @return array<string, list<array<string, mixed>>> Persisted carriers, including immutable preparation inputs */
    private function snapshot(): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = Database::sql('SELECT * FROM `' . $table . '` ORDER BY `id`')->rows();
        }
        return $rows;
    }

    /**
     * @param class-string<Throwable> $exception Expected refusal family
     * @param Closure $work Forbidden or failed action
     */
    private function assertRefused(string $exception, Closure $work): void
    {
        try {
            $work();
        } catch (Throwable $failure) {
            self::assertInstanceOf($exception, $failure, $failure->getMessage());
            return;
        }
        self::fail('Forbidden or failed work returned successfully');
    }

    /** @param bool $down Drop in reverse dependency order */
    private function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            // external-boundary: up stub filenames carry no direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
        }
    }
}

/** Uses the existing holder declarations without altering its production lifecycle. */
final class MergeCancellationHolder extends AbstractSessionsLibraryAgent
{
}

final class MergeCancellationContext extends HilosDbContext
{
}

/** Test-only interleaving at the existing facade read, retaining the real storage gate. */
final class MergeCancellationInterleavingContext extends HilosDbContext
{
    /** @return array<string, FrameworkExtension> Current-read test double over the unchanged operation carrier */
    protected function frameworkExtensions(): array
    {
        return [
            ...parent::frameworkExtensions(),
            self::accountOperations => new FrameworkExtension(MergeCancellationInterleavingOperations::class),
        ];
    }
}

/** Hooks only the two current-read boundaries; both reads still use production storage. */
final class MergeCancellationInterleavingOperations extends AccountOperations
{
    public ?Closure $beforeFresh = null;
    public ?Closure $afterFresh = null;

    /**
     * @param int $operationId Persisted operation
     * @return ?AccountOperation Real current storage row
     */
    public function fresh(int $operationId): ?AccountOperation
    {
        $this->beforeFresh?->__invoke();
        $operation = parent::fresh($operationId);
        $this->afterFresh?->__invoke();
        return $operation;
    }
}

/** Observes the actual send boundary without replacing the personal operation branch or its queue. */
final class MergeCancellationAnswerPerson extends AbstractUserAgent
{
    public ?Closure $observeAnswer = null;

    /**
     * @param string $signalName Existing outbound signal name
     * @param SignalDataInterface $data Existing signal payload
     */
    public function sendToAgent(string $signalName, SignalDataInterface $data): void
    {
        if ($data instanceof AccountOperationAnswerSignalData) {
            $this->observeAnswer?->__invoke($data);
        }
        parent::sendToAgent($signalName, $data);
    }
}
