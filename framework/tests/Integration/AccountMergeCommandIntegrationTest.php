<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountOperation\AccountMergeCancellationState;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeCommandReply;
use Hilos\Auth\AccountOperation\AccountMergeCoordinator;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeRefusal;
use Hilos\Auth\AccountOperation\AccountMergeGiveResult;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountMergeAdmissionManifest;
use Hilos\Auth\AccountOperation\AccountMergePreparationResult;
use Hilos\Auth\AccountOperation\AccountMergeProjectResult;
use Hilos\Auth\AccountOperation\AccountMergeRowRevision;
use Hilos\Auth\AccountOperation\AccountMergeSessionsResult;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationCircle;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Auth\AccountOperation\AccountOperationExpectedSet;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\DTO\AccountMergeHandoverSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Closure;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\CLI\Commands\AccountMergeCommand;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountMergeCommandConstants;
use Hilos\Users\AccountMergeProgress;
use Hilos\Users\AccountMergeProgressChangeSubscriber;
use Hilos\Users\AccountMergeProgressResolver;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\SecondFactorFate;
use Hilos\Users\SecondFactorOutcome;

/**
 * Prepared merge replies, read directly for a saved summary and through the holder's parked commands.
 *
 * Summary cases keep the fixture that records results itself. Command cases raise the holder lazily.
 */
