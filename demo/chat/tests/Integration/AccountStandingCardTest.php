<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Closure;
use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Users\UserPage;
use Demo\Chat\Pages\Hilos\Users\UsersPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\HilosUser\HilosUsersTable;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Auth\AccountOperation\AccountErasureOpening;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeCoordinator;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationCircle;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Database\Object\Objects;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\WorkerDbInterestReadyMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Users\AccountErasureProgress;
use Hilos\Users\AccountErasureProgressResolver;
use Hilos\Users\AccountMergeProgressResolver;
use Hilos\Utils\Logger;
use ReflectionProperty;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\AccountOperation as EntityAccountOperation;
use Hilos\Database\Entity\Item\AccountOperationAssignment as EntityAccountOperationAssignment;
use Hilos\Database\Entity\Item\AccountOperationResult as EntityAccountOperationResult;
use Hilos\Database\Entity\Item\AccountDeletion as EntityAccountDeletion;
use Hilos\Database\Entity\Item\UserMerge as EntityUserMerge;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Hilos as HilosFacade;
use Hilos\HilosException;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\LegalStandingResolver;
use Hilos\Legal\LegalTally;
use Hilos\Pages\Users\AccountStandingAudience;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Tables\Users\AbstractHilosUsersTable;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountStanding;
use Hilos\Users\AccountStandingResolver;
use Hilos\Users\DTO\AccountStandingStateSignalData;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * The admin card of a person reads the person's standing as one verdict, and follows it (HIL-945).
 *
 * On the chat demo's real card, its real index agent and its real people list: the page answer
 * carries the standing, a block and a scheduled deletion written under the open card reach its
 * administrator on the next tick of the agent serving it, and the list narrowed to the people past
 * a deadline holds exactly the people the legal section counts there.
 */
final class AccountStandingCardTest extends IntegrationTestCase
{
    private const string ACCEPT_KEY = 'account-standing-card';
    private const string TEST_AGENT = 'account-standing-card-test';

    private int $personId;

    protected function setUp(): void
    {
        parent::setUp();
        HilosFacade::$sr = new AccountStandingCardRouter();
        Hilos::initBrowser();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        TruthSourceRegistry::register(HilosDbContext::accountDeletions, TruthSourceKeys::all(), self::TEST_AGENT);
        TruthSourceRegistry::register(HilosDbContext::userMerges, TruthSourceKeys::all(), self::TEST_AGENT);
        foreach ([HilosDbContext::accountOperations, HilosDbContext::accountOperationAssignments, HilosDbContext::accountOperationResults] as $key) {
            TruthSourceRegistry::register($key, TruthSourceKeys::all(), self::TEST_AGENT);
        }
        Hilos::$rt->connections->actions->clear();
        AccountStandingAudience::reset();
        AccountStandingResolver::forgetAll();
        AccountErasureProgressResolver::forgetAll();
        AccountMergeProgressResolver::forgetAll();
        $this->personId = (int) Hilos::$db->users->actions->createWithName('Taras Shevchuk')->id;
        $this->connectAdmin();
    }

    protected function tearDown(): void
    {
        AccountStandingAudience::reset();
        AccountStandingResolver::forgetAll();
        AccountErasureProgressResolver::forgetAll();
        AccountMergeProgressResolver::forgetAll();
        Hilos::initBrowser();
        Hilos::$rt->connections->actions->clear();
        TruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        HilosFacade::$sr = null;
        parent::tearDown();
    }

    public function testMergeFirstAnswerAndRefreshReadPreparingWaitingAndRealTerminalOutcome(): void
    {
        self::assertSame(['reserved' => false, 'available' => true], $this->mergeCardData()[UserPage::ACCOUNT_MERGE_AVAILABILITY]);
        $operation = $this->openMerge();
        $this->inCardWorker(function (AccountStandingCardWorker $worker) use ($operation): void {
            $first = $this->mergeCardData($worker->index());
            self::assertSame(['reserved' => false, 'available' => true], $first[UserPage::ACCOUNT_MERGE_AVAILABILITY]);
            self::assertSame('preparing', $first[UserPage::ACCOUNT_MERGE]['state']);
            self::assertSame([false, false], array_slice(array_column($first[UserPage::ACCOUNT_MERGE]['parts'], 'done'), 0, 2));
            self::assertSame($first[UserPage::ACCOUNT_MERGE], $this->mergeCardData($worker->index())[UserPage::ACCOUNT_MERGE]);
        });
        foreach (Hilos::$db->accountOperationAssignments->forOperation($operation->id) as $assignment) {
            if (!in_array($assignment->assignmentKey, ['prepare_survivor', 'prepare_loser'], true)) {
                continue;
            }
            $owner = $this->personAgent($assignment->userId);
            $this->underAgent($owner, fn () => $owner->prepareForMerge($assignment->id));
        }
        $this->sessionsLibrary();
        ExecutionContext::run(new ExecutionFrame(agentId: HilosAgentType::HILOS_SESSIONS_LIBRARY),
            static fn () => AccountMergeCoordinator::begin($operation));
        $waiting = $this->mergeCardData();
        self::assertSame(['reserved' => true, 'available' => false], $waiting[UserPage::ACCOUNT_MERGE_AVAILABILITY]);
        self::assertSame($waiting[UserPage::ACCOUNT_MERGE_AVAILABILITY], $this->mergeCardData()[UserPage::ACCOUNT_MERGE_AVAILABILITY]);
        self::assertSame('wait', $waiting[UserPage::ACCOUNT_MERGE]['state']);
        self::assertSame('go', $waiting[UserPage::ACCOUNT_MERGE]['stage']);
        self::assertNull($waiting[UserPage::ACCOUNT_MERGE]['outcome']);
        self::assertSame($waiting[UserPage::ACCOUNT_MERGE], $this->mergeCardData()[UserPage::ACCOUNT_MERGE]);
        $this->cancelMerge($operation);
        $terminal = $this->mergeCardData();
        self::assertSame(['reserved' => false, 'available' => true], $terminal[UserPage::ACCOUNT_MERGE_AVAILABILITY]);
        self::assertSame('end', $terminal[UserPage::ACCOUNT_MERGE]['state']);
        self::assertSame('not_started', $terminal[UserPage::ACCOUNT_MERGE]['outcome']);
        self::assertSame($terminal[UserPage::ACCOUNT_MERGE], $this->mergeCardData()[UserPage::ACCOUNT_MERGE]);
    }

    public function testMergeOnlyChangesReplaceTheWholeStandingFrameOnceAndEqualTicksStayQuiet(): void
    {
        $operation = $this->openMerge();
        $before = $this->mergeCardData();
        $this->recordMergeWait($operation);
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        $frames = $this->standingFrames();
        self::assertCount(1, $frames);
        self::assertSame($before[UserPage::ACCOUNT_STANDING], $frames[0]->accountStanding);
        self::assertSame($before[UserPage::ACCOUNT_ERASURE], $frames[0]->accountErasure);
        self::assertSame(AccountMergeProgressResolver::of($this->personId)->toArray(), $frames[0]->accountMerge);
        self::assertSame([['participant' => 'project:card', 'userId' => $operation->loserUserId]], $frames[0]->accountMerge['waits']);
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        self::assertSame([], $this->standingFrames());
        $this->cancelMerge($operation);
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        $terminal = $this->standingFrames();
        self::assertCount(1, $terminal);
        self::assertSame('not_started', $terminal[0]->accountMerge['outcome']);
        self::assertSame($before[UserPage::ACCOUNT_STANDING], $terminal[0]->accountStanding);
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        self::assertSame([], $this->standingFrames());
    }

    public function testOnlyAvailabilityChangingSendsTheWholeCardOnceAndRefreshReadsTheSameFacts(): void
    {
        $request = Hilos::$db->accountDeletions->actions->request(
            $this->personId, TimeHelper::nowMs() + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
        );
        $before = $this->mergeCardData();
        self::assertSame(['reserved' => false, 'available' => true], $before[UserPage::ACCOUNT_MERGE_AVAILABILITY]);
        $operation = AccountErasureOpening::open($request, AccountErasureParticipantCatalog::framework())->operation;
        self::assertNotNull($operation);
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        $frames = $this->standingFrames();
        self::assertCount(1, $frames);
        self::assertSame($before[UserPage::ACCOUNT_STANDING], $frames[0]->accountStanding);
        self::assertSame($before[UserPage::ACCOUNT_ERASURE], $frames[0]->accountErasure);
        self::assertSame($before[UserPage::ACCOUNT_MERGE], $frames[0]->accountMerge);
        self::assertSame(['reserved' => false, 'available' => false], $frames[0]->accountMergeAvailability);
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        self::assertSame([], $this->standingFrames());
        self::assertSame($frames[0]->accountMergeAvailability, $this->mergeCardData()[UserPage::ACCOUNT_MERGE_AVAILABILITY]);
        self::assertTrue($operation->actions->finish($operation->stage, AccountOperationOutcome::Completed));
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        $after = $this->standingFrames();
        self::assertCount(1, $after);
        self::assertSame(['reserved' => false, 'available' => true], $after[0]->accountMergeAvailability);
        self::assertSame($before[UserPage::ACCOUNT_STANDING], $after[0]->accountStanding);
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        self::assertSame([], $this->standingFrames());
    }

