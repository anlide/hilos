<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountOperation\AccountDataOwner;
use Hilos\Auth\AccountOperation\AccountErasureOpening;
use Hilos\Auth\AccountOperation\AccountErasurePart;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountErasureProjectOwner;
use Hilos\Auth\AccountOperation\AccountErasureProjectStep;
use Hilos\Auth\AccountOperation\AccountMergeAdmission;
use Hilos\Auth\AccountOperation\AccountMergeAdmissionResult;
use Hilos\Auth\AccountOperation\AccountMergeCancellationState;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeCoordinator;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergePart;
use Hilos\Auth\AccountOperation\AccountMergeProjectOwner;
use Hilos\Auth\AccountOperation\AccountMergeProjectStep;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAnswerKind;
use Hilos\Auth\AccountOperation\AccountOperationAsks;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationExpectedSet;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\DTO\AccountMergeHandoverSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Daemon\Cron\CronScheduleTestOperation;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalTriggerRenderer;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\DbSyncAdmission;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\AccountErasure;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/AccountMergeProjectParticipantIntegrationTest.php';

/**
 * Recovery groups 1–12: AUTH holders, owner handlers and application commits.
 * Held or discarded serialized frames model lost transport in one process, not cluster failover.
 */
final class AccountMergeRecoveryIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const array EXTRA_TABLES = ['hilos_passkey_credential', 'hilos_account_merge_copy'];
    private const array JOURNAL_TABLES = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];

    private ?MergeRecoveryHolder $holder = null;
    private MergeRecoveryProjectOwner $project;
    private MergeRecoveryUsersLibrary $library;
    private string $previousApp;
    private ?SignalRouter $previousRouter;
    private ?RtContext $previousRt;
    private string $logDirectory;
    private bool $journalRaised = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        $this->stubs(true);
        $this->stubs(false);
        Database::sqlRun('CREATE TABLE `' . AccountMergeProjectNote::_table . '` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `user_id` INT UNSIGNED NOT NULL, PRIMARY KEY (`id`))');
        Schema::reset();
        Schema::initialize();
        MergeRecoveryHilos::initBrowser();
        Hilos::$sr = new SignalRouter();
        $rt = new MergeRecoveryRtContext();
        $rt->mountFeatureRuntime([new AuthFeature()]);
        $rt->configure();
        $rt->bindStateCollectionNames();
        Hilos::$rt = $rt;
        $this->freshContext();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->project = new MergeRecoveryProjectOwner();
        $this->library = new MergeRecoveryUsersLibrary();
        OwnershipDeclaration::claimAll($this->project);
        OwnershipDeclaration::claimDb($this->library::class, $this->library->getId());
        OwnershipDeclaration::claimDbSet($this->library);
        $this->claimHolder();
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (7, 'Survivor'), (9, 'Loser')");
        $this->logDirectory = sys_get_temp_dir() . '/hilos-merge-recovery-' . bin2hex(random_bytes(8));
        mkdir($this->logDirectory);
        Logger::setLogFile($this->logDirectory . '/daemon.log');
    }

    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            Database::sqlRun('DROP TABLE IF EXISTS `' . AccountMergeProjectNote::_table . '`');
            $this->stubs(true);
            if ($this->journalRaised) {
                $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
                foreach (self::JOURNAL_TABLES as $table) {
                    Database::sqlRun("DROP TABLE IF EXISTS {$journal}.`{$table}`");
                }
            }
            Schema::reset();
        } finally {
            $this->releasePersonAgents();
            $this->releaseHolder();
            foreach ([$this->project, $this->library] as $agent) {
                TruthSourceRegistry::unregisterAgent($agent->getId());
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agent->getId()));
            }
            Logger::resetLogFile();
            foreach (array_diff(scandir($this->logDirectory), ['.', '..']) as $entry) {
                unlink($this->logDirectory . '/' . $entry);
            }
            rmdir($this->logDirectory);
            Hilos::$sr = $this->previousRouter;
            Hilos::$rt = $this->previousRt;
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();
            SourceChangeBus::reset();
            parent::tearDown();
        }
    }

    /** Group 1: a saved preparation survives lost answers; first and hourly passes keep the same operation and choices. */
    public function testFirstAndHourlyPassResumePreparingFromOneSavedResult(): void
    {
        $this->raiseJournal();
        Database::sqlRun('INSERT INTO `' . AccountMergeProjectNote::_table . '` (`user_id`) VALUES (7), (9), (9)');
        $operation = $this->asHolder(fn () => $this->holder->openMerge(7, 9, new AccountMergeChoice(null, null, false, false)));
        $before = $this->row($operation);
        $initial = $this->frames();
        self::assertSame(['prepare_survivor', 'prepare_loser'], $this->keys($initial));
        $this->deliver($initial[0]);
        $this->frames(); // Lose the answer after the owner's real commit.
        $saved = Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows();
        self::assertCount(1, $saved);
        self::assertFalse(Database::hasOpenTransactions());

        $this->startHolder();
        $this->tick();
        $retry = $this->frames();
        self::assertSame(['prepare_loser'], $this->keys($retry));
        self::assertSame([$initial[1]->data->data->assignmentId], $this->assignmentIds($retry));
        self::assertSame($saved, Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
        self::assertSame($before, $this->row($operation));
        $firstReceipt = $this->receipts()[0];
        self::assertSame('hilos_account_erasure_recovery (0 * * * *)', $firstReceipt['source']);
        self::assertSame(HilosAgentType::HILOS_SESSIONS_LIBRARY, $firstReceipt['agent']);
        $this->deliver($retry[0]);
        $this->frames(); // Lose the other answer as well.
        self::assertSame('preparing', $this->row($operation)['stage']);
        $this->assertQuietTick();
        $writes = new MergeRecoveryWrites();
        SourceChangeBus::subscribe($writes);

        $this->armPass();
        $this->tick();
        $give = $this->frames();
        self::assertSame(['give'], $this->keys($give));
        self::assertSame('go', $this->row($operation)['stage']);
        self::assertSame($before['merge_choice'], $this->row($operation)['merge_choice']);
        self::assertSame([$operation->id], array_map('intval', array_column(
            Database::sql('SELECT `id` FROM `hilos_account_operation`')->rows(), 'id',
        )));
        $receipts = $this->receipts();
        self::assertCount(2, $receipts);
        self::assertNotSame($firstReceipt['id'], $receipts[1]['id']);
        self::assertSame([(int)$receipts[1]['id']], array_values(array_unique($writes->receiptIds)));
        $this->finishExchange($operation, $give);
        self::assertSame([7, 7, 7], array_map('intval', array_column(Database::sql(
            'SELECT `user_id` FROM `' . AccountMergeProjectNote::_table . '` ORDER BY `id`',
        )->rows(), 'user_id')));
    }

    /** Group 2: a new holder retries only the release that has no committed result before recording NotStarted. */
    public function testFirstPassResumesCancellationAfterOneReleaseCommitted(): void
    {
        $operation = $this->openMerge();
        $this->frames();
        self::assertSame(AccountMergeCancellationState::WaitingOwners, $this->asHolder(fn () => $this->holder->cancelMerge($operation)));
        $releases = $this->frames();
        self::assertSame(['release_loser', 'release_survivor'], $this->keys($releases));
        $this->deliver($releases[0]);
        $this->frames();
        self::assertCount(1, Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
        self::assertNull($this->row($operation)['finished_at']);

        $this->startHolder();
        $this->tick();
        $retry = $this->frames();
        self::assertSame(['release_survivor'], $this->keys($retry));
        self::assertSame([$releases[1]->data->data->assignmentId], $this->assignmentIds($retry));
        self::assertSame('cancelling', $this->row($operation)['stage']);
        self::assertNull($this->row($operation)['finished_at']);
        $this->deliver($retry[0]);
        $this->frames();
        self::assertNull($this->row($operation)['finished_at'], 'Two saved releases still need the holder to close the operation.');

        $this->armPass();
        $this->tick();
        self::assertSame('not_started', $this->row($operation)['outcome']);
        self::assertNotNull($this->row($operation)['finished_at']);
        self::assertSame([], $this->frames());
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());
        self::assertSame([], Database::sql('SELECT * FROM `hilos_user_merge`')->rows());
    }

    /** Group 2: a committed give remains forward work after restart, even when every outgoing frame was lost. */
    public function testRestartNeverCancelsOrRollsBackACommittedGive(): void
    {
        self::seedIdentity(9, IdentityType::MAGIC_LINK, 'loser@example.test');
        $operation = $this->openMerge();
        foreach ($this->frames() as $ask) {
            $this->deliver($ask);
            $this->deliver($this->frames()[0]);
        }
        [$give] = $this->frames();
        self::assertSame(['give'], $this->keys([$give]));
        $this->deliver($give);
        $this->frames();
        $copies = Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows();
        self::assertCount(1, $copies);
        self::assertSame('7', Database::sql('SELECT `user_id` FROM `hilos_identity`')->row()['user_id']);
        self::assertFalse(Database::hasOpenTransactions());

        $this->startHolder();
        $this->tick();
        $this->frames();
        self::assertSame(AccountMergeCancellationState::TooLate, $this->asHolder(fn () => $this->holder->cancelMerge($operation)));
        self::assertSame('go', $this->row($operation)['stage']);
        self::assertNull($this->row($operation)['outcome']);
        self::assertSame($copies, Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());
        self::assertSame('7', Database::sql('SELECT `user_id` FROM `hilos_identity`')->row()['user_id']);
        $this->finishExchange($operation, $this->frames());
    }

    /** @return iterable<string, array{bool}> Two existing recovery entrances after source commit */
    public static function replayEntrances(): iterable
    {
        yield 'owner startup' => [true];
        yield 'holder first pass' => [false];
    }

    /**
     * Group 3: lost handover and answer recover the same copy, result and birth revisions, without another transfer.
     *
     * @param bool $startup Whether the fresh source starts itself or receives the new holder's ask
     */
    #[DataProvider('replayEntrances')]
    public function testFreshSourceReplaysCommittedGiveThroughStartupOrNewHolder(bool $startup): void
    {
        self::seedIdentity(9, IdentityType::MAGIC_LINK, 'loser@example.test');
        [$operation, $give] = $this->readyGo();
        $this->deliver($give);
        [$handover, $answer] = $this->frames();
        self::assertInstanceOf(AccountMergeHandoverSignalData::class, $handover->data->data);
        $saved = $this->operationFacts();
        $identities = Database::sql('SELECT * FROM `hilos_identity` ORDER BY `id`')->rows();
        self::assertSame('7', $identities[0]['user_id']);
        self::assertNotEmpty($handover->data->data->handover->admission->toArray());
        $oldSource = $this->personAgent(9);
        $this->releasePersonAgents();
        $this->freshContext();
        if ($startup) {
            $this->startOwner($this->personAgent(9));
        } else {
            $this->startHolder();
            $this->tick();
            $retries = $this->frames();
            $source = array_values(array_filter($retries, fn (SignalDTO $frame): bool =>
                $frame->data->data instanceof AccountOperationAskSignalData
                && $frame->data->data->assignmentId === $give->data->data->assignmentId));
            self::assertCount(1, $source);
            $this->deliver($source[0]);
        }
        self::assertNotSame($oldSource, $this->personAgent(9));
        [$replay, $replayedAnswer] = $this->frames();
        self::assertSame($handover->data->data->toArray(), $replay->data->data->toArray());
        self::assertSame($answer->data->data->toArray(), $replayedAnswer->data->data->toArray());
        // A new holder may have committed its own sessions duty while selecting the wave.
        self::assertSame($saved['copies'], $this->operationFacts()['copies']);
        self::assertSame($saved['merges'], $this->operationFacts()['merges']);
        self::assertSame($identities, Database::sql('SELECT * FROM `hilos_identity` ORDER BY `id`')->rows());
        $originalResult = array_values(array_filter($saved['results'], static fn (array $row): bool =>
            (int)$row['assignment_id'] === $give->data->data->assignmentId));
        self::assertSame($originalResult, Database::sql('SELECT * FROM `hilos_account_operation_result` WHERE `assignment_id` = ?',
            [$give->data->data->assignmentId])->rows());
        $this->deliver($replay);
        $receiptAnswer = $this->frames();
        self::assertNotContains($give->data->data->assignmentId,
            $this->owedIds(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, '9')));
        $this->deliver($replayedAnswer);
        self::assertNotContains('give', $this->keys($this->frames()));
        foreach ($receiptAnswer as $frame) {
            $this->deliver($frame);
        }
        $pending = $this->frames();
        $this->startHolder();
        $this->tick();
        $this->finishExchange($operation, [...$pending, ...$this->frames()]);
    }

    /** Group 4: lost receipt answers replay before protection or snapshot reads and leave cleanup available to a fresh holder. */
    public function testLostReceiveAnswerReturnsSavedReceiptBeforeNewProtectionAndContinuesCleanup(): void
    {
        $own = $this->seedRecoveryIdentity(7, IdentityType::PASSWORD, 'own@example.test');
        self::seedIdentity(9, IdentityType::MAGIC_LINK, 'source@example.test');
        [$operation, $give] = $this->readyGo(choice: new AccountMergeChoice(PasswordFate::NONE, null, null, null));
        $this->deliver($give);
        [$handover] = $this->frames();
        $this->deliver($handover);
        [$lostAnswer] = $this->frames();
        self::assertSame(IdentityType::MAGIC_LINK, Hilos::$db->identities[$own]->type);
        $saved = Hilos::$db->accountOperationResults->byAssignment($handover->data->data->assignmentId)->toArray();
        $this->asPerson(7, static function () use ($own): void {
            $identity = Hilos::$db->identities[$own]->getObject();
            $identity->type = IdentityType::PASSWORD;
            $identity->sync();
            $identity->setPasswordHash('new-own-password-hash');
        });
        $this->frames();
        $protection = Database::sql('SELECT * FROM `hilos_identity` WHERE `id` = ?', [$own])->row();
        self::assertSame(IdentityType::PASSWORD, $protection['type']);
        self::assertSame('new-own-password-hash', $protection['secret']);
        $this->releasePersonAgents();
        $this->freshContext();
        $context = Hilos::$db;
        $this->personAgent(7);
        $context->denyReceiptReads = true;
        try {
            $replayed = $this->asPerson(7, static fn (AbstractUserAgent $agent) =>
                $agent->resumeMergeReceipt($handover->data->data->assignmentId));
            self::assertSame($saved, $replayed->toArray());
            $this->deliver($handover);
            [$answer] = $this->frames();
            self::assertSame($lostAnswer->data->data->toArray(), $answer->data->data->toArray());
        } finally {
            $context->denyReceiptReads = false;
        }
        self::assertFalse(isset($context->mountedObjectCollection(HilosDbContext::identities)[$own]));
        self::assertSame($protection, Database::sql('SELECT * FROM `hilos_identity` WHERE `id` = ?', [$own])->row());
        $this->startHolder();
        $this->tick();
        $pending = $this->frames();
        self::assertNotContains('give', $this->keys($pending));
        self::assertNotContains('receive', $this->keys($pending));
        self::assertContains('cleanup', $this->keys($pending));
        $this->finishExchange($operation, $pending);
        self::assertSame($saved, Hilos::$db->accountOperationResults->byAssignment($handover->data->data->assignmentId)->toArray());
        self::assertSame($protection, Database::sql('SELECT * FROM `hilos_identity` WHERE `id` = ?', [$own])->row());
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());
    }

    /** @return iterable<string, array{bool}> Independent cold and previously held startup contexts */
    public static function receiptContexts(): iterable
    {
        yield 'cold context' => [false];
        yield 'held context' => [true];
    }

    /**
     * Group 5: actual startup and generic resume use current own rows without a new handover; a new holder discovers the receipt.
     *
     * @param bool $held Whether the startup context already holds the old source and own objects
     */
    #[DataProvider('receiptContexts')]
    public function testNewSurvivorStartupResumesCurrentOwnSnapshotWithoutAnotherHandover(bool $held): void
    {
        $own = $this->seedRecoveryIdentity(7, IdentityType::MAGIC_LINK, 'own@example.test');
        $source = $this->seedRecoveryIdentity(9, IdentityType::MAGIC_LINK, 'source@example.test');
        $removed = $this->seedRecoveryIdentity(9, IdentityType::MAGIC_LINK, 'removed@example.test');
        $reader = Hilos::$db;
        $old = $reader->identities[$source];
        $reader->identities[$own];
        $reader->identities[$removed];
        $this->freshContext();
        [$operation, $give] = $this->readyGo();
        $oldOwner = $this->personAgent(7);
        $this->deliver($give);
        $this->frames(); // Lose handover and source answer after the real transfer commit.
        $this->asPerson(7, static function () use ($source, $own, $removed): void {
            foreach ([$source => 'current source', $own => 'current own'] as $id => $name) {
                $identity = Hilos::$db->identities[$id]->getObject();
                $identity->providerName = $name;
                $identity->sync();
            }
            Hilos::$db->identities[$removed]->getObject()->delete();
        });
        $this->frames();
        $current = Database::sql('SELECT * FROM `hilos_identity` ORDER BY `id`')->rows();
        $receive = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Go->value,
            AccountMergeDuty::Receive->value);
        self::assertNotNull($receive);
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($receive->id));
        $this->releasePersonAgents();
        if ($held) {
            Hilos::$db = $reader;
        } else {
            $this->freshContext();
        }
        $context = Hilos::$db;
        $context->observeSnapshot = true;
        $context->denyCopyReads = true;
        try {
            $newOwner = $this->personAgent(7);
            $this->startOwner($newOwner);
        } finally {
            $context->denyCopyReads = false;
        }
        self::assertNotSame($oldOwner, $newOwner);
        self::assertSame([null, null, null, null, null], $context->rowReadTransactions);
        $receipt = Hilos::$db->accountOperationResults->byAssignment($receive->id);
        self::assertNotNull($receipt);
        self::assertSame('current source', Hilos::$db->identities[$source]->providerName);
        self::assertSame('current own', Hilos::$db->identities[$own]->providerName);
        self::assertFalse(isset($context->mountedObjectCollection(HilosDbContext::identities)[$removed]));
        if ($held) {
            self::assertSame($old->getObject(), Hilos::$db->identities[$source]->getObject());
            self::assertSame(7, $old->userId);
        }
        self::assertSame($current, Database::sql('SELECT * FROM `hilos_identity` ORDER BY `id`')->rows());
        $this->frames(); // Startup need not deliver an external answer for its committed result to count.
        $this->startHolder();
        $this->tick();
        $pending = $this->frames();
        self::assertNotContains('give', $this->keys($pending));
        self::assertNotContains('receive', $this->keys($pending));
        $this->finishExchange($operation, $pending);
        self::assertSame($receipt->toArray(), Hilos::$db->accountOperationResults->byAssignment($receive->id)->toArray());
    }

    /** Group 6: physical no-copy is insufficient until the last project proof commits; lost answers do not repeat saved duties. */
    public function testNewHolderEndsSavedCleanupOnlyAfterTheLastCanonicalProofCommits(): void
    {
        [$operation, $give, , $project, $cleanup] = $this->cleanupWindow();
        $this->deliver($cleanup);
        $this->frames(); // Cleanup's answer is lost after its application commit.
        $saved = $this->operationFacts();
        self::assertSame([], $saved['copies']);
        self::assertNull($this->row($operation)['finished_at']);
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($project->data->data->assignmentId));
        $this->releasePersonAgents();
        $this->freshContext();
        $this->startHolder();
        $this->tick();
        $pending = $this->frames();
        self::assertSame([$project->data->data->assignmentId], $this->assignmentIds($pending));
        self::assertSame($saved, $this->operationFacts());
        self::assertNull($this->row($operation)['finished_at']);
        $this->restartProject();
        $this->startOwner($this->project);
        $this->frames(); // Lose the last proof's answer too.
        $proofs = $this->operationFacts();
        self::assertNull($this->row($operation)['finished_at']);
        self::assertNotNull(Hilos::$db->accountOperationResults->byAssignment($project->data->data->assignmentId));
        $this->asHolder(fn () => $this->holder->onStop());
        $this->freshContext();
        $this->startHolder();
        $this->tick();
        self::assertSame([], $this->frames());
        self::assertSame('completed', $this->row($operation)['outcome']);
        self::assertNotNull($this->row($operation)['finished_at']);
        $after = $this->operationFacts();
        unset($proofs['operations'], $after['operations']);
        self::assertSame($proofs, $after);
        $this->deliver($give);
        [$answer] = $this->frames();
        self::assertInstanceOf(AccountOperationAnswerSignalData::class, $answer->data->data);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());
    }

    /** Group 6: a failed terminal commit is retried by a fresh holder using only the already committed canonical proofs. */
    public function testNewHolderRetriesFailedEndAfterSavedCleanupWithoutResendingWork(): void
    {
        [$operation, , , $project, $cleanup] = $this->cleanupWindow();
        $this->deliver($cleanup);
        $this->frames();
        $this->deliver($project);
        $this->frames();
        $before = $this->operationFacts();
        Database::sqlRun('ALTER TABLE `hilos_account_operation` ADD CONSTRAINT `recovery_end_failure` CHECK (`outcome` IS NULL)');
        try {
            $this->freshContext();
            $this->startHolder();
            $this->tick();
            self::assertSame($before, $this->operationFacts());
            self::assertSame([], $this->frames());
            self::assertFalse(Database::hasOpenTransactions());
        } finally {
            Database::sqlRun('ALTER TABLE `hilos_account_operation` DROP CONSTRAINT `recovery_end_failure`');
        }
        $this->asHolder(fn () => $this->holder->onStop());
        $this->freshContext();
        $this->startHolder();
        $this->tick();
        self::assertSame('completed', $this->row($operation)['outcome']);
        self::assertNotNull($this->row($operation)['finished_at']);
        self::assertSame([], $this->frames());
        $after = $this->operationFacts();
        unset($before['operations'], $after['operations']);
        self::assertSame($before, $after);
    }

    /** Group 7: loss of the private copy without a receipt leaves warm delivery waiting, with no reconstructed source secrets. */
    public function testNewHolderCannotRebuildMissingCopyOrInventReceiveAndCleanupProofs(): void
    {
        self::seedIdentity(9, IdentityType::MAGIC_LINK, 'source@example.test');
        [$operation, $give] = $this->readyGo();
        $this->deliver($give);
        $this->frames();
        Database::sqlRun('DELETE FROM `hilos_account_merge_copy`'); // Negative input, never a success proof.
        $identities = Database::sql('SELECT * FROM `hilos_identity`')->rows();
        $given = Hilos::$db->accountOperationResults->byAssignment($give->data->data->assignmentId)->toArray();
        $this->releasePersonAgents();
        $this->freshContext();
        $this->startHolder();
        $this->tick();
        $pending = $this->frames();
        self::assertCount(3, $pending);
        self::assertSame(['give', 'receive'], $this->keys(array_slice($pending, 0, 2)));
        self::assertSame($this->project::ACCOUNT_OPERATION_SIGNAL, $pending[2]->signalName->getName());
        foreach (array_slice($pending, 0, 2) as $ask) {
            $this->deliver($ask);
        }
        $answers = $this->frames();
        self::assertCount(2, $answers);
        self::assertSame(AccountOperationAnswerKind::Done, $answers[0]->data->data->answer);
        self::assertSame(AccountOperationAnswerKind::Waits, $answers[1]->data->data->answer);
        foreach ($answers as $answer) {
            $this->deliver($answer);
        }
        $retry = $this->frames();
        self::assertSame([$pending[1]->data->data->assignmentId, $pending[2]->data->data->assignmentId], $this->assignmentIds($retry));
        foreach ($retry as $ask) {
            self::assertInstanceOf(AccountOperationAskSignalData::class, $ask->data->data);
        }
        self::assertNull($this->row($operation)['finished_at']);
        self::assertNull($this->row($operation)['outcome']);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());
        self::assertSame($identities, Database::sql('SELECT * FROM `hilos_identity`')->rows());
        self::assertSame($given, Hilos::$db->accountOperationResults->byAssignment($give->data->data->assignmentId)->toArray());
        foreach ([AccountMergeDuty::Receive, AccountMergeDuty::Cleanup] as $duty) {
            $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Go->value, $duty->value);
            self::assertNotNull($assignment);
            self::assertNull(Hilos::$db->accountOperationResults->byAssignment($assignment->id));
        }
    }

    /** @return iterable<string, array{bool}> Missing proof with or without the registered project participant */
    public static function projectAvailability(): iterable
    {
        yield 'missing project result' => [false];
        yield 'missing project participant' => [true];
    }

    /**
     * Group 7: neither a missing project proof nor an absent participant lets another owner fabricate its result or End.
     *
     * @param bool $absent Whether the required participant is also missing from the active installation
     */
    #[DataProvider('projectAvailability')]
    public function testNewHolderLeavesCleanupOpenWithoutProjectResultOrRegisteredParticipant(bool $absent): void
    {
        [$operation, , , $project, $cleanup] = $this->cleanupWindow();
        $this->deliver($cleanup);
        $this->frames();
        if ($absent) {
            MergeRecoveryWithoutProjectHilos::initBrowser();
        }
        $before = $this->operationFacts();
        $this->freshContext();
        $this->startHolder();
        $this->tick();
        $pending = $this->frames();
        self::assertSame($absent ? [] : [$project->data->data->assignmentId], $this->assignmentIds($pending));
        self::assertSame($before, $this->operationFacts());
        self::assertNull($this->row($operation)['finished_at']);
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($project->data->data->assignmentId));
        self::assertSame([7, 9, 9], array_map('intval', array_column(Database::sql(
            'SELECT `user_id` FROM `' . AccountMergeProjectNote::_table . '` ORDER BY `id`',
        )->rows(), 'user_id')));
    }

    /** @return iterable<string, array{AccountMergeDuty}> Both canonical session-holder proofs required by End */
    public static function holderProofs(): iterable
    {
        yield 'loser sessions' => [AccountMergeDuty::Sessions];
        yield 'survivor sessions' => [AccountMergeDuty::SurvivorSessions];
    }

    /**
     * Group 7: physical cleanup never substitutes for a missing holder proof; only its canonical writer may repair it.
     *
     * @param AccountMergeDuty $duty Canonical holder result made absent as negative input
     */
    #[DataProvider('holderProofs')]
    public function testNewHolderCannotEndUntilItsMissingCanonicalResultReallyCommits(AccountMergeDuty $duty): void
    {
        [$operation, , , $project, $cleanup] = $this->cleanupWindow();
        $this->deliver($cleanup);
        $this->frames();
        $this->deliver($project);
        $this->frames();
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Go->value, $duty->value);
        self::assertNotNull($assignment);
        self::assertNotNull(Hilos::$db->accountOperationResults->byAssignment($assignment->id));
        Database::sqlRun('DELETE FROM `hilos_account_operation_result` WHERE `assignment_id` = ?', [$assignment->id]); // Negative input.
        $before = $this->operationFacts();
        $identities = Database::sql('SELECT * FROM `hilos_identity`')->rows();
        Database::sqlRun('ALTER TABLE `hilos_account_operation_result` ADD CONSTRAINT `recovery_holder_proof_failure`'
            . ' CHECK (`assignment_id` <> ' . $assignment->id . ')');
        try {
            $this->freshContext();
            $this->startHolder();
            $this->tick();
            self::assertSame($before, $this->operationFacts());
            self::assertNull($this->row($operation)['finished_at']);
            self::assertNull($this->row($operation)['outcome']);
            self::assertNull(Hilos::$db->accountOperationResults->byAssignment($assignment->id));
            self::assertSame([], $this->frames());
            self::assertFalse(Database::hasOpenTransactions());
        } finally {
            Database::sqlRun('ALTER TABLE `hilos_account_operation_result` DROP CONSTRAINT `recovery_holder_proof_failure`');
        }
        $this->asHolder(fn () => $this->holder->onStop());
        $this->freshContext();
        $this->startHolder();
        $this->tick();
        self::assertSame('completed', $this->row($operation)['outcome']);
        self::assertNotNull($this->row($operation)['finished_at']);
        self::assertSame([], $this->frames());
        $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        self::assertNotNull($result);
        self::assertSame($this->holder->getId(), $result->ownerAgentId);
        $after = $this->operationFacts();
        $repaired = array_values(array_filter($after['results'], static fn (array $row): bool =>
            (int)$row['assignment_id'] === $assignment->id));
        self::assertCount(1, $repaired);
        $after['results'] = array_values(array_filter($after['results'], static fn (array $row): bool =>
            (int)$row['assignment_id'] !== $assignment->id));
        unset($before['operations'], $after['operations']);
        self::assertSame($before, $after);
        self::assertSame($identities, Database::sql('SELECT * FROM `hilos_identity`')->rows());
    }

    /** Group 7: unavailable current fences and stored proofs fail closed at the cold startup and first holder pass. */
    public function testColdRecoveryFreshReadFailuresLeaveNoEffectsReceiptOrEnd(): void
    {
        $source = $this->seedRecoveryIdentity(9, IdentityType::MAGIC_LINK, 'source@example.test');
        [$operation, $give] = $this->readyGo();
        $this->deliver($give);
        $this->frames();
        $before = $this->operationFacts();
        $identities = Database::sql('SELECT * FROM `hilos_identity`')->rows();
        $this->releasePersonAgents();
        $this->freshContext();
        Database::sqlRun('RENAME TABLE `hilos_account_merge_fence` TO `hilos_account_merge_fence_unavailable`');
        try {
            $this->startOwner($this->personAgent(7));
            self::assertFalse(isset(Hilos::$db->mountedObjectCollection(HilosDbContext::identities)[$source]));
            [$failure] = $this->frames();
            self::assertSame(AccountOperationAnswerKind::Failed, $failure->data->data->answer);
            self::assertSame('receive', $this->keys([$failure])[0]);
        } finally {
            Database::sqlRun('RENAME TABLE `hilos_account_merge_fence_unavailable` TO `hilos_account_merge_fence`');
        }
        self::assertSame($before, $this->operationFacts());
        Database::sqlRun('RENAME TABLE `hilos_account_operation_result` TO `hilos_account_operation_result_unavailable`');
        try {
            $this->freshContext();
            $this->startHolder();
            $this->tick();
            self::assertSame([], $this->frames());
            self::assertNull($this->row($operation)['finished_at']);
        } finally {
            Database::sqlRun('RENAME TABLE `hilos_account_operation_result_unavailable` TO `hilos_account_operation_result`');
        }
        self::assertSame($before, $this->operationFacts());
        self::assertSame($identities, Database::sql('SELECT * FROM `hilos_identity`')->rows());
        $this->releasePersonAgents();
        $this->freshContext();
        $this->startOwner($this->personAgent(7));
        $this->frames();
        $this->startHolder();
        $this->tick();
        $this->finishExchange($operation, $this->frames());
    }

    /** @return iterable<string, array{bool, string}> Cold boundaries after physical cleanup and after actual End */
    public static function coldFenceBoundaries(): iterable
    {
        yield 'cleanup then edit' => [false, 'edit'];
        yield 'end then edit' => [true, 'edit'];
        yield 'cleanup then delete' => [false, 'delete'];
        yield 'end then delete' => [true, 'delete'];
        yield 'end then next transfer' => [true, 'transfer'];
    }

    /**
     * Group 12: cold receipt replay cannot hydrate the old copy, and retained fresh fences reject delayed transfer facts.
     *
     * @param bool $ended Whether the first holder has already committed the terminal outcome
     * @param string $change Real owner's intervening edit, deletion or next transfer
     */
    #[DataProvider('coldFenceBoundaries')]
    public function testColdRecoveryRetainsFenceAfterCleanupOrEndAndCannotHydrateSavedCopy(bool $ended, string $change): void
    {
        [$operation, , $handover, $project, $cleanup, $sync] = $this->cleanupWindow();
        $source = $handover->data->data->handover->copy->identities[0]->id;
        $this->deliver($cleanup);
        $this->frames();
        $this->deliver($project);
        $this->frames();
        $receiptId = $handover->data->data->assignmentId;
        $receipt = Hilos::$db->accountOperationResults->byAssignment($receiptId)->toArray();
        if ($ended) {
            $this->startHolder();
            $this->tick();
            self::assertSame('completed', $this->row($operation)['outcome']);
            self::assertSame([], $this->frames());
        } else {
            self::assertNull($this->row($operation)['finished_at']);
        }
        if ($change === 'transfer') {
            Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (11, 'Next survivor')");
            [$next, $give] = $this->readyGo(survivor: 11, loser: 7);
            $this->finishExchange($next, [$give]);
        } else {
            $this->asPerson(7, static function () use ($source, $change): void {
                $identity = Hilos::$db->identities[$source]->getObject();
                if ($change === 'delete') {
                    $identity->delete();
                } else {
                    $identity->providerName = 'after cleanup';
                    $identity->sync();
                }
            });
            $this->frames();
        }
        $durable = $this->operationFacts();
        $identities = Database::sql('SELECT * FROM `hilos_identity` ORDER BY `id`')->rows();
        $fences = Database::sql('SELECT * FROM `hilos_account_merge_fence` ORDER BY `collection_key`, `row_id`')->rows();
        self::assertSame([], $durable['copies']);
        self::assertCount(1, $fences);
        self::assertSame(HilosDbContext::identities, $fences[0]['collection_key']);
        self::assertSame($source, (int)$fences[0]['row_id']);
        self::assertSame($change === 'transfer' ? 11 : 7, (int)$fences[0]['owner_user_id']);
        self::assertSame($change === 'transfer' ? $next->id : $operation->id, (int)$fences[0]['operation_id']);
        self::assertSame($change === 'delete', (bool)$fences[0]['deleted']);
        if ($change !== 'transfer') {
            self::assertGreaterThan($handover->data->data->handover->admission->identities[0]->revision, (int)$fences[0]['revision']);
        }
        $this->releasePersonAgents();
        $this->freshContext();
        $this->personAgent(7);
        $writes = new MergeRecoveryWrites();
        SourceChangeBus::subscribe($writes);
        Hilos::$db->denyReceiptReads = true;
        try {
            $replayed = $this->asPerson(7, static fn (AbstractUserAgent $agent) => $agent->resumeMergeReceipt($receiptId));
            self::assertSame($receipt, $replayed->toArray());
            $this->deliver($handover);
            [$answer] = $this->frames();
            self::assertSame(AccountOperationAnswerKind::Done, $answer->data->data->answer);
        } finally {
            Hilos::$db->denyReceiptReads = false;
        }
        $expected = $ended ? AccountMergeAdmissionResult::Rejected : match ($change) {
            'edit' => AccountMergeAdmissionResult::PendingCurrent,
            'delete' => AccountMergeAdmissionResult::AlreadyCurrent,
            'transfer' => AccountMergeAdmissionResult::Rejected,
        };
        self::assertSame($expected, $this->asPerson(7, static fn () =>
            AccountMergeAdmission::apply(7, $handover->data->data->handover)));
        self::assertNotEmpty($sync);
        foreach ($sync as $fact) {
            self::assertFalse(DbSyncAdmission::check($fact)->accepted);
            self::assertFalse(DbSyncApplicator::applyUpdated($fact, false)->accepted);
        }
        self::assertFalse(isset(Hilos::$db->mountedObjectCollection(HilosDbContext::identities)[$source]));
        self::assertSame([], $writes->receiptIds);
        self::assertSame([], $this->frames());
        self::assertSame($durable, $this->operationFacts());
        self::assertSame($identities, Database::sql('SELECT * FROM `hilos_identity` ORDER BY `id`')->rows());
        self::assertSame($fences, Database::sql('SELECT * FROM `hilos_account_merge_fence` ORDER BY `collection_key`, `row_id`')->rows());
    }

    /** Group 12: even after real End and physical cleanup, unavailable current metadata cannot admit an old frame. */
    public function testColdRecoveryAfterEndRefusesOldFactsWhenFreshFenceReadFails(): void
    {
        [$operation, , $handover, $project, $cleanup, $sync] = $this->cleanupWindow();
        $this->deliver($cleanup);
        $this->frames();
        $this->deliver($project);
        $this->frames();
        $this->startHolder();
        $this->tick();
        self::assertSame('completed', $this->row($operation)['outcome']);
        self::assertSame([], $this->frames());
        $before = $this->operationFacts();
        $identities = Database::sql('SELECT * FROM `hilos_identity`')->rows();
        $this->releasePersonAgents();
        $this->freshContext();
        $this->personAgent(7);
        $writes = new MergeRecoveryWrites();
        SourceChangeBus::subscribe($writes);
        Database::sqlRun('RENAME TABLE `hilos_account_merge_fence` TO `hilos_account_merge_fence_unavailable`');
        try {
            self::assertSame(AccountMergeAdmissionResult::Rejected, $this->asPerson(7, static fn () =>
                AccountMergeAdmission::apply(7, $handover->data->data->handover)));
            self::assertNotEmpty($sync);
            foreach ($sync as $fact) {
                self::assertFalse(DbSyncAdmission::check($fact)->accepted);
                self::assertFalse(DbSyncApplicator::applyUpdated($fact, false)->accepted);
            }
            $this->deliver($handover); // The saved receipt still returns without reading unavailable fences or business rows.
            [$answer] = $this->frames();
            self::assertSame(AccountOperationAnswerKind::Done, $answer->data->data->answer);
            self::assertFalse(isset(Hilos::$db->mountedObjectCollection(HilosDbContext::identities)[
                $handover->data->data->handover->copy->identities[0]->id]));
            self::assertSame([], $writes->receiptIds);
            self::assertFalse(Database::hasOpenTransactions());
        } finally {
            Database::sqlRun('RENAME TABLE `hilos_account_merge_fence_unavailable` TO `hilos_account_merge_fence`');
        }
        self::assertSame($before, $this->operationFacts());
        self::assertSame($identities, Database::sql('SELECT * FROM `hilos_identity`')->rows());
    }

    /** Group 8: readiness is read-only across preparation, give, exact receipt, cancellation and terminal boundaries. */
    public function testOwedToUsesTheCanonicalWavesAndLiteralOwnerAddressWithoutWriting(): void
    {
        [$operation, $give] = $this->readyGo(function (AccountOperation $operation): void {
            $asks = $this->frames();
            self::assertSame([$asks[0]->data->data->assignmentId],
                $this->owedIds(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, '7')));
            self::assertSame([], $this->owedIds(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, '07')));
            self::assertSame([], $this->owedIds(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER)));
            foreach ($asks as $ask) {
                $this->deliver($ask);
                $this->deliver($this->frames()[0]);
            }
        });
        $source = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, '9');
        $survivor = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, '7');
        self::assertSame([$give->data->data->assignmentId], $this->owedIds($source));
        self::assertSame([], $this->owedIds($survivor));
        self::assertSame([], $this->owedIds(new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY)));
        $this->deliver($give);
        [$handover] = $this->frames();
        self::assertSame([$give->data->data->assignmentId], $this->owedIds($source));
        self::assertSame([$handover->data->data->assignmentId], $this->owedIds($survivor));
        $holderDuty = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, 'go', 'sessions');
        self::assertSame([$holderDuty->id], $this->owedIds(new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY)));
        self::assertSame([], $this->owedIds(new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY, '0')));
        $this->deliver($handover);
        $this->frames();
        $cleanup = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, 'go', 'cleanup');
        self::assertSame([$cleanup->id], $this->owedIds($source));
        self::assertSame([], $this->owedIds($survivor));
        $this->startHolder();
        $this->tick();
        $this->finishExchange($operation, $this->frames());
        self::assertSame([], $this->owedIds($source));
        self::assertSame([], $this->owedIds($survivor));
    }

    /** Group 8: saved release is not new work, and reading the second release never closes cancellation itself. */
    public function testOwedToCancellationLeavesTransitionsToTheHolder(): void
    {
        $operation = $this->openMerge();
        $this->frames();
        $this->asHolder(fn () => $this->holder->cancelMerge($operation));
        [$loser, $survivor] = $this->frames();
        self::assertSame([$loser->data->data->assignmentId],
            $this->owedIds(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, '9')));
        $this->deliver($loser);
        $this->frames();
        self::assertSame([], $this->owedIds(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, '9')));
        self::assertSame([$survivor->data->data->assignmentId],
            $this->owedIds(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, '7')));
        $this->deliver($survivor);
        $this->frames();
        self::assertSame([], $this->owedIds(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, '7')));
        self::assertNull($this->row($operation)['finished_at']);
    }

    /** @return iterable<string, array{string, string}> Corrupt inputs at strict persisted readiness boundaries */
    public static function readinessDefects(): iterable
    {
        foreach (['preparation', 'give', 'receipt', 'release', 'project', 'sessions', 'survivor_sessions', 'cleanup'] as $checkpoint) {
            foreach (['owner_agent_id', 'payload_json'] as $column) {
                yield $checkpoint . ' ' . $column => [$checkpoint, $column];
            }
        }
        foreach (['user_id', 'owner_index', 'payload_json'] as $column) {
            yield 'assignment ' . $column => ['assignment', $column];
        }
        yield 'foreign pair' => ['operation', 'loser_user_id'];
    }

    /**
     * Group 8: actual owner commits precede corrupt negative inputs; no malformed proof can unlock another wave.
     *
     * @param string $checkpoint Proof or immutable address to corrupt
     * @param string $column Persisted coordinate to corrupt
     */
    #[DataProvider('readinessDefects')]
    public function testOwedToRejectsForeignAssignmentsPairsAndStrictSavedProofs(string $checkpoint, string $column): void
    {
        if ($checkpoint === 'project') {
            Database::sqlRun('INSERT INTO `' . AccountMergeProjectNote::_table . '` (`user_id`) VALUES (9)');
        }
        if ($checkpoint === 'release') {
            $operation = $this->openMerge();
            $this->frames();
            $this->asHolder(fn () => $this->holder->cancelMerge($operation));
            [$release] = $this->frames();
            $this->deliver($release);
            $this->frames();
            $assignmentId = $release->data->data->assignmentId;
        } else {
            [$operation, $give] = $this->readyGo();
            $assignmentId = $give->data->data->assignmentId;
        }
        if ($checkpoint === 'preparation') {
            $assignmentId = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, 'preparing', 'prepare_loser')->id;
        } elseif (in_array($checkpoint, ['give', 'receipt', 'project', 'sessions', 'survivor_sessions', 'cleanup'], true)) {
            $this->deliver($give);
            [$handover] = $this->frames();
            if (in_array($checkpoint, ['receipt', 'survivor_sessions', 'cleanup'], true)) {
                $this->deliver($handover);
                $this->frames();
                $assignmentId = $handover->data->data->assignmentId;
            }
            if ($checkpoint === 'project') {
                $this->startOwner($this->project);
                $this->frames();
                $assignmentId = AccountOperationExpectedSet::of($operation->id)->expected()[0]->id;
            } elseif (in_array($checkpoint, ['sessions', 'survivor_sessions'], true)) {
                $this->startHolder();
                $this->tick();
                $this->frames();
                $assignmentId = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, 'go', $checkpoint)->id;
            } elseif ($checkpoint === 'cleanup') {
                $this->startOwner($this->personAgent(9));
                $this->frames();
                $assignmentId = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, 'go', 'cleanup')->id;
            }
        }
        if ($checkpoint === 'operation') {
            Database::sqlRun('UPDATE `hilos_account_operation` SET `loser_user_id` = 7 WHERE `id` = ?', [$operation->id]);
        } elseif ($checkpoint === 'assignment') {
            Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `' . $column . '` = ? WHERE `id` = ?',
                [$column === 'payload_json' ? '{"foreign":true}' : '7', $assignmentId]);
        } else {
            Database::sqlRun('UPDATE `hilos_account_operation_result` SET `' . $column . '` = ? WHERE `assignment_id` = ?',
                [$column === 'payload_json' ? '{}' : HilosAgentType::HILOS_USER . ':11', $assignmentId]);
        }
        $this->freshContext();
        $before = $this->operationFacts();
        try {
            AccountMergeCoordinator::owedTo(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, '7'));
            self::fail('A foreign immutable address or proof must refuse readiness.');
        } catch (InvalidFormatException) {
            self::assertSame($before, $this->operationFacts());
            self::assertSame([], $this->frames());
        }
    }

    /** Group 8: fresh persisted parent metadata wins over a previously held open merge facade. */
    public function testOwedToSkipsClosedAndForeignParentsDespiteHeldOpenMetadata(): void
    {
        $operation = $this->openMerge();
        $this->frames();
        $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, '9');
        self::assertCount(1, $this->owedIds($owner));
        Database::sqlRun('UPDATE `hilos_account_operation` SET `finished_at` = ? WHERE `id` = ?', [TimeHelper::nowMs(), $operation->id]);
        self::assertSame([], $this->owedIds($owner));
        Database::sqlRun('UPDATE `hilos_account_operation` SET `finished_at` = NULL, `kind` = ? WHERE `id` = ?', ['erasure', $operation->id]);
        self::assertSame([], $this->owedIds($owner));
        Database::sqlRun('DELETE FROM `hilos_account_operation` WHERE `id` = ?', [$operation->id]);
        self::assertSame([], $this->owedIds($owner));
    }

    /** Group 8: an unregistered project's real preparing wait remains a wait after give, without a fallback result. */
    public function testOwedToNeverTurnsAnUnconnectedProjectWaitIntoWork(): void
    {
        MergeRecoveryWithoutProjectHilos::initBrowser();
        Database::sqlRun('INSERT INTO `' . AccountMergeProjectNote::_table . '` (`user_id`) VALUES (9)');
        [$operation, $give] = $this->readyGo();
        $waiting = AccountOperationExpectedSet::of($operation->id)->waiting();
        self::assertCount(1, $waiting);
        $this->deliver($give);
        $this->frames();
        $source = $this->owedIds(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, '9'));
        $holder = $this->owedIds(new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY));
        self::assertNotContains($waiting[0]->id, [...$source, ...$holder]);
        self::assertSame([], $this->owedIds(new AccountOperationOwnerAddress(MergeRecoveryProjectOwner::AGENT_TYPE)));
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($waiting[0]->id));
        self::assertSame([9], array_map('intval', array_column(Database::sql(
            'SELECT `user_id` FROM `' . AccountMergeProjectNote::_table . '`',
        )->rows(), 'user_id')));
    }

    /** Group 9: a real preparing expectation keeps its original stage/id through go startup and the carried answer. */
    public function testProjectStartupRunsTheOriginalCarriedExpectationOnceUnderAnAgentReceipt(): void
    {
        $this->raiseJournal();
        Database::sqlRun('INSERT INTO `' . AccountMergeProjectNote::_table . '` (`user_id`) VALUES (9), (9)');
        $operation = $this->openMerge();
        $expectation = AccountOperationExpectedSet::of($operation->id)->expected()[0];
        self::assertSame('preparing', $expectation->stage);
        $asks = $this->frames();
        self::assertSame([], $this->owedIds($expectation->owner));
        foreach ($asks as $ask) {
            $this->deliver($ask);
            $this->deliver($this->frames()[0]);
        }
        [$give] = $this->frames();
        self::assertSame([], $this->owedIds($expectation->owner));
        $this->deliver($give);
        $outgoing = $this->frames();
        $copy = $this->operationFacts()['copies'];
        self::assertSame([$expectation->id], $this->owedIds($expectation->owner));
        TruthSourceRegistry::unregisterAgent($this->project->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->project->getId()));
        $this->project = new MergeRecoveryProjectOwner();
        OwnershipDeclaration::claimAll($this->project);
        $writes = new MergeRecoveryWrites();
        SourceChangeBus::subscribe($writes);
        $this->startOwner($this->project);
        [$answer] = $this->frames();
        self::assertSame($expectation->id, $answer->data->data->assignmentId);
        self::assertSame('preparing', $answer->data->data->stage);
        self::assertSame('done', $answer->data->data->answer->value);
        self::assertSame([7, 7], array_map('intval', array_column(Database::sql(
            'SELECT `user_id` FROM `' . AccountMergeProjectNote::_table . '` ORDER BY `id`',
        )->rows(), 'user_id')));
        self::assertSame($copy, $this->operationFacts()['copies']);
        self::assertSame([], $this->owedIds($expectation->owner));
        self::assertCount(1, $this->project->handledIds);
        $receiptIds = array_values(array_unique($writes->receiptIds));
        self::assertCount(1, $receiptIds);
        self::assertNotNull($receiptIds[0]);
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $receipt = Database::sql("SELECT `channel`, `action`, `agent` FROM {$journal}.`hilos_change_log_receipt` WHERE `id` = ?",
            [$receiptIds[0]])->row();
        self::assertSame('agent', $receipt['channel']);
        self::assertSame($this->project::ACCOUNT_OPERATION_SIGNAL, $receipt['action']);
        self::assertSame($this->project->getId(), $receipt['agent']);
        $this->deliver($answer);
        $this->finishExchange($operation, [...$outgoing, ...$this->frames()]);
    }

    /** Group 10: every skipped assignment moves the owner scan cursor before a later canonical ready give. */
    public function testOwnerScanFindsReadyDutyAfterMoreThanAWholePageOfUnsuitableAssignments(): void
    {
        [$operation, $give] = $this->readyGo(function (AccountOperation $operation): void {
            $this->asHolder(static function () use ($operation): void {
                for ($index = 0; $index < 101; $index++) {
                    Hilos::$db->accountOperationAssignments->actions->record($operation->id, 'preparing', 'skipped-' . $index,
                        new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, '9'), 9,
                        AccountOperationAssignmentPayload::fromJson('{}'));
                }
            });
            foreach ($this->frames() as $ask) {
                $this->deliver($ask);
                $this->deliver($this->frames()[0]);
            }
        });
        self::assertGreaterThan(103, $give->data->data->assignmentId);
        self::assertSame([$give->data->data->assignmentId],
            $this->owedIds(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, '9')));
        $this->startOwner($this->personAgent(9));
        $frames = $this->frames();
        self::assertCount(2, $frames);
        self::assertInstanceOf(AccountMergeHandoverSignalData::class, $frames[0]->data->data);
        self::assertSame($give->data->data->assignmentId, $frames[1]->data->data->assignmentId);
        self::assertSame('go', $this->row($operation)['stage']);
    }

    /** @return iterable<string, array{string}> Existing dispatcher boundaries under mixed-kind startup */
    public static function startupBoundaries(): iterable
    {
        yield 'ordered duties' => ['all'];
        yield 'stop after first' => ['stop'];
        yield 'existing receipt' => ['receipt'];
        yield 'failed first duty' => ['failure'];
    }

    /**
     * Group 10: merge/erasure/merge duties share id order, stop, failure containment and the original receipt seam.
     *
     * @param string $boundary Dispatcher boundary exercised with real committed duties
     */
    #[DataProvider('startupBoundaries')]
    public function testStartupOrdersBothKindsAndKeepsStopFailureAndReceiptBoundaries(string $boundary): void
    {
        $this->raiseJournal();
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (11, 'Erasure'), (13, 'Survivor 2'), (15, 'Loser 2')");
        Database::sqlRun('INSERT INTO `' . AccountMergeProjectNote::_table . '` (`user_id`) VALUES (9), (11), (15)');
        [$first, $give] = $this->readyGo();
        $this->deliver($give);
        $this->frames();
        $erasureDuty = $this->readyErasureProject(11);
        [$last, $give] = $this->readyGo(null, 13, 15);
        $this->deliver($give);
        $this->frames();
        $ids = [AccountOperationExpectedSet::of($first->id)->expected()[0]->id, $erasureDuty->id,
            AccountOperationExpectedSet::of($last->id)->expected()[0]->id];
        self::assertSame([$ids[0], $ids[2]], $this->owedIds(new AccountOperationOwnerAddress($this->project::AGENT_TYPE)));
        $this->project->stopAfterDuty = $boundary === 'stop';
        $this->project->failingAssignmentId = $boundary === 'failure' ? $ids[0] : null;
        $outerReceipt = null;
        if ($boundary === 'receipt') {
            JournalReceiptScope::run(JournalReceiptData::agent('surrounding-frame', $this->project->getId()), function () use (&$outerReceipt): void {
                $outerReceipt = JournalReceiptScope::currentId();
                self::assertNotNull($outerReceipt);
                $this->startOwner($this->project);
                self::assertSame($outerReceipt, JournalReceiptScope::currentId());
            });
        } else {
            $this->startOwner($this->project);
        }
        self::assertSame($boundary === 'stop' ? [$ids[0]] : $ids, $this->project->handledIds);
        $answers = $this->frames();
        self::assertSame($boundary === 'stop' ? [$ids[0]] : ($boundary === 'failure' ? array_slice($ids, 1) : $ids),
            $this->assignmentIds($answers));
        foreach ($answers as $answer) {
            self::assertInstanceOf(AccountOperationAnswerSignalData::class, $answer->data->data);
            self::assertSame(HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER, $answer->signalName->getName());
            self::assertNotNull($answer->data->receiptId);
        }
        if ($boundary === 'receipt') {
            self::assertSame([$outerReceipt], array_values(array_unique(array_map(
                static fn (SignalDTO $frame): ?int => $frame->data->receiptId, $answers,
            ))));
        } else {
            self::assertCount(count($answers), array_unique(array_map(static fn (SignalDTO $frame): ?int => $frame->data->receiptId, $answers)));
        }
        self::assertSame($boundary === 'stop', $this->project->shouldStop());
        self::assertNull(JournalReceiptScope::currentId());
        if ($boundary === 'failure') {
            self::assertNull(Hilos::$db->accountOperationResults->byAssignment($ids[0]));
            self::assertStringNotContainsString('Private startup duty payload', $this->agentLog());
        }
    }

    /** Group 11 counts driver entries, including a failing one, under one global ordered cursor and receipt. */
    public function testMixedBacklogUsesTenOperationsPerTickAndRetriesAnEntryOnlyOnTheNextPass(): void
    {
        $this->raiseJournal();
        $operations = $this->mixedBacklog();
        $holder = $this->startHolder();
        $holder->failingOperationId = $operations[3];
        $this->tick();
        $first = $this->frames();
        self::assertSame(array_slice($operations, 0, 10), $holder->entered);
        self::assertSame(array_values(array_diff(array_slice($operations, 0, 10), [$operations[3]])), array_values(array_unique(
            array_map(static fn (SignalDTO $frame): int => $frame->data->data->operationId, $first),
        )));
        self::assertNotContains($operations[3], array_map(static fn (SignalDTO $frame): int => $frame->data->data->operationId, $first));
        self::assertStringContainsString(
            "Account erasure recovery of operation {$operations[3]} failed, retried by the next pass: kind=merge; entry failed",
            $this->agentLog(),
        );
        self::assertStringNotContainsString('Private fixture payload', $this->agentLog());
        $receipt = $this->receipts()[0];
        self::assertSame(count($first) + 1, (int)$receipt['open_handovers']);
        $this->tick();
        $last = $this->frames();
        self::assertSame($operations, $holder->entered);
        self::assertSame([(int)$receipt['id']], array_values(array_unique($holder->receiptIds)));
        self::assertSame(count($first) + count($last), (int)$this->receipts()[0]['open_handovers']);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
        $this->assertQuietTick();

        $holder->failingOperationId = null;
        $holder->entered = [];
        $this->armPass();
        $this->tick();
        $this->frames();
        self::assertSame(array_slice($operations, 0, 10), $holder->entered);
        $this->tick();
        $this->frames();
        self::assertSame($operations, $holder->entered);
        self::assertCount(2, $this->receipts());
        $this->assertQuietTick();
    }

    /** A real page read failure closes the current receipt; the next scheduled pass starts again at the first operation. */
    public function testStorageFailureFinishesThePassAndTheHourlyRetryStartsAtZero(): void
    {
        $this->raiseJournal();
        $operations = $this->mixedBacklog();
        $holder = $this->startHolder();
        $this->tick();
        $asks = $this->frames();
        self::assertSame(array_slice($operations, 0, 10), $holder->entered);
        Database::sqlRun('RENAME TABLE `hilos_account_operation` TO `hilos_fw_test_unavailable_operation`');
        try {
            try {
                $this->tick();
                self::fail('The unavailable operation table must refuse the storage page.');
            } catch (DatabaseException) {
                self::assertNull(JournalReceiptScope::currentId());
                self::assertFalse(Database::hasOpenTransactions());
            }
        } finally {
            Database::sqlRun('RENAME TABLE `hilos_fw_test_unavailable_operation` TO `hilos_account_operation`');
        }
        self::assertSame(count($asks), (int)$this->receipts()[0]['open_handovers']);
        self::assertSame($operations, array_map('intval', array_column(Database::sql(
            'SELECT `id` FROM `hilos_account_operation` WHERE `finished_at` IS NULL ORDER BY `id`',
        )->rows(), 'id')));
        $this->assertQuietTick();
        $holder->entered = [];
        $this->armPass();
        $this->tick();
        $this->frames();
        self::assertSame(array_slice($operations, 0, 10), $holder->entered);
        self::assertCount(2, $this->receipts());
    }

    /** The real stop drops its in-flight position and local receipt hold; a new holder owes a first pass from zero. */
    public function testStopAndRestartResetTheMixedCursorAndReleaseTheReceiptHold(): void
    {
        $this->raiseJournal();
        $operations = $this->mixedBacklog();
        $holder = $this->startHolder();
        $this->tick();
        $asks = $this->frames();
        $first = $this->receipts()[0];
        self::assertSame(count($asks) + 1, (int)$first['open_handovers']);
        $this->asHolder(static fn () => $holder->onStop());
        self::assertSame(count($asks), (int)$this->receipts()[0]['open_handovers']);
        self::assertNull(JournalReceiptScope::currentId());

        $heir = $this->startHolder();
        self::assertNotSame($holder, $heir);
        $this->tick();
        $this->frames();
        self::assertSame(array_slice($operations, 0, 10), $heir->entered);
        self::assertCount(2, $this->receipts());
        self::assertNotSame($first['id'], $this->receipts()[1]['id']);
        $this->tick();
        $this->frames();
        self::assertSame($operations, $heir->entered);
    }

    /**
     * @param int $userId Fixture owner
     * @param string $type Sign-in method kind
     * @param string $identifier Unique fixture address
     * @return int Independently selected id; sqlRun restores its timeout after the INSERT
     */
    private function seedRecoveryIdentity(int $userId, string $type, string $identifier): int
    {
        self::seedIdentity($userId, $type, $identifier);

        return (int)Database::sql('SELECT `id` FROM `hilos_identity` WHERE `type` = ? AND `identifier` = ?',
            [$type, $identifier])->row()['id'];
    }

    /**
     * @return array{AccountOperation, SignalDTO, SignalDTO, SignalDTO, SignalDTO, list<DbSyncUpdatedSignalData>}
     * Real receive checkpoint with the original project and cleanup asks still unperformed, plus the source's sync facts
     */
    private function cleanupWindow(): array
    {
        Database::sqlRun('INSERT INTO `' . AccountMergeProjectNote::_table . '` (`user_id`) VALUES (7), (9), (9)');
        self::seedIdentity(9, IdentityType::MAGIC_LINK, 'source@example.test');
        [$operation, $give] = $this->readyGo();
        $this->deliver($give);
        $sync = [];
        [$handover] = $this->frames($sync);
        $this->deliver($handover);
        [$receipt] = $this->frames();
        $this->deliver($receipt);
        $pending = $this->frames();
        self::assertCount(2, $pending);
        self::assertSame($this->project::ACCOUNT_OPERATION_SIGNAL, $pending[0]->signalName->getName());
        self::assertSame('cleanup', $this->keys([$pending[1]])[0]);
        self::assertNull($this->row($operation)['finished_at']);

        return [$operation, $give, $handover, $pending[0], $pending[1], $sync];
    }

    /** Stops the old local project object and claims its ordinary replacement before startup. */
    private function restartProject(): void
    {
        ExecutionContext::run(new ExecutionFrame(agentId: $this->project->getId()), fn () => $this->project->onStop());
        TruthSourceRegistry::unregisterAgent($this->project->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->project->getId()));
        $this->project = new MergeRecoveryProjectOwner();
        OwnershipDeclaration::claimAll($this->project);
    }

    /**
     * @param ?callable(AccountOperation): void $prepare Optional checkpoint before real preparations run
     * @param int $survivor Survivor account
     * @param int $loser Source account
     * @param ?AccountMergeChoice $choice Explicit protection choice when the scenario needs one
     * @return array{AccountOperation, SignalDTO} Open go merge and its original give ask
     */
    private function readyGo(?callable $prepare = null, int $survivor = 7, int $loser = 9, ?AccountMergeChoice $choice = null): array
    {
        $operation = $this->openMerge($survivor, $loser, $choice);
        if ($prepare !== null) {
            $prepare($operation);
        } else {
            foreach ($this->frames() as $ask) {
                $this->deliver($ask);
                $this->deliver($this->frames()[0]);
            }
        }
        [$give] = $this->frames();
        self::assertSame(['give'], $this->keys([$give]));

        return [$operation, $give];
    }

    /**
     * @param int $root Erasure root with its own rows, separate from the merges
     * @return AccountOperationAssignment Genuine project duty held after the personal capture committed
     */
    private function readyErasureProject(int $root): AccountOperationAssignment
    {
        ExecutionContext::run(new ExecutionFrame(agentId: $this->library->getId()), static fn () =>
            Hilos::$db->accountDeletions->actions->request($root, TimeHelper::nowMs()));
        $operation = $this->asHolder(static fn () => AccountErasureOpening::open(
            Hilos::$db->accountDeletions->liveOf($root), AccountErasureParticipantCatalog::installation(), true,
        )->operation);
        self::assertNotNull($operation);
        $this->asHolder(fn () => $this->holder->driveErasure($operation));
        $held = [];
        for ($round = 0; $round < 20; $round++) {
            $frames = $this->frames();
            if ($frames === []) {
                self::assertCount(1, $held);

                return Hilos::$db->accountOperationAssignments[$held[0]->data->data->assignmentId];
            }
            foreach ($frames as $frame) {
                if ($frame->signalName->getName() === $this->project::ACCOUNT_OPERATION_SIGNAL) {
                    $held[] = $frame;
                } else {
                    $this->deliver($frame);
                }
            }
        }
        self::fail('The erasure must settle at its unperformed project duty.');
    }

    /** @param AbstractAgent $agent Owner started and resumed in its real worker identity */
    private function startOwner(AbstractAgent $agent): void
    {
        ExecutionContext::run(new ExecutionFrame(agentId: $agent->getId()), static function () use ($agent): void {
            $agent->onStart();
            AccountOperationAsks::resume($agent, $agent->getType(), $agent->getIndex());
        });
        self::assertFalse(Database::hasOpenTransactions());
    }

    /**
     * @param AccountOperationOwnerAddress $owner Literal stable address
     * @return list<int> Read-only ready ids, with secret copy access prohibited
     */
    private function owedIds(AccountOperationOwnerAddress $owner): array
    {
        $before = $this->operationFacts();
        $context = Hilos::$db;
        self::assertInstanceOf(MergeRecoveryContext::class, $context);
        $context->denyCopyReads = true;
        try {
            $owed = AccountMergeCoordinator::owedTo($owner);
        } finally {
            $context->denyCopyReads = false;
        }
        self::assertSame($before, $this->operationFacts());
        self::assertSame([], $this->frames());
        self::assertFalse(Database::hasOpenTransactions());

        return array_map(static fn (AccountOperationAssignment $assignment): int => $assignment->id, $owed);
    }

    /** @return array<string, list<array<string, mixed>>> Independent durable snapshots, including original copy and result bytes */
    private function operationFacts(): array
    {
        return [
            'operations' => Database::sql('SELECT * FROM `hilos_account_operation` ORDER BY `id`')->rows(),
            'assignments' => Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows(),
            'results' => Database::sql('SELECT * FROM `hilos_account_operation_result` ORDER BY `id`')->rows(),
            'copies' => Database::sql('SELECT * FROM `hilos_account_merge_copy` ORDER BY `assignment_id`')->rows(),
            'merges' => Database::sql('SELECT * FROM `hilos_user_merge` ORDER BY `user_id`')->rows(),
        ];
    }

    /** @inheritDoc */
    protected function newPersonAgent(string $userId): AbstractUserAgent
    {
        return new MergeRecoveryUser($userId);
    }

    /**
     * @param int $survivor Survivor of the pair
     * @param int $loser Source of the pair
     * @param ?AccountMergeChoice $choice Explicit choice or the ordinary automatic resolution
     * @return AccountOperation Prepared merge opened by the real holder
     */
    private function openMerge(int $survivor = 7, int $loser = 9, ?AccountMergeChoice $choice = null): AccountOperation
    {
        return $this->asHolder(fn () => $this->holder->openMerge(
            $survivor, $loser, $choice ?? new AccountMergeChoice(null, null, null, null),
        ));
    }

    /** @return list<int> Eleven valid openings, interleaved in primary id order */
    private function mixedBacklog(): array
    {
        $operations = [];
        for ($index = 0; $index < 11; $index++) {
            Database::sql("INSERT INTO `hilos_user` (`name`) VALUES ('Recovery account')");
            $root = Database::lastInsertId();
            if ($index % 2 === 0) {
                ExecutionContext::run(new ExecutionFrame(agentId: $this->library->getId()), static fn () =>
                    Hilos::$db->accountDeletions->actions->request(
                        $root, TimeHelper::nowMs() + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
                    ));
                $operation = $this->asHolder(static fn () => AccountErasureOpening::open(
                    Hilos::$db->accountDeletions->liveOf($root), AccountErasureParticipantCatalog::framework(),
                )->operation);
            } else {
                Database::sql("INSERT INTO `hilos_user` (`name`) VALUES ('Recovery loser')");
                $loser = Database::lastInsertId();
                $operation = $this->asHolder(fn () => $this->holder->openMerge($root, $loser, new AccountMergeChoice(null, null, null, null)));
            }
            self::assertNotNull($operation);
            $operations[] = $operation->id;
        }
        $this->frames();

        return $operations;
    }

    /** @return MergeRecoveryHolder New holder before onStart, with ordinary claims */
    private function claimHolder(): MergeRecoveryHolder
    {
        $this->releaseHolder();
        $this->holder = new MergeRecoveryHolder();
        OwnershipDeclaration::claimAll($this->holder);

        return $this->holder;
    }

    /** @return MergeRecoveryHolder Fresh AUTH holder whose real first tick owes the recovery pass */
    private function startHolder(): MergeRecoveryHolder
    {
        $holder = $this->claimHolder();
        $this->asHolder(static fn () => $holder->onStart());
        $holder->changeCronScheduleForTest('hilos_account_erasure_recovery', CronScheduleTestOperation::Pause, 'quiet-ticks');

        return $holder;
    }

    /** Releases only the old holder's claims and interests, as after a lost worker. */
    private function releaseHolder(): void
    {
        if ($this->holder === null) {
            return;
        }
        TruthSourceRegistry::unregisterAgent($this->holder->getId());
        RtTruthSourceRegistry::unregisterAgent($this->holder->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->holder->getId()));
        $this->holder = null;
    }

    /** Runs the real tick with its holder identity. */
    private function tick(): void
    {
        $this->asHolder(fn () => $this->holder->onTick());
    }

    /** Arms the existing hourly rule without waiting on the real clock. */
    private function armPass(): void
    {
        self::assertNotNull($this->holder->armCronRuleForTest('hilos_account_erasure_recovery'));
    }

    /** Verifies absence of any operation-collection access, transport or new receipt on a quiet tick. */
    private function assertQuietTick(): void
    {
        $context = Hilos::$db;
        self::assertInstanceOf(MergeRecoveryContext::class, $context);
        $context->operationReads = 0;
        $receipts = $this->journalRaised ? $this->receipts() : [];
        $this->tick();
        self::assertSame(0, $context->operationReads);
        self::assertSame([], $this->frames());
        self::assertSame($receipts, $this->journalRaised ? $this->receipts() : []);
    }

    /**
     * @template T
     * @param callable(): T $work Holder-owned handler
     * @return T Handler result
     */
    private function asHolder(callable $work): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), $work);
    }

    /**
     * @param list<DbSyncUpdatedSignalData> $sync Original business sync facts observed beside the operation frames
     * @return list<SignalDTO> Actual queued operation envelopes, excluding sync broadcasts
     */
    private function frames(array &$sync = []): array
    {
        $frames = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof DbSyncUpdatedSignalData && $frame->data->admission !== null) {
                $sync[] = $frame->data;
            }
            if ($frame->data instanceof AgentSignalData
                && ($frame->data->data instanceof AccountOperationAskSignalData || $frame->data->data instanceof AccountOperationAnswerSignalData
                    || $frame->data->data instanceof AccountMergeHandoverSignalData)) {
                $frames[] = $frame;
            }
        }

        return $frames;
    }

    /** @param SignalDTO $queued Serialized and rehydrated frame delivered in the actual recipient's ExecutionFrame */
    private function deliver(SignalDTO $queued): void
    {
        $frame = SignalDTO::fromArray(json_decode(json_encode($queued->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $data = Hilos::$sr->createAgentSignalPayloadDTO($frame->signalName->getName(), $frame->data);
        $sender = SignalSource::describe($frame->signalSource);
        $work = function () use ($frame, $data, $sender): void {
            if ($data->data instanceof AccountOperationAnswerSignalData) {
                $this->asHolder(fn () => $this->holder->onSignalAgent($data, $sender, $frame->signalName->getName()));
            } elseif ($frame->signalName->getName() === MergeRecoveryProjectOwner::ACCOUNT_OPERATION_SIGNAL) {
                ExecutionContext::run(new ExecutionFrame(agentId: $this->project->getId()),
                    fn () => $this->project->onSignalAgent($data, $sender, $frame->signalName->getName()));
            } else {
                $this->asPerson((int)$data->data->ownerIndex, static fn (AbstractUserAgent $agent) =>
                    $agent->onSignalAgent($data, $sender, $frame->signalName->getName()));
            }
        };
        $agentId = $data->data instanceof AccountOperationAnswerSignalData ? $this->holder->getId()
            : ($frame->signalName->getName() === MergeRecoveryProjectOwner::ACCOUNT_OPERATION_SIGNAL
                ? $this->project->getId() : $this->personAgent((int)$data->data->ownerIndex)->getId());
        if ($frame->data->receiptId !== null) {
            JournalReceiptScope::join($frame->data->receiptId, $agentId, $work);
        } else {
            $work();
        }
        self::assertFalse(Database::hasOpenTransactions(), 'A delivered owner frame must leave after its application commit.');
    }

    /**
     * @param AccountOperation $operation Merge that the genuine handlers must finish
     * @param list<SignalDTO> $frames First pending wave
     */
    private function finishExchange(AccountOperation $operation, array $frames): void
    {
        for ($round = 0; $round < 20; $round++) {
            foreach ($frames as $frame) {
                $this->deliver($frame);
            }
            $frames = $this->frames();
            if ($frames === []) {
                self::assertSame('completed', $this->row($operation)['outcome']);
                self::assertNotNull($this->row($operation)['finished_at']);

                return;
            }
        }
        self::fail('The finite merge exchange must finish without replay loops.');
    }

    /**
     * @param list<SignalDTO> $frames Actual operation frames
     * @return list<string> Stored assignment keys in transport order
     */
    private function keys(array $frames): array
    {
        return array_map(static fn (SignalDTO $frame): string =>
            Hilos::$db->accountOperationAssignments[$frame->data->data->assignmentId]->assignmentKey, $frames);
    }

    /**
     * @param list<SignalDTO> $frames Actual operation frames
     * @return list<int> Persisted assignment addresses
     */
    private function assignmentIds(array $frames): array
    {
        return array_map(static fn (SignalDTO $frame): int => $frame->data->data->assignmentId, $frames);
    }

    /**
     * @param AccountOperation $operation Operation observed independently of its held facade
     * @return array<string, mixed> Actual committed row
     */
    private function row(AccountOperation $operation): array
    {
        return Database::sql('SELECT * FROM `hilos_account_operation` WHERE `id` = ?', [$operation->id])->row();
    }

    /** Mounts the ordinary parent context and the existing typed project model. */
    private function freshContext(): void
    {
        Hilos::$db = new MergeRecoveryContext();
        Hilos::$db->configure();
    }

    /** Configures and raises the actual journal schema so receipt holds can be observed independently. */
    private function raiseJournal(): void
    {
        $primary = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::configure(ChangeLogDatabase::CONNECTION_INDEX, $primary->host, $primary->user, $primary->password,
            ChangeLogDatabase::configuredName(), $primary->port, $primary->charset);
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $this->journalRaised = true;
        foreach (self::JOURNAL_TABLES as $table) {
            Database::sqlRun("DROP TABLE IF EXISTS {$journal}.`{$table}`");
        }
        Database::sqlRun(str_replace(JournalTriggerRenderer::DATABASE_TOKEN, $journal,
            (string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_change_log.sql')));
        Schema::reset();
        Schema::initialize();
        MergeRecoveryJournalHilos::initBrowser();
        $this->freshContext();
    }

    /** @return list<array<string, mixed>> Recovery receipts and their real outstanding holds, oldest first */
    private function receipts(): array
    {
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());

        return Database::sql("SELECT `id`, `agent`, `source`, `open_handovers` FROM {$journal}.`hilos_change_log_receipt`"
            . ' WHERE `action` = ? ORDER BY `id`', ['hilos_account_erasure_recovery'])->rows();
    }

    /** @return string Actual agent diagnostics */
    private function agentLog(): string
    {
        $log = '';
        foreach (array_diff(scandir($this->logDirectory), ['.', '..']) as $entry) {
            $log .= file_get_contents($this->logDirectory . '/' . $entry);
        }

        return $log;
    }

    /** @param bool $down Whether to drop the existing support tables in reverse order */
    private function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::EXTRA_TABLES) : self::EXTRA_TABLES as $table) {
            // external-boundary: migration stub filenames carry the direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_'
                . $table . $suffix . '.sql'));
        }
    }
}

