<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\AccountOperation\AccountMergeAdmission;
use Hilos\Auth\AccountOperation\AccountMergeAdmissionResult;
use Hilos\Auth\AccountOperation\AccountMergeAdmissionManifest;
use Hilos\Auth\AccountOperation\AccountMergeHandover;
use Hilos\Auth\AccountOperation\AccountMergeHandoverCopy;
use Hilos\Auth\AccountOperation\AccountMergeRefusal;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeGiving;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Database\Object\Item\SecondFactor as ObjectSecondFactor;
use Hilos\Database\Object\Collection\SecondFactors as ObjectSecondFactors;
use Hilos\Database\Object\Collection\AccountMergeFences as ObjectAccountMergeFences;
use Hilos\Database\Object\Objects;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\Object\Collection\PasskeyCredentials as ObjectPasskeyCredentials;
use Hilos\Database\Object\Collection\SecondFactorBackupCodes as ObjectSecondFactorBackupCodes;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountMergePreparationResult;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Sync\DTO\DbSyncAdmissionData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\SecondFactorFate;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Hilos\Tables\Users\HilosMergeCandidatesTable;
use RuntimeException;

/** Real transfers, commits and worker consumers; no synthetic rollback root. */
final class AccountMergeAdmissionIntegrationTest extends FrameworkIntegrationTestCase
{
    use PersonAgentFrames;

    private const string COORDINATOR = 'merge-admission-coordinator';
    private const int LOSER = 9;
    private const int SURVIVOR = 7;
    private const int PEER = 2;
    private const array TABLES = [
        'hilos_user', 'hilos_identity', 'hilos_passkey_credential', 'hilos_second_factor', 'hilos_second_factor_backup_code',
        'hilos_second_factor_setting', 'hilos_second_factor_reset', 'hilos_second_factor_trust',
        'hilos_user_merge', 'hilos_account_deletion', 'hilos_account_operation', 'hilos_account_operation_assignment',
        'hilos_account_operation_result', 'hilos_account_merge_copy', 'hilos_account_merge_fence',
    ];
    private ?HilosDbContext $previousDb;
    private ?SignalRouter $previousRouter;
    private int $frameToken;
    private int $identityId;
    private int $passkeyId;
    private int $factorId;
    private int $unfinishedId;
    private int $codeId;
    private int $usedCodeId;
    private int $targetFactorId;

