<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountMergePreparation;
use Hilos\Auth\AccountOperation\AccountMergePreparationResult;
use Hilos\Auth\AccountOperation\AccountMergeRefusal;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAnswerKind;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationCircle;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationStepRefusal;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\AccountOperations as ObjectAccountOperations;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\SecondFactorFate;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real commits in the holder's and the person's frames prove that preparation writes only its immutable result. */
final class AccountMergePreparationIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const string HOLDER_ID = 'merge-preparation-holder';
    private const array TABLES = ['hilos_account_operation_assignment', 'hilos_account_operation_result'];
    private const array SNAPSHOT_TABLES = [
        'hilos_identity', 'hilos_second_factor', 'hilos_second_factor_backup_code', 'hilos_second_factor_trust',
        'hilos_second_factor_trust_restriction', 'hilos_second_factor_reset', 'hilos_second_factor_setting',
        'hilos_user', 'hilos_user_merge', 'hilos_account_deletion', 'hilos_account_operation', 'hilos_account_operation_assignment',
    ];

    private ?SignalRouter $previousRouter = null;

    /** Raises only assignment and result tables over the session base and claims the holder. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        self::stubs(true);
        self::stubs(false);
        OwnershipDeclaration::claimDb(MergePreparationTestSessionsHolder::class, self::HOLDER_ID);
    }

    /** Releases all claims, source interests and the router even when a case failed. */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            self::stubs(true);
        } finally {
            $this->releasePersonAgents();
            TruthSourceRegistry::unregisterAgent(self::HOLDER_ID);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::HOLDER_ID));
            Hilos::$sr = $this->previousRouter;
            SourceChangeBus::reset();
            parent::tearDown();
        }
    }

    /**
     * @param bool $password Whether both accounts have a password
     * @param bool $factor Whether both accounts have confirmed factors
     */
    #[DataProvider('readyAccounts')]
    public function testBothSidesReserveOnlyTheirOwnIdsAndRepeatTheSavedResult(bool $password, bool $factor): void
    {
        $survivor = self::seedPerson();
        $loser = self::seedPerson();
        $passwords = [];
        $factors = [];
        foreach ([$survivor, $loser] as $userId) {
            $passwords[$userId] = $password ? self::seedPassword($userId) : null;
            $factors[$userId] = $factor ? [self::seedFactor($userId), self::seedFactor($userId)] : [];
            self::seedFactor($userId, false);
            self::seedReset($userId, false);
        }
        $operation = $this->open($survivor, $loser, new AccountMergeChoice(null, $factor ? SecondFactorFate::BOTH : null, $factor, $factor));
        $before = self::snapshot();
        foreach ([[$survivor, AccountMergeDuty::PrepareSurvivor], [$loser, AccountMergeDuty::PrepareLoser]] as [$userId, $duty]) {
            self::assertNull($this->heldBy($userId));
            $assignment = self::assignment($operation, $duty);
            $result = $this->prepare($userId, $assignment);
            $prepared = AccountMergePreparationResult::of($result->payload);
            self::assertTrue($prepared->isReserved());
            self::assertSame($passwords[$userId], $prepared->passwordIdentityId);
            self::assertSame($factors[$userId], $prepared->secondFactorIds);
            self::assertSame($operation->id, $this->heldBy($userId)?->id);
            $results = self::results();
            self::assertSame($result->id, $this->prepare($userId, $assignment)->id);
            self::assertSame($results, self::results());
        }
        self::assertSame($before, self::snapshot());
        self::assertFalse(Database::hasOpenTransactions());
    }

    /** @return iterable<string, array{bool, bool}> Password and confirmed-factor combinations */
    public static function readyAccounts(): iterable
    {
        yield 'neither' => [false, false];
        yield 'passwords' => [true, false];
        yield 'factors' => [false, true];
        yield 'both' => [true, true];
    }

    /**
     * @param bool $survivor Whether the refusal belongs to the survivor
     * @param bool $factor Actual protection of this side
     * @param ?SecondFactorFate $fate Supplied choice
     * @param ?bool $expected Supplied protection snapshot
     * @param bool $reset Whether a live removal stands
     * @param AccountMergeRefusal $code Expected first refusal
     * @param string $words Existing refusal sentence
     */
    #[DataProvider('protectionRefusals')]
    public function testProtectionRefusalIsRecordedWithoutChangingTheAccounts(
        bool $survivor,
        bool $factor,
        ?SecondFactorFate $fate,
        ?bool $expected,
        bool $reset,
        AccountMergeRefusal $code,
        string $words,
    ): void {
        $survivorId = self::seedPerson();
        $loserId = self::seedPerson();
        $userId = $survivor ? $survivorId : $loserId;
        if ($factor) {
            self::seedFactor($userId);
        }
        if ($reset) {
            self::seedReset($userId);
        }
        $operation = $this->open($survivorId, $loserId, new AccountMergeChoice(
            null, $fate, $survivor ? $expected : null, $survivor ? null : $expected,
        ));
        $before = self::snapshot();
        $result = $this->prepare($userId, self::assignment(
            $operation, $survivor ? AccountMergeDuty::PrepareSurvivor : AccountMergeDuty::PrepareLoser,
        ));
        self::assertSame($code, AccountMergePreparationResult::of($result->payload)->refusal);
        self::assertSame($words, $code->template());
        self::assertNull($this->heldBy($userId));
        self::assertSame($before, self::snapshot());
        self::assertCount(1, self::results());
    }

    /** @return iterable<string, array{bool, bool, ?SecondFactorFate, ?bool, bool, AccountMergeRefusal, string}> Local refusal cases */
    public static function protectionRefusals(): iterable
    {
        $changed = 'Second-factor protection changed. Review the accounts and choose again.';
        yield 'survivor changed before fate' => [
            true, false, SecondFactorFate::SURVIVOR, true, true, AccountMergeRefusal::ProtectionChanged, $changed,
        ];
        yield 'loser changed before fate' => [false, true, null, false, true, AccountMergeRefusal::ProtectionChanged, $changed];
        yield 'fate required before reset' => [false, true, null, null, true, AccountMergeRefusal::SecondFactorFateRequired,
            'Choose second-factor protection: survivor or both (--second-factor on the console).'];
        yield 'loser without factor' => [false, false, SecondFactorFate::BOTH, null, true, AccountMergeRefusal::LoserWithoutSecondFactor,
            'The loser has no confirmed authenticator. Remove the second-factor choice.'];
        yield 'survivor without factor' => [true, false, SecondFactorFate::SURVIVOR, null, true, AccountMergeRefusal::SurvivorWithoutSecondFactor,
            "The survivor has no confirmed authenticator. Transfer the loser's protection or cancel."];
        yield 'loser pending removal' => [false, true, SecondFactorFate::SURVIVOR, null, true, AccountMergeRefusal::LoserPendingRemoval,
            'The loser has a pending second-factor removal. Cancel it or wait for it to finish.'];
        yield 'survivor pending removal' => [true, true, SecondFactorFate::BOTH, null, true, AccountMergeRefusal::SurvivorPendingRemoval,
            'The survivor has a pending second-factor removal. Cancel it or wait for it to finish.'];
    }

    /**
     * @param bool $survivor Whether the erasure covers the survivor
     * @param bool $started Whether the deletion request has already begun
     */
    #[DataProvider('deletingAccounts')]
    public function testEarlierErasureOrStartedRequestRefusesEitherSide(bool $survivor, bool $started): void
    {
        $survivorId = self::seedPerson();
        $loserId = self::seedPerson();
        $userId = $survivor ? $survivorId : $loserId;
        $requestId = self::seedDeletion($userId, $started);
        if (!$started) {
            // The account is covered as a member, rather than only as the erasure's root.
            $rootId = self::seedPerson();
            $erasure = $this->asHolder(static fn (): AccountOperation => Hilos::$db->accountOperations->actions->recordErasure(
                $requestId, $rootId, AccountOperationCircle::fromArray([$userId, $rootId], $rootId),
            ));
            self::assertSame(AccountMergeStage::Preparing->value, $erasure->stage);
        }
        $operation = $this->open($survivorId, $loserId);
        $before = self::snapshot();
        $result = $this->prepare($userId, self::assignment(
            $operation, $survivor ? AccountMergeDuty::PrepareSurvivor : AccountMergeDuty::PrepareLoser,
        ));
        self::assertSame(AccountMergeRefusal::BeingDeleted, AccountMergePreparationResult::of($result->payload)->refusal);
        self::assertNull($this->heldBy($userId));
        self::assertSame($before, self::snapshot());
    }

    /** @return iterable<string, array{bool, bool}> Erasure coverage and begun requests on both sides */
    public static function deletingAccounts(): iterable
    {
        yield 'survivor in preparing circle' => [true, false];
        yield 'loser in preparing circle' => [false, false];
        yield 'survivor begun request' => [true, true];
        yield 'loser begun request' => [false, true];
    }

    /**
     * @param bool $survivor Whether this is the survivor's preparation
     * @param bool $missing Whether the person row is absent rather than merged
     */
    #[DataProvider('unavailableAccounts')]
    public function testMissingAndMergedAccountsRefuseBeforeProtection(bool $survivor, bool $missing): void
    {
        $survivorId = self::seedPerson();
        $loserId = self::seedPerson();
        $userId = $survivor ? $survivorId : $loserId;
        if ($missing) {
            Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [$userId]);
        } else {
            Database::sqlRun('INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, ?)',
                [$userId, self::seedPerson(), TimeHelper::nowMs()]);
        }
        $operation = $this->open($survivorId, $loserId, new AccountMergeChoice(null, SecondFactorFate::BOTH, true, true));
        $before = self::snapshot();
        $result = $this->prepare($userId, self::assignment(
            $operation, $survivor ? AccountMergeDuty::PrepareSurvivor : AccountMergeDuty::PrepareLoser,
        ));
        self::assertSame(
            $missing ? AccountMergeRefusal::Missing : ($survivor ? AccountMergeRefusal::SurvivorMerged : AccountMergeRefusal::LoserMerged),
            AccountMergePreparationResult::of($result->payload)->refusal,
        );
        self::assertNull($this->heldBy($userId));
        self::assertSame($before, self::snapshot());
    }

    /** @return iterable<string, array{bool, bool}> Both unavailable-account checks on both sides */
    public static function unavailableAccounts(): iterable
    {
        yield 'missing survivor' => [true, true];
        yield 'missing loser' => [false, true];
        yield 'merged survivor' => [true, false];
        yield 'merged loser' => [false, false];
    }

    /** @param bool $reverse Whether the newer operation prepares first */
    #[DataProvider('preparationOrders')]
    public function testFirstPreparationWinsAndOccupiedResultNeverChanges(bool $reverse): void
    {
        $account = self::seedPerson();
        $older = $this->open($account, self::seedPerson());
        $newer = $this->open($account, self::seedPerson());
        [$winner, $refused] = $reverse ? [$newer, $older] : [$older, $newer];
        $first = self::assignment($winner, AccountMergeDuty::PrepareSurvivor);
        $second = self::assignment($refused, AccountMergeDuty::PrepareSurvivor);
        self::assertNull($this->heldBy($account));
        self::assertTrue(AccountMergePreparationResult::of($this->prepare($account, $first)->payload)->isReserved());
        $result = $this->prepare($account, $second);
        self::assertSame(AccountMergeRefusal::Occupied, AccountMergePreparationResult::of($result->payload)->refusal);
        self::assertSame($winner->id, $this->heldBy($account)?->id);
        self::assertSame($result->id, $this->prepare($account, $second)->id);
        $third = $this->open(self::seedPerson(), $account);
        self::assertSame(AccountMergeRefusal::Occupied, AccountMergePreparationResult::of(
            $this->prepare($account, self::assignment($third, AccountMergeDuty::PrepareLoser))->payload,
        )->refusal);
        self::assertTrue($this->asHolder(static fn (): bool => $winner->actions->finish($winner->stage, AccountOperationOutcome::NotStarted)));
        self::assertNull($this->heldBy($account));
        $before = self::results();
        $repeat = $this->prepare($account, $second);
        self::assertSame($result->id, $repeat->id);
        self::assertSame(AccountMergeRefusal::Occupied, AccountMergePreparationResult::of($repeat->payload)->refusal);
        self::assertSame($before, self::results());
    }

    /** @return iterable<string, array{bool}> Both preparation orders with unchanged creation order */
    public static function preparationOrders(): iterable
    {
        yield 'older first' => [false];
        yield 'newer first' => [true];
    }

    /** A still unanswered second assignment can reserve on its first execution after the holder finishes the first merge. */
    public function testFinishReleasesReservationForTheFirstPreparationOfAnotherOpenMerge(): void
    {
        $account = self::seedPerson();
        $first = $this->open($account, self::seedPerson());
        $second = $this->open($account, self::seedPerson());
        $assignment = self::assignment($second, AccountMergeDuty::PrepareSurvivor);
        $this->prepare($account, self::assignment($first, AccountMergeDuty::PrepareSurvivor));
        self::assertSame($first->id, $this->heldBy($account)?->id);
        self::assertTrue($this->asHolder(static fn (): bool => $first->actions->finish($first->stage, AccountOperationOutcome::NotStarted)));
        self::assertNull($this->heldBy($account));
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($assignment->id));
        self::assertTrue(AccountMergePreparationResult::of($this->prepare($account, $assignment)->payload)->isReserved());
        self::assertSame($second->id, $this->heldBy($account)?->id);
    }

    /**
     * @param string $case Wrong kind, key, person or side of the assignment
     * @param string $words Exact validation refusal
     */
    #[DataProvider('invalidAssignments')]
    public function testInvalidAssignmentsWriteNoResult(string $case, string $words): void
    {
        $survivor = self::seedPerson();
        $loser = self::seedPerson();
        $operation = $case === 'wrong own side' || $case === 'wrong named account'
            ? $this->asHolder(static fn (): AccountOperation => Hilos::$db->accountOperations->actions->recordMerge(
                $survivor, $loser, new AccountMergeChoice(null, null, null, null),
            ))
            : $this->open($survivor, $loser);
        $assignment = null;
        $userId = $survivor;
        if ($case === 'another person') {
            $userId = self::seedPerson();
        } elseif ($case === 'erasure' || $case === 'unknown key' || $case === 'wrong own side' || $case === 'wrong named account') {
            if ($case === 'erasure') {
                $operation = $this->asHolder(static fn (): AccountOperation => Hilos::$db->accountOperations->actions->recordErasure(
                    self::seedDeletion($survivor), $survivor, AccountOperationCircle::fromArray([$survivor], $survivor),
                ));
            }
            $key = $case === 'unknown key' ? 'unrelated' : AccountMergeDuty::PrepareLoser->value;
            $assignment = $this->asHolder(static fn (): AccountOperationAssignment => Hilos::$db->accountOperationAssignments->actions->record(
                $operation->id, $operation->stage, $key,
                new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$survivor),
                $case === 'wrong named account' ? $loser : $survivor, AccountOperationAssignmentPayload::fromJson('{}'),
            ));
        }
        $assignment ??= self::assignment($operation, AccountMergeDuty::PrepareLoser);
        $before = self::snapshot();
        try {
            $this->prepare($userId, $assignment);
            self::fail('Invalid preparation was accepted');
        } catch (ValidationException $failure) {
            self::assertSame($words, $failure->getMessage());
            self::assertSame([], self::results());
        }
        self::assertSame($before, self::snapshot());
        self::assertFalse(Database::hasOpenTransactions());
    }

    /** @return iterable<string, array{string, string}> Invalid participant addresses and preparation identities */
    public static function invalidAssignments(): iterable
    {
        foreach ([
            'erasure' => 'An account merge preparation must belong to a merge.',
            'unknown key' => 'This assignment is not an account merge preparation.',
            'loser in survivor frame' => 'This account operation assignment belongs to another participant.',
            'another person' => 'This account operation assignment belongs to another participant.',
            'wrong own side' => 'An account merge preparation must name its own side of the merge.',
            'wrong named account' => 'An account merge preparation must name its own side of the merge.',
        ] as $case => $words) {
            yield $case => [$case, $words];
        }
    }

    /** The ordered selection crosses a full page of unrelated merges and notices persisted completion. */
    public function testOpenMergesNamingWalksEveryPageAndExcludesFinishedOperations(): void
    {
        $account = self::seedPerson();
        $other = self::seedPerson();
        self::assertSame([], $this->mergeIdsNaming($account));
        $first = $this->open($account, self::seedPerson());
        $values = [];
        $parameters = [];
        $now = TimeHelper::nowMs();
        for ($offset = 0; $offset < ObjectAccountOperations::PAGE_LIMIT_MAX; $offset++) {
            $loser = 10000 + $offset;
            $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
            array_push($parameters, AccountOperationKind::Merge->value, $other, $loser, $loser,
                new AccountMergeChoice(null, null, null, null)->toJson(), json_encode([$loser, $other]),
                AccountMergeStage::Preparing->value, $now, $now);
        }
        Database::sqlRun('INSERT INTO `hilos_account_operation`'
            . ' (`kind`, `root_user_id`, `loser_user_id`, `open_loser_user_id`, `merge_choice`, `circle_user_ids`,'
            . ' `stage`, `created_at`, `updated_at`) VALUES ' . implode(', ', $values), $parameters);
        $last = $this->open(self::seedPerson(), $account);
        self::assertSame([$first->id, $last->id], $this->mergeIdsNaming($account));
        self::assertTrue($this->asHolder(static fn (): bool => $first->actions->finish($first->stage, AccountOperationOutcome::NotStarted)));
        self::assertSame([$last->id], $this->mergeIdsNaming($account));
    }

    /** An unanswered assignment of a closed stage cannot write a result. */
    public function testClosedStageRefusesBeforePreparing(): void
    {
        $survivor = self::seedPerson();
        $operation = $this->open($survivor, self::seedPerson());
        $assignment = self::assignment($operation, AccountMergeDuty::PrepareSurvivor);
        self::assertTrue($this->asHolder(static fn (): bool => $operation->actions->advance(
            AccountMergeStage::Preparing->value, AccountMergeStage::Cancelling->value,
        )));
        $before = self::snapshot();
        try {
            $this->prepare($survivor, $assignment);
            self::fail('Closed preparation was accepted');
        } catch (AccountOperationStepRefusedException $failure) {
            self::assertSame(AccountOperationStepRefusal::ClosedStage, $failure->reason);
        }
        self::assertSame([], self::results());
        self::assertSame($before, self::snapshot());
    }

    /** The existing frame answers done for a reservation or business refusal, and refused for another participant's assignment. */
    public function testPersonFrameAnswersAfterRecordingAndRefusesForeignAssignment(): void
    {
        $survivor = self::seedPerson();
        $loser = self::seedPerson();
        $first = $this->open($survivor, $loser);
        $own = self::assignment($first, AccountMergeDuty::PrepareSurvivor);
        self::assertSame(AccountOperationAnswerKind::Done, $this->deliver($survivor, $own)->answer);
        self::assertTrue(AccountMergePreparationResult::of(Hilos::$db->accountOperationResults->byAssignment($own->id)->payload)->isReserved());
        $second = $this->open($survivor, self::seedPerson());
        $occupied = self::assignment($second, AccountMergeDuty::PrepareSurvivor);
        self::assertSame(AccountOperationAnswerKind::Done, $this->deliver($survivor, $occupied)->answer);
        self::assertSame(AccountMergeRefusal::Occupied,
            AccountMergePreparationResult::of(Hilos::$db->accountOperationResults->byAssignment($occupied->id)->payload)->refusal);
        $foreign = self::assignment($first, AccountMergeDuty::PrepareLoser);
        $before = self::results();
        self::assertSame(AccountOperationAnswerKind::Refused, $this->deliver($survivor, $foreign)->answer);
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($foreign->id));
        self::assertSame($before, self::results());
    }

    /**
     * @param int $survivor Survivor account
     * @param int $loser Loser account
     * @param ?AccountMergeChoice $choice Choices or an empty choice
     * @return AccountOperation Open merge with its two preparations
     */
    private function open(int $survivor, int $loser, ?AccountMergeChoice $choice = null): AccountOperation
    {
        return $this->asHolder(static fn (): AccountOperation => AccountMergeOpening::open(
            $survivor, $loser, $choice ?? new AccountMergeChoice(null, null, null, null),
        ));
    }

    /**
     * @param AccountOperation $operation Merge whose assignment is read
     * @param AccountMergeDuty $duty Side to prepare
     * @return AccountOperationAssignment Stored preparation assignment
     */
    private static function assignment(AccountOperation $operation, AccountMergeDuty $duty): AccountOperationAssignment
    {
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Preparing->value, $duty->value);
        self::assertNotNull($assignment);

        return $assignment;
    }

    /**
     * @param int $userId Account whose agent carries the step
     * @param AccountOperationAssignment $assignment Preparation address
     * @return AccountOperationResult Recorded or saved result
     */
    private function prepare(int $userId, AccountOperationAssignment $assignment): AccountOperationResult
    {
        return $this->asPerson($userId, static fn (AbstractUserAgent $agent): AccountOperationResult => $agent->prepareForMerge($assignment->id));
    }

    /**
     * @param int $userId Account whose own reservation is read
     * @return ?AccountOperation Merge currently holding the account
     */
    private function heldBy(int $userId): ?AccountOperation
    {
        return $this->asPerson($userId, static fn (): ?AccountOperation => AccountMergePreparation::heldBy($userId));
    }

    /**
     * @param int $userId Account whose open merges are read
     * @return list<int> Matching primary ids in selection order
     */
    private function mergeIdsNaming(int $userId): array
    {
        return $this->asHolder(static function () use ($userId): array {
            $ids = [];
            foreach (Hilos::$db->accountOperations->openMergesNaming($userId) as $operation) {
                $ids[] = $operation->id;
            }

            return $ids;
        });
    }

    /**
     * @param int $userId Account whose agent receives the frame
     * @param AccountOperationAssignment $assignment Address sent to that agent
     * @return AccountOperationAnswerSignalData Correlated answer from the agent
     */
    private function deliver(int $userId, AccountOperationAssignment $assignment): AccountOperationAnswerSignalData
    {
        $this->asPerson($userId, static fn (AbstractUserAgent $agent) => $agent->onSignalAgent(
            new AgentSignalData(new AccountOperationAskSignalData($assignment->operationId, $assignment->stage, $assignment->id, (string)$userId)),
            self::HOLDER_ID, HilosSignalConstants::HILOS_USER_ACCOUNT_OPERATION_STEP,
        ));
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER) {
                continue;
            }
            self::assertInstanceOf(AgentSignalData::class, $signal->data);
            $answer = $signal->data->data;
            self::assertInstanceOf(AccountOperationAnswerSignalData::class, $answer);
            self::assertSame($assignment->id, $answer->assignmentId);
            self::assertSame($assignment->operationId, $answer->operationId);
            self::assertSame($assignment->stage, $answer->stage);

            return $answer;
        }
        self::fail('The person did not answer the preparation');
    }

    /**
     * @template T
     * @param callable(): T $step Step in the session holder's frame
     * @return T Step result
     */
    private function asHolder(callable $step): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: self::HOLDER_ID), $step);
    }

    /** @return int New person id */
    private static function seedPerson(): int
    {
        Database::sql("INSERT INTO `hilos_user` (`name`) VALUES ('Person')");

        return Database::lastInsertId();
    }

    /**
     * @param int $userId Password owner
     * @return int Password identity id
     */
    private static function seedPassword(int $userId): int
    {
        Database::sql('INSERT INTO `hilos_identity` (`user_id`, `type`, `identifier`, `secret`, `created_at`, `updated_at`)'
            . ' VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, IdentityType::PASSWORD, "person{$userId}@example.test", 'fixture-hash', TimeHelper::nowMs(), TimeHelper::nowMs()]);

        return Database::lastInsertId();
    }

    /**
     * @param int $userId Factor owner
     * @param bool $confirmed Whether the factor is confirmed
     * @return int Factor id
     */
    private static function seedFactor(int $userId, bool $confirmed = true): int
    {
        $now = TimeHelper::nowMs();
        Database::sql('INSERT INTO `hilos_second_factor` (`user_id`, `label`, `secret`, `confirmed_at`, `created_at`) VALUES (?, ?, ?, ?, ?)',
            [$userId, 'merge-app', 'JBSWY3DPEHPK3PXP', $confirmed ? $now : null, $now]);

        return Database::lastInsertId();
    }

    /**
     * @param int $userId Removal owner
     * @param bool $live Whether the removal still stands
     */
    private static function seedReset(int $userId, bool $live = true): void
    {
        $now = TimeHelper::nowMs();
        Database::sqlRun('INSERT INTO `hilos_second_factor_reset` (`user_id`, `requested_at`, `effective_at`, `notified_at`, `canceled_at`)'
            . ' VALUES (?, ?, ?, ?, ?)', [
                $userId, $now, $now + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND, $now, $live ? null : $now,
            ]);
    }

    /**
     * @param int $userId Deletion request owner
     * @param bool $started Whether the deletion has begun
     * @return int Request id
     */
    private static function seedDeletion(int $userId, bool $started = false): int
    {
        $now = TimeHelper::nowMs();
        Database::sql('INSERT INTO `hilos_account_deletion` (`user_id`, `requested_at`, `effective_at`, `started_at`) VALUES (?, ?, ?, ?)',
            [$userId, $now, $now, $started ? $now : null]);

        return Database::lastInsertId();
    }

    /** @return array<string, list<array<string, mixed>>> Full persisted business rows and immutable operation inputs */
    private static function snapshot(): array
    {
        $rows = [];
        foreach (self::SNAPSHOT_TABLES as $table) {
            $rows[$table] = Database::sql("SELECT * FROM `{$table}` ORDER BY 1")->rows();
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> All saved result rows in primary id order */
    private static function results(): array
    {
        return Database::sql('SELECT * FROM `hilos_account_operation_result` ORDER BY `id`')->rows();
    }

    /** @param bool $down Drop in reverse dependency order */
    private static function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            // external-boundary: up stub filenames carry no direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
        }
    }
}

/** The existing holder's declarations, with no production behavior overridden. */
final class MergePreparationTestSessionsHolder extends AbstractSessionsLibraryAgent
{
}