/** Operation-entry observations surround the inherited drivers; no result or coordinator work is replaced. */
final class MergeRecoveryHolder extends AbstractSessionsLibraryAgent
{
    /** @var list<int> Actual operation driver entries */
    public array $entered = [];
    /** @var list<?int> Actual receipt at each entry */
    public array $receiptIds = [];
    public ?int $failingOperationId = null;

    /** @inheritDoc */
    protected function driveAccountErasure(AccountOperation $operation): void
    {
        $this->observe($operation);
        parent::driveAccountErasure($operation);
    }

    /** @inheritDoc */
    protected function driveAccountMerge(AccountOperation $operation): void
    {
        $this->observe($operation);
        parent::driveAccountMerge($operation);
    }

    /**
     * @param AccountOperation $operation Entry whose real driver will follow unless deliberately refused
     */
    private function observe(AccountOperation $operation): void
    {
        $this->entered[] = $operation->id;
        $this->receiptIds[] = JournalReceiptScope::currentId();
        if ($operation->id === $this->failingOperationId) {
            throw new ValidationException('Private fixture payload');
        }
    }

    /**
     * @param int $survivor Survivor account
     * @param int $loser Loser account
     * @param AccountMergeChoice $choice Immutable supplied choices
     * @return AccountOperation Genuine prepared opening
     */
    public function openMerge(int $survivor, int $loser, AccountMergeChoice $choice): AccountOperation
    {
        return $this->openAccountMerge($survivor, $loser, $choice);
    }

