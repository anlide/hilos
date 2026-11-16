<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountOperation\AccountMergeCancellation;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationParticipantStep;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosAgentType;
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
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Hilos;
use Hilos\Notification\HilosNotifier;
use Hilos\Users\Agent\AbstractUserAgent;

/** Real carrier commits and personal frames, shared by access and deferred-policy scenarios. */
abstract class AccountMergeGuardIntegrationTestCase extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    protected const string HOLDER_ID = 'merge-access-holder';
    protected const string ACCEPT_KEY = 'merge-access-browser';
    protected const string REPLY = 'merge-access-answer';
    private const array TABLES = ['hilos_account_operation_assignment', 'hilos_account_operation_result', 'hilos_passkey_credential'];

    private ?SignalRouter $previousRouter = null;
    private ?HilosNotifier $previousNotify = null;

    /** @var list<string> Other frames the last owner handler emitted */
    protected array $otherSignals = [];

    /** Mounts the two immutable carriers and the real holder's declarations. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->previousNotify = Hilos::$notify;
        Hilos::$notify = new HilosNotifier();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        self::stubs(true);
        self::stubs(false);
        OwnershipDeclaration::claimDb(MergeAccessTestSessionsHolder::class, self::HOLDER_ID);
    }

    /** Releases every declaration and restores the router after dropping the carriers. */
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
            Hilos::$notify = $this->previousNotify;
            SourceChangeBus::reset();
            parent::tearDown();
        }
    }

    /** @return int New account */
    protected static function person(): int
    {
        Database::sql("INSERT INTO `hilos_user` (`name`) VALUES ('Merge access person')");

        return Database::lastInsertId();
    }

    /**
     * @param int $survivor Surviving account
     * @param int $loser Losing account
     * @return AccountOperation Merge with preparation assignments and no reservations yet
     */
    protected function open(int $survivor, int $loser): AccountOperation
    {
        return $this->asHolder(static fn (): AccountOperation => AccountMergeOpening::open(
            $survivor, $loser, new AccountMergeChoice(null, null, null, null),
        ));
    }

    /**
     * @param AccountOperation $operation Open merge
     * @param int $userId Side whose owner reserves it
     */
    protected function reserve(AccountOperation $operation, int $userId): void
    {
        $assignment = self::assignment($operation,
            $userId === $operation->survivorUserId ? AccountMergeDuty::PrepareSurvivor : AccountMergeDuty::PrepareLoser);
        $this->asPerson($userId, static fn (AbstractUserAgent $agent) => $agent->prepareForMerge($assignment->id));
    }

    /**
     * @param AccountOperation $operation Merge being canceled
     * @param int $userId Only side whose protection is released
     */
    protected function release(AccountOperation $operation, int $userId): void
    {
        $this->asHolder(static fn () => AccountMergeCancellation::request($operation));
        $assignment = self::assignment($operation,
            $userId === $operation->survivorUserId ? AccountMergeDuty::ReleaseSurvivor : AccountMergeDuty::ReleaseLoser,
            AccountMergeStage::Cancelling);
        $this->asPerson($userId, static fn (AbstractUserAgent $agent) => $agent->releaseMergePreparation($assignment->id));
    }

    /**
     * @param AccountOperation $operation Merge entering go
     * @return AccountOperationAssignment Give obligation with no result yet
     */
    protected function go(AccountOperation $operation): AccountOperationAssignment
    {
        return $this->asHolder(static function () use ($operation): AccountOperationAssignment {
            self::assertTrue($operation->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Go->value));

            return Hilos::$db->accountOperationAssignments->actions->record(
                $operation->id, AccountMergeStage::Go->value, AccountMergeDuty::Give->value,
                new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$operation->loserUserId),
                $operation->loserUserId, AccountOperationAssignmentPayload::fromJson('{}'),
            );
        });
    }

    /**
     * @param int $loser Owner of the give result
     * @param AccountOperationAssignment $assignment Give obligation
     */
    protected function give(int $loser, AccountOperationAssignment $assignment): void
    {
        $this->asPerson($loser, static fn () => AccountOperationParticipantStep::run(
            $assignment->id, static fn (): AccountOperationResultPayload => AccountOperationResultPayload::fromJson('{}'),
        ));
    }

    /**
     * @param AccountOperation $operation Parent merge
     * @param AccountMergeDuty $duty Participant's duty
     * @param AccountMergeStage $stage Assignment stage
     * @return AccountOperationAssignment Stored immutable assignment
     */
    protected static function assignment(
        AccountOperation $operation,
        AccountMergeDuty $duty,
        AccountMergeStage $stage = AccountMergeStage::Preparing,
    ): AccountOperationAssignment {
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, $stage->value, $duty->value);
        self::assertNotNull($assignment);

        return $assignment;
    }

    /**
     * @template T
     * @param callable(): T $work Holder work
     * @return T Callback result
     */
    protected function asHolder(callable $work): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: self::HOLDER_ID), $work);
    }

    /**
     * @param int $userId Personal owner receiving the frame
     * @param string $name Existing agent signal
     * @param SignalDataInterface $ask Existing payload
     * @return SignalDataInterface Sole correlated answer
     */
    protected function answer(int $userId, string $name, SignalDataInterface $ask): SignalDataInterface
    {
        self::drain();
        $this->otherSignals = [];
        $this->asPerson($userId, static fn (AbstractUserAgent $agent) => $agent->onSignalAgent(
            new AgentSignalData($ask), self::HOLDER_ID, $name,
        ));
        $answers = [];
        while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($queued->signalName->getName() === self::REPLY) {
                self::assertInstanceOf(AgentSignalData::class, $queued->data);
                $answers[] = $queued->data->data;
            } else {
                $this->otherSignals[] = $queued->signalName->getName();
            }
        }
        self::assertCount(1, $answers);

        return $answers[0];
    }

    /** Discards fixture announcements before observing a handler. */
    protected static function drain(): void
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
    }

    /** @param bool $down Drop children before their parents */
    private static function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            // external-boundary: migration stub names carry the direction only on down
            $suffix = $down ? '_down' : '';
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
        }
    }
}

/** Claims and behavior are the production holder's, without a dispatcher override. */
final class MergeAccessTestSessionsHolder extends AbstractSessionsLibraryAgent
{
}