final class AccountMergeCommandIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;
    private const int SURVIVOR = 7;
    private const int LOSER = 9;
    private const string COORDINATOR_ID = 'merge-reply-coordinator';
    private const string CORRELATION_ID = 'merge-1';
    private const string CANCEL_MESSAGE = 'The account merge was canceled before any accounts were merged.';

    private const int GIVE_IDENTITY_ID = 41;
    private const int GIVE_OTHER_IDENTITY_ID = 82;

    private ?int $frameToken = null;
    private bool $copyReady = false;
    private ?AccountMergeCommandReplyPerson $survivor = null;
    private ?AccountMergeCommandReplyPerson $loser = null;
    private ?AccountMergeCommandReplyNotes $notes = null;
    private ?AccountMergeCommandReplySessions $sessions = null;

    private ?AccountMergeCommandHolder $commandHolder = null;

    private ?SignalRouter $previousRouter = null;

    /** @var ?class-string<Hilos> Application class to restore after a command case */
    private ?string $previousApp = null;

    private bool $passkeyReady = false;

    /** @var list<CommandReplyDTO> Command replies drained from the holder router */
    private array $commandReplies = [];

    /** @var list<SignalDTO> Operation frames drained from the holder router */
    private array $operationFrames = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->copyTable(false);
        $this->copyReady = true;
        $this->survivor = new AccountMergeCommandReplyPerson(self::SURVIVOR);
        $this->loser = new AccountMergeCommandReplyPerson(self::LOSER);
        $this->notes = new AccountMergeCommandReplyNotes();
        $this->sessions = new AccountMergeCommandReplySessions();
        OwnershipDeclaration::claimDb(AccountMergeCommandReplyCoordinator::class, self::COORDINATOR_ID);
        OwnershipDeclaration::claimAll($this->survivor);
        OwnershipDeclaration::claimAll($this->loser);
        OwnershipDeclaration::claimAll($this->notes);
        OwnershipDeclaration::claimAll($this->sessions);
        $this->frameToken = ExecutionContext::push(new ExecutionFrame(agentId: self::COORDINATOR_ID));
    }

    protected function tearDown(): void
    {
        $this->releaseCommandHolder();
        if ($this->frameToken !== null) {
            ExecutionContext::pop($this->frameToken);
        }
        $agentIds = [self::COORDINATOR_ID, $this->survivor?->getId(), $this->loser?->getId(), $this->notes?->getId(), $this->sessions?->getId()];
        foreach ($agentIds as $agentId) {
            if ($agentId !== null) {
                TruthSourceRegistry::unregisterAgent($agentId);
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
            }
        }
        if ($this->copyReady) {
            $this->copyTable(true);
        }
        parent::tearDown();
    }

    public function testCompletedSummaryKeepsSavedFactsAfterTheCopyAndPasswordChange(): void
    {
        self::seedIdentity(self::SURVIVOR, IdentityType::PASSWORD, 'survivor@example.test');
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        $built = $this->completed();
        Database::sqlRun(
            'INSERT INTO `hilos_account_merge_copy` (`assignment_id`, `owner_agent_id`, `payload_json`, `created_at`) VALUES (?, ?, ?, ?)',
            [$built['giveAssignmentId'], $this->loser->getId(), '{"identities":[1,2,3,4,5]}', 1],
        );
        $before = $this->reply($built['operationId']);
        $this->assertCompletedSummary($before);
        Database::sqlRun('DELETE FROM `hilos_account_merge_copy` WHERE `assignment_id` = ?', [$built['giveAssignmentId']]);
        Database::sqlRun(
            'UPDATE `hilos_identity` SET `user_id` = ?, `identifier` = ?, `secret` = ? WHERE `user_id` = ? AND `type` = ?',
            [self::LOSER, 'moved@example.test', 'replaced-secret', self::SURVIVOR, IdentityType::PASSWORD],
        );
        $after = $this->reply($built['operationId']);
        self::assertSame($before->payload[AccountMergeCommandConstants::FIELD_SUMMARY], $after->payload[AccountMergeCommandConstants::FIELD_SUMMARY]);
        $this->assertCompletedSummary($after);
    }

    public function testNotStartedWithoutRefusalCarriesTheCancelMessage(): void
    {
        $operation = $this->open(new AccountMergeChoice(PasswordFate::SURVIVOR, null, null, null));
        self::assertTrue($operation->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Cancelling->value));
        self::assertTrue($operation->actions->finish(AccountMergeStage::Cancelling->value, AccountOperationOutcome::NotStarted));
        $payload = $this->reply($operation->id)->payload;
        self::assertSame(self::CANCEL_MESSAGE, $payload[CommandConstants::FIELD_MESSAGE]);
        self::assertNull($payload[AccountMergeCommandConstants::FIELD_SUMMARY]);
        self::assertSame(AccountMergeProgress::END, $payload[AccountMergeCommandConstants::FIELD_MERGE][AccountMergeProgress::state]);
        self::assertSame(
            AccountOperationOutcome::NotStarted->value,
            $payload[AccountMergeCommandConstants::FIELD_MERGE][AccountMergeProgress::outcome],
        );
        self::assertNull($payload[AccountMergeCommandConstants::FIELD_MERGE][AccountMergeProgress::refusal]);
    }

    public function testBusinessRefusalOmitsTheCancelMessage(): void
    {
        $operation = $this->open(new AccountMergeChoice(null, null, null, null));
        $this->record(
            $this->survivor,
            $this->preparation($operation, AccountMergeDuty::PrepareSurvivor),
            AccountMergePreparationResult::reserved(4, []),
        );
        $this->record(
            $this->loser,
            $this->preparation($operation, AccountMergeDuty::PrepareLoser),
            AccountMergePreparationResult::reserved(8, []),
        );
        self::assertTrue($operation->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Cancelling->value));
        self::assertTrue($operation->actions->finish(AccountMergeStage::Cancelling->value, AccountOperationOutcome::NotStarted));
        $payload = $this->reply($operation->id)->payload;
        self::assertArrayNotHasKey(CommandConstants::FIELD_MESSAGE, $payload);
        self::assertNull($payload[AccountMergeCommandConstants::FIELD_SUMMARY]);
        self::assertSame(
            AccountOperationOutcome::NotStarted->value,
            $payload[AccountMergeCommandConstants::FIELD_MERGE][AccountMergeProgress::outcome],
        );
        self::assertSame(
            'Both accounts have a password: pass --password=survivor|loser|none',
            $payload[AccountMergeCommandConstants::FIELD_MERGE][AccountMergeProgress::refusal][AccountMergeProgress::reason],
        );
    }

    public function testPreparingAndWaitOmitSummaryAndMessage(): void
    {
        $operation = $this->open(new AccountMergeChoice(PasswordFate::SURVIVOR, null, null, null));
        $preparing = $this->reply($operation->id)->payload;
        self::assertSame(AccountMergeProgress::PREPARING, $preparing[AccountMergeCommandConstants::FIELD_MERGE][AccountMergeProgress::state]);
        self::assertNull($preparing[AccountMergeCommandConstants::FIELD_MERGE][AccountMergeProgress::outcome]);
        self::assertNull($preparing[AccountMergeCommandConstants::FIELD_SUMMARY]);
        self::assertArrayNotHasKey(CommandConstants::FIELD_MESSAGE, $preparing);
        self::assertTrue($operation->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Go->value));
        $waiting = $this->reply($operation->id)->payload;
        self::assertSame(AccountMergeProgress::WAIT, $waiting[AccountMergeCommandConstants::FIELD_MERGE][AccountMergeProgress::state]);
        self::assertNull($waiting[AccountMergeCommandConstants::FIELD_MERGE][AccountMergeProgress::outcome]);
        self::assertNull($waiting[AccountMergeCommandConstants::FIELD_SUMMARY]);
        self::assertArrayNotHasKey(CommandConstants::FIELD_MESSAGE, $waiting);
    }

    public function testBrokenResultsDoNotBecomeCompletedOrAZeroTally(): void
    {
        $built = $this->completed();
        $go = Database::sql('SELECT * FROM `hilos_account_operation_result` WHERE `id` = ?', [$built['goResultId']])->row();
        $preparing = Database::sql('SELECT * FROM `hilos_account_operation_result` WHERE `id` = ?', [$built['preparingResultId']])->row();
        $give = Database::sql('SELECT * FROM `hilos_account_operation_result` WHERE `id` = ?', [$built['giveResultId']])->row();
        foreach ([
            [$built['goResultId'], 'payload_json', '{"rows":{"messages":-1}}', $go['payload_json']],
            [$built['goResultId'], 'payload_json', '{}', $go['payload_json']],
            [$built['goResultId'], 'owner_agent_id', HilosAgentType::HILOS_USER . ':11', $go['owner_agent_id']],
            [$built['giveResultId'], 'payload_json', '{}', $give['payload_json']],
        ] as [$resultId, $column, $broken, $restored]) {
            Database::sqlRun(
                'UPDATE `hilos_account_operation_result` SET `' . $column . '` = ? WHERE `id` = ?',
                [$broken, $resultId],
            );
            $this->assertRefused($built['operationId']);
            Database::sqlRun(
                'UPDATE `hilos_account_operation_result` SET `' . $column . '` = ? WHERE `id` = ?',
                [$restored, $resultId],
            );
        }
        Database::sqlRun(
            'UPDATE `hilos_account_operation_result` SET `payload_json` = ? WHERE `id` = ?',
            ['{"rows":{"messages":' . PHP_INT_MAX . '}}', $built['preparingResultId']],
        );
        Database::sqlRun(
            'UPDATE `hilos_account_operation_result` SET `payload_json` = ? WHERE `id` = ?',
            ['{"rows":{"messages":1}}', $built['goResultId']],
        );
        $this->assertRefused($built['operationId']);
        Database::sqlRun(
            'UPDATE `hilos_account_operation_result` SET `payload_json` = ? WHERE `id` = ?',
            [$preparing['payload_json'], $built['preparingResultId']],
        );
        Database::sqlRun(
            'UPDATE `hilos_account_operation_result` SET `payload_json` = ? WHERE `id` = ?',
            [$go['payload_json'], $built['goResultId']],
        );
        Database::sqlRun('DELETE FROM `hilos_account_operation_result` WHERE `id` = ?', [$built['goResultId']]);
        $this->assertRefused($built['operationId']);
        $operation = Hilos::$db->accountOperations->fresh($built['operationId']);
        Database::sqlRun('DELETE FROM `hilos_account_operation` WHERE `id` = ?', [$built['operationId']]);
        AccountMergeProgressResolver::forgetAll();
        $this->assertRefusedOperation($operation);
        $erasure = Hilos::$db->accountOperations->actions->recordErasure(
            81,
            self::SURVIVOR,
            AccountOperationCircle::fromArray([self::SURVIVOR], self::SURVIVOR),
        );
        $this->assertRefusedOperation($erasure);
        $reply = $this->reply($this->completed()['operationId']);
        $this->assertCompletedSummary($reply);
    }

    public function testPreparingWaitsUntilTheDeadlineWithoutCancelling(): void
    {
        $this->start('preparing');
        self::assertSame([], $this->replies());
        $operation = $this->soleOperation();
        self::assertSame(AccountMergeStage::Preparing->value, $operation->stage);
        self::assertNull($operation->outcome);
        self::assertNull($operation->mergeChoice->passwordFate);
        self::assertNull($operation->mergeChoice->secondFactorFate);
        self::assertNull($operation->mergeChoice->expectedSurvivorHasSecondFactor);
        self::assertNull($operation->mergeChoice->expectedLoserHasSecondFactor);
        $this->sweep();
        $reply = $this->onlyReply();
        $this->assertOpenSnapshot($reply, $operation->id, AccountMergeProgress::PREPARING);
        $choice = $reply->payload[AccountMergeCommandConstants::FIELD_MERGE][AccountMergeProgress::choice];
        self::assertNull($choice[AccountMergeChoice::passwordFate]);
        self::assertNull($choice[AccountMergeChoice::secondFactorFate]);
        self::assertNull($choice[AccountMergeChoice::expectedSurvivorHasSecondFactor]);
        self::assertNull($choice[AccountMergeChoice::expectedLoserHasSecondFactor]);
        $this->sweep();
        self::assertSame([], $this->replies());
        $row = Database::sql(
            'SELECT `stage`, `outcome`, `finished_at` FROM `hilos_account_operation` WHERE `id` = ?',
            [$operation->id],
        )->row();
        self::assertSame(AccountMergeStage::Preparing->value, $row['stage']);
        self::assertNull($row['outcome']);
        self::assertNull($row['finished_at']);
        self::assertSame(
            0,
            count(Database::sql(
                'SELECT `id` FROM `hilos_account_operation_assignment` WHERE `operation_id` = ? AND `stage` = ?',
                [$operation->id, AccountMergeStage::Cancelling->value],
            )->rows()),
        );
        $this->start('named', 11, 13, [
            AccountMergeCommandConstants::FIELD_PASSWORD_FATE => PasswordFate::NONE->value,
            AccountMergeCommandConstants::FIELD_SECOND_FACTOR_FATE => SecondFactorFate::SURVIVOR->value,
        ]);
        $named = Hilos::$db->accountOperations->latestMergeNaming(13);
        self::assertNotNull($named);
        self::assertSame(PasswordFate::NONE, $named->mergeChoice->passwordFate);
        self::assertSame(SecondFactorFate::SURVIVOR, $named->mergeChoice->secondFactorFate);
        self::assertNull($named->mergeChoice->expectedSurvivorHasSecondFactor);
        self::assertNull($named->mergeChoice->expectedLoserHasSecondFactor);
        self::assertSame(2, $this->operationCount());
    }

    public function testWaitAfterGiveBecomesOneCompletedReply(): void
    {
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        $this->start('give');
        self::assertSame([], $this->replies());
        $operationId = $this->soleOperation()->id;
        $held = null;
        for ($round = 0; $round < 20; $round++) {
            $frames = $this->frames();
            if ($frames === []) {
                self::fail('The exchange ended before give without receive.');
            }
            $progress = AccountMergeProgressResolver::forOperation($operationId);
            self::assertNotNull($progress);
            if ($progress->given && !$progress->received && $progress->state === AccountMergeProgress::WAIT) {
                $held = $frames;
                break;
            }
            foreach ($frames as $frame) {
                $this->deliver($frame);
            }
        }
        self::assertNotNull($held);
        self::assertSame([], $this->replies());
        $preview = AccountMergeCommandReply::of('preview', Hilos::$db->accountOperations->fresh($operationId));
        self::assertNull($preview->payload[AccountMergeCommandConstants::FIELD_SUMMARY]);
        self::assertSame(AccountMergeProgress::WAIT, $preview->payload[AccountMergeCommandConstants::FIELD_MERGE][AccountMergeProgress::state]);
        foreach ($held as $frame) {
            $this->deliver($frame);
        }
        $this->finishExchange($operationId);
        $reply = $this->onlyReply();
        self::assertTrue($reply->isOk());
        $merge = $reply->payload[AccountMergeCommandConstants::FIELD_MERGE];
        self::assertSame(AccountMergeProgress::END, $merge[AccountMergeProgress::state]);
        self::assertSame(AccountOperationOutcome::Completed->value, $merge[AccountMergeProgress::outcome]);
        self::assertNotNull($reply->payload[AccountMergeCommandConstants::FIELD_SUMMARY]);
        self::assertSame(1, $reply->payload[AccountMergeCommandConstants::FIELD_SUMMARY][AccountMergeCommandConstants::FIELD_IDENTITIES_MOVED]);
        self::assertArrayNotHasKey(CommandConstants::FIELD_MESSAGE, $reply->payload);
        $this->sweep();
        self::assertSame([], $this->replies());
    }

    public function testBusinessRefusalStaysParkedUntilBothReleases(): void
    {
        $this->start('refusal');
        $asks = $this->frames();
        self::assertSame([], $this->replies());
        self::seedIdentity(self::SURVIVOR, IdentityType::PASSWORD, 'survivor@example.test');
        self::seedIdentity(self::LOSER, IdentityType::PASSWORD, 'loser@example.test');
        $before = Database::sql('SELECT * FROM `hilos_identity` ORDER BY `id`')->rows();
        $pending = [];
        foreach ($asks as $ask) {
            $this->deliver($ask);
            $answer = $this->frames();
            self::assertCount(1, $answer);
            $this->deliver($answer[0]);
            $pending = $this->frames();
        }
        $operation = $this->soleOperation();
        self::assertSame(AccountMergeRefusal::PasswordFateRequired, AccountMergeCoordinator::refusalOf($operation));
        self::assertNull($operation->outcome);
        self::assertSame([], $this->replies());
        self::assertSame(['release_loser', 'release_survivor'], $this->keys($pending));
        $this->deliver($pending[0]);
        $firstRelease = $this->frames();
        self::assertCount(1, $firstRelease);
        $this->deliver($firstRelease[0]);
        self::assertNull(Hilos::$db->accountOperations->fresh($operation->id)->outcome);
        self::assertSame([], $this->replies());
        $this->deliver($pending[1]);
        $secondRelease = $this->frames();
        self::assertCount(1, $secondRelease);
        $this->deliver($secondRelease[0]);
        $reply = $this->onlyReply();
        self::assertTrue($reply->isOk());
        $merge = $reply->payload[AccountMergeCommandConstants::FIELD_MERGE];
        self::assertSame(AccountMergeProgress::REFUSAL, $merge[AccountMergeProgress::state]);
        self::assertSame(AccountOperationOutcome::NotStarted->value, $merge[AccountMergeProgress::outcome]);
        self::assertSame(AccountMergeRefusal::PasswordFateRequired->value, $merge[AccountMergeProgress::refusal][AccountMergeProgress::code]);
        self::assertSame(
            'Both accounts have a password: pass --password=survivor|loser|none',
            $merge[AccountMergeProgress::refusal][AccountMergeProgress::reason],
        );
        self::assertNull($reply->payload[AccountMergeCommandConstants::FIELD_SUMMARY]);
        self::assertArrayNotHasKey(CommandConstants::FIELD_MESSAGE, $reply->payload);
        self::assertSame($before, Database::sql('SELECT * FROM `hilos_identity` ORDER BY `id`')->rows());
        $this->sweep();
        self::assertSame([], $this->replies());
    }

    public function testExplicitCancellationAnswersOnceAndObserveDoesNotRestart(): void
    {
        $this->start('cancel');
        $this->frames();
        $operation = $this->soleOperation();
        $this->asHolder(fn () => $this->holder()->cancel($operation));
        self::assertSame([], $this->replies());
        $this->finishCancellation();
        $reply = $this->onlyReply();
        self::assertTrue($reply->isOk());
        self::assertSame(self::CANCEL_MESSAGE, $reply->payload[CommandConstants::FIELD_MESSAGE]);
        $merge = $reply->payload[AccountMergeCommandConstants::FIELD_MERGE];
        self::assertSame(AccountMergeProgress::END, $merge[AccountMergeProgress::state]);
        self::assertSame(AccountOperationOutcome::NotStarted->value, $merge[AccountMergeProgress::outcome]);
        self::assertNull($merge[AccountMergeProgress::refusal]);
        self::assertNull($reply->payload[AccountMergeCommandConstants::FIELD_SUMMARY]);
        $count = $this->operationCount();
        $this->observeId('observe-id', $operation->id);
        $byId = $this->onlyReply();
        $this->observePair('observe-pair');
        $byPair = $this->onlyReply();
        self::assertSame($reply->payload, $byId->payload);
        self::assertSame($reply->payload, $byPair->payload);
        self::assertSame($count, $this->operationCount());
        $this->start('again', self::SURVIVOR, self::LOSER, [
            AccountMergeCommandConstants::FIELD_PASSWORD_FATE => PasswordFate::SURVIVOR->value,
        ]);
        self::assertSame([], $this->replies());
        self::assertSame($count + 1, $this->operationCount());
        $new = Hilos::$db->accountOperations->openMergeOfLoser(self::LOSER);
        self::assertNotNull($new);
        self::assertNotSame($operation->id, $new->id);
        self::assertSame(PasswordFate::SURVIVOR, $new->mergeChoice->passwordFate);
        self::assertCount(2, $this->frames());
    }

    public function testTwoWaitsAnswerOnceAcrossADuplicateFrameAndARepeatedSweep(): void
    {
        $this->start('first');
        $this->frames();
        $this->start('repeat');
        $operation = $this->soleOperation();
        $asks = $this->frames();
        self::assertNotEmpty($asks);
        self::assertSame([], $this->replies());
        $this->deliver($asks[0]);
        $answer = $this->frames();
        self::assertCount(1, $answer);
        $this->deliver($answer[0]);
        $this->deliver($answer[0]);
        self::assertSame([], $this->replies());
        $this->sweep();
        $replies = $this->replies();
        self::assertCount(2, $replies);
        self::assertEqualsCanonicalizing(
            ['first', 'repeat'],
            array_map(static fn (CommandReplyDTO $reply): string => $reply->correlationId, $replies),
        );
        foreach ($replies as $reply) {
            $this->assertOpenSnapshot($reply, $operation->id, AccountMergeProgress::PREPARING);
        }
        $this->sweep();
        self::assertSame([], $this->replies());
        $this->deliver($answer[0]);
        self::assertSame([], $this->replies());
        $this->sweep();
        self::assertSame([], $this->replies());
        self::assertSame(1, $this->operationCount());
    }

    public function testObservePairAfterALostReplyReadsTheSameEnd(): void
    {
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        $this->start('lost');
        $operationId = $this->soleOperation()->id;
        $this->finishExchange($operationId);
        $lost = $this->onlyReply();
        self::assertTrue($lost->isOk());
        self::assertNotNull($lost->payload[AccountMergeCommandConstants::FIELD_SUMMARY]);
        $facts = $this->facts();
        $this->observePair('found');
        $byPair = $this->onlyReply();
        $this->observeId('again', $operationId);
        $byId = $this->onlyReply();
        self::assertSame($lost->payload, $byPair->payload);
        self::assertSame($lost->payload, $byId->payload);
        self::assertSame($facts, $this->facts());
        self::assertSame([], $this->frames());
    }

    public function testObserveOpenHearsAnotherDriveWithoutSendingOne(): void
    {
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        $operation = $this->asHolder(fn (): AccountOperation => $this->holder()->open(
            self::SURVIVOR,
            self::LOSER,
            new AccountMergeChoice(null, null, null, null),
        ));
        $this->frames();
        $choice = $operation->mergeChoice->toJson();
        $assignments = Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows();
        $results = Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows();
        $this->observeId('watching', $operation->id);
        self::assertSame([], $this->frames());
        self::assertSame([], $this->replies());
        self::assertSame($choice, Hilos::$db->accountOperations->fresh($operation->id)->mergeChoice->toJson());
        self::assertSame($assignments, Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows());
        self::assertSame($results, Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
        $this->asHolder(fn () => $this->holder()->drive(Hilos::$db->accountOperations->fresh($operation->id)));
        $this->finishExchange($operation->id);
        $reply = $this->onlyReply();
        self::assertSame('watching', $reply->correlationId);
        self::assertTrue($reply->isOk());
        self::assertSame(
            AccountOperationOutcome::Completed->value,
            $reply->payload[AccountMergeCommandConstants::FIELD_MERGE][AccountMergeProgress::outcome],
        );
        self::assertNotNull($reply->payload[AccountMergeCommandConstants::FIELD_SUMMARY]);
        self::assertSame(1, $this->operationCount());
    }

    public function testObserveRefusesUnknownErasureForeignPairAbsenceAndTombstone(): void
    {
        $this->holder();
        $this->observeId('missing', 424242);
        $this->assertError('missing', 'No saved merge has that operation id.');
        self::assertSame(0, $this->operationCount());
        $erasure = Hilos::$db->accountOperations->actions->recordErasure(
            81,
            self::SURVIVOR,
            AccountOperationCircle::fromArray([self::SURVIVOR], self::SURVIVOR),
        );
        $this->observeId('erasure', $erasure->id);
        $this->assertError('erasure', 'No saved merge has that operation id.');
        $older = AccountMergeOpening::open(self::SURVIVOR, self::LOSER, new AccountMergeChoice(null, null, null, null));
        self::assertTrue($older->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Cancelling->value));
        self::assertTrue($older->actions->finish(AccountMergeStage::Cancelling->value, AccountOperationOutcome::NotStarted));
        $newer = AccountMergeOpening::open(11, self::LOSER, new AccountMergeChoice(null, null, null, null));
        self::assertGreaterThan($older->id, $newer->id);
        $this->observePair('older', self::SURVIVOR, self::LOSER);
        $this->assertError(
            'older',
            'The latest recorded merge names a different pair. Use --operation to observe an earlier operation.',
        );
        $this->observePair('absent', self::SURVIVOR, 13);
        $this->assertError(
            'absent',
            'No recorded merge was found for this pair. The outcome of the earlier request is unknown.',
        );
        Database::sqlRun(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (13, 11, 1)',
        );
        $count = $this->operationCount();
        $this->observePair('tomb', 11, 13);
        $this->assertError(
            'tomb',
            'No recorded merge was found for this pair. The outcome of the earlier request is unknown.',
        );
        self::assertSame($count, $this->operationCount());
        self::assertSame([], $this->frames());
    }

    public function testPreparedRequestRejectsABadShapeBeforeOpening(): void
    {
        $this->holder();
        $start = [
            AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_START,
            AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID => self::SURVIVOR,
            AccountMergeCommandConstants::FIELD_LOSER_USER_ID => self::LOSER,
        ];
        $cases = [
            'mode' => [[], 'The account merge command requires mode start or observe.'],
            'unknown-mode' => [[AccountMergeCommandConstants::FIELD_MODE => 'later'], 'The account merge command requires mode start or observe.'],
            'string-id' => [[
                ...$start,
                AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID => '7',
            ], 'The account merge command requires a positive integer account id.'],
            'same-id' => [[
                ...$start,
                AccountMergeCommandConstants::FIELD_LOSER_USER_ID => self::SURVIVOR,
            ], 'The merge requires two different positive account ids.'],
            'start-operation' => [[
                ...$start,
                AccountMergeCommandConstants::FIELD_OPERATION_ID => 1,
            ], 'The account merge command has an unexpected field.'],
            'fate' => [[
                ...$start,
                AccountMergeCommandConstants::FIELD_PASSWORD_FATE => 'keep',
            ], 'Unknown account-merge password fate'],
            'observe-fate' => [[
                AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_OBSERVE,
                AccountMergeCommandConstants::FIELD_OPERATION_ID => 1,
                AccountMergeCommandConstants::FIELD_PASSWORD_FATE => PasswordFate::SURVIVOR->value,
            ], 'An account merge observation does not choose a password or a second factor.'],
            'mixed' => [[
                AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_OBSERVE,
                AccountMergeCommandConstants::FIELD_OPERATION_ID => 1,
                AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID => self::SURVIVOR,
                AccountMergeCommandConstants::FIELD_LOSER_USER_ID => self::LOSER,
            ], 'The account merge command needs exactly one address: an operation id, or a survivor and a loser.'],
            'neither' => [[
                AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_OBSERVE,
            ], 'The account merge command needs exactly one address: an operation id, or a survivor and a loser.'],
            'half-pair' => [[
                AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_OBSERVE,
                AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID => self::SURVIVOR,
            ], 'The account merge command needs exactly one address: an operation id, or a survivor and a loser.'],
            'string-operation' => [[
                AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_OBSERVE,
                AccountMergeCommandConstants::FIELD_OPERATION_ID => '1',
            ], 'The account merge command requires a positive integer account id.'],
            'missing-user' => [[
                AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_START,
                AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID => 21,
                AccountMergeCommandConstants::FIELD_LOSER_USER_ID => 22,
            ], 'No such user: 21'],
        ];
        foreach ($cases as $name => [$payload, $message]) {
            $this->handle($this->command($name, $payload));
            $this->assertError($name, $message);
        }
        self::assertSame(0, $this->operationCount());
        self::assertSame([], $this->frames());
    }

    public function testReadErrorRemovesTheParkedWaitWithOneError(): void
    {
        $this->start('broken');
        $operation = $this->soleOperation();
        self::assertSame([], $this->replies());
        Database::sqlRun('UPDATE `hilos_account_operation` SET `stage` = ? WHERE `id` = ?', ['broken', $operation->id]);
        AccountMergeProgressResolver::forgetAll();
        $this->sweep();
        $reply = $this->onlyReply();
        self::assertFalse($reply->isOk());
        self::assertSame(
            'Merge observation requires a saved distinct pair, known stage and durable outcome.',
            $reply->payload[CommandConstants::FIELD_MESSAGE],
        );
        self::assertArrayNotHasKey(AccountMergeCommandConstants::FIELD_SUMMARY, $reply->payload);
        $this->sweep();
        self::assertSame([], $this->replies());
        self::assertNull(Database::sql(
            'SELECT `outcome` FROM `hilos_account_operation` WHERE `id` = ?',
            [$operation->id],
        )->row()['outcome']);
    }

    public function testLostTransportLeavesTheOpenMergeReadable(): void
    {
        $spy = new AccountMergeCommandTransportSpy();
        $cases = [
            [CommandChannelResult::timedOut('127.0.0.1:8094'), self::SURVIVOR, self::LOSER],
            [CommandChannelResult::unreachable('127.0.0.1:8094'), 11, 13],
        ];
        foreach ($cases as [$loss, $survivor, $loser]) {
            $spy->transport = function (string $command, array $payload) use ($loss): CommandChannelResult {
                $this->handle(new CommandRequestDTO(
                    'lost-' . $payload[AccountMergeCommandConstants::FIELD_LOSER_USER_ID],
                    $command,
                    $payload,
                ));
                self::assertSame([], $this->replies());

                return $loss;
            };
            $sent = count($spy->sent);
            $spy->standardError = '';
            self::assertSame(ExitCode::ERROR, $spy->execute([], [(string)$survivor, (string)$loser]));
            self::assertCount($sent + 1, $spy->sent);
            self::assertStringContainsString(
                'The merge outcome is unknown; the channel failure did not cancel it.',
                $spy->standardError,
            );
            self::assertStringContainsString("account:merge {$survivor} {$loser} --observe", $spy->standardError);
            self::assertStringNotContainsString('merged', $spy->standardError);
            self::assertStringNotContainsString('canceled', $spy->standardError);
            $operation = Hilos::$db->accountOperations->latestMergeNaming($loser);
            self::assertNotNull($operation);
            self::assertNull($operation->outcome);
            self::assertSame(AccountMergeProgress::PREPARING, $this->savedProgress($operation->id)->state);
        }
        self::assertSame(2, $this->operationCount());
        $this->sweep();
        $replies = $this->replies();
        self::assertCount(2, $replies);
        foreach ($replies as $reply) {
            self::assertTrue($reply->isOk());
            self::assertNull($reply->payload[AccountMergeCommandConstants::FIELD_SUMMARY]);
            $merge = $reply->payload[AccountMergeCommandConstants::FIELD_MERGE];
            self::assertSame(AccountMergeProgress::PREPARING, $merge[AccountMergeProgress::state]);
            self::assertNull($merge[AccountMergeProgress::outcome]);
        }
        self::assertSame(2, $this->operationCount());
    }

    public function testRepeatedStartAfterCompletedPrintsThatSnapshot(): void
    {
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        $this->start('first');
        $operationId = $this->soleOperation()->id;
        $this->finishExchange($operationId);
        $saved = $this->onlyReply();
        $summary = $saved->payload[AccountMergeCommandConstants::FIELD_SUMMARY];
        self::assertIsArray($summary);
        $spy = $this->ownerTransport();
        $identities = $summary[AccountMergeCommandConstants::FIELD_IDENTITIES_MOVED];
        $this->expectOutputRegex(
            '/Operation ' . $operationId . ', pair ' . self::SURVIVOR . ' ' . self::LOSER
            . '.*' . $identities . ' identities.*Password:.*Second factor:/s',
        );
        self::assertSame(ExitCode::SUCCESS, $spy->execute([], [(string)self::SURVIVOR, (string)self::LOSER]));
        self::assertSame(1, $this->operationCount());
        self::assertSame($operationId, $this->soleOperation()->id);
        self::assertSame(AccountMergeCommandConstants::MODE_START, $spy->sent[0][AccountMergeCommandConstants::FIELD_MODE]);
        self::assertArrayNotHasKey(AccountMergeCommandConstants::FIELD_PASSWORD_FATE, $spy->sent[0]);
    }

    public function testRepeatedStartAfterNotStartedOpensANewOperation(): void
    {
        $this->start('cancel');
        $this->frames();
        $operation = $this->soleOperation();
        $this->asHolder(fn () => $this->holder()->cancel($operation));
        $this->finishCancellation();
        $saved = $this->onlyReply();
        self::assertSame(self::CANCEL_MESSAGE, $saved->payload[CommandConstants::FIELD_MESSAGE]);
        $spy = new AccountMergeCommandTransportSpy();
        $spy->transport = function (string $command, array $payload): CommandChannelResult {
            $this->handle(new CommandRequestDTO('again', $command, $payload));
            self::assertSame([], $this->replies());
            $this->sweep();

            return CommandChannelResult::replied($this->onlyReply(), '127.0.0.1:8094');
        };
        $this->expectOutputRegex('/Operation [0-9]+, pair ' . self::SURVIVOR . ' ' . self::LOSER . '.*preparing/s');
        self::assertSame(ExitCode::TIMEOUT, $spy->execute([], [(string)self::SURVIVOR, (string)self::LOSER]));
        self::assertSame('', $spy->standardError);
        self::assertSame(2, $this->operationCount());
        $new = Hilos::$db->accountOperations->openMergeOfLoser(self::LOSER);
        self::assertNotNull($new);
        self::assertNotSame($operation->id, $new->id);
        self::assertNull($new->mergeChoice->passwordFate);
        self::assertArrayNotHasKey(AccountMergeCommandConstants::FIELD_PASSWORD_FATE, $spy->sent[0]);
    }

    public function testRegisteredCommandDispatchesTheMergeProtocol(): void
    {
        $classes = (new CliManager([]))->commandClasses();
        self::assertSame(AccountMergeCommand::class, $classes[CliCommands::ACCOUNT_MERGE]);
        self::assertSame(1, count(array_filter($classes, static fn (string $class): bool => $class === AccountMergeCommand::class)));
        $this->holder();
        $before = $this->operationCount();
        $this->asHolder(fn () => $this->holder()->onSignalCommand(
            new CommandRequestDTO('prod', CliCommands::ACCOUNT_MERGE, [
                AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_OBSERVE,
                AccountMergeCommandConstants::FIELD_OPERATION_ID => 1,
            ]),
            '',
            '',
        ));
        $reply = $this->onlyReply();
        self::assertFalse($reply->isOk());
        self::assertSame('No saved merge has that operation id.', $reply->payload[CommandConstants::FIELD_MESSAGE]);
        self::assertArrayNotHasKey(AccountMergeCommandConstants::FIELD_MERGE, $reply->payload);
        self::assertSame($before, $this->operationCount());
    }

    private function ownerTransport(): AccountMergeCommandTransportSpy
    {
        $spy = new AccountMergeCommandTransportSpy();
        $spy->transport = function (string $command, array $payload) use ($spy): CommandChannelResult {
            $this->handle(new CommandRequestDTO('cli-' . count($spy->sent), $command, $payload));
            $replies = $this->replies();
            self::assertCount(1, $replies);

            return CommandChannelResult::replied($replies[0], '127.0.0.1:8094');
        };

        return $spy;
    }

    private function assertCompletedSummary(CommandReplyDTO $reply): void
    {
        self::assertTrue($reply->isOk());
        $merge = $reply->payload[AccountMergeCommandConstants::FIELD_MERGE];
        self::assertSame($merge, AccountMergeProgressResolver::forOperation($merge[AccountMergeProgress::operationId])->toArray());
        self::assertSame(AccountMergeProgress::END, $merge[AccountMergeProgress::state]);
        self::assertSame(AccountOperationOutcome::Completed->value, $merge[AccountMergeProgress::outcome]);
        self::assertArrayNotHasKey(CommandConstants::FIELD_MESSAGE, $reply->payload);
        self::assertSame([
            AccountMergeCommandConstants::FIELD_IDENTITIES_MOVED => 2,
            AccountMergeCommandConstants::FIELD_ROWS_MOVED => ['messages' => 7, 'threads' => 1],
            AccountMergeCommandConstants::FIELD_PASSWORD_KEPT => PasswordFate::SURVIVOR->value,
            AccountMergeCommandConstants::FIELD_SECOND_FACTOR_KEPT => SecondFactorOutcome::BOTH->value,
        ], $reply->payload[AccountMergeCommandConstants::FIELD_SUMMARY]);
        $json = json_encode($reply->payload, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString((string)self::GIVE_IDENTITY_ID, $json);
        self::assertStringNotContainsString((string)self::GIVE_OTHER_IDENTITY_ID, $json);
        self::assertStringNotContainsString('admission', $json);
    }

    /** @return array{operationId: int, giveAssignmentId: int, giveResultId: int, preparingResultId: int, goResultId: int} */
    private function completed(): array
    {
        $operation = $this->open(new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, null, null));
        $preparing = $this->expectation($operation, AccountMergeStage::Preparing);
        $preparingResult = $this->record($this->notes, $preparing, AccountMergeProjectResult::payloadOf(['messages' => 3, 'threads' => 1]));
        self::assertTrue($operation->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Go->value));
        $going = $this->expectation($operation, AccountMergeStage::Go);
        $give = $this->duty($operation, AccountMergeDuty::Give, new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)self::LOSER));
        $sessions = $this->duty($operation, AccountMergeDuty::Sessions, new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY));
        $wait = new AccountOperationExpectation('hold', self::LOSER, null);
        $waiting = Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            AccountMergeStage::Go->value,
            $wait->assignmentKey(),
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY),
            self::LOSER,
            $wait->payload(),
        );
        $goResult = $this->record($this->notes, $going, AccountMergeProjectResult::payloadOf(['messages' => 4]));
        $giveResult = $this->record($this->loser, $give, $this->givePayload());
        $this->record($this->sessions, $sessions, (new AccountMergeSessionsResult(40, 40))->toPayload());
        $this->record($this->sessions, $waiting, AccountMergeProjectResult::payloadOf(['messages' => 100]));
        self::assertTrue($operation->actions->finish(AccountMergeStage::Go->value, AccountOperationOutcome::Completed));
        self::assertCount(2, AccountOperationExpectedSet::of($operation->id)->expected());

        return [
            'operationId' => $operation->id,
            'giveAssignmentId' => $give->id,
            'giveResultId' => $giveResult->id,
            'preparingResultId' => $preparingResult->id,
            'goResultId' => $goResult->id,
        ];
    }

    private function open(AccountMergeChoice $choice): AccountOperation
    {
        return AccountMergeOpening::open(self::SURVIVOR, self::LOSER, $choice);
    }

    private function expectation(AccountOperation $operation, AccountMergeStage $stage): AccountOperationAssignment
    {
        $owner = new AccountOperationOwnerAddress($this->notes->getType(), $this->notes->getIndex());
        $expectation = new AccountOperationExpectation('notes', self::LOSER, $owner);

        return Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            $stage->value,
            $expectation->assignmentKey(),
            $owner,
            self::LOSER,
            $expectation->payload(),
        );
    }

    private function duty(
        AccountOperation $operation,
        AccountMergeDuty $duty,
        AccountOperationOwnerAddress $owner,
    ): AccountOperationAssignment {
        return Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            AccountMergeStage::Go->value,
            $duty->value,
            $owner,
            self::LOSER,
            AccountOperationAssignmentPayload::fromJson('{}'),
        );
    }

    private function preparation(AccountOperation $operation, AccountMergeDuty $duty): AccountOperationAssignment
    {
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Preparing->value, $duty->value);
        self::assertNotNull($assignment);

        return $assignment;
    }

    private function givePayload(): AccountOperationResultPayload
    {
        return (new AccountMergeGiveResult(
            identities: [self::GIVE_IDENTITY_ID, self::GIVE_OTHER_IDENTITY_ID],
            passkeyCredentials: [],
            secondFactors: [],
            secondFactorBackupCodes: [],
            passwordKept: PasswordFate::SURVIVOR,
            secondFactorKept: SecondFactorOutcome::BOTH->value,
            admission: new AccountMergeAdmissionManifest(
                identities: [
                    new AccountMergeRowRevision(self::GIVE_IDENTITY_ID, 1),
                    new AccountMergeRowRevision(self::GIVE_OTHER_IDENTITY_ID, 1),
                ],
                passkeyCredentials: [],
                secondFactors: [],
                secondFactorBackupCodes: [],
            ),
            effectiveWaitDays: null,
        ))->toPayload();
    }

    private function record(
        AbstractAgent $owner,
        AccountOperationAssignment $assignment,
        AccountOperationResultPayload $payload,
    ): AccountOperationResult {
        return ExecutionContext::run(
            new ExecutionFrame(agentId: $owner->getId()),
            static function () use ($assignment, $payload): AccountOperationResult {
                return Database::transaction(
                    DatabaseNames::PRIMARY,
                    static fn (): AccountOperationResult => Hilos::$db->accountOperationResults->actions->record($assignment, $payload),
                );
            },
        );
    }

    private function reply(int $operationId): CommandReplyDTO
    {
        $this->reread();
        $operation = Hilos::$db->accountOperations->fresh($operationId);
        self::assertNotNull($operation);

        return AccountMergeCommandReply::of(self::CORRELATION_ID, $operation);
    }

    private function assertRefused(int $operationId): void
    {
        $this->reread();
        $operation = Hilos::$db->accountOperations->fresh($operationId);
        self::assertNotNull($operation);
        $this->assertRefusedOperation($operation);
    }

    private function assertRefusedOperation(AccountOperation $operation): void
    {
        AccountMergeProgressResolver::forgetAll();
        try {
            AccountMergeCommandReply::of(self::CORRELATION_ID, $operation);
        } catch (InvalidFormatException | InvalidJsonException) {
            return;
        }
        self::fail('A broken merge read returned a reply.');
    }

    private function reread(): void
    {
        Hilos::$db = new HilosSessionTestDbContext();
        Hilos::$db->configure();
        AccountMergeProgressResolver::forgetAll();
    }

    /** @param bool $down Whether to drop the copy table */
    private function copyTable(bool $down): void
    {
        $fileName = $down
            ? 'create_hilos_account_merge_copy_down.sql'
            : 'create_hilos_account_merge_copy.sql';
        Database::sqlRun((string)file_get_contents(
            dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/' . $fileName,
        ));
    }

    private function holder(): AccountMergeCommandHolder
    {
        if ($this->commandHolder !== null) {
            return $this->commandHolder;
        }
        $this->previousApp = Hilos::appClass();
        AccountMergeCommandHilos::initBrowser();
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        SourceChangeBus::subscribe(new AccountMergeProgressChangeSubscriber());
        AccountMergeProgressResolver::forgetAll();
        Database::sqlRun(
            "INSERT IGNORE INTO `hilos_user` (`id`, `name`) VALUES (7, 'Survivor'), (9, 'Loser'), (11, 'Other'), (13, 'Peer')",
        );
        Database::sqlRun((string)file_get_contents(
            dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_passkey_credential.sql',
        ));
        $this->passkeyReady = true;
        $this->commandHolder = new AccountMergeCommandHolder();
        OwnershipDeclaration::claimAll($this->commandHolder);

        return $this->commandHolder;
    }

    private function releaseCommandHolder(): void
    {
        $this->releasePersonAgents();
        if ($this->commandHolder !== null) {
            TruthSourceRegistry::unregisterAgent($this->commandHolder->getId());
            RtTruthSourceRegistry::unregisterAgent($this->commandHolder->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->commandHolder->getId()));
            $this->commandHolder = null;
        }
        if ($this->previousRouter !== null) {
            Hilos::$sr = $this->previousRouter;
            $this->previousRouter = null;
        }
        $app = $this->previousApp;
        if ($app !== null) {
            $app::initBrowser();
            Hilos::resetBrowser();
            $this->previousApp = null;
        }
        if ($this->passkeyReady) {
            Database::sqlRun((string)file_get_contents(
                dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_passkey_credential_down.sql',
            ));
            $this->passkeyReady = false;
        }
        AccountMergeProgressResolver::forgetAll();
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function asHolder(callable $work): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->holder()->getId()), $work);
    }

    /** @param array<string, mixed> $payload */
    private function command(string $correlationId, array $payload): CommandRequestDTO
    {
        return new CommandRequestDTO($correlationId, CliCommands::ACCOUNT_MERGE, $payload);
    }

    private function handle(CommandRequestDTO $command): void
    {
        $this->asHolder(fn () => $this->holder()->onSignalCommand($command, '', ''));
    }

    /** @param array<string, mixed> $extra Fate keys the start names */
    private function start(
        string $correlationId,
        int $survivor = self::SURVIVOR,
        int $loser = self::LOSER,
        array $extra = [],
    ): void {
        $this->handle($this->command($correlationId, [
            AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_START,
            AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID => $survivor,
            AccountMergeCommandConstants::FIELD_LOSER_USER_ID => $loser,
            ...$extra,
        ]));
    }

    private function observeId(string $correlationId, int $operationId): void
    {
        $this->handle($this->command($correlationId, [
            AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_OBSERVE,
            AccountMergeCommandConstants::FIELD_OPERATION_ID => $operationId,
        ]));
    }

    private function observePair(string $correlationId, int $survivor = self::SURVIVOR, int $loser = self::LOSER): void
    {
        $this->handle($this->command($correlationId, [
            AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_OBSERVE,
            AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID => $survivor,
            AccountMergeCommandConstants::FIELD_LOSER_USER_ID => $loser,
        ]));
    }

    private function drain(): void
    {
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof CommandReplyDTO) {
                $this->commandReplies[] = $frame->data;
            } elseif ($frame->data instanceof AgentSignalData
                && ($frame->data->data instanceof AccountOperationAskSignalData
                    || $frame->data->data instanceof AccountOperationAnswerSignalData
                    || $frame->data->data instanceof AccountMergeHandoverSignalData)) {
                $this->operationFrames[] = $frame;
            }
        }
    }

    /** @return list<CommandReplyDTO> */
    private function replies(): array
    {
        $this->drain();
        $replies = $this->commandReplies;
        $this->commandReplies = [];

        return $replies;
    }

    /** @return list<SignalDTO> */
    private function frames(): array
    {
        $this->drain();
        $frames = $this->operationFrames;
        $this->operationFrames = [];

        return $frames;
    }

    private function onlyReply(): CommandReplyDTO
    {
        $replies = $this->replies();
        self::assertCount(1, $replies);

        return $replies[0];
    }

    private function assertError(string $correlationId, string $message): void
    {
        $reply = $this->onlyReply();
        self::assertSame($correlationId, $reply->correlationId);
        self::assertFalse($reply->isOk());
        self::assertSame($message, $reply->payload[CommandConstants::FIELD_MESSAGE]);
    }

    private function assertOpenSnapshot(CommandReplyDTO $reply, int $operationId, string $state): void
    {
        self::assertTrue($reply->isOk());
        $merge = $reply->payload[AccountMergeCommandConstants::FIELD_MERGE];
        self::assertSame($operationId, $merge[AccountMergeProgress::operationId]);
        self::assertSame(self::SURVIVOR, $merge[AccountMergeProgress::survivorUserId]);
        self::assertSame(self::LOSER, $merge[AccountMergeProgress::loserUserId]);
        self::assertSame($state, $merge[AccountMergeProgress::state]);
        self::assertNull($merge[AccountMergeProgress::outcome]);
        self::assertNull($reply->payload[AccountMergeCommandConstants::FIELD_SUMMARY]);
        self::assertArrayNotHasKey(CommandConstants::FIELD_MESSAGE, $reply->payload);
    }

    private function deliver(SignalDTO $queued): void
    {
        $frame = SignalDTO::fromArray(json_decode(
            json_encode($queued->toArray(), JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
        self::assertInstanceOf(AgentSignalData::class, $frame->data);
        $data = Hilos::$sr->createAgentSignalPayloadDTO($frame->signalName->getName(), $frame->data);
        $sender = SignalSource::describe($frame->signalSource);
        if ($data->data instanceof AccountOperationAnswerSignalData) {
            $this->asHolder(fn () => $this->holder()->onSignalAgent($data, $sender, $frame->signalName->getName()));

            return;
        }
        self::assertTrue(
            $data->data instanceof AccountOperationAskSignalData || $data->data instanceof AccountMergeHandoverSignalData,
        );
        self::assertNotNull($data->data->ownerIndex);
        $this->asPerson((int)$data->data->ownerIndex, static fn (AbstractUserAgent $agent) => $agent->onSignalAgent(
            $data,
            $sender,
            $frame->signalName->getName(),
        ));
    }

    private function finishExchange(int $operationId): void
    {
        for ($round = 0; $round < 20; $round++) {
            $frames = $this->frames();
            if ($frames === []) {
                self::assertSame(AccountOperationOutcome::Completed->value, $this->savedProgress($operationId)->outcome);

                return;
            }
            foreach ($frames as $frame) {
                $this->deliver($frame);
            }
        }
        self::fail('The merge exchange did not finish.');
    }

    private function finishCancellation(): void
    {
        for ($round = 0; $round < 8; $round++) {
            $frames = $this->frames();
            if ($frames === []) {
                return;
            }
            foreach ($frames as $frame) {
                $this->deliver($frame);
            }
        }
        self::fail('The cancellation exchange did not finish.');
    }

    private function savedProgress(int $operationId): AccountMergeProgress
    {
        $progress = AccountMergeProgressResolver::forOperation($operationId);
        self::assertNotNull($progress);

        return $progress;
    }

    private function soleOperation(): AccountOperation
    {
        self::assertSame(1, $this->operationCount());
        $operation = Hilos::$db->accountOperations->latestMergeNaming(self::LOSER);
        self::assertNotNull($operation);

        return $operation;
    }

    private function operationCount(): int
    {
        return count(Database::sql('SELECT `id` FROM `hilos_account_operation`')->rows());
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function facts(): array
    {
        $facts = [];
        foreach ([
            'hilos_account_operation',
            'hilos_account_operation_assignment',
            'hilos_account_operation_result',
            'hilos_identity',
            'hilos_user_merge',
        ] as $table) {
            $facts[$table] = Database::sql('SELECT * FROM `' . $table . '`')->rows();
        }

        return $facts;
    }

    /** @param list<SignalDTO> $frames */
    private function keys(array $frames): array
    {
        return array_map(static fn (SignalDTO $frame): string => Hilos::$db->accountOperationAssignments[
            $frame->data->data->assignmentId
        ]->assignmentKey, $frames);
    }

    private function sweep(): void
    {
        $this->asHolder(fn () => $this->holder()->sweep(microtime(true) + CommandChannelWindows::AGENT_WAIT_SECONDS));
    }
}

/** Thin access to the live merge command and explicit deadline sweeps. */
final class AccountMergeCommandHolder extends AbstractSessionsLibraryAgent
{
    public function sweep(float $now): void
    {
        $this->sweepAccountMergeCommands($now);
    }

    public function open(int $survivor, int $loser, AccountMergeChoice $choice): AccountOperation
    {
        return $this->openAccountMerge($survivor, $loser, $choice);
    }

    public function drive(AccountOperation $operation): void
    {
        $this->driveAccountMerge($operation);
    }

    public function cancel(AccountOperation $operation): AccountMergeCancellationState
    {
        return $this->cancelAccountMerge($operation);
    }
}

/**
 * Stands in for the socket: the payload goes to the prepared holder, and the real reply comes back.
 */
final class AccountMergeCommandTransportSpy extends AccountMergeCommand
{
    /** @var Closure(string, array<string, mixed>): CommandChannelResult */
    public Closure $transport;

    /** @var list<array<string, mixed>> Payloads the command sent */
    public array $sent = [];

    /** @var string Text sent to stderr */
    public string $standardError = '';

    /**
     * Hands the payload to the test's transport instead of opening a socket.
     *
     * @param string $command Command-channel wire name
     * @param array<string, mixed> $payload Request payload
     * @param ?float $waitSeconds Wait budget (ignored)
     * @return CommandChannelResult Reply the transport built from the holder
     */
    protected function sendCommand(
        string $command,
        array $payload,
        ?float $waitSeconds = null,
    ): CommandChannelResult {
        $this->sent[] = $payload;

        return ($this->transport)($command, $payload);
    }

    /** Captures stderr output instead of writing it during the suite. */
    protected function writeToStandardError(string $text): void
    {
        $this->standardError .= $text . "\n";
    }
}

/** Registers the personal owner the prepared exchange delivers to. */
abstract class AccountMergeCommandHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [
            AgentRegistryKey::WORKER => IntegrationUserAgent::class,
            AgentRegistryKey::INDEXED => true,
        ],
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [AgentRegistryKey::WORKER => AccountMergeCommandHolder::class],
    ];
}

final class AccountMergeCommandReplyCoordinator extends AbstractSessionsLibraryAgent
{
}

/** Loser and survivor each record only the results addressed to themselves. */
final class AccountMergeCommandReplyPerson extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_USER;

    public const array OWNS_DB_SET = [HilosDbContext::accountOperationResults => [TruthSourceOperation::Add]];

    public const array READS_DB = [HilosDbContext::accountOperations, HilosDbContext::accountOperationAssignments];

    public function __construct(int $userId)
    {
        $this->agentIndex = (string)$userId;
    }

    public function ownedDbSetKey(string $collection): string
    {
        return $this->getId();
    }

    public function onStop(): void
    {
    }
}

/** Project owner of both the carried preparing expectation and the go expectation. */
final class AccountMergeCommandReplyNotes extends AbstractAgent
{
    public const string AGENT_TYPE = 'merge_reply_notes';

    public const array OWNS_DB_SET = [HilosDbContext::accountOperationResults => [TruthSourceOperation::Add]];

    public const array READS_DB = [HilosDbContext::accountOperations, HilosDbContext::accountOperationAssignments];

    public function __construct()
    {
        $this->agentIndex = 'a';
    }

    public function ownedDbSetKey(string $collection): string
    {
        return $this->getId();
    }

    public function onStop(): void
    {
    }
}

/** Session holder, the only writer of its own session result and of a wait result. */
final class AccountMergeCommandReplySessions extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_SESSIONS_LIBRARY;

    public const array OWNS_DB_SET = [HilosDbContext::accountOperationResults => [TruthSourceOperation::Add]];

    public const array READS_DB = [HilosDbContext::accountOperations, HilosDbContext::accountOperationAssignments];

    public function ownedDbSetKey(string $collection): string
    {
        return $this->getId();
    }

    public function onStop(): void
    {
    }
}