    /**
     * @param AccountOperation $operation Merge whose durable give gate decides cancellation
     * @return AccountMergeCancellationState Real cancellation verdict
     */
    public function cancelMerge(AccountOperation $operation): AccountMergeCancellationState
    {
        return $this->cancelAccountMerge($operation);
    }

    /** @param AccountOperation $operation Existing erasure entering the production driver */
    public function driveErasure(AccountOperation $operation): void
    {
        $this->driveAccountErasure($operation);
    }
}

/** Ordinary personal owner, with every operation step inherited. */
final class MergeRecoveryUser extends AbstractUserAgent
{
}

/** Ordinary creator of the erasure requests mixed into the common pass. */
final class MergeRecoveryUsersLibrary extends AbstractUsersLibraryAgent
{
}

/** Local AUTH activation; recovery must not depend on a synthetic explicit driver call. */
abstract class MergeRecoveryHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH];
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = MergeRecoveryProjectDirectory::class;
    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [AgentRegistryKey::WORKER => MergeRecoveryUser::class, AgentRegistryKey::INDEXED => true],
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [AgentRegistryKey::WORKER => MergeRecoveryHolder::class],
        HilosAgentType::HILOS_USERS_LIBRARY => [AgentRegistryKey::WORKER => MergeRecoveryUsersLibrary::class],
        MergeRecoveryProjectOwner::AGENT_TYPE => [AgentRegistryKey::WORKER => MergeRecoveryProjectOwner::class],
    ];
}

