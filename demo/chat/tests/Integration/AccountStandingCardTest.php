<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Closure;
use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Users\UserPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\HilosUser\HilosUsersTable;
use Hilos\Auth\AccountOperation\AccountErasureOpening;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
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
use ReflectionProperty;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Hilos as HilosFacade;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\LegalStandingResolver;
use Hilos\Legal\LegalTally;
use Hilos\Pages\Users\AccountStandingAudience;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
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
        $this->personId = (int) Hilos::$db->users->actions->createWithName('Taras Shevchuk')->id;
        $this->connectAdmin();
    }

    protected function tearDown(): void
    {
        AccountStandingAudience::reset();
        AccountStandingResolver::forgetAll();
        AccountErasureProgressResolver::forgetAll();
        Hilos::initBrowser();
        Hilos::$rt->connections->actions->clear();
        TruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        HilosFacade::$sr = null;
        parent::tearDown();
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
                self::assertSame([$agentConsumer], SourceInterestRegistry::consumersOf(SourceChange::KIND_DB, $key));
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
            self::assertNotSame($cached, AccountErasureProgressResolver::of($this->personId));
            self::assertSame($cached->toArray(), AccountErasureProgressResolver::of($this->personId)->toArray());
            AccountStandingAudience::onAgentTick($worker->index());
            self::assertSame([], $this->standingFrames(), 'Results do not complete a part before the end predicate exists');
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
        Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            $operation->stage,
            'expect:broken',
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY),
            $this->personId,
            AccountOperationAssignmentPayload::fromJson('{}'),
        );

        $this->expectException(InvalidFormatException::class);
        $this->subscribeToCardData();
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
                [$userId, LegalDocument::TERMS->value, $revision, '2026-01-02 00:00:00'],
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