    private ?BrowserContext $previousBrowser;
    /** A complete schema and the production person's claims belong to each case. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousBrowser = Hilos::$browser;
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->stubs(true);
        $this->stubs(false);
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->freshContext();
        OwnershipDeclaration::claimDb(AdmissionCoordinator::class, self::COORDINATOR);
        $this->personAgent(self::LOSER);
        $this->personAgent(self::SURVIVOR);
        $this->frameToken = ExecutionContext::push(new ExecutionFrame(agentId: self::COORDINATOR));
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (7, 'Survivor'), (9, 'Loser'), (11, 'Other')");
        $this->identityId = $this->identity(self::LOSER, IdentityType::PASSKEY);
        Database::sql('INSERT INTO `hilos_passkey_credential` (`identity_id`, `user_id`, `credential_id`, `public_key`, `algorithm`,'
            . ' `sign_count`, `transports`, `aaguid`, `user_handle`, `label`, `last_used_at`, `created_at`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->identityId, self::LOSER, 'credential-original', 'key-original', PasskeyAlgorithm::Es256->value, 17,
                '["usb", "internal"]', 'aaguid-original', "\xff\0\x80", 'Key', -1, 0]);
        $this->passkeyId = Database::lastInsertId();
        $this->factorId = $this->factor(self::LOSER);
        $this->unfinishedId = $this->factor(self::LOSER, false);
        $this->targetFactorId = $this->factor(self::SURVIVOR);
        $this->codeId = $this->code(self::LOSER);
        $this->usedCodeId = $this->code(self::LOSER, -1);
        $this->code(self::SURVIVOR);
        foreach ([self::LOSER, self::SURVIVOR] as $user) {
            Database::sqlRun('INSERT INTO `hilos_second_factor_setting` (`user_id`, `reset_wait_days`, `updated_at`) VALUES (?, ?, ?)',
                [$user, 21, 0]);
            Database::sqlRun('INSERT INTO `hilos_second_factor_trust` (`session_id`, `user_id`, `trusted_until`, `created_at`, `granted_at`)'
                . ' VALUES (?, ?, ?, ?, ?)', [$user, $user, 10000, 0, 0]);
        }
    }

    /** DDL follows handler cleanup; every declaration and facade is restored. */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            $this->stubs(true);
        } finally {
            ExecutionContext::pop($this->frameToken);
            $this->releasePersonAgents();
            TruthSourceRegistry::unregisterAgent(self::COORDINATOR);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::COORDINATOR));
            Hilos::$db = $this->previousDb;
            Hilos::$browser = $this->previousBrowser;
            Hilos::$sr = $this->previousRouter;
            SourceChangeBus::reset();
            parent::tearDown();
        }
    }

    public function testRealGiveHydratesAllFourMappedRowsWithoutBusinessWritesAndSuppressesDuplicates(): void
    {
        [, $give] = $this->ready();
        $this->give($give);
        $frames = $this->frames();
        self::assertCount(4, $frames);
        $this->freshContext();
        $updates = $this->updates();
        foreach ($frames as $collectionKey => $frame) {
            $created = new DbSyncCreatedSignalData($collectionKey, $frame->idString, $frame->admission->snapshot,
                writerAgentId: $frame->writerAgentId, admission: $frame->admission);
            $result = DbSyncApplicator::applyCreated($created, false);
            self::assertTrue($result->accepted);
            self::assertTrue($result->memoryChanged);
            $object = Hilos::$db->mountedObjectCollection($collectionKey)[$frame->idString];
            foreach ($frame->admission->snapshot as $column => $value) {
                self::assertSame($value, $object->valueForColumn($column));
            }
            self::assertFalse(DbSyncApplicator::applyUpdated($frame, false)->accepted);
        }
        self::assertSame($updates, $this->updates());
        self::assertSame([], $this->frames());
    }

    public function testHandoverHydratesInPlaceBeforeAndAfterReceiptAndCopyRemovalWithoutBusinessReads(): void
    {
        $views = [
            HilosDbContext::identities => Hilos::$db->identities[$this->identityId],
            HilosDbContext::passkeyCredentials => Hilos::$db->passkeyCredentials[$this->passkeyId],
            HilosDbContext::secondFactors => Hilos::$db->secondFactors[$this->factorId],
            HilosDbContext::secondFactorBackupCodes => Hilos::$db->secondFactorBackupCodes[$this->usedCodeId],
        ];
        $reader = Hilos::$db;
        $this->freshContext();
        $this->asPerson(self::LOSER, function (): void {
            $identity = Hilos::$db->identities[$this->identityId]->getObject();
            $identity->providerName = 'before transfer';
            $identity->sync();
        });
        $preTransfer = null;
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof DbSyncUpdatedSignalData && $signal->data->collectionKey === HilosDbContext::identities) {
                $preTransfer = $signal->data;
            }
        }
        self::assertNotNull($preTransfer);
        self::assertNull($preTransfer->admission);
        [$operation, $give] = $this->ready();
        $this->give($give);
        $handover = $this->handover($give);
        $frames = $this->frames();
        $writer = Hilos::$db;
        Hilos::$db = $reader;
        $sources = new AdmissionSourceChanges();
        SourceChangeBus::subscribe($sources);
        $updates = $this->updates();
        $writes = Database::sql("SHOW SESSION STATUS WHERE Variable_name IN ('Com_update', 'Com_insert', 'Com_delete')")->rows();
        self::assertSame(AccountMergeAdmissionResult::Admitted, $this->admit($handover));
        foreach ($views as $key => $view) {
            $collection = Hilos::$db->mountedObjectCollection($key);
            self::assertSame($view->getObject(), $collection[$view->id]);
            self::assertSame($view, Hilos::$db->{$key}[$view->id]);
            self::assertSame(self::SURVIVOR, $view->userId);
            foreach ($frames[$key]->admission->snapshot as $column => $value) {
                self::assertSame($value, $view->getObject()->valueForColumn($column));
            }
        }
        self::assertSame($this->identityId, $views[HilosDbContext::passkeyCredentials]->identityId);
        self::assertNull(Hilos::$db->secondFactorBackupCodes[$this->codeId]->usedAt);
        self::assertSame(-1, Hilos::$db->secondFactorBackupCodes[$this->usedCodeId]->usedAt);
        self::assertSame([HilosDbContext::identities, HilosDbContext::passkeyCredentials, HilosDbContext::secondFactors,
            HilosDbContext::secondFactorBackupCodes, HilosDbContext::secondFactorBackupCodes],
            array_map(static fn (SourceChange $change): string => $change->sourceKey, $sources->changes));
        self::assertCount(1, Hilos::$db->identities->listByUser(self::SURVIVOR));
        self::assertSame([], Hilos::$db->identities->listByUser(self::LOSER));
        $count = count($sources->changes);
        self::assertSame(AccountMergeAdmissionResult::AlreadyCurrent, $this->admit($handover));
        foreach ($frames as $frame) {
            self::assertFalse(DbSyncApplicator::applyUpdated($frame, false)->accepted);
        }
        self::assertFalse(DbSyncApplicator::applyUpdated($preTransfer, false)->accepted);
        self::assertSame('before transfer', $views[HilosDbContext::identities]->providerName);
        self::assertSame($count, count($sources->changes));
        self::assertSame($updates, $this->updates());
        self::assertSame($writes,
            Database::sql("SHOW SESSION STATUS WHERE Variable_name IN ('Com_update', 'Com_insert', 'Com_delete')")->rows());
        self::assertSame([], $this->frames());
        Hilos::$db = $writer;
        $receipt = Hilos::$db->accountOperationAssignments->actions->record($operation->id, AccountMergeStage::Go->value,
            'test-receipt', new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)self::SURVIVOR),
            self::SURVIVOR, AccountOperationAssignmentPayload::fromJson('{}'));
        $this->asPerson(self::SURVIVOR, static fn () => Database::transaction(DatabaseNames::PRIMARY,
            static fn () => Hilos::$db->accountOperationResults->actions->record($receipt,
                AccountOperationResultPayload::fromJson('{"testReceipt":true}'))));
        Hilos::$db = $reader;
        self::assertSame(AccountMergeAdmissionResult::AlreadyCurrent, $this->admit($handover));
        Database::sqlRun('DELETE FROM `hilos_account_merge_copy` WHERE `assignment_id` = ?', [$give->id]);
        self::assertSame(AccountMergeAdmissionResult::AlreadyCurrent, $this->admit($handover));
        self::assertSame($count, count($sources->changes));
        $this->freshContext();
        self::assertNull($this->asPerson(self::LOSER, static fn () => AccountMergeGiving::handover($give->id, self::LOSER)));
        $cold = new AdmissionReadlessContext();
        $cold->configure();
        Hilos::$db = $cold;
        self::assertSame(AccountMergeAdmissionResult::Admitted, $this->admit($handover));
        self::assertSame(AccountMergeAdmissionResult::AlreadyCurrent, $this->admit($handover));
        foreach ($frames as $key => $frame) {
            self::assertTrue(isset($cold->mountedObjectCollection($key)[$frame->idString]));
        }
        foreach ($sources->changes as $change) {
            $serialized = serialize($change->toArray());
            self::assertStringNotContainsString('totp-secret-original', $serialized);
            self::assertStringNotContainsString('backup-code', $serialized);
            self::assertArrayNotHasKey('secret', $change->row);
            self::assertArrayNotHasKey('code', $change->row);
        }
        self::assertSame([], $this->frames());
    }

    public function testEqualCurrentHandoverHydratesAfterUncachedTransferDeliveryWithoutRepeatingFanout(): void
    {
        [, $give] = $this->ready();
        $this->give($give);
        $handover = $this->handover($give);
        $frames = $this->frames();
        self::assertCount(4, $frames);
        $cold = new AdmissionReadlessContext();
        $cold->configure();
        Hilos::$db = $cold;
        $observer = new AdmissionObserver();
        $worker = new AdmissionWorker($observer);
        Hilos::$browser = new AdmissionBrowser();
        $sources = new AdmissionSourceChanges();
        SourceChangeBus::subscribe($sources);
        $writes = Database::sql("SHOW SESSION STATUS WHERE Variable_name IN ('Com_update', 'Com_insert', 'Com_delete')")->rows();
        foreach ($frames as $key => $frame) {
            $worker->deliver($frame);
            self::assertFalse(isset($cold->mountedObjectCollection($key)[$frame->idString]));
        }
        self::assertSame(4, $observer->updates);
        self::assertSame(4, Hilos::$browser->events);
        self::assertSame([], $sources->changes);
        try {
            Database::transaction(DatabaseNames::PRIMARY, function () use ($handover): void {
                self::assertSame(AccountMergeAdmissionResult::Admitted, $this->admit($handover));
                throw new RuntimeException('Roll back equal-current hydration.');
            });
            self::fail('The failed hydration transaction must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('Roll back equal-current hydration.', $exception->getMessage());
        }
        foreach ($frames as $key => $frame) {
            self::assertFalse(isset($cold->mountedObjectCollection($key)[$frame->idString]));
            self::assertSame(1, $cold->mountedObjectCollection(HilosDbContext::accountMergeFences)[$key . ':' . $frame->idString]
                ->appliedRevision());
        }
        self::assertSame([], $sources->changes);
        self::assertSame(AccountMergeAdmissionResult::Admitted, $this->admit($handover));
        foreach ($frames as $key => $frame) {
            $object = $cold->mountedObjectCollection($key)[$frame->idString];
            foreach ($frame->admission->snapshot as $column => $value) {
                self::assertSame($value, $object->valueForColumn($column));
            }
            self::assertSame(self::SURVIVOR, $object->userId);
            self::assertSame(1, $cold->mountedObjectCollection(HilosDbContext::accountMergeFences)[$key . ':' . $frame->idString]
                ->appliedRevision());
        }
        self::assertSame($this->identityId,
            $cold->mountedObjectCollection(HilosDbContext::passkeyCredentials)[$this->passkeyId]->identityId);
        $count = count($sources->changes);
        self::assertSame(5, $count);
        self::assertSame(AccountMergeAdmissionResult::AlreadyCurrent, $this->admit($handover));
        foreach ($frames as $frame) {
            $worker->deliver($frame);
        }
        self::assertSame($count, count($sources->changes));
        self::assertSame(4, $observer->updates);
        self::assertSame(4, Hilos::$browser->events);
        self::assertSame($writes,
            Database::sql("SHOW SESSION STATUS WHERE Variable_name IN ('Com_update', 'Com_insert', 'Com_delete')")->rows());
        self::assertSame([], $this->frames());
    }

    public function testHandoverPendingCurrentPreflightsTheWholeBatchWithAStaleOrMissingObject(): void
    {
        [, $give] = $this->ready();
        $this->give($give);
        $handover = $this->handover($give);
        $birth = $this->frames()[HilosDbContext::secondFactors];
        $writer = Hilos::$db;
        $this->freshContext();
        $reader = Hilos::$db;
        self::assertTrue(DbSyncApplicator::applyCreated(new DbSyncCreatedSignalData($birth->collectionKey, $birth->idString,
            $birth->admission->snapshot, writerAgentId: $birth->writerAgentId, admission: $birth->admission), false)->accepted);
        $stale = $reader->secondFactors[$this->factorId];
        Hilos::$db = $writer;
        $this->asPerson(self::SURVIVOR, function (): void {
            $factor = Hilos::$db->secondFactors[$this->factorId]->getObject();
            $factor->label = 'current label';
            $factor->sync();
        });
        $current = $this->frames()[HilosDbContext::secondFactors];
        Hilos::$db = $reader;
        $sources = new AdmissionSourceChanges();
        SourceChangeBus::subscribe($sources);
        self::assertSame(AccountMergeAdmissionResult::PendingCurrent, $this->admit($handover));
        self::assertSame('Authenticator', $stale->label);
        self::assertFalse(isset($reader->mountedObjectCollection(HilosDbContext::identities)[$this->identityId]));
        self::assertSame([], $sources->changes);
        self::assertTrue(DbSyncApplicator::applyUpdated($current, false)->accepted);
        self::assertSame(AccountMergeAdmissionResult::Admitted, $this->admit($handover));
        self::assertSame('current label', $stale->label);
        self::assertSame(AccountMergeAdmissionResult::AlreadyCurrent, $this->admit($handover));
        $this->freshContext();
        $sources->changes = [];
        self::assertSame(AccountMergeAdmissionResult::PendingCurrent, $this->admit($handover));
        self::assertSame([], $sources->changes);
        self::assertFalse(isset(Hilos::$db->mountedObjectCollection(HilosDbContext::secondFactors)[$this->factorId]));
        $uncached = DbSyncApplicator::applyUpdated($current, false);
        self::assertTrue($uncached->accepted);
        self::assertFalse($uncached->memoryChanged);
        self::assertSame(AccountMergeAdmissionResult::PendingCurrent, $this->admit($handover));
        self::assertSame([], $sources->changes);
        self::assertFalse(isset(Hilos::$db->mountedObjectCollection(HilosDbContext::secondFactors)[$this->factorId]));
        self::assertSame([], $this->frames());
    }

    public function testHandoverRejectsChangedManifestCoordinatesAnchorsAndLastRowMetadataBeforeAnyEffect(): void
    {
        [$operation, $give] = $this->ready();
        $this->give($give);
        $handover = $this->handover($give);
        $this->frames();
        $this->freshContext();
        $sources = new AdmissionSourceChanges();
        SourceChangeBus::subscribe($sources);
        self::assertSame(AccountMergeAdmissionResult::Rejected, AccountMergeAdmission::apply(self::SURVIVOR, $handover));
        self::assertSame(AccountMergeAdmissionResult::Rejected, $this->asPerson(self::LOSER,
            static fn () => AccountMergeAdmission::apply(self::SURVIVOR, $handover)));
        foreach (['operationId', 'assignmentId', 'survivorPasswordIdentityId'] as $key) {
            $copy = $handover->copy->toArray();
            $copy[$key] = 99999;
            $altered = new AccountMergeHandover(AccountMergeHandoverCopy::fromArray($copy), $handover->admission);
            self::assertSame(AccountMergeAdmissionResult::Rejected, $this->admit($altered));
        }
        $manifest = $handover->admission->toArray();
        ++$manifest[HilosDbContext::secondFactorBackupCodes][1]['revision'];
        self::assertSame(AccountMergeAdmissionResult::Rejected, $this->admit(new AccountMergeHandover($handover->copy,
            AccountMergeAdmissionManifest::fromArray($manifest))));
        Database::sqlRun('DELETE FROM `hilos_account_merge_fence` WHERE `collection_key` = ? AND `row_id` = ?',
            [HilosDbContext::secondFactorBackupCodes, $this->usedCodeId]);
        self::assertSame(AccountMergeAdmissionResult::Rejected, $this->admit($handover));
        foreach (ObjectAccountMergeFences::BUSINESS_COLLECTIONS as $key) {
            foreach ($handover->copy->{$key} as $row) {
                self::assertFalse(isset(Hilos::$db->mountedObjectCollection($key)[$row->id]));
            }
        }
        self::assertSame([], $sources->changes);
        self::assertSame([], $this->frames());
        self::assertTrue(Hilos::$db->accountOperations[$operation->id]->actions->finish(
            AccountMergeStage::Go->value, AccountOperationOutcome::Completed));
        self::assertSame(AccountMergeAdmissionResult::Rejected, $this->admit($handover));
    }

    public function testHandoverAndDbSyncShareProgressThroughEditsUnmappedWritesAndDeletion(): void
    {
        [, $give] = $this->ready();
        $this->give($give);
        $handover = $this->handover($give);
        $old = $this->frames();
        $writer = Hilos::$db;
        $this->freshContext();
        self::assertSame(AccountMergeAdmissionResult::Admitted, $this->admit($handover));
        $reader = Hilos::$db;
        Hilos::$db = $writer;
        $this->asPerson(self::SURVIVOR, function (): void {
            Hilos::$db->secondFactorBackupCodes[$this->codeId]->getObject()->writeCode('changed-code');
        });
        $unmapped = $this->frames()[HilosDbContext::secondFactorBackupCodes];
        $this->asPerson(self::SURVIVOR, function (): void {
            $identity = Hilos::$db->identities[$this->identityId]->getObject();
            $identity->providerName = 'current name';
            $identity->sync();
            Hilos::$db->secondFactors[$this->factorId]->getObject()->delete();
        });
        $current = $this->frames();
        Hilos::$db = $reader;
        self::assertSame(AccountMergeAdmissionResult::PendingCurrent, $this->admit($handover));
        self::assertTrue(DbSyncApplicator::applyUpdated($unmapped, false)->accepted);
        self::assertTrue(DbSyncApplicator::applyUpdated($current[HilosDbContext::identities], false)->accepted);
        self::assertTrue(DbSyncApplicator::applyDeleted($current[HilosDbContext::secondFactors], false)->accepted);
        self::assertSame(AccountMergeAdmissionResult::AlreadyCurrent, $this->admit($handover));
        self::assertSame('current name', Hilos::$db->identities[$this->identityId]->providerName);
        self::assertFalse(isset($reader->mountedObjectCollection(HilosDbContext::secondFactors)[$this->factorId]));
        foreach ($old as $frame) {
            self::assertFalse(DbSyncApplicator::applyUpdated($frame, false)->accepted);
        }
        self::assertSame([], $this->frames());
    }

    public function testHandoverHydrationFailureRollsBackMembershipProgressAndHeldAnnouncements(): void
    {
        $identity = Hilos::$db->identities[$this->identityId];
        $factor = Hilos::$db->secondFactors[$this->factorId];
        $reader = Hilos::$db;
        $this->freshContext();
        [, $give] = $this->ready();
        $this->give($give);
        $handover = $this->handover($give);
        $this->frames();
        Hilos::$db = $reader;
        $sources = new AdmissionSourceChanges();
        SourceChangeBus::subscribe($sources);
        $mirror = new AdmissionFailingMirror();
        SourceChangeBus::subscribe($mirror);
        $updates = $this->updates();
        self::assertSame(AccountMergeAdmissionResult::Rejected, $this->admit($handover));
        foreach (ObjectAccountMergeFences::BUSINESS_COLLECTIONS as $key) {
            foreach ($handover->copy->{$key} as $row) {
                self::assertSame(in_array($key, [HilosDbContext::identities, HilosDbContext::secondFactors], true),
                    isset(Hilos::$db->mountedObjectCollection($key)[$row->id]));
                self::assertFalse(isset(Hilos::$db->mountedObjectCollection(HilosDbContext::accountMergeFences)[$key . ':' . $row->id]));
            }
        }
        self::assertSame(self::LOSER, $identity->userId);
        self::assertSame(self::LOSER, $factor->userId);
        self::assertSame($identity, Hilos::$db->identities[$this->identityId]);
        self::assertSame($factor, Hilos::$db->secondFactors[$this->factorId]);
        self::assertSame([], $sources->changes);
        self::assertSame($updates, $this->updates());
        $mirror->fail = false;
        self::assertSame(AccountMergeAdmissionResult::Admitted, $this->admit($handover));
        self::assertSame(AccountMergeAdmissionResult::AlreadyCurrent, $this->admit($handover));
        self::assertSame([], $this->frames());
    }

    public function testHandoverChecksExactPreparationAndGiveFactsWithoutFalseConsumerEffects(): void
    {
        [$operation, $give] = $this->ready();
        $this->give($give);
        $handover = $this->handover($give);
        $this->frames();
        $source = Hilos::$db->accountOperationAssignments->byIdentity($operation->id,
            AccountMergeStage::Preparing->value, AccountMergeDuty::PrepareLoser->value);
        $target = Hilos::$db->accountOperationAssignments->byIdentity($operation->id,
            AccountMergeStage::Preparing->value, AccountMergeDuty::PrepareSurvivor->value);
        $sourceResult = Hilos::$db->accountOperationResults->byAssignment($source->id);
        $targetResult = Hilos::$db->accountOperationResults->byAssignment($target->id);
        $giveResult = Hilos::$db->accountOperationResults->byAssignment($give->id);
        $mutations = [
            ['hilos_account_operation_assignment', $source->id, 'user_id', 11],
            ['hilos_account_operation_assignment', $target->id, 'owner_index', '11'],
            ['hilos_account_operation_assignment', $give->id, 'assignment_key', AccountMergeDuty::PrepareLoser->value],
            ['hilos_account_operation_assignment', $give->id, 'stage', AccountMergeStage::Preparing->value],
            ['hilos_account_operation_result', $sourceResult->id, 'owner_agent_id', HilosAgentType::HILOS_USER . ':11'],
            ['hilos_account_operation_result', $targetResult->id, 'payload_json',
                AccountMergePreparationResult::refused(AccountMergeRefusal::Occupied)->toJson()],
            ['hilos_account_operation_result', $targetResult->id, 'payload_json', '{"prepare":"refused","refusal":"invalid"}'],
            ['hilos_account_operation_result', $giveResult->id, 'owner_agent_id', HilosAgentType::HILOS_USER . ':7'],
        ];
        $observer = new AdmissionObserver();
        $worker = new AdmissionWorker($observer);
        Hilos::$browser = new AdmissionBrowser();
        $sources = new AdmissionSourceChanges();
        SourceChangeBus::subscribe($sources);
        foreach ($mutations as [$table, $id, $column, $value]) {
            $original = Database::sql('SELECT `' . $column . '` FROM `' . $table . '` WHERE `id` = ?', [$id])->firstRow()[$column];
            Database::sqlRun('UPDATE `' . $table . '` SET `' . $column . '` = ? WHERE `id` = ?', [$value, $id]);
            $this->freshContext();
            $updates = $this->updates();
            self::assertSame(AccountMergeAdmissionResult::Rejected, $this->admit($handover), $table . '.' . $column);
            $worker->flushLocal();
            self::assertSame($updates, $this->updates());
            self::assertSame([], $sources->changes);
            self::assertSame(0, $observer->updates);
            self::assertSame(0, Hilos::$browser->events);
            self::assertSame([], $worker->client->sent);
            Database::sqlRun('UPDATE `' . $table . '` SET `' . $column . '` = ? WHERE `id` = ?', [$original, $id]);
        }
        $this->freshContext();
        self::assertSame(AccountMergeAdmissionResult::Admitted, $this->admit($handover));
    }

    public function testHandoverCannotRewindTheNextRealTransferOrReplaceItsCurrentOwner(): void
    {
        [$operation, $give] = $this->ready();
        $this->give($give);
        $old = $this->handover($give);
        $oldFrames = $this->frames();
        self::assertTrue($operation->actions->finish(AccountMergeStage::Go->value, AccountOperationOutcome::Completed));
        [, $next] = $this->ready(survivor: 11, loser: self::SURVIVOR);
        $this->asPerson(self::SURVIVOR, static fn () => AccountMergeGiving::run($next->id, self::SURVIVOR));
        $current = $this->asPerson(self::SURVIVOR, static fn () => AccountMergeGiving::handover($next->id, self::SURVIVOR));
        $this->frames();
        $this->freshContext();
        self::assertSame(AccountMergeAdmissionResult::Admitted, $this->asPerson(11,
            static fn () => AccountMergeAdmission::apply(11, $current)));
        $identity = Hilos::$db->identities[$this->identityId];
        $sources = new AdmissionSourceChanges();
        SourceChangeBus::subscribe($sources);
        self::assertSame(AccountMergeAdmissionResult::Rejected, $this->admit($old));
        foreach ($oldFrames as $frame) {
            self::assertFalse(DbSyncApplicator::applyUpdated($frame, false)->accepted);
        }
        self::assertSame(11, $identity->userId);
        self::assertSame($identity, Hilos::$db->identities[$this->identityId]);
        self::assertSame([], $sources->changes);
        self::assertSame([], $this->frames());
    }

    public function testHandoverFreshMetadataFailureAndNegativeMirrorCannotBecomeSuccessfulAdmission(): void
    {
        $reader = Hilos::$db;
        $key = HilosDbContext::identities . ':' . $this->identityId;
        self::assertNull($reader->accountMergeFences[$key] ?? null);
        $this->freshContext();
        [, $give] = $this->ready();
        $this->give($give);
        $handover = $this->handover($give);
        $this->frames();
        Hilos::$db = $reader;
        Database::sqlRun('RENAME TABLE `hilos_account_merge_fence` TO `hilos_account_merge_fence_unavailable`');
        try {
            self::assertSame(AccountMergeAdmissionResult::Rejected, $this->admit($handover));
            self::assertFalse(isset($reader->mountedObjectCollection(HilosDbContext::identities)[$this->identityId]));
        } finally {
            Database::sqlRun('RENAME TABLE `hilos_account_merge_fence_unavailable` TO `hilos_account_merge_fence`');
        }
        self::assertSame(AccountMergeAdmissionResult::Admitted, $this->admit($handover));
        self::assertSame(AccountMergeAdmissionResult::AlreadyCurrent, $this->admit($handover));
        $reader->reHydrateDbBackedCollections();
        self::assertSame(AccountMergeAdmissionResult::Admitted, $this->admit($handover));
        self::assertSame([], $this->frames());
    }

    public function testEmptyRealHandoverAdmitsWithoutInventingAReceiptOrBusinessEffect(): void
    {
        [, $give] = $this->ready(fate: null, loser: 11);
        $this->asPerson(11, static fn () => AccountMergeGiving::run($give->id, 11));
        $handover = $this->asPerson(11, static fn () => AccountMergeGiving::handover($give->id, 11));
        $this->frames();
        $this->freshContext();
        $sources = new AdmissionSourceChanges();
        SourceChangeBus::subscribe($sources);
        $writes = Database::sql("SHOW SESSION STATUS WHERE Variable_name IN ('Com_update', 'Com_insert', 'Com_delete')")->rows();
        self::assertSame(AccountMergeAdmissionResult::Admitted, $this->admit($handover));
        self::assertSame($writes,
            Database::sql("SHOW SESSION STATUS WHERE Variable_name IN ('Com_update', 'Com_insert', 'Com_delete')")->rows());
        self::assertSame([], $sources->changes);
        self::assertSame([], $this->frames());
    }

    public function testHandoverInvalidatesAnExistingDerivedSecretWithoutHydratingTheCopySecret(): void
    {
        $collection = Hilos::$db->mountedObjectCollection(HilosDbContext::secondFactors);
        $original = Hilos::$db->secondFactors[$this->factorId]->getObject();
        $mapped = [];
        foreach ($original::ENTITY_CLASS::_columns as $column) {
            $mapped[$column] = $original->valueForColumn($column);
        }
        $cached = AdmissionCachedFactor::fromEntity($original::ENTITY_CLASS::fromRow($mapped));
        SourceChangeBus::whileApplyingRemote(function () use ($collection, $cached): void {
            $collection[$this->factorId] = $cached;
        });
        $view = Hilos::$db->secondFactors[$this->factorId];
        self::assertSame('totp-secret-original', $cached->cachedSecret());
        $reader = Hilos::$db;
        $this->freshContext();
        $this->asPerson(self::LOSER, function (): void {
            Hilos::$db->secondFactors[$this->factorId]->getObject()->writeSecret('secret-at-transfer');
        });
        [, $give] = $this->ready();
        $this->give($give);
        $handover = $this->handover($give);
        $this->frames();
        Hilos::$db = $reader;
        self::assertSame(AccountMergeAdmissionResult::Admitted, $this->admit($handover));
        self::assertSame($view, Hilos::$db->secondFactors[$this->factorId]);
        self::assertSame($cached, $view->getObject());
        self::assertSame('secret-at-transfer', $cached->cachedSecret());
        self::assertArrayNotHasKey('secret', $cached->toArray());
        self::assertSame([], $this->frames());
    }

    /**
     * @param AccountOperationAssignment $give Committed source assignment
     * @return AccountMergeHandover Original source copy and stamps
     */
    private function handover(AccountOperationAssignment $give): AccountMergeHandover
    {
        return $this->asPerson(self::LOSER, static fn () => AccountMergeGiving::handover($give->id, self::LOSER));
    }

    /**
     * @param AccountMergeHandover $handover Batch held between actual owner handlers
     * @return AccountMergeAdmissionResult Receiving owner's verdict
     */
    private function admit(AccountMergeHandover $handover): AccountMergeAdmissionResult
    {
        return $this->asPerson(self::SURVIVOR, static fn () => AccountMergeAdmission::apply(self::SURVIVOR, $handover));
    }

    public function testOrdinaryRawProofAndDeleteWritersAdvanceAtomicallyWithoutDuplicateUpdates(): void
    {
        [, $give] = $this->ready();
        $this->give($give);
        $this->frames();
        $this->asPerson(self::SURVIVOR, function (): void {
            $factor = Hilos::$db->secondFactors[$this->factorId]->getObject();
            $key = HilosDbContext::secondFactors . ':' . $this->factorId;
            $initial = Hilos::$db->accountMergeFences->fresh($key)->revision;
            $updates = $this->updates();
            self::assertTrue($factor->acceptStep(24));
            self::assertSame($updates + 2, $this->updates(), 'One proof UPDATE and one fence UPDATE, without a second business UPDATE.');
            self::assertSame($initial + 1, Hilos::$db->accountMergeFences->fresh($key)->revision);
            $frame = $this->frames()[HilosDbContext::secondFactors];
            self::assertSame(24, $frame->admission->snapshot['last_used_step']);
            self::assertSame(HilosAgentType::HILOS_USER . ':7', $frame->writerAgentId);
            $updates = $this->updates();
            self::assertFalse($factor->acceptStep(24));
            self::assertSame($updates, $this->updates());
            self::assertSame([], $this->frames());

            $credential = Hilos::$db->passkeyCredentials[$this->passkeyId]->getObject();
            $updates = $this->updates();
            $credential->recordUse(18);
            self::assertSame($updates + 4, $this->updates());
            self::assertFalse($credential->hasChanges());
            $credential->updateSignCount(18);
            $this->frames();

            $identity = Hilos::$db->identities[$this->identityId]->getObject();
            $identity->setPasswordHash('private-password-value');
            $identityFrame = $this->frames()[HilosDbContext::identities];
            self::assertStringNotContainsString('private-password-value', $identityFrame->toJson());
            $code = Hilos::$db->secondFactorBackupCodes[$this->codeId]->getObject();
            $code->writeCode('new-code-1');
            $unmapped = $this->frames()[HilosDbContext::secondFactorBackupCodes];
            self::assertSame([], $unmapped->row);
            self::assertNotNull($unmapped->admission->snapshot);
            self::assertStringNotContainsString('new-code-1', $unmapped->toJson());
            self::assertTrue($code->spend());
            $this->frames();
            $updates = $this->updates();
            self::assertFalse($code->spend());
            self::assertSame($updates, $this->updates());
            self::assertSame([], $this->frames());

            $factor->delete();
            $deleted = $this->frames()[HilosDbContext::secondFactors];
            self::assertInstanceOf(DbSyncDeletedSignalData::class, $deleted);
            self::assertNull($deleted->admission->snapshot);
            self::assertTrue(Hilos::$db->accountMergeFences->fresh($key)->deleted);
        });
    }

    public function testRollbackRestoresBusinessFenceAppliedProgressAndHeldAnnouncements(): void
    {
        [, $give] = $this->ready();
        $this->give($give);
        $this->frames();
        $this->asPerson(self::SURVIVOR, function (): void {
            $identity = Hilos::$db->identities[$this->identityId]->getObject();
            $key = HilosDbContext::identities . ':' . $this->identityId;
            $fence = Hilos::$db->accountMergeFences[$key]->getObject();
            $before = $identity->toArray();
            $revision = $fence->revision;
            $progress = $fence->appliedRevision();
            try {
                Database::transaction(DatabaseNames::PRIMARY, function () use ($identity, $fence, $revision): void {
                    $identity->providerName = 'temporary';
                    $identity->sync();
                    self::assertSame($revision + 1, $fence->revision);
                    self::assertSame($revision + 1, $fence->appliedRevision());
                    $identity->setPasswordHash('rollback-secret');
                    throw new RuntimeException('rollback ordinary write');
                });
            } catch (RuntimeException $failure) {
                self::assertSame('rollback ordinary write', $failure->getMessage());
            }
            self::assertSame($before, $identity->toArray());
            self::assertSame($revision, $fence->revision);
            self::assertSame($progress, $fence->appliedRevision());
            self::assertSame($revision, Hilos::$db->accountMergeFences->fresh($key)->revision);
            self::assertSame([], $this->frames());
        });
    }

    public function testWorkerAdmitsCurrentUncachedFactButRejectsStaleDuplicateMissingStampAndMetadataFailure(): void
    {
        [$operation, $give] = $this->ready();
        $this->give($give);
        $old = $this->frames()[HilosDbContext::identities];
        $this->asPerson(self::SURVIVOR, function (): void {
            $identity = Hilos::$db->identities[$this->identityId]->getObject();
            $identity->providerName = 'current';
            $identity->sync();
        });
        $current = $this->frames()[HilosDbContext::identities];
        $this->freshContext();
        $observer = new AdmissionObserver();
        $worker = new AdmissionWorker($observer);
        Hilos::$browser = new AdmissionBrowser();
        $sources = new AdmissionSourceChanges();
        SourceChangeBus::subscribe($sources);
        // Worker construction installs its router; the remote emitter must survive unchanged.
        $worker->deliver($old);
        $worker->deliver(new DbSyncUpdatedSignalData($current->collectionKey, $current->idString, $current->row,
            writerAgentId: 'foreign-writer', admission: $current->admission));
        $stamp = $current->admission;
        foreach ([
            new DbSyncAdmissionData($stamp->operationId + 1, $stamp->assignmentId, $stamp->ownerUserId, $stamp->revision, $stamp->snapshot),
            new DbSyncAdmissionData($stamp->operationId, $stamp->assignmentId + 1, $stamp->ownerUserId, $stamp->revision, $stamp->snapshot),
            new DbSyncAdmissionData($stamp->operationId, $stamp->assignmentId, $stamp->ownerUserId, $stamp->revision + 1, $stamp->snapshot),
        ] as $altered) {
            $worker->deliver(new DbSyncUpdatedSignalData($current->collectionKey, $current->idString, $current->row,
                writerAgentId: $current->writerAgentId, admission: $altered));
        }
        $worker->deliver(new DbSyncUpdatedSignalData($current->collectionKey, $current->idString, $current->row));
        self::assertSame(0, $observer->updates);
        self::assertSame(0, Hilos::$browser->events);
        self::assertSame([], $sources->changes);
        $updates = $this->updates();
        $worker->deliver($current);
        self::assertSame(1, $observer->updates);
        self::assertSame(1, Hilos::$browser->events);
        self::assertFalse(isset(Hilos::$db->mountedObjectCollection(HilosDbContext::identities)[$current->idString]));
        $worker->deliver($current);
        self::assertSame(1, $observer->updates);
        self::assertSame(1, Hilos::$browser->events);
        self::assertSame($updates, $this->updates());
        Database::sqlRun('RENAME TABLE `hilos_account_merge_fence` TO `hilos_account_merge_fence_unavailable`');
        try {
            $worker->deliver($current);
            self::assertSame(1, $observer->updates);
            self::assertSame(1, Hilos::$browser->events);
        } finally {
            Database::sqlRun('RENAME TABLE `hilos_account_merge_fence_unavailable` TO `hilos_account_merge_fence`');
        }
        self::assertSame([], $sources->changes, 'Neither rejected facts nor legitimate uncached facts mutate held business sources.');
    }

    public function testFullSnapshotRebuildsBothRealUserCardsAfterDelayedOwnershipTransfer(): void
    {
        $this->assertTransferRebuildsUserCards(false);
    }

    public function testFullSnapshotReachesNarrowOwnerTriggerAfterLabelOnlyFrame(): void
    {
        $this->assertTransferRebuildsUserCards(true);
    }

    /** @param bool $narrowTrigger Whether the factor row listens only for its ownership field */
    private function assertTransferRebuildsUserCards(bool $narrowTrigger): void
    {
        $this->asPerson(self::SURVIVOR, fn () => Hilos::$db->secondFactors[$this->targetFactorId]->getObject()->delete());
        $this->frames();
        $reader = Hilos::$db;
        $view = $reader->secondFactors[$this->factorId];
        $observer = new AdmissionObserver();
        $worker = new AdmissionWorker($observer);
        $browser = new AdmissionCardBrowser($narrowTrigger);
        Hilos::$browser = $browser;
        foreach ([self::LOSER, self::SURVIVOR] as $userId) {
            $params = new PageRouteParams([HilosPageRouteParams::HILOS_USER_USER_ID => $userId]);
            Hilos::$sr->subscribeToPage(AdmissionCardBrowser::PAGE,
                new WebSocketPageSubscribeSignalDTO('card-' . $userId, AdmissionCardBrowser::PAGE, $params->toArray()));
            $snapshot = $browser->buildSubscribeSnapshot(AdmissionCardBrowser::PAGE, 'card-' . $userId, $params);
            $row = $snapshot->tables[HilosUserDetailBrowserTable::TABLE][PagePayload::rows][0];
            self::assertSame((string)$userId, (string)$row[PagePayload::rowKey]);
            self::assertSame($userId === self::LOSER,
                $row[PagePayload::slots][HilosDbContext::secondFactors][HilosMergeCandidatesTable::FIELD_HAS_SECOND_FACTOR]);
        }
        $readerRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->freshContext();
        [, $give] = $this->ready();
        $this->give($give);
        $transfer = $this->frames()[HilosDbContext::secondFactors];
        $this->asPerson(self::SURVIVOR, function (): void {
            $factor = Hilos::$db->secondFactors[$this->factorId]->getObject();
            $factor->label = 'Renamed after transfer';
            $factor->sync();
        });
        $current = $this->frames()[HilosDbContext::secondFactors];
        self::assertSame(['label' => 'Renamed after transfer'], $current->row);
        self::assertSame(['label' => 'Authenticator'], $current->previous);
        Hilos::$db = $reader;
        Hilos::$sr = $readerRouter;
        $sources = new AdmissionSourceChanges();
        SourceChangeBus::subscribe($sources);
        $worker->deliver($transfer);
        self::assertSame(self::LOSER, $view->userId);
        self::assertSame([], $sources->changes);
        self::assertSame([], $browser->flushToSignalRouter());
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        $worker->deliver($current);
        self::assertSame(self::SURVIVOR, $view->userId);
        self::assertSame('Renamed after transfer', $view->label);
        self::assertCount(1, $sources->changes);
        self::assertSame([], $browser->flushToSignalRouter());
        $rows = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
            self::assertSame(AdmissionCardBrowser::PAGE, $signal->data->data->pageKey);
            $rows[$signal->data->targetAcceptKey] = $signal->data->data->payload
                ->tables[HilosUserDetailBrowserTable::TABLE][PagePayload::rows] ?? [];
        }
        ksort($rows);
        self::assertSame(['card-7', 'card-9'], array_keys($rows));
        foreach ([self::SURVIVOR => true, self::LOSER => false] as $userId => $hasFactor) {
            self::assertCount(1, $rows['card-' . $userId]);
            $row = $rows['card-' . $userId][0];
            self::assertSame((string)$userId, (string)$row[PagePayload::rowKey]);
            self::assertSame($userId, $row[PagePayload::slots][HilosDbContext::secondFactors][ObjectSecondFactor::userId]);
            self::assertSame($hasFactor,
                $row[PagePayload::slots][HilosDbContext::secondFactors][HilosMergeCandidatesTable::FIELD_HAS_SECOND_FACTOR]);
        }
        $worker->deliver($current);
        self::assertSame([], $browser->flushToSignalRouter());
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        self::assertSame(1, $observer->updates);
        self::assertCount(1, $sources->changes);
    }

    public function testFullSnapshotRepairsSkippedDiffWithoutRereadAndDeleteCannotResurrect(): void
    {
        [, $give] = $this->ready();
        $this->give($give);
        $birth = $this->frames()[HilosDbContext::identities];
        $this->asPerson(self::SURVIVOR, function (): void {
            $identity = Hilos::$db->identities[$this->identityId]->getObject();
            $identity->providerName = 'missed';
            $identity->sync();
        });
        $missed = $this->frames()[HilosDbContext::identities];
        $this->asPerson(self::SURVIVOR, function (): void {
            $identity = Hilos::$db->identities[$this->identityId]->getObject();
            $identity->verified = false;
            $identity->sync();
        });
        $current = $this->frames()[HilosDbContext::identities];
        $this->freshContext();
        $collection = Hilos::$db->mountedObjectCollection(HilosDbContext::identities);
        $objectClass = $collection::OBJECT_CLASS;
        $entityClass = $objectClass::ENTITY_CLASS;
        $object = $objectClass::fromEntity($entityClass::fromRow($birth->admission->snapshot));
        SourceChangeBus::whileApplyingRemote(static function () use ($collection, $birth, $object): void {
            $collection[$birth->idString] = $object;
        });
        $view = Hilos::$db->identities[$birth->idString];
        $sources = new AdmissionSourceChanges();
        SourceChangeBus::subscribe($sources);
        $selects = (int)Database::sql("SHOW SESSION STATUS LIKE 'Com_select'")->firstRow()['Value'];
        $applied = DbSyncApplicator::applyUpdated($current, false);
        self::assertTrue($applied->accepted);
        self::assertSame($selects + 1, (int)Database::sql("SHOW SESSION STATUS LIKE 'Com_select'")->firstRow()['Value']);
        self::assertSame($current->admission->snapshot, $applied->sourceChange->row);
        self::assertSame($birth->admission->snapshot, $applied->sourceChange->previous);
        self::assertSame($current->origin, $applied->sourceChange->origin);
        self::assertSame($current->originRequestId, $applied->sourceChange->originRequestId);
        self::assertSame('missed', $object->providerName);
        self::assertFalse($object->verified);
        self::assertSame($object, $view->getObject());
        self::assertCount(1, $sources->changes);
        self::assertFalse(DbSyncApplicator::applyUpdated($missed, false)->accepted);
        self::assertCount(1, $sources->changes);
        self::assertSame('missed', $view->providerName);
        $this->asPerson(self::SURVIVOR, function () use ($object): void {
            Hilos::$db->passkeyCredentials[$this->passkeyId]->getObject()->delete();
            $object->delete();
        });
        $delete = $this->frames()[HilosDbContext::identities];
        unset($collection[$birth->idString]);
        $this->freshContext();
        self::assertTrue(DbSyncApplicator::applyDeleted($delete, false)->accepted);
        self::assertFalse(DbSyncApplicator::applyDeleted($delete, false)->accepted);
        self::assertFalse(DbSyncApplicator::applyCreated(new DbSyncCreatedSignalData($birth->collectionKey, $birth->idString,
            $birth->admission->snapshot, writerAgentId: $birth->writerAgentId, admission: $birth->admission), false)->accepted);
        self::assertFalse(isset(Hilos::$db->mountedObjectCollection($birth->collectionKey)[$birth->idString]));
    }

    /**
     * @param ?SecondFactorFate $fate Protection outcome for the real preparation pair
     * @param ?PasswordFate $password Password outcome from the immutable choice
     * @param int $survivor Target account of this operation
     * @param int $loser Source account of this operation
     * @return array{AccountOperation, AccountOperationAssignment} Prepared operation and give address
     */
    private function ready(
        ?SecondFactorFate $fate = SecondFactorFate::BOTH, ?PasswordFate $password = null,
        int $survivor = self::SURVIVOR, int $loser = self::LOSER,
    ): array
    {
        $operation = AccountMergeOpening::open($survivor, $loser, new AccountMergeChoice($password, $fate, null, null));
        foreach ([[$survivor, AccountMergeDuty::PrepareSurvivor], [$loser, AccountMergeDuty::PrepareLoser]] as [$user, $duty]) {
            $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Preparing->value, $duty->value);
            $result = $this->asPerson($user, static fn (AbstractUserAgent $agent) => $agent->prepareForMerge($assignment->id));
            self::assertTrue(AccountMergePreparationResult::of($result->payload)->isReserved());
        }
        self::assertTrue($operation->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Go->value));
        $give = Hilos::$db->accountOperationAssignments->actions->record($operation->id, AccountMergeStage::Go->value,
            AccountMergeDuty::Give->value, new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$loser),
            $loser, AccountOperationAssignmentPayload::fromJson('{}'));

        return [$operation, $give];
    }

    /**
     * @param AccountOperationAssignment $give Assignment delivered to its actual person owner
     * @return AccountOperationResult Durable source fact
     */
    private function give(AccountOperationAssignment $give): AccountOperationResult
    {
        return $this->asPerson(self::LOSER, static fn (AbstractUserAgent $agent) => $agent->giveForMerge($give->id));
    }

    public function testASecondRealTransferRejectsOldFactsAndClosedOperationStillAllowsCurrentOwnerEdits(): void
    {
        [$operation, $give] = $this->ready();
        $this->give($give);
        $old = $this->frames()[HilosDbContext::identities];
        self::assertTrue($operation->actions->finish(AccountMergeStage::Go->value, AccountOperationOutcome::Completed));
        $this->asPerson(self::SURVIVOR, function (): void {
            $row = Hilos::$db->identities[$this->identityId]->getObject();
            $row->providerName = 'after close';
            $row->sync();
        });
        $closed = $this->frames()[HilosDbContext::identities];
        $this->freshContext();
        self::assertTrue(DbSyncApplicator::applyUpdated($closed, false)->accepted);
        self::assertFalse(DbSyncApplicator::applyUpdated($old, false)->accepted);
        [, $next] = $this->ready(survivor: 11, loser: self::SURVIVOR);
        $this->asPerson(self::SURVIVOR, static fn () => AccountMergeGiving::run($next->id, self::SURVIVOR));
        $current = $this->frames()[HilosDbContext::identities];
        self::assertSame(11, $current->admission->ownerUserId);
        Database::sqlRun('DELETE FROM `hilos_account_merge_copy`');
        $this->freshContext();
        self::assertFalse(DbSyncApplicator::applyUpdated($old, false)->accepted);
        self::assertFalse(DbSyncApplicator::applyUpdated($closed, false)->accepted);
        self::assertTrue(DbSyncApplicator::applyUpdated($current, false)->accepted);
    }

    public function testSnapshotInvalidatesDerivedSecretEvenWhenTheUnmappedFactWasSkipped(): void
    {
        [, $give] = $this->ready();
        $this->give($give);
        $birth = $this->frames()[HilosDbContext::secondFactors];
        $writer = Hilos::$db;
        $this->freshContext();
        $reader = Hilos::$db;
        $collection = Hilos::$db->mountedObjectCollection(HilosDbContext::secondFactors);
        $entityClass = ObjectSecondFactor::ENTITY_CLASS;
        $object = AdmissionCachedFactor::fromEntity($entityClass::fromRow($birth->admission->snapshot));
        SourceChangeBus::whileApplyingRemote(static function () use ($collection, $birth, $object): void {
            $collection[$birth->idString] = $object;
        });
        self::assertSame('totp-secret-original', $object->cachedSecret());
        Hilos::$db = $writer;
        $this->asPerson(self::SURVIVOR, function (): void {
            $factor = Hilos::$db->secondFactors[$this->factorId]->getObject();
            $factor->writeSecret('rotated-secret');
        });
        $unmapped = $this->frames()[HilosDbContext::secondFactors];
        self::assertSame([], $unmapped->row);
        $this->asPerson(self::SURVIVOR, function (): void {
            $factor = Hilos::$db->secondFactors[$this->factorId]->getObject();
            $factor->label = 'renamed';
            $factor->sync();
        });
        $current = $this->frames()[HilosDbContext::secondFactors];
        Hilos::$db = $reader;
        self::assertTrue(DbSyncApplicator::applyUpdated($current, false)->accepted);
        self::assertFalse(DbSyncApplicator::applyUpdated($unmapped, false)->accepted);
        self::assertSame('rotated-secret', $object->cachedSecret());
        self::assertSame('renamed', $object->label);
    }

    public function testSuccessfulLocalWriteDispatchesOnceWithoutIncomingAdmissionAndItsEchoHasNoEffects(): void
    {
        [, $give] = $this->ready();
        $this->give($give);
        $this->frames();
        $observer = new AdmissionObserver();
        $worker = new AdmissionWorker($observer);
        Hilos::$browser = new AdmissionBrowser();
        $updates = $this->updates();
        $this->asPerson(self::SURVIVOR, function (): void {
            $identity = Hilos::$db->identities[$this->identityId]->getObject();
            $identity->providerName = 'local';
            $identity->sync();
        });
        $worker->flushLocal();
        self::assertSame($updates + 2, $this->updates());
        self::assertSame(2, $observer->updates, 'One fence and one business callback use the successful local path.');
        self::assertSame(2, Hilos::$browser->events);
        $business = array_values(array_filter($worker->client->sent,
            static fn (WorkerDTO $frame): bool => $frame instanceof WorkerDbSyncUpdatedMessageDTO && $frame->signalData->admission !== null));
        self::assertCount(1, $business);
        $worker->deliver($business[0]->signalData);
        self::assertSame(2, $observer->updates);
        self::assertSame(2, Hilos::$browser->events);
        self::assertSame($updates + 2, $this->updates());
    }

    public function testTargetedWriterCannotAnnounceUnwrittenDraftFieldsAndNoOpDoesNotAdvance(): void
    {
        [, $give] = $this->ready();
        $this->give($give);
        $this->frames();
        $this->asPerson(self::SURVIVOR, function (): void {
            $identity = Hilos::$db->identities[$this->identityId]->getObject();
            $key = HilosDbContext::identities . ':' . $this->identityId;
            $revision = Hilos::$db->accountMergeFences->fresh($key)->revision;
            $updates = $this->updates();
            $identity->sync();
            self::assertSame($revision, Hilos::$db->accountMergeFences->fresh($key)->revision);
            self::assertSame($updates, $this->updates());
            self::assertSame([], $this->frames());
            $identity->providerName = 'unsaved draft';
            try {
                $identity->setPasswordHash('not-written');
                self::fail('A targeted write cannot certify unrelated unsaved columns.');
            } catch (DatabaseException) {
                self::assertSame($updates, $this->updates());
                self::assertSame($revision, Hilos::$db->accountMergeFences->fresh($key)->revision);
                self::assertSame([], $this->frames());
            }
            $identity->revert();
        });
    }

    public function testUnprotectedOrdinaryWriteKeepsLegacyAdmissionAndCannotInventAFence(): void
    {
        $this->asPerson(self::LOSER, function (): void {
            Hilos::$db->identities[$this->identityId]->getObject()->setPasswordHash('new-hash');
        });
        self::assertNull(Hilos::$sr->getNextQueuedSignal(), 'An unfenced hash write retains its legacy silence.');
        $this->asPerson(self::LOSER, function (): void {
            $identity = Hilos::$db->identities[$this->identityId]->getObject();
            $identity->providerName = 'ordinary unfenced edit';
            $identity->sync();
        });
        $legacy = null;
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof DbSyncUpdatedSignalData && $signal->data->collectionKey === HilosDbContext::identities) {
                $legacy = $signal->data;
            }
        }
        self::assertNotNull($legacy);
        self::assertNull($legacy->admission);
        $key = HilosDbContext::identities . ':' . $this->identityId;
        self::assertNull(Hilos::$db->accountMergeFences->fresh($key));
        $object = Hilos::$db->identities[$this->identityId]->getObject();
        $snapshot = [];
        foreach ($object::ENTITY_CLASS::_columns as $column) {
            $snapshot[$column] = $object->valueForColumn($column);
        }
        $this->freshContext();
        self::assertTrue(DbSyncApplicator::applyUpdated($legacy, false)->accepted);
        $stamped = new DbSyncUpdatedSignalData($legacy->collectionKey, $legacy->idString, $legacy->row,
            writerAgentId: HilosAgentType::HILOS_USER . ':9', admission: new DbSyncAdmissionData(999, 998, self::LOSER, 1, $snapshot));
        self::assertFalse(DbSyncApplicator::applyUpdated($stamped, false)->accepted);
        self::assertNull(Hilos::$db->accountMergeFences->fresh($key));
    }

    public function testMemoryOnlySnapshotAndProgressRollbackPreserveTheExistingViewWrapper(): void
    {
        [, $give] = $this->ready();
        $this->give($give);
        $birth = $this->frames()[HilosDbContext::identities];
        $this->asPerson(self::SURVIVOR, function (): void {
            $identity = Hilos::$db->identities[$this->identityId]->getObject();
            $identity->providerName = 'committed elsewhere';
            $identity->sync();
        });
        $current = $this->frames()[HilosDbContext::identities];
        $this->freshContext();
        $collection = Hilos::$db->mountedObjectCollection($birth->collectionKey);
        $objectClass = $collection::OBJECT_CLASS;
        $entityClass = $objectClass::ENTITY_CLASS;
        $object = $objectClass::fromEntity($entityClass::fromRow($birth->admission->snapshot));
        SourceChangeBus::whileApplyingRemote(static function () use ($collection, $birth, $object): void {
            $collection[$birth->idString] = $object;
        });
        $view = Hilos::$db->identities[$birth->idString];
        $updates = $this->updates();
        Database::transactionStart();
        self::assertTrue(DbSyncApplicator::applyUpdated($current, false)->accepted);
        self::assertSame('committed elsewhere', $view->providerName);
        Database::transactionRollback();
        self::assertSame($object, $view->getObject());
        self::assertNull($view->providerName);
        self::assertSame($updates, $this->updates());
        self::assertTrue(DbSyncApplicator::applyUpdated($current, false)->accepted);
        self::assertSame('committed elsewhere', $view->providerName);
        self::assertFalse(DbSyncApplicator::applyUpdated($current, false)->accepted);
        self::assertSame([], $this->frames());
    }

    public function testRevokedFenceGrantRefusesBeforeBusinessSqlEvenIfTheCallerCommitsItsTransaction(): void
    {
        [, $give] = $this->ready();
        $this->give($give);
        $this->frames();
        $agent = $this->personAgent(self::SURVIVOR);
        $this->asPerson(self::SURVIVOR, function () use ($agent): void {
            $factor = Hilos::$db->secondFactors[$this->factorId]->getObject();
            $key = HilosDbContext::secondFactors . ':' . $this->factorId;
            $revision = Hilos::$db->accountMergeFences->fresh($key)->revision;
            $updates = $this->updates();
            TruthSourceRegistry::unregister(HilosDbContext::accountMergeFences, $agent->getId());
            try {
                Database::transactionStart();
                try {
                    $factor->acceptStep(24);
                    self::fail('A revoked metadata grant must refuse before physical business mutation.');
                } catch (WriteNotAllowedException) {
                    self::assertSame($updates, $this->updates());
                }
                Database::transactionCommit();
            } finally {
                Database::transactionRollbackSilent();
                OwnershipDeclaration::claimAll($agent);
            }
            self::assertSame(23, $factor->lastUsedStep);
            self::assertSame($revision, Hilos::$db->accountMergeFences->fresh($key)->revision);
            self::assertSame([], $this->frames());
        });
    }

    public function testProtectedCachelessUpdateAndDeleteKeepTheirSourceRowsWithoutRetainingBusinessObjects(): void
    {
        [, $give] = $this->ready();
        $this->give($give);
        $frame = $this->frames()[HilosDbContext::secondFactors];
        $writer = Hilos::$db;
        $reader = new AdmissionCachelessContext();
        $reader->configure();
        Hilos::$db = $reader;
        $sources = new AdmissionSourceChanges();
        SourceChangeBus::subscribe($sources);
        $result = DbSyncApplicator::applyUpdated($frame, false);
        self::assertTrue($result->accepted);
        self::assertFalse($result->memoryChanged);
        self::assertCount(1, $sources->changes);
        self::assertSame($frame->admission->snapshot, $sources->changes[0]->row);
        self::assertFalse(isset($reader->mountedObjectCollection($frame->collectionKey)[$frame->idString]));
        Hilos::$db = $writer;
        $this->asPerson(self::SURVIVOR, function (): void {
            Hilos::$db->secondFactors[$this->factorId]->getObject()->delete();
        });
        $delete = $this->frames()[HilosDbContext::secondFactors];
        Hilos::$db = $reader;
        $sources->changes = [];
        $result = DbSyncApplicator::applyDeleted($delete, false);
        self::assertTrue($result->accepted);
        self::assertFalse($result->memoryChanged);
        self::assertCount(1, $sources->changes);
        self::assertSame($delete->row, $sources->changes[0]->row);
        self::assertFalse(DbSyncApplicator::applyDeleted($delete, false)->accepted);
        self::assertCount(1, $sources->changes);
    }

    /**
     * @param int $userId Owner of the new sign-in row
     * @param string $type Method kind under test
     * @param bool $verified Whether the address was verified
     * @param ?string $identifier Optional collision address
     * @return int Persisted identity id
     */
    private function identity(int $userId, string $type = IdentityType::MAGIC_LINK, bool $verified = true, ?string $identifier = null): int
    {
        Database::sql('INSERT INTO `hilos_identity` (`user_id`, `type`, `identifier`, `secret`, `verified`, '
            . '`password_changed_at`, `provider`, `provider_name`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $type, $identifier ?? $type . '-' . $userId . '@example.test',
                $type === IdentityType::PASSWORD ? 'password-hash-original' : null, (int)$verified,
                $type === IdentityType::PASSWORD ? 0 : null, null, null, -1001, 0]);

        return Database::lastInsertId();
    }

    /**
     * @param int $userId Factor owner
     * @param bool $confirmed Whether enrollment finished
     * @return int Persisted factor id
     */
    private function factor(int $userId, bool $confirmed = true): int
    {
        Database::sql('INSERT INTO `hilos_second_factor` (`user_id`, `label`, `secret`, `last_used_step`, `confirmed_at`, '
            . '`last_used_at`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, 'Authenticator', 'totp-secret-original', 23, $confirmed ? -1 : null, 0, -1001]);

        return Database::lastInsertId();
    }

    /**
     * @param int $userId Backup code owner
     * @param ?int $usedAt Nullable original spend moment
     * @return int Persisted backup-code id
     */
    private function code(int $userId, ?int $usedAt = null): int
    {
        Database::sql('INSERT INTO `hilos_second_factor_backup_code` (`user_id`, `code`, `used_at`, `created_at`) VALUES (?, ?, ?, ?)',
            [$userId, 'backup-code', $usedAt, -1]);

        return Database::lastInsertId();
    }

    /** @param bool $down Whether to drop dependent tables in reverse order */
    private function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            // external-boundary: up stub filenames carry no direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
        }
    }

    /** Starts a new ORM generation without touching durable rows. */
    private function freshContext(): void
    {
        Hilos::$db = new AdmissionContext();
        Hilos::$db->configure();
    }

    /** @return array<string, DbSyncSignalDataInterface> Last stamped business fact for each collection */
    private function frames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof DbSyncSignalDataInterface && $signal->data->admission !== null) {
                $frames[$signal->data->collectionKey] = $signal->data;
            }
        }

        return $frames;
    }

    /** @return int Physical UPDATE work in this MariaDB session */
    private function updates(): int
    {
        return (int)Database::sql("SHOW SESSION STATUS LIKE 'Handler_update'")->firstRow()['Value'];
    }
}