/** The same activation with real stored receipts. */
abstract class MergeRecoveryJournalHilos extends MergeRecoveryHilos
{
    protected const array FEATURES = [HilosFeature::AUTH, HilosFeature::CHANGE_LOG];
}

/** The ordinary project census with its participant deliberately unregistered. */
abstract class MergeRecoveryWithoutProjectHilos extends MergeRecoveryHilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [AgentRegistryKey::WORKER => MergeRecoveryUser::class, AgentRegistryKey::INDEXED => true],
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [AgentRegistryKey::WORKER => MergeRecoveryHolder::class],
        HilosAgentType::HILOS_USERS_LIBRARY => [AgentRegistryKey::WORKER => MergeRecoveryUsersLibrary::class],
    ];
}

/** Ordinary AUTH runtime mounted for the holder's inherited lifecycle. */
final class MergeRecoveryRtContext extends RtContext
{
    /** AUTH supplies every runtime collection this fixture needs. */
    public function configure(): void
    {
    }
}

/** Parent framework mounts plus the existing typed notes, with a targeted collection-access counter. */
final class MergeRecoveryContext extends HilosDbContext
{
    private const array RECEIPT_READ_COLLECTIONS = [
        self::identities, self::passkeyCredentials, self::secondFactors, self::secondFactorBackupCodes, self::secondFactorSettings,
        self::secondFactorTrusts, self::secondFactorResets, self::accountDeletions, self::users, self::accountMergeFences, self::accountMergeCopies,
    ];

