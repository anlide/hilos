<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountDeletion\AccountDeletionCommandConstants;
use Hilos\Users\AccountMergeCommandConstants;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Users\SecondFactorFate;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\AccountDataOwner;
use Hilos\Auth\AccountOperation\AccountDataOwnerDirectory;
use Hilos\Auth\AccountOperation\AccountErasureFinal;
use Hilos\Auth\AccountOperation\AccountErasurePart;
use Hilos\Auth\AccountOperation\AccountErasureProjectOwner;
use Hilos\Auth\AccountOperation\AccountErasureProjectStep;
use Hilos\Auth\AccountOperation\AccountOperationAnswerKind;
use Hilos\Auth\AccountOperation\AccountOperationAsks;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Agent\Hilos\AbstractHilosLegalAgent;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\PhpType;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\DataExport\AbstractDataExportAgent;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Legal\Export\LegalAcceptancesExports;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Users\AccountErasure;
use Hilos\Users\AccountErasureProgressChangeSubscriber;
use Hilos\Users\AccountErasureProgressResolver;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * The held force-purge reply over the real holder and participant exchange (HIL-1953).
 * Every frame goes through its agent handler over a controlled signal queue; the deadline is
 * brought forward through the holder's sweep instead of waiting for the clock.
 */
final class AccountErasureForcePurgeIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const array TABLES = [
        'hilos_passkey_credential',
        'hilos_user_verification',
        'hilos_legal_acceptance_export',
        'hilos_notification',
        'hilos_notification_delivery',
        'hilos_account_operation',
        'hilos_account_operation_assignment',
        'hilos_account_operation_result',
    ];

    /** @var list<AbstractAgent> Supporting owners whose result sets are claimed for each case */
    private array $owners;
    /** @var list<SignalDTO> Operation frames drained from the queue and not yet handed out */
    private array $queued = [];
    /** @var list<CommandReplyDTO> Command replies drained from the queue and not yet handed out */
    private array $replies = [];
    private string $previousApp;
    private ?SignalRouter $previousRouter;
    private ?FsContext $previousFs;
    private string $exportDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        self::stubs(down: true);
        self::stubs(down: false);
        Database::sql('DROP TABLE IF EXISTS `hilos_fw_test_force_purge_note`');
        Database::sql(
            'CREATE TABLE `hilos_fw_test_force_purge_note` (`user_id` INT UNSIGNED NOT NULL PRIMARY KEY,'
                . ' FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT) ENGINE=InnoDB',
        );
        Schema::reset();
        Schema::initialize();
        $this->previousApp = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        $this->previousFs = Hilos::$fs;
        LegalAcceptancesExports::reset();
        Hilos::$sr = new SignalRouter();
        Hilos::$db = new ForcePurgeContext();
        Hilos::$db->configure();
        ForcePurgeTestHilos::initBrowser();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe(new AccountErasureProgressChangeSubscriber());
        AccountErasureProgressResolver::forgetAll();
        $this->exportDirectory = sys_get_temp_dir() . '/hilos-erasure-force-purge-' . bin2hex(random_bytes(8));
        Hilos::$fs = new ForcePurgeFs($this->exportDirectory);
        Hilos::$fs->configure();
        Hilos::$fs->getDirectory(FsContext::LEGAL_EXPORT)->ensureDirectory();
        $this->owners = [
            new ForcePurgeTestUsersLibrary(),
            new ForcePurgeTestHolder(),
            new ForcePurgeTestDataExport(),
            new ForcePurgeTestLegal(),
        ];
        foreach ($this->owners as $agent) {
            OwnershipDeclaration::claimDb($agent::class, $agent->getId());
            OwnershipDeclaration::claimDbSet($agent);
        }
        foreach ([new MailDeliveryChannelAgent('1'), new ForcePurgeProjectOwner()] as $agent) {
            OwnershipDeclaration::claimAll($agent);
            $this->owners[] = $agent;
        }
    }

    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            Database::sql('DROP TABLE IF EXISTS `hilos_fw_test_force_purge_note`');
            self::stubs(down: true);
            Schema::reset();
        } finally {
            $this->releasePersonAgents();
            foreach ($this->owners as $agent) {
                TruthSourceRegistry::unregisterAgent($agent->getId());
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agent->getId()));
            }
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();
            Hilos::$sr = $this->previousRouter;
            Hilos::$fs = $this->previousFs;
            LegalAcceptancesExports::reset();
            FsPath::removeDirectory($this->exportDirectory);
            SourceChangeBus::reset();
            AccountErasureProgressResolver::forgetAll();
            parent::tearDown();
        }
    }

    /** The command stays parked through every step and gets one completed reply with the personal and project rows. */
    public function testTheCommandIsAnsweredOnceWhenTheLastFinalEndsTheErasure(): void
    {
        $root = self::seedPerson();
        $folded = self::seedPerson();
        self::seedMerge($folded, $root);
        $operation = $this->commandErasure($root, 'purge-one');
        $finals = $this->exchange($this->isFinalAsk(...));
        self::assertCount(1, $finals);
        self::assertSame([], $this->replies());
        $this->deliverOperationFrame($finals[0]);
        $this->deliverOperationFrame($this->operationFrames()[0]);
        self::assertSame([], $this->replies());
        $rootFinal = $this->operationFrames();
        self::assertCount(1, $rootFinal);
        self::assertSame(AccountErasureFinal::personalKey($root), $this->frameAssignment($rootFinal[0])->assignmentKey);
        $this->deliverOperationFrame($rootFinal[0]);
        self::assertSame([], $this->replies());
        $this->deliverOperationFrame($this->operationFrames()[0]);

        $replies = $this->replies();
        self::assertCount(1, $replies);
        self::assertSame('purge-one', $replies[0]->correlationId);
        self::assertTrue($replies[0]->isOk());
        self::assertSame([
            AccountDeletionCommandConstants::FIELD_USER_ID => $root,
            AccountDeletionCommandConstants::FIELD_OUTCOME => 'completed',
            AccountDeletionCommandConstants::FIELD_ROWS_ERASED => ['personalRows' => 2, 'notes' => 2],
        ], $replies[0]->payload);
        self::assertSame('completed', self::operationRow($operation)['outcome']);
        $this->sweepAtDeadline();
        self::assertSame([], $this->replies());
    }

    /** "Keep my account" winning the start ends the erasure unstarted, answered with the earlier path's words. */
    public function testKeepMyAccountWinningTheStartIsAnsweredWithTheCancellation(): void
    {
        $root = self::seedPerson();
        $operation = $this->commandErasure($root, 'purge-kept');
        $this->asPerson($root, static fn () => Hilos::$db->accountDeletions[$operation->requestId]->actions->cancel());
        $this->deliverOperationFrame($this->operationFrames()[0]);
        $this->deliverOperationFrame($this->operationFrames()[0]);

        $replies = $this->replies();
        self::assertCount(1, $replies);
        self::assertFalse($replies[0]->isOk());
        self::assertSame(
            "The deletion of user {$root} was canceled before it could be carried out",
            $replies[0]->payload['message'] ?? null,
        );
        self::assertSame('not_started', self::operationRow($operation)['outcome']);
        self::assertTrue(isset(Hilos::$db->users[$root]));
        self::assertSame([], $this->projectOwner()->erasedAccounts);
    }

    /** A lost owner frame leaves the erasure open: the deadline answers its stage once, with that part not done. */
    public function testALostOwnerFrameIsAnsweredAtTheDeadlineWithTheErasingStage(): void
    {
        $root = self::seedPerson();
        $operation = $this->commandErasure($root, 'purge-lost');
        self::assertNotSame([], $this->exchange($this->isProjectAsk(...)));
        self::assertSame([], $this->replies());

        $this->sweepAtDeadline();
        $replies = $this->replies();
        self::assertCount(1, $replies);
        self::assertSame('purge-lost', $replies[0]->correlationId);
        self::assertTrue($replies[0]->isOk());
        self::assertSame($root, $replies[0]->payload[AccountDeletionCommandConstants::FIELD_USER_ID] ?? null);
        self::assertSame('erasing', $replies[0]->payload[AccountDeletionCommandConstants::FIELD_OUTCOME] ?? null);
        $erasure = $replies[0]->payload[AccountDeletionCommandConstants::FIELD_ERASURE] ?? null;
        self::assertSame(AccountErasureProgressResolver::of($root)?->toArray(), $erasure);
        self::assertSame([false], array_column(array_values(array_filter(
            $erasure['parts'],
            static fn (array $part): bool => $part['participant'] === ForcePurgeProjectDirectory::KEY,
        )), 'done'));
        self::assertNull(self::operationRow($operation)['finished_at']);
        $this->sweepAtDeadline();
        self::assertSame([], $this->replies());
    }

    /** An unanswered start is answered at the deadline as starting, without a progress block. */
    public function testAnUnansweredStartIsAnsweredAtTheDeadlineAsStarting(): void
    {
        $root = self::seedPerson();
        $operation = $this->commandErasure($root, 'purge-start');
        self::assertCount(1, $this->operationFrames());

        $this->sweepAtDeadline();
        $replies = $this->replies();
        self::assertCount(1, $replies);
        self::assertSame([
            AccountDeletionCommandConstants::FIELD_USER_ID => $root,
            AccountDeletionCommandConstants::FIELD_OUTCOME => 'starting',
        ], $replies[0]->payload);
    }

    /** A final refused by a RESTRICT reference keeps the erasure open; the deadline names the refused table. */
    public function testARestrictRefusedFinalIsAnsweredAtTheDeadlineWithItsRefusal(): void
    {
        $root = self::seedPerson();
        $operation = $this->commandErasure($root, 'purge-refused');
        [$final] = $this->exchange($this->isFinalAsk(...));
        self::seedIdentity($root, 'password', 'late@example.test');
        $this->deliverOperationFrame($final);
        $answer = $this->operationFrames()[0];
        self::assertSame(AccountOperationAnswerKind::FinalRefused, $answer->data->data->answer);
        $this->deliverOperationFrame($answer);
        self::assertSame([], $this->replies());

        $this->sweepAtDeadline();
        $replies = $this->replies();
        self::assertCount(1, $replies);
        self::assertSame('erasing', $replies[0]->payload[AccountDeletionCommandConstants::FIELD_OUTCOME] ?? null);
        self::assertSame(
            [['userId' => $root, 'table' => 'hilos_identity']],
            $replies[0]->payload[AccountDeletionCommandConstants::FIELD_ERASURE]['refusals'] ?? null,
        );
        self::assertTrue(isset(Hilos::$db->users[$root]));
    }

    /** A repeated command observes the same erasure: it resends the lost frame and alone gets the completion. */
    public function testARepeatedCommandResendsTheLostFrameAndGetsTheCompletion(): void
    {
        $root = self::seedPerson();
        $operation = $this->commandErasure($root, 'purge-first');
        $this->exchange($this->isProjectAsk(...));
        $this->sweepAtDeadline();
        self::assertCount(1, $this->replies());
        self::assertSame([], $this->projectOwner()->erasedAccounts);

        $observed = $this->forcePurgeOperation($root);
        self::assertSame($operation->id, $observed?->id);
        $this->sendCommand(self::command('purge-second', $root));
        self::assertSame([], $this->exchange());

        $replies = $this->replies();
        self::assertCount(1, $replies);
        self::assertSame('purge-second', $replies[0]->correlationId);
        self::assertSame('completed', $replies[0]->payload[AccountDeletionCommandConstants::FIELD_OUTCOME] ?? null);
        self::assertSame([$root], $this->projectOwner()->erasedAccounts);
        $this->sweepAtDeadline();
        self::assertSame([], $this->replies());
    }

    /** Two commands parked on one erasure are both answered once at its end. */
    public function testTwoCommandsOnOneErasureAreEachAnsweredOnce(): void
    {
        $root = self::seedPerson();
        $operation = $this->commandErasure($root, 'purge-a');
        $this->sendCommand(self::command('purge-b', $root));
        self::assertSame([], $this->exchange());

        $replies = $this->replies();
        self::assertSame(['purge-a', 'purge-b'], array_map(static fn (CommandReplyDTO $reply): string => $reply->correlationId, $replies));
        foreach ($replies as $reply) {
            self::assertSame([
                AccountDeletionCommandConstants::FIELD_USER_ID => $root,
                AccountDeletionCommandConstants::FIELD_OUTCOME => 'completed',
                AccountDeletionCommandConstants::FIELD_ROWS_ERASED => ['personalRows' => 1, 'notes' => 1],
            ], $reply->payload);
        }
        $this->sweepAtDeadline();
        self::assertSame([], $this->replies());
    }

    /** After a lost reply the erased request still leads to its erasure, answered at once from the saved results. */
    public function testAnEndedErasureIsAnsweredAtOnceFromItsSavedResults(): void
    {
        $root = self::seedPerson();
        $operation = $this->commandErasure($root, 'purge-lost-reply');
        $this->exchange();
        [$lost] = $this->replies();
        self::assertSame('completed', $lost->payload[AccountDeletionCommandConstants::FIELD_OUTCOME] ?? null);

        $observed = $this->forcePurgeOperation($root);
        self::assertSame($operation->id, $observed?->id);
        $this->sendCommand(self::command('purge-again', $root));

        self::assertSame([], $this->operationFrames());
        $replies = $this->replies();
        self::assertCount(1, $replies);
        self::assertSame('purge-again', $replies[0]->correlationId);
        self::assertSame($lost->payload, $replies[0]->payload);
        self::assertSame([$root], $this->projectOwner()->erasedAccounts);
    }

    /** Only a request that has an erasure leads the command to one. */
    public function testTheObservedErasureIsTheRequestsOperation(): void
    {
        self::assertNull($this->forcePurgeOperation(self::seedPerson()));
        $requested = self::seedPerson();
        self::requestDeletion($requested);
        self::assertNull($this->forcePurgeOperation($requested));
        $root = self::seedPerson();
        $operation = $this->commandErasure($root, 'purge-observed');
        self::assertSame($operation->id, $this->forcePurgeOperation($root)?->id);
    }

    /** The ordinary merge command refuses an account already claimed by the live force opening. */
    public function testMergeRefusesAnAccountOfTheErasureOperation(): void
    {
        $root = self::seedPerson();
        $other = self::seedPerson();
        $operation = $this->commandErasure($root, 'purge-before-merge');
        $this->operationFrames();

        $this->sendCommand(new CommandRequestDTO('merge-after-opening', CliCommands::ACCOUNT_MERGE, [
            AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_START,
            AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID => $other,
            AccountMergeCommandConstants::FIELD_LOSER_USER_ID => $root,
        ]));

        [$refusal] = $this->replies();
        self::assertSame('merge-after-opening', $refusal->correlationId);
        self::assertFalse($refusal->isOk());
        self::assertSame("Force purge person (#{$root}) is being deleted", $refusal->payload['message']);
        self::assertSame($operation->id, $this->forcePurgeOperation($root)?->id);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_user_merge`')->rows());
        self::assertNull(self::operationRow($operation)['finished_at']);
    }

    /** A blocking merge leaves the future date untouched; retry after it ends opens this request's own force mode. */
    public function testADeferredForceDoesNotWriteTheDateOrAnswerForTheBlockingOperation(): void
    {
        $root = self::seedPerson();
        $other = self::seedPerson();
        self::requestDeletion($root);
        $request = Hilos::$db->accountDeletions->liveOf($root);
        $before = Database::sql('SELECT * FROM `hilos_account_deletion` WHERE `id` = ?', [$request->id])->rows();
        $merge = $this->asHolder(static fn () => Hilos::$db->accountOperations->actions->recordMerge(
            $root, $other, new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, true, false),
        ));

        $this->sendCommand(self::command('purge-deferred', $root));

        [$reply] = $this->replies();
        self::assertFalse($reply->isOk());
        self::assertSame("The deletion of user {$root} is waiting for another account operation.", $reply->payload['message']);
        self::assertSame($before, Database::sql('SELECT * FROM `hilos_account_deletion` WHERE `id` = ?', [$request->id])->rows());
        self::assertNull($this->forcePurgeOperation($root));
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows());
        self::assertSame([], $this->operationFrames());
        $this->asHolder(static fn () => $merge->actions->finish($merge->stage, AccountOperationOutcome::Completed));
        $this->sendCommand(self::command('purge-unblocked', $root));
        self::assertNotSame($merge->id, $this->forcePurgeOperation($root)?->id);
        $this->exchange();
        [$completed] = $this->replies();
        self::assertSame('completed', $completed->payload[AccountDeletionCommandConstants::FIELD_OUTCOME]);
    }

    /** A repeat after the start was committed watches the same operation and preserves its already changed date. */
    public function testARepeatedForcePreservesTheCommittedStartDate(): void
    {
        $root = self::seedPerson();
        $operation = $this->commandErasure($root, 'purge-date');
        $held = $this->exchange($this->isProjectAsk(...));
        self::assertNotSame([], $held);
        $before = Database::sql('SELECT `effective_at`, `started_at` FROM `hilos_account_deletion` WHERE `id` = ?', [$operation->requestId])->rows();
        self::assertNotNull($before[0]['started_at']);
        $this->sendCommand(self::command('purge-date-again', $root));
        self::assertSame($operation->id, $this->forcePurgeOperation($root)?->id);
        $this->exchange($this->isProjectAsk(...));
        self::assertSame($before, Database::sql(
            'SELECT `effective_at`, `started_at` FROM `hilos_account_deletion` WHERE `id` = ?', [$operation->requestId],
        )->rows());
        foreach ($held as $frame) {
            $this->deliverOperationFrame($frame);
        }
        $this->exchange();
        self::assertCount(2, $this->replies());
    }

    /** @return ForcePurgeTestHolder The real holder exposed by the fixture */
    private function holder(): ForcePurgeTestHolder
    {
        return $this->owners[1];
    }

    /** @return ForcePurgeProjectOwner The project participant for the exchange */
    private function projectOwner(): ForcePurgeProjectOwner
    {
        return $this->owners[5];
    }

    /**
     * @param string $correlationId Socket the operator waits on
     * @param int $userId Account the command names
     * @return CommandRequestDTO Force-purge command as the CLI sends it
     */
    private static function command(string $correlationId, int $userId): CommandRequestDTO
    {
        return new CommandRequestDTO(
            $correlationId,
            CliCommands::ACCOUNT_TEST_FORCE_PURGE,
            [AccountDeletionCommandConstants::FIELD_USER_ID => $userId],
        );
    }

    /** @param CommandRequestDTO $command The real command entry of the holder */
    private function sendCommand(CommandRequestDTO $command): void
    {
        $this->asHolder(fn () => $this->holder()->onSignalCommand($command, '', ''));
    }

    /**
     * @param int $userId Account the command names
     * @return ?AccountOperation The erasure the holder hands the command
     */
    private function forcePurgeOperation(int $userId): ?AccountOperation
    {
        return $this->asHolder(fn (): ?AccountOperation => $this->holder()->observed($userId));
    }

    /** Runs the holder's sweep at the parked commands' deadline. */
    private function sweepAtDeadline(): void
    {
        $this->asHolder(fn () => $this->holder()->sweep(microtime(true) + CommandChannelWindows::AGENT_WAIT_SECONDS));
    }

    /**
     * @param AccountOperationAnswerSignalData $answer Frame under the real holder's address judge
     * @param string $sender Canonical source preserved by the queued frame
     */
    private function receiveAnswer(AccountOperationAnswerSignalData $answer, string $sender): void
    {
        $this->asHolder(fn () => $this->holder()->onSignalAgent(
            new AgentSignalData($answer), $sender, HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER,
        ));
    }

    /** Moves operation frames and command replies out of the queue, dropping unrelated announcements. */
    private function drain(): void
    {
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof CommandReplyDTO) {
                $this->replies[] = $frame->data;
            } elseif ($frame->data instanceof AgentSignalData
                && ($frame->data->data instanceof AccountOperationAskSignalData || $frame->data->data instanceof AccountOperationAnswerSignalData)) {
                $this->queued[] = $frame;
            }
        }
    }

    /** @return list<SignalDTO> Operation frames queued since the last call */
    private function operationFrames(): array
    {
        $this->drain();
        $frames = $this->queued;
        $this->queued = [];

        return $frames;
    }

    /** @return list<CommandReplyDTO> Command replies queued since the last call */
    private function replies(): array
    {
        $this->drain();
        $replies = $this->replies;
        $this->replies = [];

        return $replies;
    }

    /**
     * @param SignalDTO $frame Queued assignment frame
     * @return AccountOperationAssignment Stored address for assertion or participant dispatch
     */
    private function frameAssignment(SignalDTO $frame): AccountOperationAssignment
    {
        return Hilos::$db->accountOperationAssignments[$frame->data->data->assignmentId];
    }

    /**
     * @param SignalDTO $frame Queued operation frame
     * @return bool Whether it asks an account's final
     */
    private function isFinalAsk(SignalDTO $frame): bool
    {
        return $frame->data->data instanceof AccountOperationAskSignalData
            && str_starts_with($this->frameAssignment($frame)->assignmentKey, AccountErasureFinal::FINAL_PREFIX . ':');
    }

    /**
     * @param SignalDTO $frame Queued operation frame
     * @return bool Whether it asks the project owner's part
     */
    private function isProjectAsk(SignalDTO $frame): bool
    {
        return $frame->data->data instanceof AccountOperationAskSignalData
            && $this->frameAssignment($frame)->ownerType === ForcePurgeProjectOwner::AGENT_TYPE;
    }

    /** @param SignalDTO $frame Queued ask or answer delivered in its real owner's execution frame */
    private function deliverOperationFrame(SignalDTO $frame): void
    {
        self::assertInstanceOf(AgentSignalData::class, $frame->data);
        $payload = $frame->data->data;
        if ($payload instanceof AccountOperationAnswerSignalData) {
            self::assertSame(HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER, $frame->signalName->getName());
            $this->receiveAnswer($payload, SignalSource::describe($frame->signalSource));

            return;
        }
        self::assertInstanceOf(AccountOperationAskSignalData::class, $payload);
        $assignment = $this->frameAssignment($frame);
        self::assertSame($assignment->ownerIndex, $payload->ownerIndex);
        self::assertNotSame(HilosAgentType::HILOS_SESSIONS_LIBRARY, $assignment->ownerType);
        $agent = $assignment->ownerType === HilosAgentType::HILOS_USER
            ? $this->personAgent((int)$payload->ownerIndex)
            : array_values(array_filter(
                $this->owners,
                static fn (AbstractAgent $owner): bool => $owner->getId() === $assignment->owner->agentId(),
            ))[0];
        self::assertSame($agent::ACCOUNT_OPERATION_SIGNAL, $frame->signalName->getName());
        ExecutionContext::run(new ExecutionFrame(agentId: $agent->getId()), static fn () => $agent->onSignalAgent(
            $frame->data, SignalSource::describe($frame->signalSource), $frame->signalName->getName(),
        ));
    }

    /**
     * Delivers every operation frame until the queue settles, holding back the ones the case keeps.
     *
     * @param ?callable(SignalDTO): bool $holds Frames the case keeps undelivered, none when omitted
     * @return list<SignalDTO> Frames held back, in queue order
     */
    private function exchange(?callable $holds = null): array
    {
        $held = [];
        $delivered = 0;
        $frames = $this->operationFrames();
        while (($frame = array_shift($frames)) !== null) {
            self::assertLessThan(100, ++$delivered, 'Operation frames must settle without a retry loop.');
            if ($holds !== null && $holds($frame)) {
                $held[] = $frame;
            } else {
                $this->deliverOperationFrame($frame);
            }
            $frames = [...$this->operationFrames(), ...$frames];
        }

        return $held;
    }

    /**
     * @param string $userId Person id as the agent address carries it
     * @return AbstractUserAgent Agent with the final's rights confined to this fixture
     */
    protected function newPersonAgent(string $userId): AbstractUserAgent
    {
        return new ForcePurgeTestUserAgent($userId);
    }

    /** @param int $userId Person whose standing deletion request is written */
    private static function requestDeletion(int $userId): void
    {
        ExecutionContext::run(
            new ExecutionFrame(agentId: HilosAgentType::HILOS_USERS_LIBRARY),
            static fn () => Hilos::$db->accountDeletions->actions->request(
                $userId,
                TimeHelper::nowMs() + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
            ),
        );
    }

    /**
     * @param int $root Account whose future request the command opens
     * @param string $correlationId Command watching this opening
     * @return AccountOperation The operation opened through the real command entry
     */
    private function commandErasure(int $root, string $correlationId): AccountOperation
    {
        self::requestDeletion($root);
        $this->sendCommand(self::command($correlationId, $root));
        $operation = $this->forcePurgeOperation($root);
        self::assertNotNull($operation);

        return $operation;
    }

    /**
     * @template T
     * @param callable(): T $step Step to run in the session holder's frame
     * @return T Result of the step
     */
    private function asHolder(callable $step): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: HilosAgentType::HILOS_SESSIONS_LIBRARY), $step);
    }

    /** @return int Id of a new person with one project note, before any ORM reader sees it */
    private static function seedPerson(): int
    {
        Database::sql("INSERT INTO `hilos_user` (`name`, `admin`) VALUES ('Force purge person', 0)");
        $userId = Database::lastInsertId();
        Database::sql('INSERT INTO `hilos_fw_test_force_purge_note` (`user_id`) VALUES (?)', [$userId]);

        return $userId;
    }

    /**
     * @param int $folded Folded account
     * @param int $root Survivor of the merge
     */
    private static function seedMerge(int $folded, int $root): void
    {
        Database::sql(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, ?)',
            [$folded, $root, TimeHelper::nowMs()],
        );
    }

    /**
     * @param AccountOperation $operation Erasure whose persisted terminal state is checked independently
     * @return array<string, mixed> Persisted outcome and finish time
     */
    private static function operationRow(AccountOperation $operation): array
    {
        return Database::sql(
            'SELECT `outcome`, `finished_at` FROM `hilos_account_operation` WHERE `id` = ?',
            [$operation->id],
        )->row();
    }

    /**
     * @param bool $down Drop in reverse dependency order
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

/** The supporting owners retain their production ownership declarations. */
final class ForcePurgeTestUsersLibrary extends AbstractUsersLibraryAgent
{
}

/** Exposes only the protected force-purge entry, its observed erasure and its sweep; the rest is production. */
final class ForcePurgeTestHolder extends AbstractSessionsLibraryAgent
{
    /**
     * @param int $userId Account the command names
     * @return ?AccountOperation The erasure the command watches
     */
    public function observed(int $userId): ?AccountOperation
    {
        return $this->forcePurgeOperation($userId);
    }

    /** @param float $now Moment the parked commands are judged at */
    public function sweep(float $now): void
    {
        $this->sweepForcePurges($now);
    }
}

/** The data-export owner of the real exchange. */
final class ForcePurgeTestDataExport extends AbstractDataExportAgent
{
}

/** The legal-export owner of the real exchange. */
final class ForcePurgeTestLegal extends AbstractHilosLegalAgent
{
}

/** The final uses the production personal writer's rights. */
final class ForcePurgeTestUserAgent extends AbstractUserAgent
{
    /**
     * Counts one fixture row per account beside the real personal erasure, so the reply's sum is visible.
     *
     * @param int $userId Account whose personal rows go
     * @return AccountErasure The real personal erasure plus the fixture's row
     */
    protected function erasePersonalChildren(int $userId): AccountErasure
    {
        return parent::erasePersonalChildren($userId)->plus(new AccountErasure(['personalRows' => 1], []));
    }
}

/** The project participant owns the fixture's mounted note collection. */
abstract class ForcePurgeProjectDirectory extends AccountDataOwnerDirectory
{
    public const string KEY = 'force_purge_project';

    /** @return list<AccountDataOwner> Project note owner */
    protected static function owners(): array
    {
        return [new AccountDataOwner(
            self::KEY, [ForcePurgeNotes::COLLECTION_KEY], ForcePurgeProjectOwner::AGENT_TYPE, false,
            static fn (int $userId): bool => Hilos::$db->getObjectCollection(ForcePurgeNotes::COLLECTION_KEY)
                ->countByColumn(ForcePurgeNote::user_id, $userId) > 0,
        )];
    }
}

/** The project uses the same participant step and answer translation as a real project owner. */
final class ForcePurgeProjectOwner extends AbstractAgent implements AccountErasureProjectOwner
{
    public const string AGENT_TYPE = 'force_purge_project';
    public const string ACCOUNT_OPERATION_SIGNAL = 'force_purge_project_account_operation_step';
    public const array AGENT_SIGNALS = [self::ACCOUNT_OPERATION_SIGNAL => AccountOperationAskSignalData::class];
    public const array OWNS_DB = [ForcePurgeNotes::COLLECTION_KEY => [TruthSourceOperation::Remove]];
    public const array OWNS_DB_SET = [HilosDbContext::accountOperationResults => [TruthSourceOperation::Add]];
    public const array READS_DB = [HilosDbContext::accountOperationAssignments, HilosDbContext::accountOperations];

    /** @var list<int> Accounts whose project work actually ran */
    public array $erasedAccounts = [];

    /**
     * @param string $collection Result collection owned by this agent
     * @return string Author of this owner's results
     */
    public function ownedDbSetKey(string $collection): string
    {
        return $this->getId();
    }

    /**
     * @param AccountErasurePart $part Account whose note is erased
     * @return AccountErasure The actual note erasure outcome
     */
    public function eraseAccountPart(AccountErasurePart $part): AccountErasure
    {
        $this->erasedAccounts[] = $part->userId;
        $notes = Hilos::$db->getObjectCollection(ForcePurgeNotes::COLLECTION_KEY);
        $ids = iterator_to_array($notes->loadByColumn(ForcePurgeNote::user_id, $part->userId), false);
        foreach ($ids as $id) {
            $notes[$id]->delete();
        }

        return new AccountErasure(['notes' => count($ids)], []);
    }

    /**
     * @param AgentSignalData $data Assignment address sent by the holder
     * @param string $sender Sender, unused
     * @param string $name Declared project step name
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
                    fn (AccountOperationAssignment $assignment): AccountOperationResult => AccountErasureProjectStep::run($this, $assignment->id),
                    $this->getId(),
                ));

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /** Nothing to release in the controlled queue. */
    public function onStop(): void
    {
    }
}

/** One real mail shard, declared without changing environment or production settings. */
final class ForcePurgeMailChannel extends MailDeliveryChannel
{
    /** @return int The fixture's one mail shard */
    public function poolSize(): int
    {
        return 1;
    }
}

/** The exchange activates mail without a sender, a transport or network I/O. */
abstract class ForcePurgeChannels extends DeliveryChannelRegistry
{
    /** @return array<string, MailDeliveryChannel> The real mail channel's deterministic shard descriptor */
    protected static function channels(): array
    {
        return [MailDeliveryChannel::NAME => new ForcePurgeMailChannel()];
    }
}

/** All real framework owners, plus a project participant and the real mail handler. */
abstract class ForcePurgeTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH];
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = ForcePurgeProjectDirectory::class;
    protected const string NOTIFICATION_CHANNEL_REGISTRY = ForcePurgeChannels::class;

    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [AgentRegistryKey::WORKER => ForcePurgeTestUserAgent::class, AgentRegistryKey::INDEXED => true],
        HilosAgentType::HILOS_USERS_LIBRARY => [AgentRegistryKey::WORKER => ForcePurgeTestUsersLibrary::class],
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [AgentRegistryKey::WORKER => ForcePurgeTestHolder::class],
        HilosAgentType::HILOS_DATA_EXPORT => [AgentRegistryKey::WORKER => ForcePurgeTestDataExport::class],
        HilosAgentType::HILOS_LEGAL => [AgentRegistryKey::WORKER => ForcePurgeTestLegal::class],
        HilosAgentType::HILOS_MAIL => [AgentRegistryKey::WORKER => MailDeliveryChannelAgent::class, AgentRegistryKey::INDEXED => true],
        ForcePurgeProjectOwner::AGENT_TYPE => [AgentRegistryKey::WORKER => ForcePurgeProjectOwner::class],
    ];
}

/** Framework collections plus the project's one account-bound note. */
final class ForcePurgeContext extends HilosDbContext
{
    /** Mounts the framework and the test project's note collection. */
    public function configure(): void
    {
        parent::configure();
        $this->mountObjectCollection(ForcePurgeNotes::class, Objects::LAZY_STRATEGY_KEY);
    }
}

/** The project fixture's only persisted field is also its primary account key. */
final class ForcePurgeNote extends Entity
{
    public const string user_id = 'user_id';
    public const string _table = 'hilos_fw_test_force_purge_note';
    public const string _primary = self::user_id;
    public const array _columns = [self::user_id];
    public const array _types = [self::user_id => PhpType::INTEGER->value];

    public int $user_id;
}

/** @extends Object_<ForcePurgeNote> */
final class ForcePurgeNoteObject extends Object_
{
    public const string ENTITY_CLASS = ForcePurgeNote::class;
}

/** @extends Objects<ForcePurgeNoteObject> */
final class ForcePurgeNotes extends Objects
{
    public const string COLLECTION_KEY = 'forcePurgeProjectRows';
    public const string OBJECT_CLASS = ForcePurgeNoteObject::class;
}

/** The legal handler's real after-commit sweep reads an isolated temporary directory. */
final class ForcePurgeFs extends FsContext
{
    /** @param string $path Isolated legal-export directory */
    public function __construct(private readonly string $path)
    {
    }

    /** Registers the real legal-export directory contract. */
    public function configure(): void
    {
        $this->registerDirectory(self::LEGAL_EXPORT, $this->path, DirectoryScope::CLUSTER);
    }
}