final class AdmissionCoordinator extends AbstractSessionsLibraryAgent
{
}

final class AdmissionContext extends HilosDbContext
{
}

/** Records actual worker deliveries, without claiming a business writer. */
final class AdmissionObserver extends AbstractAgent
{
    public const string AGENT_TYPE = 'admission-observer';

    public int $updates = 0;

    /** No owned resources in this recording agent. */
    public function onStop(): void
    {
    }

    /**
     * @param DbSyncUpdatedSignalData $data Delivered update
     * @param string $source Actual source kind
     * @param string $name Actual signal name
     */
    public function onSignalDbSyncUpdated(DbSyncUpdatedSignalData $data, string $source, string $name): void
    {
        ++$this->updates;
    }
}

/** The normal worker consumer path, entered without a process or socket fixture. */
final class AdmissionWorker extends WorkerManager
{
    public readonly AdmissionDaemonClient $client;
    /** @param AdmissionObserver $observer Agent receiving accepted facts */
    public function __construct(AdmissionObserver $observer)
    {
        parent::__construct(1);
        $this->client = new AdmissionDaemonClient();
        $this->daemonClient = $this->client;
        $this->agentManager->addAgent($observer->getId(), $observer);
    }

    /** @return SignalRouter Router for the actual worker constructor */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManager Agent registry holding the recording observer */
    protected function createAgentManager(): AgentManager
    {
        return new AdmissionAgentManager();
    }