    public int $operationReads = 0;
    public bool $denyCopyReads = false;
    public bool $denyReceiptReads = false;
    public bool $observeSnapshot = false;
    /** @var list<?int> Actual transaction level at the five current-own snapshot row reads */
    public array $rowReadTransactions = [];

    /** @inheritDoc */
    public function configure(): void
    {
        parent::configure();
        $this->mountObjectCollection(AccountMergeProjectNotes::class, Objects::LAZY_STRATEGY_KEY);
    }

    /**
     * @param string $collectionKey Mounted row collection
     * @return ?Objects Ordinary mount with autocommit snapshot reads observed
     */
    public function mountedObjectCollection(string $collectionKey): ?Objects
    {
        if ($this->denyReceiptReads && in_array($collectionKey, self::RECEIPT_READ_COLLECTIONS, true)) {
            Assert::fail('Saved receipt replay must not resolve a business, copy or fence mount.');
        }
        if ($this->observeSnapshot && in_array($collectionKey, [self::identities, self::passkeyCredentials,
            self::secondFactors, self::secondFactorBackupCodes, self::secondFactorSettings], true)) {
            $this->rowReadTransactions[] = Database::runtimeTransactionLevel(DatabaseConnectionDefaults::PRIMARY_INDEX);
            if ($collectionKey === self::secondFactorSettings) {
                $this->observeSnapshot = false;
            }
        }

        return parent::mountedObjectCollection($collectionKey);
    }

