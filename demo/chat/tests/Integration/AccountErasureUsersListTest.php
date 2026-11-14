<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Users\UsersPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Auth\AccountOperation\AccountErasureOpening;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageResender;
use Hilos\Core\Page\PageResendOutcome;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableProgressSignalData;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Object\Collection\AccountOperations as ObjectAccountOperations;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Pages\Users\AccountStandingAudience;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Users\AbstractHilosUsersTable;
use Hilos\Tables\Users\HilosUserTableRow;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountErasureProgress;
use Hilos\Users\AccountErasureProgressResolver;
use Hilos\Users\AccountStandingResolver;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * The list of people shows a begun erasure as a bar of work under each account of its circle (HIL-1955).
 *
 * On the chat demo's real people page, its real table and the index agent's real audience: the
 * first window of the list carries the bars of the picture last sent, the agent's tick sends each
 * open list only what moved - a bar that appeared or changed, a bar that ended - a page it sends
 * again in the middle of that round carries that tick's picture, and the rows of the list, their
 * order and their count are those it has without the erasure. A person merged into the one being
 * erased stands in the circle, so both rows get the same bar.
 *
 * The chat suite keeps its rows between cases, so a window's snapshot also names the erasures earlier
 * cases left open; the snapshot is read for this case's two accounts. Those erasures do not move,
 * so the frames a tick sends are this case's alone and are read whole.
 */
final class AccountErasureUsersListTest extends IntegrationTestCase
{
    private const string ACCEPT_KEY = 'account-erasure-users-list';
    private const string TEST_AGENT = 'account-erasure-users-list-test';

    private int $personId;

    private int $foldedId;