    /** Drains successful local facts through the actual queue and local consumers. */
    public function flushLocal(): void
    {
        Closure::bind(function (): void {
            $this->dispatchQueuedSignalsToDaemon();
        }, $this, WorkerManager::class)();
    }

    /** @param DbSyncUpdatedSignalData $frame Real transported fact */
    public function deliver(DbSyncUpdatedSignalData $frame): void
    {
        Closure::bind(function () use ($frame): void {
            $this->handleDbSyncMessage(new WorkerDbSyncUpdatedMessageDTO($frame, 'remote-node'));
        }, $this, WorkerManager::class)();
    }
}

final class AdmissionBrowser extends BrowserContext
{
    public int $events = 0;

    /** @param SourceChange $change Fact the worker admitted */
    public function record(SourceChange $change): void
    {
        ++$this->events;
    }
}

/** Uses the production user-card anchor and second-factor row with the actual BrowserContext flush. */
final class AdmissionCardBrowser extends BrowserContext
{
    public const string PAGE = 'admission-card';

    /** @param bool $narrowTrigger Restrict the existing factor source to its ownership trigger */
    public function __construct(private readonly bool $narrowTrigger)
    {
        parent::__construct();
    }

    /**
     * @param string $page Subscribed fixture page
     * @return ?BrowserPageConfig Page metadata for real worker-local fan-out
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        return BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => self::PAGE]);
    }

    /**
     * @param string $page Subscribed fixture page
     * @return BrowserPageBindings Production card route binding
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return BrowserPageBindings::fromArray([HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING]);
    }

    /**
     * @param string $browserKey Requested browser table
     * @return ?BrowserSourceConfig Production anchor and factor declarations with an optional narrow trigger
     */
    protected function resolveBrowserOnlyConfig(string $browserKey): ?BrowserSourceConfig
    {
        if ($browserKey !== HilosUserDetailBrowserTable::TABLE) {
            return null;
        }
        $config = HilosUserDetailBrowserTable::BROWSER;
        $rows = array_values(array_filter($config[BrowserTableConfigKey::ROWS], static fn (array $row): bool =>
            in_array($row[BrowserTableFieldKey::SOURCE][BrowserSourceKey::KEY], [HilosDbContext::users, HilosDbContext::secondFactors], true)));
        if ($this->narrowTrigger) {
            $rows[1][BrowserTableFieldKey::TRIGGERS] = [ObjectSecondFactor::userId];
        }
        return BrowserSourceConfig::fromArray([
            BrowserTableConfigKey::PARAMS => $config[BrowserTableConfigKey::PARAMS],
            BrowserTableConfigKey::SOURCES => array_column($rows, BrowserTableFieldKey::SOURCE),
            BrowserTableConfigKey::ROWS => $rows,
        ]);
    }
}