    /**
     * @param string $name Mounted collection key
     * @return mixed Ordinary facade value, with operation access counted and forbidden copy reads rejected
     */
    public function __get(string $name)
    {
        if ($name === self::accountMergeCopies && $this->denyCopyReads) {
            Assert::fail('Read-only merge readiness must never access the private copy collection.');
        }
        if ($this->denyReceiptReads && in_array($name, self::RECEIPT_READ_COLLECTIONS, true)) {
            Assert::fail('Saved receipt replay must precede snapshot, admission and business reads.');
        }
        if ($name === self::accountOperations) {
            $this->operationReads++;
        }

        return parent::__get($name);
    }
}

/** Existing project census, bound to this suite's genuine merge participant. */
abstract class MergeRecoveryProjectDirectory extends AccountMergeProjectDirectory
{
    /** @return list<AccountDataOwner> One notes owner, available before opening */
    protected static function owners(): array
    {
        return array_map(static fn (AccountDataOwner $owner): AccountDataOwner => new AccountDataOwner(
            $owner->key, $owner->collections, MergeRecoveryProjectOwner::AGENT_TYPE, false, $owner->hasRowsOf,
        ), parent::owners());
    }
}

/** Actual project writer with its own result set and the production project step. */
final class MergeRecoveryProjectOwner extends AbstractAgent implements AccountMergeProjectOwner, AccountErasureProjectOwner
{
    public const string AGENT_TYPE = 'merge_recovery_notes';
    public const string ACCOUNT_OPERATION_SIGNAL = 'mergerecoverynotes_account_operation_step';
    public const array AGENT_SIGNALS = [self::ACCOUNT_OPERATION_SIGNAL => AccountOperationAskSignalData::class];
    public const array OWNS_DB = [AccountMergeProjectNotes::COLLECTION_KEY => [TruthSourceOperation::Update, TruthSourceOperation::Remove]];
    public const array OWNS_DB_SET = [HilosDbContext::accountOperationResults => [TruthSourceOperation::Add]];
    public const array READS_DB = [HilosDbContext::accountOperationAssignments, HilosDbContext::accountOperations];