    private string $foldedName;

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::$sr = new AccountErasureUsersListRouter();
        Hilos::initBrowser();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        TruthSourceRegistry::register(HilosDbContext::accountDeletions, TruthSourceKeys::all(), self::TEST_AGENT);
        TruthSourceRegistry::register(HilosDbContext::userMerges, TruthSourceKeys::all(), self::TEST_AGENT);
        foreach ([HilosDbContext::accountOperations, HilosDbContext::accountOperationAssignments, HilosDbContext::accountOperationResults] as $key) {
            TruthSourceRegistry::register($key, TruthSourceKeys::all(), self::TEST_AGENT);
        }
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->connections->actions->clear();
        AccountStandingAudience::reset();
        AccountStandingResolver::forgetAll();
        $this->closeOpenErasures();
        AccountErasureProgressResolver::forgetAll();
        $this->personId = (int) Hilos::$db->users->actions->createWithName('Taras Shevchuk')->id;
        // Searched for by name, and the suite keeps the people of earlier cases: one name a case.
        $this->foldedName = 'Ivan Franko ' . substr(hash('sha256', $this->nameWithDataSet()), 0, 8);
        $this->foldedId = (int) Hilos::$db->users->actions->createWithName($this->foldedName)->id;
        Hilos::$db->userMerges->actions->add($this->foldedId, $this->personId);
    }

    protected function tearDown(): void
    {
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        AccountStandingAudience::reset();
        AccountStandingResolver::forgetAll();
        AccountErasureProgressResolver::forgetAll();
        Hilos::initBrowser();
        Hilos::$rt->connections->actions->clear();
        TruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testTheFirstWindowCarriesTheBarOfEveryAccountOfTheCircleAndTheRowsItHasWithoutTheErasure(): void
    {
        $this->connect(admin: true);
        $without = $this->subscribeToList();
        self::assertSame([], $this->ownBars($without));
        new UsersPage(new DemoHilosAgent())->onUnsubscribe(self::ACCEPT_KEY);

        $this->openErasure();
        $with = $this->subscribeToList();

        self::assertSame($without[TableWindowSignalData::rows], $with[TableWindowSignalData::rows]);
        self::assertSame($without[TableWindowSignalData::totalCount], $with[TableWindowSignalData::totalCount]);
        self::assertSame($this->expectedBars(), $this->ownBars($with));
        self::assertNotSame([], $with[TableWindowSignalData::rows]);
        foreach ($with[TableWindowSignalData::rows] as $row) {
            self::assertSame(
                [AbstractHilosUsersTable::SLOT_USER, AbstractHilosUsersTable::SLOT_CONNECTIONS, AbstractHilosUsersTable::SLOT_MERGE],
                array_keys($row[PagePayload::slots]),
            );
            self::assertArrayHasKey(HilosUserTableRow::openSessionCount, $row[PagePayload::slots][AbstractHilosUsersTable::SLOT_CONNECTIONS]);
        }
    }

    public function testErasureBeginningUnderTheOpenListSendsOneBarPerAccountOfTheCircleOnTheNextTick(): void
    {
        $this->connect(admin: true);
        $this->subscribeToList();
        $this->settleFanOut();

        $this->openErasure();
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        self::assertSame([], Hilos::$browser?->flushToSignalRouter());
        $frames = $this->queuedFrames();

        self::assertSame($this->expectedBars(), $this->bars($frames));
        self::assertSame([SignalTypeConstants::TABLE_PROGRESS, SignalTypeConstants::TABLE_PROGRESS], array_column($frames, 'name'));
    }

    public function testAnUnchangedErasureSendsNothingOnTheNextTick(): void
    {
        $this->connect(admin: true);
        $this->subscribeToList();
        $this->openErasure();
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        self::assertCount(2, $this->bars($this->queuedFrames()));

        AccountStandingAudience::onAgentTick(new DemoHilosAgent());

        self::assertSame([], $this->queuedFrames());
    }

    public function testAMoveOfTheErasureReplacesTheBarOfEachAccountAndMovesNoRow(): void
    {
        $operation = $this->openErasure();
        $this->connect(admin: true);
        $this->subscribeToList();
        $this->settleFanOut();

        $this->recordWait($operation);
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        self::assertSame([], Hilos::$browser?->flushToSignalRouter());
        $frames = $this->queuedFrames();

        $bars = $this->bars($frames);
        self::assertSame($this->expectedBars(), $bars);
        self::assertSame([['participant' => 'delivery:mail', 'userId' => $this->personId]], $bars[0]['detail'][AccountErasureProgress::waits]);
        self::assertSame([SignalTypeConstants::TABLE_PROGRESS, SignalTypeConstants::TABLE_PROGRESS], array_column($frames, 'name'));
    }

    public function testAPageSentAgainInTheMiddleOfTheTickCarriesThePictureOfThatTick(): void
    {
        $this->connect(admin: true);
        $this->subscribeToList();
        $operation = $this->openErasure();
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        self::assertCount(2, $this->bars($this->queuedFrames()));
        $this->oweThePageWhole();

        self::assertTrue($operation->actions->finish($operation->stage, AccountOperationOutcome::Completed));
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        $frames = $this->queuedFrames();

        self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($frames, 'name'));
        self::assertSame([], $this->ownBars($this->pageWindow($frames)));
    }

    public function testAPageSentAgainInTheMiddleOfTheTickCarriesTheNewBlockOfAMovedErasure(): void
    {
        $this->connect(admin: true);
        $this->subscribeToList();
        $operation = $this->openErasure();
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        $sent = $this->bars($this->queuedFrames());
        self::assertCount(2, $sent);
        $this->oweThePageWhole();

        $this->recordWait($operation);
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        $frames = $this->queuedFrames();

        self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($frames, 'name'));
        $bars = $this->ownBars($this->pageWindow($frames));
        self::assertSame($this->expectedBars(), $bars);
        self::assertNotSame($sent, $bars);
        self::assertSame([['participant' => 'delivery:mail', 'userId' => $this->personId]], $bars[0]['detail'][AccountErasureProgress::waits]);
    }

    public function testAClosedErasureEndsTheBarOfEveryAccount(): void
    {
        $operation = $this->openErasure();
        $this->connect(admin: true);
        $this->subscribeToList();

        self::assertTrue($operation->actions->finish($operation->stage, AccountOperationOutcome::Completed));
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());

        self::assertSame([$this->endedBar($this->foldedId), $this->endedBar($this->personId)], $this->bars($this->queuedFrames()));
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        self::assertSame([], $this->queuedFrames());
    }

    public function testADeletedAccountRowGoesAsTheUsualRemovalAndItsBarEndsOnTheNextTick(): void
    {
        $this->openErasure();
        $this->connect(admin: true);
        $this->subscribeToList();
        $viewport = new TableViewportSubscription(
            tableKey: ChatTableContext::hilosUsers,
            filter: [TableConstants::FILTER_KEY_SEARCH => $this->foldedName],
            limit: 10,
        );
        Hilos::$sr->setTableViewport(self::ACCEPT_KEY, $viewport);
        self::assertTrue(Hilos::$browser?->sendTableWindow(UsersPage::PAGE, self::ACCEPT_KEY, $viewport));
        $window = $this->frameNamed($this->queuedFrames(), SignalTypeConstants::TABLE_WINDOW);
        self::assertInstanceOf(TableWindowSignalData::class, $window);
        self::assertSame([$this->foldedId], array_column($window->rows, PagePayload::rowKey));

        // The account's final: its tombstone and then its row go, the circle stays as the operation fixed it.
        Database::sqlRun('DELETE FROM `hilos_user_merge` WHERE `user_id` = ?', [$this->foldedId]);
        Hilos::$db->users[$this->foldedId]->actions->delete();
        Hilos::$browser?->record(SourceChange::dbDeleted(HilosDbContext::users, (string) $this->foldedId));
        self::assertSame([], Hilos::$browser?->flushToSignalRouter());
        $frames = $this->queuedFrames();

        $delta = $this->frameNamed($frames, SignalTypeConstants::TABLE_VIEWPORT_DELTA);
        self::assertInstanceOf(TableViewportDeltaDTO::class, $delta);
        self::assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta->kind);
        self::assertSame($this->foldedId, $delta->rowKey);
        self::assertSame([], $this->bars($frames));
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        self::assertSame([$this->endedBar($this->foldedId)], $this->bars($this->queuedFrames()));
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        self::assertSame([], $this->queuedFrames());
    }

    public function testAWindowServedAgainCarriesTheBarsLastSentAndTheTickBringsItWhatMovedSince(): void
    {
        $this->connect(admin: true);
        $this->subscribeToList();
        $operation = $this->openErasure();
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        $sent = $this->bars($this->queuedFrames());
        self::assertCount(2, $sent);

        $this->recordWait($operation);
        $again = $this->subscribeToList();

        self::assertSame($sent, $this->ownBars($again));
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        self::assertSame($this->expectedBars(), $this->bars($this->queuedFrames()));
    }

    public function testAViewerGetsTheDetailWithTheNumbersOpenAndNoNamesOrHiddenMarks(): void
    {
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);
        $operation = $this->openErasure();
        $this->connect(admin: false);

        $first = $this->ownBars($this->subscribeToList());
        $this->recordWait($operation);
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        $live = $this->bars($this->queuedFrames());

        self::assertCount(2, $first);
        self::assertSame($this->expectedBars(), $live);
        foreach ([...$first, ...$live] as $bar) {
            self::assertSame(count($bar['detail'][AccountErasureProgress::parts]), $bar['total']);
            self::assertSame(
                [$this->foldedId, $this->personId],
                array_values(array_unique(array_column($bar['detail'][AccountErasureProgress::parts], AccountErasureProgress::userId))),
            );
        }
        $sent = json_encode([$first, $live], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(HiddenValue::KEY, $sent);
        self::assertStringNotContainsString('Taras Shevchuk', $sent);
        self::assertStringNotContainsString('Ivan Franko', $sent);
    }

    /** Closes every open erasure: the suite keeps rows between cases, and the resolver's begun() reads all open erasures of the data set. */
    private function closeOpenErasures(): void
    {
        $afterId = 0;
        do {
            $page = Hilos::$db->accountOperations->openPage(AccountOperationKind::Erasure, $afterId, ObjectAccountOperations::PAGE_LIMIT_MAX);
            foreach ($page as $operationId => $operation) {
                $afterId = (int) $operationId;
                self::assertTrue($operation->actions->finish($operation->stage, AccountOperationOutcome::Completed));
            }
        } while (count($page) === ObjectAccountOperations::PAGE_LIMIT_MAX);
    }

    /**
     * @return AccountOperation A begun erasure of the person, the folded account in its circle
     */
    private function openErasure(): AccountOperation
    {
        $request = Hilos::$db->accountDeletions->actions->request(
            $this->personId,
            TimeHelper::nowMs() + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
        );
        $operation = AccountErasureOpening::open($request, AccountErasureParticipantCatalog::framework())->operation;
        self::assertNotNull($operation);
        self::assertTrue($request->actions->start());

        return $operation;
    }

    /** @param AccountOperation $operation Open erasure that starts waiting for a participant with no owner */
    private function recordWait(AccountOperation $operation): void
    {
        $wait = new AccountOperationExpectation('delivery:mail', $this->personId, null);
        Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id, $operation->stage, $wait->assignmentKey(), $wait->assignmentOwner(), $wait->userId, $wait->payload(),
        );
    }

    /** Leaves the connection owed its page whole, which the worker answers by serving the subscribe again. */
    private function oweThePageWhole(): void
    {
        Hilos::$browser?->bindPageResender(new AccountErasureUsersListResender());
        Hilos::$sr->markPageDeliveryFailure(self::ACCEPT_KEY);
    }

    /**
     * Opens a connection for a person who is an admin or is not.
     *
     * @param bool $admin Whether the person behind the connection is an admin
     */
    private function connect(bool $admin): void
    {
        $user = Hilos::$db->users->actions->createWithName($admin ? 'Administrator of the list' : 'Visitor of the list');
        $user->actions->setAdmin($admin);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', $this->nameWithDataSet()), 0, 32));
        $session->actions->bindUser((int) $user->id);
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, (int) $user->id, $session->token, (int) $session->id);
    }

    /**
     * Subscribes the connection to the list of people and reads the first window of the people table.
     *
     * @return array<string, mixed> The window section the page answer carries
     */
    private function subscribeToList(): array
    {
        $this->queuedFrames();
        Hilos::$sr->subscribeToPage(UsersPage::PAGE, new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, UsersPage::PAGE));
        ExecutionContext::run(new ExecutionFrame(acceptKey: self::ACCEPT_KEY), static function (): void {
            new UsersPage(new DemoHilosAgent())->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        });

        return $this->pageWindow($this->queuedFrames());
    }

    /**
     * @param list<array{name: string, data: SignalDataInterface}> $frames Queued frames
     * @return array<string, mixed> The window section of the people table a page answer among them carries
     */
    private function pageWindow(array $frames): array
    {
        foreach ($frames as $frame) {
            if ($frame['name'] !== SignalTypeConstants::PAGE_RESPONSE) {
                continue;
            }
            $window = $frame['data']->toArray()[PageResponseSignalData::payload][PagePayload::windows][ChatTableContext::hilosUsers] ?? null;
            if (is_array($window)) {
                return $window;
            }
        }
        self::fail('The list did not answer with the window of the people table');
    }

    /** Sends what the people written so far fan out and drops it, so that the next flush is the case's own. */
    private function settleFanOut(): void
    {
        self::assertSame([], Hilos::$browser?->flushToSignalRouter());
        $this->queuedFrames();
    }

    /**
     * @return list<array{name: string, data: SignalDataInterface}> Frames queued to the connection since the last read
     */
    private function queuedFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                self::assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
                $frames[] = ['name' => $signal->signalName->getName(), 'data' => $signal->data->data];
            }
        }

        return $frames;
    }

    /**
     * @param list<array{name: string, data: SignalDataInterface}> $frames Queued frames
     * @return list<array<string, mixed>> The bars of the table_progress frames among them, in order
     */
    private function bars(array $frames): array
    {
        $bars = [];
        foreach ($frames as $frame) {
            if ($frame['name'] !== SignalTypeConstants::TABLE_PROGRESS) {
                continue;
            }
            self::assertInstanceOf(TableProgressSignalData::class, $frame['data']);
            self::assertSame(UsersPage::PAGE, $frame['data']->page);
            self::assertSame(ChatTableContext::hilosUsers, $frame['data']->tableKey);
            $bars[] = $frame['data']->progress->toArray();
        }

        return $bars;
    }

    /**
     * @param list<array{name: string, data: SignalDataInterface}> $frames Queued frames
     * @param string $name Signal name of the frame
     * @return SignalDataInterface The first frame under that name
     */
    private function frameNamed(array $frames, string $name): SignalDataInterface
    {
        foreach ($frames as $frame) {
            if ($frame['name'] === $name) {
                return $frame['data'];
            }
        }
        self::fail("No frame named {$name}");
    }

    /**
     * @param array<string, mixed> $window Window section of a page answer
     * @return list<array<string, mixed>> Its bars that stand under this case's two accounts, in order
     */
    private function ownBars(array $window): array
    {
        $own = [(string) $this->foldedId, (string) $this->personId];

        return array_values(array_filter(
            $window[TableProgressSignalData::progress] ?? [],
            static fn (array $bar): bool => in_array($bar['rowKey'], $own, true),
        ));
    }

    /**
     * @return list<array<string, mixed>> The bar each account of the circle has now, folded account first
     */
    private function expectedBars(): array
    {
        $progress = AccountErasureProgressResolver::of($this->personId)?->toArray();
        self::assertIsArray($progress);
        self::assertNotSame([], $progress[AccountErasureProgress::parts]);

        return array_map(
            static fn (int $userId): array => [
                'scope' => 'row',
                'progressKey' => 'hilos-account-erasure',
                'current' => 0,
                'rowKey' => (string) $userId,
                'total' => count($progress[AccountErasureProgress::parts]),
                'detail' => $progress,
            ],
            [$this->foldedId, $this->personId],
        );
    }

    /**
     * @param int $userId Account whose bar comes down
     * @return array<string, mixed> The frame that takes it down
     */
    private function endedBar(int $userId): array
    {
        return ['scope' => 'row', 'progressKey' => 'hilos-account-erasure', 'current' => 0, 'rowKey' => (string) $userId, 'ended' => true];
    }
}