final class AdmissionAgentManager extends AgentManager
{
    /**
     * @param string $agentType Requested fixture type
     * @param ?string $agentIndex Requested fixture index
     * @return AgentInterface Recording observer
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new AdmissionObserver();
    }
}

/** A real factor Object with one local value derived from its unmapped secret. */
class AdmissionCachedFactor extends ObjectSecondFactor
{
    private ?string $secretCache = null;

    /** @return ?string The secret read once until an accepted snapshot invalidates it */
    public function cachedSecret(): ?string
    {
        return $this->secretCache ??= $this->readSecret();
    }

    /** Drops this reader's derived value before source subscribers receive the snapshot. */
    public function applyDbSyncUnmappedUpdate(): void
    {
        $this->secretCache = null;
    }
}

/** The existing worker transport port, recording frames after actual local dispatch. */
final class AdmissionDaemonClient extends WorkerDaemonClient
{
    /** @var list<WorkerDTO> Sent transport frames */
    public array $sent = [];

    /** @return bool The test transport is ready without opening a socket */
    public function isConnected(): bool
    {
        return true;
    }

    /** @param WorkerDTO|array<string, mixed> $data Frame dispatched by the actual worker */
    public function send(WorkerDTO|array $data): void
    {
        $this->sent[] = $data;
    }
}

/** Records business source effects independently of worker callbacks and browser events. */
final class AdmissionSourceChanges implements SourceChangeSubscriberInterface
{
    /** @var list<SourceChange> Observed business facts */
    public array $changes = [];