    /** @var list<int> Original assignment ids dispatched through the real handler */
    public array $handledIds = [];
    public bool $stopAfterDuty = false;
    public ?int $failingAssignmentId = null;

    /** @inheritDoc */
    public function ownedDbSetKey(string $collection): string
    {
        return $this->getId();
    }

    /** @inheritDoc */
    public function mergeAccountPart(AccountMergePart $part): array
    {
        $notes = Hilos::$db->getObjectCollection(AccountMergeProjectNotes::COLLECTION_KEY);
        Assert::assertInstanceOf(AccountMergeProjectNotes::class, $notes);
        $keys = iterator_to_array($notes->loadByColumn(AccountMergeProjectNote::user_id, $part->loserUserId), false);
        foreach ($keys as $key) {
            $note = $notes[$key];
            $note->userId = $part->survivorUserId;
            $note->sync();
        }

        return ['notes' => count($keys)];
    }

    /** @inheritDoc */
    public function eraseAccountPart(AccountErasurePart $part): AccountErasure
    {
        $notes = Hilos::$db->getObjectCollection(AccountMergeProjectNotes::COLLECTION_KEY);
        Assert::assertInstanceOf(AccountMergeProjectNotes::class, $notes);
        $keys = iterator_to_array($notes->loadByColumn(AccountMergeProjectNote::user_id, $part->userId), false);
        foreach ($keys as $key) {
            $notes[$key]->delete();
        }

        return new AccountErasure(['notes' => count($keys)], []);
    }