/** Re-sends the list of people as the worker does: the page answers its subscribe again, under the connection. */
final class AccountErasureUsersListResender implements PageResender
{
    /**
     * @param string $page Page owed whole
     * @param string $acceptKey Connection the page is owed to
     * @param array<string, mixed> $params Unused: the list of people has no route params
     * @param bool $reportRefusal Unused: no deletion is refused here
     * @return PageResendOutcome The page answered whole
     */
    public function resendPage(string $page, string $acceptKey, array $params, bool $reportRefusal = false): PageResendOutcome
    {
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), static function () use ($acceptKey): void {
            new UsersPage(new DemoHilosAgent())->onSubscribe($acceptKey, new PageRouteParams([]));
        });

        return PageResendOutcome::Answered;
    }

    /**
     * @param string $page Unused page key
     * @param string $acceptKey Unused connection key
     * @return PageResendOutcome No deleted row is refused by this fixture
     */
    public function refuseDeletedResource(string $page, string $acceptKey): PageResendOutcome
    {
        return PageResendOutcome::Unserved;
    }
}

/** Router answering from the chat demo's topology rather than the framework's bare one. */
final class AccountErasureUsersListRouter extends SignalRouter
{
    /**
     * @return class-string<Hilos> The chat demo's facade
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }
}