    public function testUnreadableReservationIsUnknownLogsOnceAndRecoversWithoutReload(): void
    {
        $this->inCardWorker(function (AccountStandingCardWorker $worker): void {
            $operation = $this->openMerge();
            $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, 'preparing', 'prepare_survivor');
            $owner = $this->personAgent($this->personId);
            $result = $this->underAgent($owner, fn () => $owner->prepareForMerge($assignment->id));
            $before = $this->mergeCardData();
            $saved = Database::sql('SELECT payload_json FROM hilos_account_operation_result WHERE id = ?', [$result->id])->row()['payload_json'];
            $log = tempnam(sys_get_temp_dir(), 'hilos-card-availability-');
            self::assertIsString($log);
            $previousLog = new ReflectionProperty(Logger::class, 'logFile')->getValue();
            Logger::setLogFile($log);
            try {
                // Repair fixture: no production writer can store a malformed preparation proof.
                $this->repairPreparationProof($worker, $result->id, '{"private-engine-proof":true}');
                AccountStandingAudience::onAgentTick(new DemoHilosAgent());
                $frames = $this->standingFrames();
                self::assertCount(1, $frames);
                self::assertNull($frames[0]->accountMergeAvailability);
                self::assertSame($before[UserPage::ACCOUNT_STANDING], $frames[0]->accountStanding);
                self::assertSame($before[UserPage::ACCOUNT_ERASURE], $frames[0]->accountErasure);
                AccountStandingAudience::onAgentTick(new DemoHilosAgent());
                self::assertSame([], $this->standingFrames());
                self::assertSame(1, substr_count(file_get_contents($log), 'Account card merge availability unavailable:'));
                self::assertStringNotContainsString('private-engine-proof', file_get_contents($log));
                self::assertStringNotContainsString('Syntax error', file_get_contents($log));
                $this->repairPreparationProof($worker, $result->id, $saved);
                AccountStandingAudience::onAgentTick(new DemoHilosAgent());
                $recovered = $this->standingFrames();
                self::assertCount(1, $recovered);
                self::assertSame($before[UserPage::ACCOUNT_MERGE_AVAILABILITY], $recovered[0]->accountMergeAvailability);
                self::assertSame($before[UserPage::ACCOUNT_MERGE], $recovered[0]->accountMerge);
                AccountStandingAudience::onAgentTick(new DemoHilosAgent());
                self::assertSame([], $this->standingFrames());
                $this->repairPreparationProof($worker, $result->id, '{"private-engine-proof":true}');
                $frames = $this->routedCardFrames();
                self::assertCount(1, $frames);
                self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $frames[0]['name']);
                self::assertSame(PageSubscriptionErrorSignalData::forStatus(UserPage::PAGE, 500, 'internal_error')->toArray(), $frames[0]['data']);
                self::assertSame([], new ReflectionProperty(AccountStandingAudience::class, 'shownAvailability')->getValue());
            } finally {
                $this->repairPreparationProof($worker, $result->id, $saved);
                if ($previousLog === null) {
                    Logger::resetLogFile();
                } else {
                    Logger::setLogFile($previousLog);
                }
                unlink($log);
            }
            self::assertSame($before[UserPage::ACCOUNT_MERGE_AVAILABILITY], $this->mergeCardData()[UserPage::ACCOUNT_MERGE_AVAILABILITY]);
        });
    }

    public function testFirstAvailabilityReadFailureUsesOnlyTheSafeSubscriptionError(): void
    {
        $operation = $this->openErasure(started: false);
        $this->inCardWorker(function (AccountStandingCardWorker $worker) use ($operation): void {
            $before = $this->mergeCardData($worker->index());
            self::assertNull($before[UserPage::ACCOUNT_MERGE]);
            $saved = Database::sql('SELECT * FROM hilos_account_operation WHERE id = ?', [$operation->id])->row();
            try {
                // A malformed circle makes availability unreadable without breaking merge observation.
                Database::sqlRun('UPDATE hilos_account_operation SET circle_user_ids = ? WHERE id = ?',
                    ['{"private-engine-proof":true}', $operation->id]);
                $row = Database::sql('SELECT * FROM hilos_account_operation WHERE id = ?', [$operation->id])->row();
                $worker->deliver(new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
                    HilosDbContext::accountOperations, (string) $operation->id, $row,
                ), 'other-node'));
                self::assertNull(AccountMergeProgressResolver::of($this->personId));
                $frames = $this->routedCardFrames($worker->index());
                self::assertCount(1, $frames);
                self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $frames[0]['name']);
                self::assertSame(PageSubscriptionErrorSignalData::forStatus(UserPage::PAGE, 500, 'internal_error')->toArray(), $frames[0]['data']);
                self::assertStringNotContainsString('private-engine-proof', json_encode($frames, JSON_THROW_ON_ERROR));
                self::assertSame([], new ReflectionProperty(AccountStandingAudience::class, 'shownAvailability')->getValue());
            } finally {
                Database::sqlRun('UPDATE hilos_account_operation SET circle_user_ids = ? WHERE id = ?',
                    [$saved['circle_user_ids'], $operation->id]);
                $worker->deliver(new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
                    HilosDbContext::accountOperations, (string) $operation->id, $saved,
                ), 'other-node'));
            }
            self::assertSame($before[UserPage::ACCOUNT_MERGE_AVAILABILITY],
                $this->mergeCardData($worker->index())[UserPage::ACCOUNT_MERGE_AVAILABILITY]);
        });
    }

    /** @return iterable<string, array{bool}> Cold first read and a previously served absence */
    public static function mergeReaderStates(): iterable
    {
        yield 'cold page' => [false];
        yield 'shown absence' => [true];
    }

    /**
     * E2e exception 1 (testing.md): only this worker can reveal its absent cached answer
     * and the rows its lazy applicator did not retain. Feed actual WorkerManager ingress.
     *
     * @param bool $warm Whether the card already showed that no merge existed
     */
    #[DataProvider('mergeReaderStates')]
    public function testRemoteMergeLazyCreatesAndAcceptedUpdatesAndDeletesReachTheCard(bool $warm): void
    {
        $this->inCardWorker(function (AccountStandingCardWorker $worker) use ($warm): void {
            if ($warm) {
                self::assertNull($this->mergeCardData($worker->index())[UserPage::ACCOUNT_MERGE]);
            }
            [$operation, $facts] = $this->inOtherWriter(function (): array {
                $operation = $this->openMerge();
                $facts = [$this->createdMergeFact(HilosDbContext::accountOperations, 'hilos_account_operation', $operation->id)];
                foreach (Hilos::$db->accountOperationAssignments->forOperation($operation->id) as $assignment) {
                    $facts[] = $this->createdMergeFact(HilosDbContext::accountOperationAssignments,
                        'hilos_account_operation_assignment', $assignment->id);
                }

                return [$operation, $facts];
            });
            foreach ($facts as $fact) {
                $objects = Hilos::$db->mountedObjectCollection($fact->collectionKey);
                $held = array_keys($objects->toArray());
                self::assertSame(Objects::LAZY_STRATEGY_KEY, $objects->getLazyStrategy());
                self::assertFalse($objects->isAllLoaded());
                $worker->deliver(new WorkerDbSyncCreatedMessageDTO($fact, 'other-node'));
                self::assertSame($held, array_keys($objects->toArray()));
            }
            if ($warm) {
                AccountStandingAudience::onAgentTick($worker->index());
                $frames = $this->standingFrames();
                self::assertCount(1, $frames);
                self::assertSame('preparing', $frames[0]->accountMerge['state']);
            }
            $first = $this->mergeCardData($worker->index());
            self::assertSame($operation->id, $first[UserPage::ACCOUNT_MERGE]['operationId']);
            $cached = AccountMergeProgressResolver::of($this->personId);
            foreach ([HilosDbContext::accountOperations, HilosDbContext::accountOperationAssignments,
                HilosDbContext::accountOperationResults] as $key) {
                foreach ([
                    new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData($key, '999999999', []), 'other-node'),
                    new WorkerDbSyncDeletedMessageDTO(new DbSyncDeletedSignalData($key, '999999999'), 'other-node'),
                ] as $fact) {
                    $worker->deliver($fact);
                    $fresh = AccountMergeProgressResolver::of($this->personId);
                    self::assertNotSame($cached, $fresh);
                    self::assertSame($cached->toArray(), $fresh->toArray());
                    $cached = $fresh;
                    AccountStandingAudience::onAgentTick($worker->index());
                    self::assertSame([], $this->standingFrames());
                }
            }
            $prepared = $this->inOtherWriter(function () use ($operation): DbSyncCreatedSignalData {
                $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, 'preparing', 'prepare_survivor');
                $owner = $this->personAgent($assignment->userId);
                $result = $this->underAgent($owner, fn () => $owner->prepareForMerge($assignment->id));

                return $this->createdMergeFact(HilosDbContext::accountOperationResults, 'hilos_account_operation_result', $result->id);
            });
            self::assertSame($cached, AccountMergeProgressResolver::of($this->personId));
            $objects = Hilos::$db->mountedObjectCollection(HilosDbContext::accountOperationResults);
            $held = array_keys($objects->toArray());
            $worker->deliver(new WorkerDbSyncCreatedMessageDTO($prepared, 'other-node'));
            self::assertSame($held, array_keys($objects->toArray()));
            AccountStandingAudience::onAgentTick($worker->index());
            $afterPreparation = $this->standingFrames();
            self::assertCount(1, $afterPreparation);
            self::assertTrue($afterPreparation[0]->accountMerge['parts'][0]['done']);
            self::assertSame(['reserved' => true, 'available' => false], $afterPreparation[0]->accountMergeAvailability);
            self::assertSame('preparing', $afterPreparation[0]->accountMerge['state']);
            $cached = AccountMergeProgressResolver::of($this->personId);
            $waitFact = $this->inOtherWriter(function () use ($operation): DbSyncCreatedSignalData {
                $id = $this->recordMergeWait($operation);

                return $this->createdMergeFact(HilosDbContext::accountOperationAssignments, 'hilos_account_operation_assignment', $id);
            });
            self::assertSame($cached, AccountMergeProgressResolver::of($this->personId));
            $worker->deliver(new WorkerDbSyncCreatedMessageDTO($waitFact, 'other-node'));
            AccountStandingAudience::onAgentTick($worker->index());
            self::assertSame([['participant' => 'project:card', 'userId' => $operation->loserUserId]],
                $this->standingFrames()[0]->accountMerge['waits']);
            // A removed immutable source is a repair fixture, not a production writer API.
            Database::sqlRun('DELETE FROM hilos_account_operation_assignment WHERE id = ?', [(int) $waitFact->idString]);
            $worker->deliver(new WorkerDbSyncDeletedMessageDTO(
                new DbSyncDeletedSignalData(HilosDbContext::accountOperationAssignments, $waitFact->idString), 'other-node',
            ));
            AccountStandingAudience::onAgentTick($worker->index());
            $afterDelete = $this->standingFrames();
            self::assertCount(1, $afterDelete);
            self::assertSame([], $afterDelete[0]->accountMerge['waits']);
            $changed = $this->inOtherWriter(function () use ($operation): DbSyncUpdatedSignalData {
                $this->sessionsLibrary();
                ExecutionContext::run(new ExecutionFrame(agentId: HilosAgentType::HILOS_SESSIONS_LIBRARY),
                    static fn () => AccountMergeCoordinator::cancel($operation));
                $row = Database::sql('SELECT * FROM hilos_account_operation WHERE id = ?', [$operation->id])->row();

                return new DbSyncUpdatedSignalData(HilosDbContext::accountOperations, (string) $operation->id, $row);
            });
            $worker->deliver(new WorkerDbSyncUpdatedMessageDTO($changed, 'other-node'));
            AccountStandingAudience::onAgentTick($worker->index());
            $afterUpdate = $this->standingFrames();
            self::assertCount(1, $afterUpdate);
            self::assertSame('cancelling', $afterUpdate[0]->accountMerge['stage']);
            self::assertSame($first[UserPage::ACCOUNT_STANDING], $afterUpdate[0]->accountStanding);
        });
    }

    /** E2e exception 1 (testing.md): a process restart cannot retain and test the stopped agent's cache. */
    public function testMergeWorkerStopNoReaderAndRestartReadARealOutcomeWrittenDuringTheGap(): void
    {
        $operation = $this->openMerge();
        $this->preparePair($operation);
        $this->inCardWorker(function (AccountStandingCardWorker $worker) use ($operation): void {
            $before = $this->mergeCardData($worker->index());
            self::assertSame(['reserved' => true, 'available' => false], $before[UserPage::ACCOUNT_MERGE_AVAILABILITY]);
            $cached = AccountMergeProgressResolver::of($this->personId);
            foreach (DemoHilosAgent::readDbCollections() as $key) {
                SourceInterestRegistry::register(SourceChange::KIND_DB, $key, SourceConsumer::page(self::ACCEPT_KEY));
            }
            $worker->deliver(new AgentStopDTO(HilosAgentType::HILOS_INDEX));
            self::assertNotSame($cached, AccountMergeProgressResolver::of($this->personId));
            self::assertSame([], new ReflectionProperty(AccountStandingAudience::class, 'shownMerge')->getValue());
            self::assertSame([], new ReflectionProperty(AccountStandingAudience::class, 'shownAvailability')->getValue());
            $cached = AccountMergeProgressResolver::of($this->personId);
            $worker->releasePage(self::ACCEPT_KEY);
            foreach ([HilosDbContext::accountOperations, HilosDbContext::accountOperationAssignments,
                HilosDbContext::accountOperationResults] as $key) {
                self::assertSame([], SourceInterestRegistry::consumersOf(SourceChange::KIND_DB, $key));
            }
            $this->inOtherWriter(fn () => $this->cancelMerge($operation));
            self::assertSame($cached, AccountMergeProgressResolver::of($this->personId));
            $this->readyAndStartIndex($worker);
            $after = $this->mergeCardData($worker->index());
            self::assertSame($before[UserPage::ACCOUNT_STANDING], $after[UserPage::ACCOUNT_STANDING]);
            self::assertSame('end', $after[UserPage::ACCOUNT_MERGE]['state']);
            self::assertSame('not_started', $after[UserPage::ACCOUNT_MERGE]['outcome']);
            self::assertSame(['reserved' => false, 'available' => true], $after[UserPage::ACCOUNT_MERGE_AVAILABILITY]);
        });
    }

    /** @return iterable<string, array{string, string}> Each mounted metadata source, independently of the other four */
    public static function metadataSources(): iterable
    {
        yield 'operation' => [HilosDbContext::accountOperations, 'hilos_account_operation'];
        yield 'assignment' => [HilosDbContext::accountOperationAssignments, 'hilos_account_operation_assignment'];
        yield 'result' => [HilosDbContext::accountOperationResults, 'hilos_account_operation_result'];
        yield 'deletion' => [HilosDbContext::accountDeletions, 'hilos_account_deletion'];
        yield 'tombstone' => [HilosDbContext::userMerges, 'hilos_user_merge'];
    }

    /**
     * E2e exception 1 (testing.md): prove each declared source at real WorkerManager ingress,
     * retaining the warmed viewport and lazy mirrors while the separate owners commit.
     * Immutable-source updates are accepted reannouncements; deletes are explicit repair fixtures.
     *
     * @param string $key Mounted metadata source under test
     * @param string $table Physical fixture table for independent persisted evidence
     */
    #[DataProvider('metadataSources')]
    public function testEachMetadataSourceReachesBothPeopleThroughRealWorkerIngress(string $key, string $table): void
    {
        $loserId = (int) Hilos::$db->users->actions->createWithName('Worker list loser')->id;
        $this->inCardWorker(function (AccountStandingCardWorker $worker) use ($key, $table, $loserId): void {
            $this->subscribeToUsersList($worker);
            [$operation, $id] = $this->inOtherWriter(function () use ($key, $loserId): array {
                $operation = AccountMergeOpening::open($this->personId, $loserId, new AccountMergeChoice(null, null, null, null));
                $this->preparePair($operation);
                $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, 'preparing', 'prepare_loser');
                $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
                $id = match ($key) {
                    HilosDbContext::accountOperations => $operation->id,
                    HilosDbContext::accountOperationAssignments => $assignment->id,
                    HilosDbContext::accountOperationResults => $result->id,
                    HilosDbContext::accountDeletions => Hilos::$db->accountDeletions->actions->request(
                        $this->personId, TimeHelper::nowMs() + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
                    )->id,
                    HilosDbContext::userMerges => $loserId,
                };
                if ($key === HilosDbContext::userMerges) {
                    Hilos::$db->userMerges->actions->add($loserId, $this->personId);
                }

                return [$operation, $id];
            });
            $primary = $key === HilosDbContext::userMerges ? 'user_id' : 'id';
            $row = Database::sql('SELECT * FROM `' . $table . '` WHERE `' . $primary . '` = ?', [$id])->row();
            self::assertIsArray($row);
            $objects = Hilos::$db->mountedObjectCollection($key);
            $held = array_keys($objects->toArray());
            $worker->deliver(new WorkerDbSyncCreatedMessageDTO(new DbSyncCreatedSignalData($key, (string) $id, $row), 'other-node'));
            self::assertSame($held, array_keys($objects->toArray()), 'Lazy ingress must not retain an unheld metadata row');
            self::assertSame([], Hilos::$browser->flushToSignalRouter());
            $expected = $key === HilosDbContext::accountDeletions ? [$this->personId] : [$this->personId, $loserId];
            $this->assertListOccupancy($expected, true, false);

            $this->inOtherWriter(function () use ($operation, $key): void {
                $this->cancelMerge($operation);
                if ($key === HilosDbContext::accountDeletions) {
                    Hilos::$db->accountDeletions->liveOf($this->personId)->actions->cancel();
                }
            });
            $row = Database::sql('SELECT * FROM `' . $table . '` WHERE `' . $primary . '` = ?', [$id])->row();
            $worker->deliver(new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData($key, (string) $id, $row), 'other-node'));
            self::assertSame([], Hilos::$browser->flushToSignalRouter());
            $deltas = $this->listDeltas();
            self::assertEqualsCanonicalizing($expected, array_column($deltas, TableViewportDeltaDTO::rowKey));
            foreach ($deltas as $delta) {
                $availability = $delta['row']['slots']['merge']['accountMergeAvailability'];
                self::assertSame(['reserved' => false, 'available' => $key !== HilosDbContext::userMerges
                    || (int) $delta['rowKey'] !== $loserId], $availability);
            }

            $this->inOtherWriter(function () use ($key, $table, $primary, $id, $operation, $loserId): void {
                // Removing immutable history is a repair fixture, never a production merge outcome.
                if ($key === HilosDbContext::accountOperations) {
                    Database::sqlRun('DELETE r FROM hilos_account_operation_result r'
                        . ' JOIN hilos_account_operation_assignment a ON a.id = r.assignment_id WHERE a.operation_id = ?', [$operation->id]);
                    Database::sqlRun('DELETE FROM hilos_account_operation_assignment WHERE operation_id = ?', [$operation->id]);
                } elseif ($key === HilosDbContext::accountOperationAssignments) {
                    Database::sqlRun('DELETE FROM hilos_account_operation_result WHERE assignment_id = ?', [$id]);
                }
                Database::sqlRun('DELETE FROM `' . $table . '` WHERE `' . $primary . '` = ?', [$id]);
                self::assertNull(Database::sql('SELECT * FROM `' . $table . '` WHERE `' . $primary . '` = ?', [$id])->row());
                $next = AccountMergeOpening::open($this->personId, $loserId, new AccountMergeChoice(null, null, null, null));
                $this->preparePair($next);
            });
            $worker->deliver(new WorkerDbSyncDeletedMessageDTO(new DbSyncDeletedSignalData($key, (string) $id), 'other-node'));
            self::assertSame([], Hilos::$browser->flushToSignalRouter());
            $this->assertListOccupancy([$this->personId, $loserId], true, false);
        });
    }

    /** @return iterable<string, array{string, string, bool, bool}> Native row/previous carriers and circle fallback */
    public static function metadataRelations(): iterable
    {
        foreach (self::metadataSources() as $name => [$key, $table]) {
            yield $name . ' previous relation' => [$key, $table, false, false];
            yield $name . ' row relation' => [$key, $table, true, false];
        }
        yield 'operation previous circle' => [HilosDbContext::accountOperations, 'hilos_account_operation', false, true];
        yield 'operation row circle' => [HilosDbContext::accountOperations, 'hilos_account_operation', true, true];
    }

    /**
     * Existing repair carriers retain both native relations through the real lazy ingress.
     * These reannouncements grant no mutation right over immutable assignments or results.
     *
     * @param string $key Metadata source with persisted relation keys
     * @param string $table Physical table for independent fixture reads
     * @param bool $reverse Whether the other relation is carried in row instead of previous
     * @param bool $circle Whether only the operation circle is carried, requiring conservative recovery
     */
    #[DataProvider('metadataRelations')]
    public function testEntityRelationsInRowAndPreviousReachOldAndNewPeopleThroughWorkerIngress(
        string $key,
        string $table,
        bool $reverse,
        bool $circle,
    ): void
    {
        $loser = (int) Hilos::$db->users->actions->createWithName('Current relation loser')->id;
        $otherRoot = (int) Hilos::$db->users->actions->createWithName('Previous relation root')->id;
        $otherLoser = (int) Hilos::$db->users->actions->createWithName('Previous relation loser')->id;
        Hilos::$db->users->actions->createWithName('Outside both relations');
        $this->inCardWorker(function (AccountStandingCardWorker $worker) use (
            $key, $table, $loser, $otherRoot, $otherLoser, $reverse, $circle,
        ): void {
            $this->subscribeToUsersList($worker);
            [$id, $previousId] = $this->inOtherWriter(function () use ($key, $loser, $otherRoot, $otherLoser): array {
                $current = AccountMergeOpening::open($this->personId, $loser, new AccountMergeChoice(null, null, null, null));
                $this->preparePair($current);
                $previous = AccountMergeOpening::open($otherRoot, $otherLoser, new AccountMergeChoice(null, null, null, null));
                $this->preparePair($previous);
                $currentAssignment = Hilos::$db->accountOperationAssignments->byIdentity($current->id, 'preparing', 'prepare_loser');
                $previousAssignment = Hilos::$db->accountOperationAssignments->byIdentity($previous->id, 'preparing', 'prepare_loser');
                if ($key === HilosDbContext::accountOperations) {
                    return [$current->id, $previous->id];
                }
                if ($key === HilosDbContext::accountOperationAssignments) {
                    return [$currentAssignment->id, $previousAssignment->id];
                }
                if ($key === HilosDbContext::accountDeletions) {
                    $effectiveAt = TimeHelper::nowMs() + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;

                    return [Hilos::$db->accountDeletions->actions->request($this->personId, $effectiveAt)->id,
                        Hilos::$db->accountDeletions->actions->request($otherRoot, $effectiveAt)->id];
                }
                if ($key === HilosDbContext::userMerges) {
                    Hilos::$db->userMerges->actions->add($loser, $this->personId);
                    Hilos::$db->userMerges->actions->add($otherLoser, $otherRoot);

                    return [$loser, $otherLoser];
                }
                // A normal owner result can belong to the entire erasure circle, without a personal user_id.
                $result = ExecutionContext::run(new ExecutionFrame(agentId: self::TEST_AGENT), function () use ($loser) {
                    $request = Hilos::$db->accountDeletions->actions->request($this->personId,
                        TimeHelper::nowMs() + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND);
                    $erasure = Hilos::$db->accountOperations->actions->recordErasure(
                        $request->id, $this->personId, AccountOperationCircle::fromArray([$loser, $this->personId], $this->personId),
                    );
                    $assignment = Hilos::$db->accountOperationAssignments->actions->record(
                        $erasure->id, $erasure->stage, 'whole-circle', new AccountOperationOwnerAddress(self::TEST_AGENT),
                        null, AccountOperationAssignmentPayload::fromJson('{}'),
                    );
                    Database::transactionStart();
                    try {
                        $result = Hilos::$db->accountOperationResults->actions->record(
                            $assignment, AccountOperationResultPayload::fromJson('{}'),
                        );
                        Database::transactionCommit();

                        return $result;
                    } catch (HilosException $failure) {
                        Database::transactionRollback();
                        throw $failure;
                    }
                });
                $saved = Database::sql('SELECT a.user_id, r.assignment_id FROM hilos_account_operation_assignment a'
                    . ' JOIN hilos_account_operation_result r ON r.assignment_id = a.id WHERE r.id = ?', [$result->id])->row();
                self::assertNotNull($saved);
                self::assertNull($saved['user_id']);
                self::assertSame($result->assignmentId, (int) $saved['assignment_id']);

                return [$result->id, Hilos::$db->accountOperationResults->byAssignment($previousAssignment->id)->id];
            });
            $primary = $key === HilosDbContext::userMerges ? EntityUserMerge::user_id : 'id';
            $row = Database::sql('SELECT * FROM `' . $table . '` WHERE `' . $primary . '` = ?', [$id])->row();
            $previous = Database::sql('SELECT * FROM `' . $table . '` WHERE `' . $primary . '` = ?', [$previousId])->row();
            self::assertIsArray($row);
            self::assertIsArray($previous);
            $relationFields = match ($key) {
                HilosDbContext::accountOperations => [EntityAccountOperation::root_user_id, EntityAccountOperation::loser_user_id],
                HilosDbContext::accountOperationAssignments => [EntityAccountOperationAssignment::operation_id,
                    EntityAccountOperationAssignment::user_id],
                HilosDbContext::accountOperationResults => [EntityAccountOperationResult::assignment_id],
                HilosDbContext::accountDeletions => [EntityAccountDeletion::user_id],
                default => [EntityUserMerge::user_id, EntityUserMerge::survivor_user_id],
            };
            if ($circle) {
                $relationFields = [EntityAccountOperation::circle_user_ids];
            }
            $currentRelation = array_intersect_key($row, array_flip($relationFields));
            $previousRelation = array_intersect_key($previous, array_flip($relationFields));
            $expected = $key === HilosDbContext::accountDeletions
                ? [$this->personId, $otherRoot] : [$this->personId, $loser, $otherRoot, $otherLoser];
            $data = new DbSyncUpdatedSignalData($key, (string) $id, $reverse ? $previousRelation : $currentRelation,
                previous: $reverse ? $currentRelation : $previousRelation);
            $data = DbSyncUpdatedSignalData::fromArray(json_decode(json_encode($data->toArray(), JSON_THROW_ON_ERROR),
                true, flags: JSON_THROW_ON_ERROR));
            $worker->deliver(new WorkerDbSyncUpdatedMessageDTO($data, 'other-node'));
            self::assertSame([], Hilos::$browser->flushToSignalRouter());
            $deltas = $this->listDeltas();
            self::assertCount(count($expected), $deltas);
            self::assertEqualsCanonicalizing($expected, array_column($deltas, TableViewportDeltaDTO::rowKey));
            foreach ($deltas as $delta) {
                self::assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta[TableViewportDeltaDTO::kind]);
                self::assertIsArray($delta['row']['slots']['merge']['accountMergeAvailability']);
            }
        });
    }

    public function testMergeReadFailureKeepsStandingAndErasureAndRecoversWithoutReload(): void
    {
        $this->openErasure();
        $operation = $this->openMerge();
        $before = $this->mergeCardData();
        $healthy = Hilos::$db->users->actions->createWithName('Healthy card during merge failure');
        Hilos::$rt->connections->actions->register('healthy-merge-card', Hilos::$rt->connections[self::ACCEPT_KEY]->userId);
        $subscription = new WebSocketPageSubscribeSignalDTO('healthy-merge-card', UserPage::PAGE, ['userId' => (string) $healthy->id]);
        Hilos::$sr->subscribeToPage(UserPage::PAGE, $subscription);
        $router = new PageSignalRouter(new HilosPageFactory(new DemoHilosAgent(), Hilos::class), new ActionRouteConfig());
        $router->dispatchPageSubscribe($subscription, 'websocket', UserPage::PAGE);
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $log = tempnam(sys_get_temp_dir(), 'hilos-merge-card-');
        self::assertIsString($log);
        $previousLog = new ReflectionProperty(Logger::class, 'logFile')->getValue();
        Logger::setLogFile($log);
        try {
            $bad = Hilos::$db->accountOperationAssignments->actions->record(
                $operation->id, $operation->stage, 'expect:private-payload',
                new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string) $this->personId),
                $this->personId, AccountOperationAssignmentPayload::fromJson('{}'),
            );
            $healthy->actions->setBlock(true);
            AccountStandingAudience::onAgentTick(new DemoHilosAgent());
            $frames = [];
            while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
                if ($signal->signalName->getName() === HilosSignalConstants::HILOS_ACCOUNT_STANDING_STATE) {
                    $frames[$signal->data->targetAcceptKey] = $signal->data->data;
                }
            }
            self::assertCount(2, $frames);
            self::assertNull($frames[self::ACCEPT_KEY]->accountMerge);
            self::assertSame($before[UserPage::ACCOUNT_STANDING], $frames[self::ACCEPT_KEY]->accountStanding);
            self::assertSame($before[UserPage::ACCOUNT_ERASURE], $frames[self::ACCEPT_KEY]->accountErasure);
            self::assertTrue($frames['healthy-merge-card']->accountStanding[AccountStanding::blocked]);
            self::assertNull($frames['healthy-merge-card']->accountMerge);
            AccountStandingAudience::onAgentTick(new DemoHilosAgent());
            self::assertSame([], $this->standingFrames());
            self::assertSame(1, substr_count(file_get_contents($log), 'Account card merge progress unavailable:'));
            self::assertStringNotContainsString('private-payload', file_get_contents($log));
            Database::sqlRun('DELETE FROM hilos_account_operation_assignment WHERE id = ?', [$bad->id]);
            SourceChangeBus::publish(SourceChange::dbDeleted(HilosDbContext::accountOperationAssignments, (string) $bad->id));
            AccountStandingAudience::onAgentTick(new DemoHilosAgent());
            $recovered = $this->standingFrames();
            self::assertCount(1, $recovered);
            self::assertSame($before[UserPage::ACCOUNT_MERGE], $recovered[0]->accountMerge);
            AccountStandingAudience::onAgentTick(new DemoHilosAgent());
            self::assertSame([], $this->standingFrames());
        } finally {
            new UserPage(new DemoHilosAgent())->onUnsubscribe('healthy-merge-card');
            if ($previousLog === null) {
                Logger::resetLogFile();
            } else {
                Logger::setLogFile($previousLog);
            }
            unlink($log);
        }
    }

    public function testFirstMergeReadFailureUsesOnlyTheSafeSubscriptionError(): void
    {
        $operation = $this->openMerge();
        $bad = Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id, $operation->stage, 'expect:private-payload',
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string) $this->personId),
            $this->personId, AccountOperationAssignmentPayload::fromJson('{}'),
        );
        try {
            $frames = $this->routedCardFrames();
            self::assertCount(1, $frames);
            self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $frames[0]['name']);
            self::assertSame(PageSubscriptionErrorSignalData::forStatus(UserPage::PAGE, 500, 'internal_error')->toArray(), $frames[0]['data']);
            self::assertStringNotContainsString('private-payload', json_encode($frames, JSON_THROW_ON_ERROR));
            self::assertSame([], new ReflectionProperty(AccountStandingAudience::class, 'shownMerge')->getValue());
        } finally {
            Database::sqlRun('DELETE FROM hilos_account_operation_assignment WHERE id = ?', [$bad->id]);
            SourceChangeBus::publish(SourceChange::dbDeleted(HilosDbContext::accountOperationAssignments, (string) $bad->id));
        }
    }

    public function testMergeAudienceReleasesUnsubscribeNavigationResetAndDeletedPerson(): void
    {
        $operation = $this->openMerge();
        $this->mergeCardData();
        $memory = new ReflectionProperty(AccountStandingAudience::class, 'shownMerge');
        $failures = new ReflectionProperty(AccountStandingAudience::class, 'mergeFailures');
        $availability = new ReflectionProperty(AccountStandingAudience::class, 'shownAvailability');
        $availabilityFailures = new ReflectionProperty(AccountStandingAudience::class, 'availabilityFailures');
        foreach (['unsubscribe', 'navigation', 'reset'] as $exit) {
            self::assertArrayHasKey(self::ACCEPT_KEY, $memory->getValue());
            $failures->setValue(null, [self::ACCEPT_KEY => true]);
            self::assertArrayHasKey(self::ACCEPT_KEY, $availability->getValue());
            $availabilityFailures->setValue(null, [self::ACCEPT_KEY => true]);
            if ($exit === 'unsubscribe') {
                new UserPage(new DemoHilosAgent())->onUnsubscribe(self::ACCEPT_KEY);
            } elseif ($exit === 'navigation') {
                $router = new PageSignalRouter(new HilosPageFactory(new DemoHilosAgent(), Hilos::class), new ActionRouteConfig());
                $router->dispatchPageUnsubscribe(new WebSocketPageUnsubscribeSignalDTO(self::ACCEPT_KEY), 'websocket', UserPage::PAGE);
                Hilos::$sr->subscribeToPage(UsersPage::PAGE, new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, UsersPage::PAGE, []));
            } else {
                AccountStandingAudience::reset();
            }
            self::assertArrayNotHasKey(self::ACCEPT_KEY, $memory->getValue());
            self::assertArrayNotHasKey(self::ACCEPT_KEY, $failures->getValue());
            self::assertArrayNotHasKey(self::ACCEPT_KEY, $availability->getValue());
            self::assertArrayNotHasKey(self::ACCEPT_KEY, $availabilityFailures->getValue());
            AccountStandingAudience::onAgentTick(new DemoHilosAgent());
            self::assertSame([], $this->standingFrames());
            $this->mergeCardData();
        }
        Hilos::$db->users[$this->personId]->actions->delete();
        AccountMergeProgressResolver::forgetAll();
        $this->recordMergeWait($operation);
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        self::assertSame([], $this->standingFrames());
        self::assertArrayHasKey(self::ACCEPT_KEY, $memory->getValue(), 'A deleted row must be skipped before reading its merge');
        $router = new PageSignalRouter(new HilosPageFactory(new DemoHilosAgent(), Hilos::class), new ActionRouteConfig());
        $router->dispatchPageAccessReassess(new WebSocketPageSubscribeSignalDTO(
            self::ACCEPT_KEY, UserPage::PAGE, ['userId' => (string) $this->personId],
        ), 'websocket', UserPage::PAGE);
        $errors = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signal->signalName->getName());
                $errors[] = $signal->data->data->toArray();
            }
        }
        self::assertCount(1, $errors);
        self::assertSame(404, $errors[0]['httpCode']);
        self::assertArrayNotHasKey(self::ACCEPT_KEY, $memory->getValue());
        self::assertArrayNotHasKey(self::ACCEPT_KEY, $availability->getValue());
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        self::assertSame([], $this->standingFrames());
    }

    public function testTheCardAnswersWithTheStandingOfItsPerson(): void
    {
        $standing = $this->subscribeToCard();

        self::assertSame('none', $standing[AccountStanding::shown]);
        self::assertFalse($standing[AccountStanding::blocked]);
        self::assertFalse($standing[AccountStanding::frozen]);
        self::assertNull($standing[AccountStanding::deletionEffectiveAt]);
        self::assertSame([], $standing[AccountStanding::lapsed]);
    }

    public function testTheFirstCardAnswerReadsBegunErasureWithOnlyItsRealWorkerInterests(): void
    {
        $this->openErasure();
        // Clear derived memory so the page must actually read under the worker's guard.
        AccountStandingResolver::forgetAll();
        AccountErasureProgressResolver::forgetAll();
        $this->inIsolatedSourceInterests(function (): void {
            $agent = new DemoHilosAgent();
            $agentConsumer = SourceConsumer::agent($agent->getId());
            $pageConsumer = SourceConsumer::page(self::ACCEPT_KEY);
            $processWide = Hilos::$db->processWideReadKeys();
            SourceInterestRegistry::readsWhatIsDelivered();
            Hilos::$db->declareProcessWideReads();
            Hilos::$rt->declareProcessWideReads();
            foreach ($processWide as $key) {
                SourceInterestRegistry::markReady(SourceChange::KIND_DB, $key);
            }
            foreach ($agent::readDbCollections() as $key) {
                SourceInterestRegistry::register(SourceChange::KIND_DB, $key, $agentConsumer);
                SourceInterestRegistry::markReady(SourceChange::KIND_DB, $key);
            }
            foreach ([...UserPage::READS_DB, ...Hilos::$browser->dbSourceKeysOfPage(UserPage::PAGE)] as $key) {
                SourceInterestRegistry::register(SourceChange::KIND_DB, $key, $pageConsumer);
                SourceInterestRegistry::markReady(SourceChange::KIND_DB, $key);
            }
            foreach ([...UserPage::READS_RT, ...Hilos::$browser->rtSourceKeysOfPage(UserPage::PAGE)] as $key) {
                SourceInterestRegistry::register(SourceChange::KIND_RT, $key, $pageConsumer);
                SourceInterestRegistry::markReady(SourceChange::KIND_RT, $key);
            }
            foreach (Hilos::$rt->collectionKeys() as $key) {
                SourceInterestRegistry::markReady(SourceChange::KIND_RT, $key);
            }
            foreach ([
                HilosDbContext::accountOperations,
                HilosDbContext::accountOperationAssignments,
                HilosDbContext::accountOperationResults,
            ] as $key) {
                self::assertSame([$agentConsumer, $pageConsumer], SourceInterestRegistry::consumersOf(SourceChange::KIND_DB, $key));
                self::assertNotContains($key, $processWide);
            }
            self::assertSame([], SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::agent(self::TEST_AGENT), SourceChange::KIND_DB));

            $data = $this->subscribeToCardData();

            self::assertSame('erasing', $data[UserPage::ACCOUNT_STANDING][AccountStanding::shown]);
            $progress = $data[UserPage::ACCOUNT_ERASURE];
            self::assertSame(Hilos::$db->accountDeletions->liveOf($this->personId)->startedAt, $progress[AccountErasureProgress::startedAt]);
            self::assertNotSame([], $progress[AccountErasureProgress::parts]);
            self::assertContains([
                'participant' => AccountErasureParticipantCatalog::PERSONAL,
                'owner' => HilosAgentType::HILOS_USER,
                'userId' => $this->personId,
                'done' => false,
            ], $progress[AccountErasureProgress::parts]);
            self::assertSame([], $progress[AccountErasureProgress::waits]);
        });
    }

    public function testErasureStartingUnderTheOpenCardSendsBothBlocksOnce(): void
    {
        $this->openErasure(started: false);
        $data = $this->subscribeToCardData();
        self::assertNull($data[UserPage::ACCOUNT_ERASURE]);
        self::assertTrue(Hilos::$db->accountDeletions->liveOf($this->personId)->actions->start());

        AccountStandingAudience::onAgentTick(new DemoHilosAgent());

        $frames = $this->standingFrames();
        self::assertCount(1, $frames);
        self::assertSame('erasing', $frames[0]->accountStanding[AccountStanding::shown]);
        self::assertSame(AccountErasureProgressResolver::of($this->personId)->toArray(), $frames[0]->accountErasure);
    }

    public function testUnchangedErasureSendsNoFrame(): void
    {
        $this->openErasure();
        $this->subscribeToCardData();

        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());

        self::assertSame([], $this->standingFrames());
    }

    /**
     * E2e exception 1 (testing.md): scenarios 11/15 see DB facts and interests, but cannot
     * observe a particular worker's warmed resolver or whether its lazy applicator retained
     * a row. Recreating a node destroys that memory. Drive the real worker ingress here;
     * the separate writer context commits through the declared owner's actions.
     */
    public function testRemoteLazyCreatesMoveOnlyProgressOnTheNextCardTick(): void
    {
        $operation = $this->openErasure();
        $this->inCardWorker(function (AccountStandingCardWorker $worker) use ($operation): void {
            $before = $this->subscribeToCardData($worker->index());
            $cached = AccountErasureProgressResolver::of($this->personId);
            $objects = Hilos::$db->mountedObjectCollection(HilosDbContext::accountOperationAssignments);
            self::assertSame(Objects::LAZY_STRATEGY_KEY, $objects->getLazyStrategy());
            self::assertTrue($objects->cachesRows());
            self::assertFalse($objects->isAllLoaded());

            foreach ([null, new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY)] as $owner) {
                $fact = $this->inOtherWriter(function () use ($operation, $owner): DbSyncCreatedSignalData {
                    $expectation = new AccountOperationExpectation('delivery:mail', $this->personId, $owner);
                    $assignment = Hilos::$db->accountOperationAssignments->actions->record(
                        $operation->id, $operation->stage, $expectation->assignmentKey(), $expectation->assignmentOwner(),
                        $expectation->userId, $expectation->payload(),
                    );
                    Database::sql('SELECT * FROM hilos_account_operation_assignment WHERE id = ?', [$assignment->id]);
                    $row = Database::row();
                    self::assertIsArray($row);

                    return new DbSyncCreatedSignalData(HilosDbContext::accountOperationAssignments, (string) $assignment->id, $row);
                });
                self::assertSame($cached, AccountErasureProgressResolver::of($this->personId));
                $heldIds = array_keys($objects->toArray());
                self::assertNotContains((int) $fact->idString, $heldIds);

                $worker->deliver(new WorkerDbSyncCreatedMessageDTO($fact, 'other-node'));

                self::assertSame($heldIds, array_keys($objects->toArray()), 'Ingress must preserve the lazy retention policy');
                AccountStandingAudience::onAgentTick($worker->index());
                $frames = $this->standingFrames();
                self::assertCount(1, $frames);
                self::assertSame($before[UserPage::ACCOUNT_STANDING], $frames[0]->accountStanding);
                self::assertSame($owner === null ? [['participant' => 'delivery:mail', 'userId' => $this->personId]] : [],
                    $frames[0]->accountErasure[AccountErasureProgress::waits]);
                self::assertCount($owner === null ? count($cached->parts) : count($cached->parts) + 1,
                    $frames[0]->accountErasure[AccountErasureProgress::parts]);
                $cached = AccountErasureProgressResolver::of($this->personId);
                AccountStandingAudience::onAgentTick($worker->index());
                self::assertSame([], $this->standingFrames());
            }

            $result = $this->inOtherWriter(function () use ($fact): DbSyncCreatedSignalData {
                $assignment = Hilos::$db->accountOperationAssignments[(int) $fact->idString];
                $result = Database::transaction(DatabaseNames::PRIMARY, static fn () =>
                    Hilos::$db->accountOperationResults->actions->record($assignment, AccountOperationResultPayload::fromJson('{}')));
                Database::sql('SELECT * FROM hilos_account_operation_result WHERE id = ?', [$result->id]);
                $row = Database::row();
                self::assertIsArray($row);

                return new DbSyncCreatedSignalData(HilosDbContext::accountOperationResults, (string) $result->id, $row);
            });
            self::assertSame($cached, AccountErasureProgressResolver::of($this->personId));
            $worker->deliver(new WorkerDbSyncCreatedMessageDTO($result, 'other-node'));
            $progress = AccountErasureProgressResolver::of($this->personId);
            self::assertNotSame($cached, $progress);
            $expectedErasure = [
                AccountErasureProgress::startedAt => Hilos::$db->accountDeletions->liveOf($this->personId)->startedAt,
                AccountErasureProgress::parts => [
                    [
                        AccountErasureProgress::participant => 'personal',
                        AccountErasureProgress::owner => HilosAgentType::HILOS_USER,
                        AccountErasureProgress::userId => $this->personId,
                        AccountErasureProgress::done => false,
                    ],
                    [
                        AccountErasureProgress::participant => 'users_library',
                        AccountErasureProgress::owner => HilosAgentType::HILOS_USERS_LIBRARY,
                        AccountErasureProgress::userId => $this->personId,
                        AccountErasureProgress::done => false,
                    ],
                    [
                        AccountErasureProgress::participant => AccountErasureParticipantCatalog::SESSIONS_HOLDER,
                        AccountErasureProgress::owner => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                        AccountErasureProgress::userId => $this->personId,
                        AccountErasureProgress::done => false,
                    ],
                    [
                        AccountErasureProgress::participant => AccountErasureParticipantCatalog::DATA_EXPORT,
                        AccountErasureProgress::owner => HilosAgentType::HILOS_DATA_EXPORT,
                        AccountErasureProgress::userId => $this->personId,
                        AccountErasureProgress::done => false,
                    ],
                    [
                        AccountErasureProgress::participant => AccountErasureParticipantCatalog::LEGAL_EXPORT,
                        AccountErasureProgress::owner => HilosAgentType::HILOS_LEGAL,
                        AccountErasureProgress::userId => $this->personId,
                        AccountErasureProgress::done => false,
                    ],
                    [
                        AccountErasureProgress::participant => 'delivery:mail',
                        AccountErasureProgress::owner => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                        AccountErasureProgress::userId => $this->personId,
                        AccountErasureProgress::done => true,
                    ],
                ],
                AccountErasureProgress::waits => [],
                AccountErasureProgress::refusals => [],
            ];
            self::assertSame($expectedErasure, $progress->toArray());
            AccountStandingAudience::onAgentTick($worker->index());
            $frames = $this->standingFrames();
            self::assertCount(1, $frames);
            self::assertSame([
                AccountStandingStateSignalData::userId => $this->personId,
                AccountStandingStateSignalData::accountStanding => $before[UserPage::ACCOUNT_STANDING],
                AccountStandingStateSignalData::accountErasure => $expectedErasure,
                AccountStandingStateSignalData::accountMerge => null,
                AccountStandingStateSignalData::accountMergeAvailability => ['reserved' => false, 'available' => false],
            ], $frames[0]->toArray());
            AccountStandingAudience::onAgentTick($worker->index());
            self::assertSame([], $this->standingFrames());
        });
    }

    /** @return iterable<string, array{bool}> Changes made while no scoped reader receives facts */
    public static function changesWithoutReader(): iterable
    {
        yield 'new assignment' => [false];
        yield 'closed operation' => [true];
    }

    /** Accepted updates and deletes still repair memory when the lazy applicator holds no target row. */
    public function testAcceptedSourceUpdatesAndDeletesInvalidateProgressButUnrelatedFactsDoNot(): void
    {
        $this->openErasure();
        $this->inCardWorker(function (AccountStandingCardWorker $worker): void {
            $cached = AccountErasureProgressResolver::of($this->personId);
            foreach ([HilosDbContext::accountOperations, HilosDbContext::accountOperationAssignments,
                HilosDbContext::accountOperationResults, HilosDbContext::accountDeletions] as $key) {
                foreach ([
                    new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData($key, '999999999', []), 'other-node'),
                    new WorkerDbSyncDeletedMessageDTO(new DbSyncDeletedSignalData($key, '999999999'), 'other-node'),
                ] as $fact) {
                    $worker->deliver($fact);
                    $fresh = AccountErasureProgressResolver::of($this->personId);
                    self::assertNotSame($cached, $fresh);
                    self::assertSame($cached->toArray(), $fresh->toArray());
                    $cached = $fresh;
                }
            }
            foreach ([
                new WorkerDbSyncCreatedMessageDTO(new DbSyncCreatedSignalData(HilosDbContext::users, (string) $this->personId, []), 'other-node'),
                new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(HilosDbContext::users, '999999999', []), 'other-node'),
                new WorkerDbSyncDeletedMessageDTO(new DbSyncDeletedSignalData(HilosDbContext::users, '999999999'), 'other-node'),
            ] as $fact) {
                $worker->deliver($fact);
                self::assertSame($cached, AccountErasureProgressResolver::of($this->personId));
            }
        });
    }

    /**
     * E2e exception 1 (testing.md): the resolver surviving an agent stop is private to this
     * worker; scenario 11 recreates the process and erases the evidence. Keep the worker,
     * stop its actual agent and last scoped reader, then deliver readiness and start again.
     *
     * @param bool $close Whether the operation closes instead of gaining an assignment
     */
    #[DataProvider('changesWithoutReader')]
    public function testRestartAnswersWithProgressWrittenWhileTheWorkerHadNoReader(bool $close): void
    {
        $operation = $this->openErasure();
        $this->inCardWorker(function (AccountStandingCardWorker $worker) use ($operation, $close): void {
            $before = $this->subscribeToCardData($worker->index());
            $cached = AccountErasureProgressResolver::of($this->personId);
            $oldAgent = $worker->index();
            foreach (DemoHilosAgent::readDbCollections() as $key) {
                SourceInterestRegistry::register(SourceChange::KIND_DB, $key, SourceConsumer::page(self::ACCEPT_KEY));
            }
            $worker->deliver(new AgentStopDTO(HilosAgentType::HILOS_INDEX));
            self::assertNull($worker->index());
            self::assertNotSame($cached, AccountErasureProgressResolver::of($this->personId), 'Stop drops derived memory too');
            // A remaining scoped reader may refill the resolver before its own interest ends.
            $cached = AccountErasureProgressResolver::of($this->personId);
            $worker->releasePage(self::ACCEPT_KEY);
            foreach (DemoHilosAgent::readDbCollections() as $key) {
                self::assertSame([], SourceInterestRegistry::consumersOf(SourceChange::KIND_DB, $key));
                self::assertSame([], Hilos::$db->mountedObjectCollection($key)->toArray());
            }

            $this->inOtherWriter(function () use ($operation, $close): void {
                $current = Hilos::$db->accountOperations[$operation->id];
                if ($close) {
                    self::assertTrue($current->actions->finish($current->stage, AccountOperationOutcome::Completed));

                    return;
                }
                $expectation = new AccountOperationExpectation('delivery:mail', $this->personId, null);
                Hilos::$db->accountOperationAssignments->actions->record(
                    $current->id, $current->stage, $expectation->assignmentKey(), $expectation->assignmentOwner(),
                    $expectation->userId, $expectation->payload(),
                );
            });
            // Inspect only the derived cache while interest is absent; no DB read can repair it.
            self::assertSame($cached, AccountErasureProgressResolver::of($this->personId));
            $this->readyAndStartIndex($worker);
            self::assertNotSame($oldAgent, $worker->index());
            $after = $this->subscribeToCardData($worker->index());
            self::assertSame($before[UserPage::ACCOUNT_STANDING], $after[UserPage::ACCOUNT_STANDING]);
            if ($close) {
                self::assertNull($after[UserPage::ACCOUNT_ERASURE]);
            } else {
                self::assertSame($before[UserPage::ACCOUNT_ERASURE][AccountErasureProgress::parts],
                    $after[UserPage::ACCOUNT_ERASURE][AccountErasureProgress::parts]);
                self::assertSame([['participant' => 'delivery:mail', 'userId' => $this->personId]],
                    $after[UserPage::ACCOUNT_ERASURE][AccountErasureProgress::waits]);
            }
        });
    }

    public function testProgressAloneMovesTheCardAndClosingTheOperationClearsItsBlock(): void
    {
        $operation = $this->openErasure();
        $before = $this->subscribeToCardData();
        $wait = new AccountOperationExpectation('delivery:mail', $this->personId, null);
        Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id, $operation->stage, $wait->assignmentKey(), $wait->assignmentOwner(), $wait->userId, $wait->payload(),
        );

        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        $frames = $this->standingFrames();
        self::assertCount(1, $frames);
        self::assertSame($before[UserPage::ACCOUNT_STANDING], $frames[0]->accountStanding);
        self::assertSame(
            $before[UserPage::ACCOUNT_ERASURE][AccountErasureProgress::parts],
            $frames[0]->accountErasure[AccountErasureProgress::parts],
        );
        self::assertSame(
            [['participant' => 'delivery:mail', 'userId' => $this->personId]],
            $frames[0]->accountErasure[AccountErasureProgress::waits],
        );
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        self::assertSame([], $this->standingFrames());

        self::assertTrue($operation->actions->finish($operation->stage, AccountOperationOutcome::Completed));
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        $frames = $this->standingFrames();
        self::assertCount(1, $frames);
        self::assertSame($before[UserPage::ACCOUNT_STANDING], $frames[0]->accountStanding);
        self::assertNull($frames[0]->accountErasure);
        self::assertNull($this->subscribeToCardData()[UserPage::ACCOUNT_ERASURE]);
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        self::assertSame([], $this->standingFrames());
    }

    public function testTheCardDoesNotHideABrokenExpectationPayload(): void
    {
        $operation = $this->openErasure();
        $bad = Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            $operation->stage,
            'expect:broken',
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY),
            $this->personId,
            AccountOperationAssignmentPayload::fromJson('{}'),
        );

        $this->expectException(InvalidFormatException::class);
        try {
            $this->subscribeToCardData();
        } finally {
            Database::sqlRun('DELETE FROM hilos_account_operation_assignment WHERE id = ?', [$bad->id]);
            SourceChangeBus::publish(SourceChange::dbDeleted(HilosDbContext::accountOperationAssignments, (string) $bad->id));
        }
    }

    public function testUnsubscribeAndResetReleaseTheShownErasureMemory(): void
    {
        $this->openErasure();
        $this->subscribeToCardData();
        $memory = new ReflectionProperty(AccountStandingAudience::class, 'shownErasure');
        self::assertArrayHasKey(self::ACCEPT_KEY, $memory->getValue());

        new UserPage(new DemoHilosAgent())->onUnsubscribe(self::ACCEPT_KEY);

        self::assertArrayNotHasKey(self::ACCEPT_KEY, $memory->getValue());
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        self::assertSame([], $this->standingFrames());
        $this->subscribeToCardData();
        self::assertArrayHasKey(self::ACCEPT_KEY, $memory->getValue());
        AccountStandingAudience::reset();
        self::assertSame([], $memory->getValue());
    }

    public function testABlockAndADeletionUnderTheOpenCardReachItOnTheNextTick(): void
    {
        $this->subscribeToCard();
        $agent = new DemoHilosAgent();

        AccountStandingAudience::onAgentTick($agent);
        self::assertSame([], $this->standingFrames(), 'Nothing moved, nothing is sent');

        Hilos::$db->users[$this->personId]->actions->setBlock(true);
        AccountStandingAudience::onAgentTick($agent);
        $frames = $this->standingFrames();
        self::assertCount(1, $frames);
        self::assertSame($this->personId, $frames[0]->userId);
        self::assertSame('blocked', $frames[0]->accountStanding[AccountStanding::shown]);

        $request = Hilos::$db->accountDeletions->actions->request(
            $this->personId,
            TimeHelper::nowMs() + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
        );
        AccountStandingAudience::onAgentTick($agent);
        $frames = $this->standingFrames();
        self::assertCount(1, $frames);
        self::assertSame('blocked', $frames[0]->accountStanding[AccountStanding::shown], 'The block takes more away than the deletion');
        self::assertIsInt($frames[0]->accountStanding[AccountStanding::deletionEffectiveAt]);

        $request->actions->cancel();
        Hilos::$db->users[$this->personId]->actions->setBlock(false);
        AccountStandingAudience::onAgentTick($agent);
        $frames = $this->standingFrames();
        self::assertCount(1, $frames);
        self::assertSame('none', $frames[0]->accountStanding[AccountStanding::shown]);
    }

    /**
     * The person row deleted under the open card sends no frame on the next tick: the live 404 of
     * that row closes the card, and a 'none' frame could overtake it and bring the buttons back
     * (HIL-1957).
     */
    public function testThePersonRowDeletedUnderTheOpenCardSendsNoFrameOnTheNextTick(): void
    {
        $this->subscribeToCard();
        $agent = new DemoHilosAgent();

        Hilos::$db->users[$this->personId]->actions->setBlock(true);
        AccountStandingAudience::onAgentTick($agent);
        $frames = $this->standingFrames();
        self::assertCount(1, $frames);
        self::assertSame('blocked', $frames[0]->accountStanding[AccountStanding::shown]);

        Hilos::$db->users[$this->personId]->actions->delete();
        AccountStandingAudience::onAgentTick($agent);
        self::assertSame([], $this->standingFrames(), 'A gone person gets no frame, not even none');
    }

    /**
     * A merge written under the open card reaches it on the next tick as the merged standing, naming
     * where the account went (HIL-1292); the chain growing past the survivor and the survivor's
     * rename move the card the same way, with nobody telling the resolver to forget anything.
     */
    public function testAMergeUnderTheOpenCardReachesItWithWhereTheAccountWent(): void
    {
        $this->subscribeToCard();
        $agent = new DemoHilosAgent();
        $survivor = Hilos::$db->users->actions->createWithName('Lesya Ukrainka');
        $survivorId = (int) $survivor->id;

        // The tombstone first, the block second - the order the merge writes them in.
        Hilos::$db->userMerges->actions->add($this->personId, $survivorId);
        Hilos::$db->users[$this->personId]->actions->setBlock(true);
        AccountStandingAudience::onAgentTick($agent);
        $frames = $this->standingFrames();
        self::assertCount(1, $frames);
        self::assertSame('merged', $frames[0]->accountStanding[AccountStanding::shown]);
        self::assertTrue($frames[0]->accountStanding[AccountStanding::blocked], 'The block stays named under the merge');
        self::assertSame($survivorId, $frames[0]->accountStanding[AccountStanding::mergedInto]);
        self::assertSame('Lesya Ukrainka', $frames[0]->accountStanding[AccountStanding::mergedIntoName]);

        $survivor->actions->rename('Larysa Kosach');
        AccountStandingAudience::onAgentTick($agent);
        $frames = $this->standingFrames();
        self::assertCount(1, $frames);
        self::assertSame('Larysa Kosach', $frames[0]->accountStanding[AccountStanding::mergedIntoName]);

        $thirdId = (int) Hilos::$db->users->actions->createWithName('Olha Kobylianska')->id;
        Hilos::$db->userMerges->actions->add($survivorId, $thirdId);
        AccountStandingAudience::onAgentTick($agent);
        $frames = $this->standingFrames();
        self::assertCount(1, $frames);
        self::assertSame($thirdId, $frames[0]->accountStanding[AccountStanding::mergedInto], 'The card follows the chain to its live end');
        self::assertSame('Olha Kobylianska', $frames[0]->accountStanding[AccountStanding::mergedIntoName]);
    }

    public function testTheListNarrowedToALapsedDocumentHoldsExactlyThePeopleTheLegalRootCounts(): void
    {
        $other = (int) Hilos::$db->users->actions->createWithName('Lesya Ukrainka')->id;
        foreach ([[$this->personId, 'first'], [$other, 'first'], [$other, 'second']] as [$userId, $revision]) {
            Database::sqlRun(
                'INSERT INTO hilos_legal_acceptance (user_id, document, revision_id, accepted_at) VALUES (?, ?, ?, ?)',
                [$userId, LegalDocument::TERMS->value, $revision, 1767312000000],
            );
        }
        AccountStandingCardCatalogHilos::initBrowser();
        try {
            $table = new HilosUsersTable();
            $ids = array_map(
                static fn ($row): int => (int) $row->id,
                $table->getPage(new TableQueryDTO(limit: 500, filter: [AbstractHilosUsersTable::FILTER_LAPSED => 'terms']))->rows,
            );
            $counted = LegalTally::all(LegalStandingResolver::today())[LegalDocument::TERMS->value]->lapsed;
            $none = $table->getPage(new TableQueryDTO(limit: 500, filter: [AbstractHilosUsersTable::FILTER_LAPSED => 'unknown']))->rows;
        } finally {
            Database::sqlRun('DELETE FROM hilos_legal_acceptance WHERE user_id IN (?, ?)', [$this->personId, $other]);
            Hilos::initBrowser();
        }

        self::assertContains($this->personId, $ids);
        self::assertNotContains($other, $ids);
        self::assertCount($counted, $ids);
        self::assertSame([], $none, 'A document nobody declared narrows to nobody');
    }

    /** @param Closure(): void $scenario Worker scenario with no inherited consumers or readiness */
    private function inIsolatedSourceInterests(Closure $scenario): void
    {
        $consumers = new ReflectionProperty(SourceInterestRegistry::class, 'byConsumer');
        $states = new ReflectionProperty(SourceInterestRegistry::class, 'states');
        $mode = new ReflectionProperty(SourceInterestRegistry::class, 'readsWhatIsDelivered');
        $previousConsumers = $consumers->getValue();
        $previousStates = $states->getValue();
        $previousMode = $mode->getValue();
        try {
            $consumers->setValue(null, []);
            $states->setValue(null, []);
            SourceInterestRegistry::readsWhatItMounts();
            $scenario();
        } finally {
            $consumers->setValue(null, $previousConsumers);
            $states->setValue(null, $previousStates);
            $mode->setValue(null, $previousMode);
        }
    }

    /** @param Closure(AccountStandingCardWorker): void $scenario Scenario including any index stop/restart interval */
    private function inCardWorker(Closure $scenario): void
    {
        $this->inIsolatedSourceInterests(function () use ($scenario): void {
            $worker = $this->startCardWorker();
            try {
                $scenario($worker);
            } finally {
                $this->stopCardWorker($worker);
            }
        });
    }

    /** @return AccountStandingCardWorker Worker using the normal lazy mounts and real index declarations */
    private function startCardWorker(): AccountStandingCardWorker
    {
        $worker = new AccountStandingCardWorker();
        SourceInterestRegistry::readsWhatIsDelivered();
        Hilos::$db->declareProcessWideReads();
        Hilos::$rt->declareProcessWideReads();
        foreach (Hilos::$db->processWideReadKeys() as $key) {
            SourceInterestRegistry::markReady(SourceChange::KIND_DB, $key);
        }
        foreach (Hilos::$rt->collectionKeys() as $key) {
            SourceInterestRegistry::markReady(SourceChange::KIND_RT, $key);
        }
        $this->readyAndStartIndex($worker);

        return $worker;
    }

    /** @param AccountStandingCardWorker $worker Same process receiving fresh readiness before each start */
    private function readyAndStartIndex(AccountStandingCardWorker $worker): void
    {
        foreach (DemoHilosAgent::readDbCollections() as $key) {
            SourceInterestRegistry::register(SourceChange::KIND_DB, $key, SourceConsumer::agent(HilosAgentType::HILOS_INDEX));
            $worker->deliver(new WorkerDbInterestReadyMessageDTO($key));
        }
        $worker->deliver(new AgentStartDTO(HilosAgentType::HILOS_INDEX, [self::ACCEPT_KEY]));
        self::assertInstanceOf(DemoHilosAgent::class, $worker->index());
        foreach ([...UserPage::READS_DB, ...Hilos::$browser->dbSourceKeysOfPage(UserPage::PAGE)] as $key) {
            SourceInterestRegistry::register(SourceChange::KIND_DB, $key, SourceConsumer::page(self::ACCEPT_KEY));
            $worker->deliver(new WorkerDbInterestReadyMessageDTO($key));
        }
        foreach ([...UserPage::READS_RT, ...Hilos::$browser->rtSourceKeysOfPage(UserPage::PAGE)] as $key) {
            SourceInterestRegistry::register(SourceChange::KIND_RT, $key, SourceConsumer::page(self::ACCEPT_KEY));
            SourceInterestRegistry::markReady(SourceChange::KIND_RT, $key);
        }
    }

    /** @param AccountStandingCardWorker $worker Worker whose scoped and process-wide fixture interests end */
    private function stopCardWorker(AccountStandingCardWorker $worker): void
    {
        SourceInterestRegistry::readsWhatItMounts();
        $worker->releasePage(self::ACCEPT_KEY);
        $worker->deliver(new AgentStopDTO(HilosAgentType::HILOS_INDEX));
        foreach ([...Hilos::$db->processWideReadKeys(), ...Hilos::$rt->collectionKeys()] as $key) {
            SourceInterestRegistry::releaseConsumer(SourceConsumer::feature($key));
        }
    }

    /**
     * Isolates another writer's ORM and mirrors: its committed actions must not repair the
     * reader's process memory before a DB_SYNC frame reaches that reader.
     *
     * @template T
     * @param Closure(): T $write Lawful owner action in a separate context
     * @return T The committed fact or action result
     */
    private function inOtherWriter(Closure $write): mixed
    {
        $reader = Hilos::$db;
        $mirrors = new ReflectionProperty(SourceChangeBus::class, 'mirrors')->getValue();
        $reactions = new ReflectionProperty(SourceChangeBus::class, 'reactions')->getValue();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceInterestRegistry::readsWhatItMounts();
        try {
            Hilos::$db = new ChatDbContext();
            Hilos::$db->configure();

            return $write();
        } finally {
            Hilos::$db = $reader;
            SourceChangeBus::reset();
            foreach ([...$mirrors, ...$reactions] as $subscriber) {
                SourceChangeBus::subscribe($subscriber);
            }
            SourceInterestRegistry::readsWhatIsDelivered();
        }
    }

    /**
     * @param AccountStandingCardWorker $worker Worker receiving the repair through its normal ingress
     * @param int $resultId Preparation proof to corrupt or restore
     * @param string $payloadJson Repair fixture, never a production result mutation
     */
    private function repairPreparationProof(AccountStandingCardWorker $worker, int $resultId, string $payloadJson): void
    {
        Database::sqlRun('UPDATE hilos_account_operation_result SET payload_json = ? WHERE id = ?', [$payloadJson, $resultId]);
        $saved = Database::sql('SELECT * FROM hilos_account_operation_result WHERE id = ?', [$resultId])->row();
        $worker->deliver(new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
            HilosDbContext::accountOperationResults, (string) $resultId, $saved,
        ), 'other-node'));
    }

    /** @param AccountOperation $operation Preparation addressed to both actual personal owners */
    private function preparePair(AccountOperation $operation): void
    {
        foreach (['prepare_survivor', 'prepare_loser'] as $duty) {
            $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, 'preparing', $duty);
            $owner = $this->personAgent($assignment->userId);
            $this->underAgent($owner, fn () => $owner->prepareForMerge($assignment->id));
        }
    }

    /** @param AccountStandingCardWorker $worker Real worker serving the declared people-list sources */
    private function subscribeToUsersList(AccountStandingCardWorker $worker): void
    {
        $pageConsumer = SourceConsumer::page(self::ACCEPT_KEY);
        $declared = Hilos::$browser->dbSourceKeysOfPage(UsersPage::PAGE);
        foreach ([...UsersPage::READS_DB, ...$declared] as $source) {
            SourceInterestRegistry::register(SourceChange::KIND_DB, $source, $pageConsumer);
            $worker->deliver(new WorkerDbInterestReadyMessageDTO($source));
        }
        foreach (Hilos::$browser->rtSourceKeysOfPage(UsersPage::PAGE) as $source) {
            SourceInterestRegistry::register(SourceChange::KIND_RT, $source, $pageConsumer);
            SourceInterestRegistry::markReady(SourceChange::KIND_RT, $source);
        }
        foreach ([HilosDbContext::accountOperations, HilosDbContext::accountOperationAssignments,
            HilosDbContext::accountOperationResults, HilosDbContext::accountDeletions, HilosDbContext::userMerges] as $source) {
            self::assertContains($source, $declared);
            self::assertContains($pageConsumer, SourceInterestRegistry::consumersOf(SourceChange::KIND_DB, $source));
        }
        $subscription = new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, UsersPage::PAGE, [],
            [ChatTableContext::hilosUsers => new TableWindowDescriptorDTO(limit: 500)]);
        Hilos::$sr->subscribeToPage(UsersPage::PAGE, $subscription);
        $router = new PageSignalRouter(new HilosPageFactory($worker->index(), Hilos::class), new ActionRouteConfig());
        $router->dispatchPageSubscribe($subscription, 'websocket', UsersPage::PAGE);
        self::assertNotNull(Hilos::$sr->getTableViewports(self::ACCEPT_KEY)[ChatTableContext::hilosUsers] ?? null);
        $this->listDeltas();
        Hilos::$browser->flushToSignalRouter();
        $this->listDeltas();
    }

    /** @return list<array<string, mixed>> Serialized row deltas from the real list viewport */
    private function listDeltas(): array
    {
        $deltas = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if (!$signal->data instanceof WebSocketSignalData) {
                continue;
            }
            self::assertNotSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signal->signalName->getName());
            if ($signal->data->data instanceof TableViewportDeltaDTO) {
                self::assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
                self::assertSame(ChatTableContext::hilosUsers, $signal->data->data->tableKey);
                $deltas[] = $signal->data->data->toArray();
            }
        }

        return $deltas;
    }

    /**
     * @param list<int> $expected People the metadata source must repaint, with no operation or result id among them
     * @param bool $reserved Expected reservation fact
     * @param bool $available Expected availability fact
     */
    private function assertListOccupancy(array $expected, bool $reserved, bool $available): void
    {
        $deltas = $this->listDeltas();
        self::assertEqualsCanonicalizing($expected, array_column($deltas, TableViewportDeltaDTO::rowKey));
        foreach ($deltas as $delta) {
            self::assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta[TableViewportDeltaDTO::kind]);
            self::assertSame(['reserved' => $reserved, 'available' => $available],
                $delta['row']['slots']['merge']['accountMergeAvailability']);
            self::assertArrayHasKey('connections', $delta['row']['slots']);
        }
    }

    /** @return AccountOperation Real opening naming the card's account as survivor */
    private function openMerge(): AccountOperation
    {
        $loser = Hilos::$db->users->actions->createWithName('Merge loser of the card');

        return AccountMergeOpening::open($this->personId, (int) $loser->id, new AccountMergeChoice(null, null, null, null));
    }

    /**
     * @param AccountOperation $operation Merge which waits for an absent project owner
     * @return int Stored wait assignment id
     */
    private function recordMergeWait(AccountOperation $operation): int
    {
        $wait = new AccountOperationExpectation('project:card', $operation->loserUserId, null);

        return Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id, $operation->stage, $wait->assignmentKey(), $wait->assignmentOwner(), $wait->userId, $wait->payload(),
        )->id;
    }

    /** @param AccountOperation $operation Merge completed without starting by both real personal release steps */
    private function cancelMerge(AccountOperation $operation): void
    {
        $this->sessionsLibrary();
        $duties = ExecutionContext::run(new ExecutionFrame(agentId: HilosAgentType::HILOS_SESSIONS_LIBRARY),
            static function () use ($operation): array {
                AccountMergeCoordinator::cancel($operation);

                return AccountMergeCoordinator::begin($operation);
            });
        foreach ($duties as $assignment) {
            $owner = $this->personAgent($assignment->userId);
            $this->underAgent($owner, fn () => $owner->releaseMergePreparation($assignment->id));
        }
        $this->sessionsLibrary();
        ExecutionContext::run(new ExecutionFrame(agentId: HilosAgentType::HILOS_SESSIONS_LIBRARY),
            static fn () => AccountMergeCoordinator::begin($operation));
        self::assertSame('not_started',
            Database::sql('SELECT outcome FROM hilos_account_operation WHERE id = ?', [$operation->id])->row()['outcome']);
    }

    /**
     * @param string $key Mounted source collection
     * @param string $table Its physical fixture table
     * @param int $id Newly committed primary id
     * @return DbSyncCreatedSignalData Independently read persisted row at the sync boundary
     */
    private function createdMergeFact(string $key, string $table, int $id): DbSyncCreatedSignalData
    {
        $row = Database::sql('SELECT * FROM `' . $table . '` WHERE id = ?', [$id])->row();
        self::assertIsArray($row);

        return new DbSyncCreatedSignalData($key, (string) $id, $row);
    }

    /**
     * @param ?DemoHilosAgent $agent Worker index, or a standalone index with the same topology
     * @return list<array{name: string, data: array<string, mixed>}> Frames of the real page subscription dispatcher
     */
    private function routedCardFrames(?DemoHilosAgent $agent = null): array
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $subscription = new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, UserPage::PAGE, ['userId' => (string) $this->personId]);
        Hilos::$sr->subscribeToPage(UserPage::PAGE, $subscription);
        $router = new PageSignalRouter(new HilosPageFactory($agent ?? new DemoHilosAgent(), Hilos::class), new ActionRouteConfig());
        $router->dispatchPageSubscribe($subscription, 'websocket', UserPage::PAGE);
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                $frames[] = ['name' => $signal->signalName->getName(), 'data' => $signal->data->data->toArray()];
            }
        }

        return $frames;
    }

    /**
     * @param ?DemoHilosAgent $agent Real worker index, when the scenario drives one
     * @return array<string, mixed> Complete first picture, carried by exactly one page_response
     */
    private function mergeCardData(?DemoHilosAgent $agent = null): array
    {
        $frames = $this->routedCardFrames($agent);
        self::assertCount(1, $frames);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frames[0]['name']);
        $data = $frames[0]['data'][PageResponseSignalData::payload][PagePayload::data];
        self::assertArrayHasKey('accountMerge', $data);
        self::assertArrayHasKey('accountMergeAvailability', $data);

        return $data;
    }

    /**
     * @param bool $started Whether the request has begun erasure
     * @return AccountOperation An open erasure with the installation's real framework census
     */
    private function openErasure(bool $started = true): AccountOperation
    {
        $request = Hilos::$db->accountDeletions->actions->request(
            $this->personId,
            TimeHelper::nowMs() + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
        );
        $operation = AccountErasureOpening::open($request, AccountErasureParticipantCatalog::framework())->operation;
        self::assertNotNull($operation);
        if ($started) {
            self::assertTrue($request->actions->start());
        }

        return $operation;
    }

    /**
     * Opens a connection for an administrator.
     */
    private function connectAdmin(): void
    {
        $admin = Hilos::$db->users->actions->createWithName('Administrator of the card');
        $admin->actions->setAdmin(true);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', $this->nameWithDataSet()), 0, 32));
        $session->actions->bindUser((int) $admin->id);
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, (int) $admin->id, $session->token, (int) $session->id);
    }

    /**
     * Subscribes the administrator to the card of the person and reads the standing its answer carries.
     *
     * @return array<string, mixed> The standing in the page data
     */
    private function subscribeToCard(): array
    {
        return $this->subscribeToCardData()[UserPage::ACCOUNT_STANDING];
    }

    /**
     * @param ?DemoHilosAgent $agent Actual worker instance, or a standalone page fixture
     * @return array<string, mixed> The first card response's complete page data
     */
    private function subscribeToCardData(?DemoHilosAgent $agent = null): array
    {
        $agent ??= new DemoHilosAgent();
        $params = ['userId' => (string) $this->personId];
        HilosFacade::$sr->subscribeToPage(UserPage::PAGE, new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, UserPage::PAGE, $params));
        ExecutionContext::run(new ExecutionFrame(acceptKey: self::ACCEPT_KEY), static function () use ($params, $agent): void {
            new UserPage($agent)->onSubscribe(self::ACCEPT_KEY, new PageRouteParams($params));
        });
        $pageData = null;
        while (($signal = HilosFacade::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE || !$signal->data instanceof WebSocketSignalData) {
                continue;
            }
            $data = $signal->data->data->toArray()[PageResponseSignalData::payload][PagePayload::data] ?? [];
            $pageData ??= $data;
        }
        self::assertIsArray($pageData, 'The card did not answer');
        self::assertIsArray($pageData[UserPage::ACCOUNT_STANDING]);
        self::assertArrayHasKey(UserPage::ACCOUNT_ERASURE, $pageData);

        return $pageData;
    }

    /**
     * @return list<AccountStandingStateSignalData> Standing frames queued to the administrator, in order
     */
    private function standingFrames(): array
    {
        $frames = [];
        while (($signal = HilosFacade::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== HilosSignalConstants::HILOS_ACCOUNT_STANDING_STATE) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
            self::assertInstanceOf(AccountStandingStateSignalData::class, $signal->data->data);
            $frames[] = $signal->data->data;
        }

        return $frames;
    }
}

/** Router answering from the chat demo's topology rather than the framework's bare one. */
final class AccountStandingCardRouter extends SignalRouter
{
    /**
     * @return class-string<Hilos> The chat demo's facade
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }
}

/** Terms whose second substantial revision has been in force since March. */
final class AccountStandingCardCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture declarations */
    public static function revisions(): array
    {
        return [
            LegalDocument::TERMS->value => [
                new LegalRevision(LegalDocument::TERMS, 'first', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
                new LegalRevision(LegalDocument::TERMS, 'second', '2026-02-01', 1, LegalSignificance::SUBSTANTIAL, '2026-03-01', []),
            ],
        ];
    }
}

/** Binds the fixture catalog, the chat's own having no revision anybody could be past. */
abstract class AccountStandingCardCatalogHilos extends HilosFacade
{
    protected const ?string LEGAL_CATALOG = AccountStandingCardCatalog::class;
}