    /**
     * @param AgentSignalData $data Actual generic assignment ask
     * @param string $sender Routed holder or the local startup sender
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
                $this->handledIds[] = $ask->assignmentId;
                Assert::assertSame($this->getId(), ExecutionContext::currentAgentId());
                if ($ask->assignmentId === $this->failingAssignmentId) {
                    throw new ValidationException('Private startup duty payload');
                }
                $this->sendToAgent(HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER, AccountOperationAsks::answer(
                    $ask,
                    fn (AccountOperationAssignment $assignment): AccountOperationResult => match ($assignment->operation->kind) {
                        AccountOperationKind::Merge => AccountMergeProjectStep::run($this, $assignment->id),
                        AccountOperationKind::Erasure => AccountErasureProjectStep::run($this, $assignment->id),
                    },
                    $this->getId(),
                ));
                if ($this->stopAfterDuty) {
                    $this->selfStop();
                }

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /** No resources outlive one delivered test frame. */
    public function onStop(): void
    {
    }
}

/** Records actual committed non-bookkeeping writes under their current receipt. */
final class MergeRecoveryWrites implements SourceChangeSubscriberInterface
{
    /** @var list<?int> Receipts of actual committed writes */
    public array $receiptIds = [];

    /** @inheritDoc */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->sourceKey !== HilosDbContext::changeLogReceipts) {
            $this->receiptIds[] = JournalReceiptScope::currentId();
        }
    }
}