    /**
     * @param SourceChange $change Observed fact
     * @param SourceChangeProvenance $provenance Actual bus provenance
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if (in_array($change->sourceKey, ObjectAccountMergeFences::BUSINESS_COLLECTIONS, true)) {
            $this->changes[] = $change;
        }
    }
}

/** A real cacheless Object collection on the receiving port; all durable metadata stays mounted normally. */
final class AdmissionCachelessContext extends HilosDbContext
{
    private ?Objects $factors = null;

    /**
     * @param string $name Collection requested by the applicator
     * @return ?Objects Actual cacheless factor store or another normally mounted collection
     */
    public function mountedObjectCollection(string $name): ?Objects
    {
        if ($name === HilosDbContext::secondFactors) {
            return $this->factors ??= AdmissionCachelessFactors::initDB(Objects::LAZY_STRATEGY_KEY);
        }

        return parent::mountedObjectCollection($name);
    }
}

class AdmissionCachelessFactors extends ObjectSecondFactors
{
    public const bool CACHE_ROWS = false;
}

/** Refuses hidden business loads while keeping the production metadata collections. */
final class AdmissionReadlessContext extends HilosDbContext
{
    /** @var array<string, Objects> Per-context stores used only by the memory admission port */
    private array $stores = [];

    /**
     * @param string $name Requested mounted collection
     * @return ?Objects Readless business store or the ordinary metadata mount
     */
    public function mountedObjectCollection(string $name): ?Objects
    {
        $class = match ($name) {
            self::identities => AdmissionReadlessIdentities::class,
            self::passkeyCredentials => AdmissionReadlessPasskeys::class,
            self::secondFactors => AdmissionReadlessFactors::class,
            self::secondFactorBackupCodes => AdmissionReadlessCodes::class,
            default => null,
        };

        return $class === null ? parent::mountedObjectCollection($name)
            : ($this->stores[$name] ??= $class::initDB(Objects::LAZY_STRATEGY_KEY));
    }

    /**
     * @param string $name Requested View collection
     * @return mixed Metadata facade; business View reads are forbidden on this port
     */
    public function __get(string $name): mixed
    {
        if (in_array($name, ObjectAccountMergeFences::BUSINESS_COLLECTIONS, true)) {
            throw new RuntimeException('Admission attempted a business View lookup.');
        }

        return parent::__get($name);
    }
}

/** Both key and whole-set business reads must fail even when no SQL counter would notice. */
trait AdmissionRefusesBusinessLoad
{
    /**
     * @param int|string|null $key Business row that must come from the copy
     * @return ?Object_ No hidden business row may be loaded
     */
    protected function lazyLoadObject(int|string|null $key): ?Object_
    {
        throw new RuntimeException('Admission attempted a business row load.');
    }

    /** Business completeness cannot be obtained by a hidden SELECT. */
    protected function lazyLoadAll(): void
    {
        throw new RuntimeException('Admission attempted a business set load.');
    }
}

class AdmissionReadlessIdentities extends ObjectIdentities
{
    use AdmissionRefusesBusinessLoad;
}

class AdmissionReadlessPasskeys extends ObjectPasskeyCredentials
{
    use AdmissionRefusesBusinessLoad;
}

class AdmissionReadlessFactors extends ObjectSecondFactors
{
    use AdmissionRefusesBusinessLoad;
}

class AdmissionReadlessCodes extends ObjectSecondFactorBackupCodes
{
    use AdmissionRefusesBusinessLoad;
}

/** Fails during the batch, while its memory and reactions still belong to the open transaction. */
final class AdmissionFailingMirror implements SourceMirrorSubscriberInterface
{
    public bool $fail = true;

    /**
     * @param SourceChange $change Synchronous memory fact
     * @param SourceChangeProvenance $provenance Actual remote provenance
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($this->fail && $change->sourceKey === HilosDbContext::secondFactors) {
            $this->fail = false;
            throw new RuntimeException('Injected batch mirror failure.');
        }
    }
}
