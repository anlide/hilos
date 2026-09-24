<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageServiceUnavailableException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A page is answered out of the state this process holds, or it is not answered yet (HIL-717).
 *
 * The worker takes up what the page reads and waits for it before the frame is judged, so a
 * subscription that still finds a collection missing is early rather than wrong - and the
 * difference has to survive as far as the client, which is why the refusal is the transient 503
 * the freeze already uses and not the internal error a refused read would otherwise become.
 *
 * The cases below are about which collections count and when they stop counting: only the RT
 * ones (a DB row is read out of the shared database and needs no copy here), all of them rather
 * than any of them, and only once the state has actually landed - saying what you want is not
 * the same as holding it.
 */
final class PageSourceReadinessTest extends TestCase
{
    /** @var string RT collection the test page draws its rows from; the fixtures below name it too */
    public const string COLLECTION = 'unitPageSourceRows';

    /** @var string Second RT collection, for the case about a page reading several */
    public const string OTHER_COLLECTION = 'unitPageSourceOther';

    /** @var string Third RT collection, named by an heir instead of what its parent named */
    public const string HEIR_COLLECTION = 'unitPageSourceHeir';

    /** @var string Consumer standing in for the subscribing connection */
    private const string ACCEPT_KEY = 'ak-page-source';

    protected function setUp(): void
    {
        // The refusal only exists where the copy is delivered, so the cases have to stand where
        // a worker stands; a process holding its own state answers every read and would prove
        // nothing here.
        SourceInterestRegistry::readsWhatIsDelivered();
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        SourceInterestRegistry::releaseConsumer(SourceConsumer::page(self::ACCEPT_KEY));
        SourceInterestRegistry::readsWhatItMounts();
        Hilos::$sr = null;
        Hilos::$rt = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testAPageIsRefusedWhileNobodyHereReadsWhatItDrawsFrom(): void
    {
        $context = new PageSourceReadinessTestBrowserContext([self::COLLECTION]);

        try {
            $context->assertSubscriptionAccess(
                PageSourceReadinessTestBrowserContext::PAGE,
                self::ACCEPT_KEY,
                new PageRouteParams([]),
            );
            $this->fail('Expected a page with no state behind it to be refused.');
        } catch (PageServiceUnavailableException $e) {
            $this->assertSame(503, $e->httpCode);
            $this->assertSame('service_unavailable', $e->errorCode);
            // The collection name is engine detail and stays in the log; the wire carries a
            // domain sentence, and naming the missing collection on it would be the leak.
            $this->assertStringNotContainsString(self::COLLECTION, $e->getMessage());
        }
    }

    public function testAPageIsRefusedWhileItsStateIsStillOnItsWay(): void
    {
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::page(self::ACCEPT_KEY),
        );
        $context = new PageSourceReadinessTestBrowserContext([self::COLLECTION]);

        $this->expectException(PageServiceUnavailableException::class);
        $context->assertSubscriptionAccess(
            PageSourceReadinessTestBrowserContext::PAGE,
            self::ACCEPT_KEY,
            new PageRouteParams([]),
        );
    }

    public function testTheSubscriptionIsJudgedOnceTheStateHasLanded(): void
    {
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::page(self::ACCEPT_KEY),
        );
        SourceInterestRegistry::markReady(SourceChange::KIND_RT, self::COLLECTION);
        $context = new PageSourceReadinessTestBrowserContext([self::COLLECTION]);

        // Passing the judge sends nothing, whichever way it goes.
        $context->assertSubscriptionAccess(
            PageSourceReadinessTestBrowserContext::PAGE,
            self::ACCEPT_KEY,
            new PageRouteParams([]),
        );

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testEveryCollectionThePageReadsHasToBeHereAndNotJustOne(): void
    {
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::page(self::ACCEPT_KEY),
        );
        SourceInterestRegistry::markReady(SourceChange::KIND_RT, self::COLLECTION);
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::OTHER_COLLECTION,
            SourceConsumer::page(self::ACCEPT_KEY),
        );
        $context = new PageSourceReadinessTestBrowserContext([self::COLLECTION, self::OTHER_COLLECTION]);

        $this->expectException(PageServiceUnavailableException::class);
        $context->assertSubscriptionAccess(
            PageSourceReadinessTestBrowserContext::PAGE,
            self::ACCEPT_KEY,
            new PageRouteParams([]),
        );
    }

    public function testAPageThatReadsNothingWaitsForNothing(): void
    {
        $context = new PageSourceReadinessTestBrowserContext([]);

        $context->assertSubscriptionAccess(
            PageSourceReadinessTestBrowserContext::PAGE,
            self::ACCEPT_KEY,
            new PageRouteParams([]),
        );

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testADbSourceIsNotWaitedFor(): void
    {
        $context = new PageSourceReadinessTestBrowserContext([], [self::COLLECTION]);

        // Nothing was taken up and nothing is ready, yet the page is answered: the rows are in
        // the shared database, and this process owes them no copy.
        $context->assertSubscriptionAccess(
            PageSourceReadinessTestBrowserContext::PAGE,
            self::ACCEPT_KEY,
            new PageRouteParams([]),
        );

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testTheCollectionsOfAPageAreNamedFromTopologyAlone(): void
    {
        $context = new PageSourceReadinessTestBrowserContext(
            [self::COLLECTION, self::COLLECTION, self::OTHER_COLLECTION],
            [self::COLLECTION],
        );

        $this->assertSame(
            [self::COLLECTION, self::OTHER_COLLECTION],
            $context->rtSourceKeysOfPage(PageSourceReadinessTestBrowserContext::PAGE),
        );
    }

    public function testAnUnboundPageNamesNoCollections(): void
    {
        $context = new PageSourceReadinessTestBrowserContext([self::COLLECTION]);

        $this->assertSame([], $context->rtSourceKeysOfPage('some_other_page'));
    }

    /**
     * What a page READS is topology plus its own declaration, which is a wider thing than what
     * it SHOWS: a screen drawn out of a mirror depends on the collection behind that mirror
     * without putting a single row of it on the page (HIL-876).
     */
    public function testAPageReadsItsTablesAndWhatItDeclaresBeyondThem(): void
    {
        $manager = $this->managerReading([self::COLLECTION]);

        $this->assertSame(
            [self::COLLECTION, self::OTHER_COLLECTION],
            $this->pageReadsRt($manager, PageSourceReadinessTestBrowserContext::PAGE),
        );
    }

    public function testAPageDeclaringNothingReadsItsTablesAlone(): void
    {
        $manager = $this->managerReading([self::COLLECTION]);

        $this->assertSame(
            [self::COLLECTION],
            $this->pageReadsRt($manager, PageSourceReadinessTestBrowserContext::PLAIN_PAGE),
        );
    }

    /**
     * Declaring REPLACES, it does not add: an heir with a list of its own carries its parent's
     * entries only by writing them out, exactly as READS_DB behaves one constant above.
     */
    public function testAnHeirDeclaringItsOwnListReplacesItsParents(): void
    {
        $manager = $this->managerReading([self::COLLECTION]);

        $this->assertSame(
            [self::COLLECTION, self::HEIR_COLLECTION],
            $this->pageReadsRt($manager, PageSourceReadinessTestBrowserContext::HEIR_PAGE),
        );
    }

    /**
     * The refusal gate stays on what the page's answer is BUILT from, and a collection named
     * only by the declaration is not that (Flow F6). Refusing the whole section because the
     * mark about one of its sources had not landed would be worse than the staleness the mark
     * exists to report - and it is the same asymmetry READS_DB already has, which is waited for
     * and never refused.
     */
    public function testACollectionNamedOnlyByTheDeclarationDoesNotRefuseTheSubscription(): void
    {
        $context = $this->bindFacade([self::COLLECTION]);
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::page(self::ACCEPT_KEY),
        );
        SourceInterestRegistry::markReady(SourceChange::KIND_RT, self::COLLECTION);

        // OTHER_COLLECTION is declared by the page and has not landed; the page is answered anyway.
        $context->assertSubscriptionAccess(
            PageSourceReadinessTestBrowserContext::PAGE,
            self::ACCEPT_KEY,
            new PageRouteParams([]),
        );

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * A table registered in TABLES is walked like a browser-only source: a page showing one reads
     * what its rows draw from, and does not have to repeat it in READS_* to be served (HIL-376).
     */
    public function testATableRegisteredInTablesNamesItsSourcesToo(): void
    {
        $context = new class extends BrowserContext {
        };
        $context->bindHilosFacade(PageSourceReadinessTestTablesHilos::class);

        $this->assertSame(
            [self::COLLECTION],
            $context->rtSourceKeysOfPage(PageSourceReadinessTestTablesHilos::PAGE),
        );
        $this->assertSame(
            [self::OTHER_COLLECTION],
            $context->dbSourceKeysOfPage(PageSourceReadinessTestTablesHilos::PAGE),
        );
    }

    /**
     * The delivery journal declares its collection, so the page showing it reads notificationDeliveries and
     * the master addresses the journal's db_sync_* frames to the worker serving that page (HIL-1049).
     */
    public function testThePageShowingTheDeliveryJournalReadsItsCollection(): void
    {
        $context = new class extends BrowserContext {
        };
        $context->bindHilosFacade(PageSourceReadinessTestJournalHilos::class);

        $this->assertSame(
            [HilosDbContext::notificationDeliveries],
            $context->dbSourceKeysOfPage(PageSourceReadinessTestJournalHilos::PAGE),
        );
    }

    /**
     * Puts the test topology and the test page registry where the worker looks for them.
     *
     * @param list<string> $rtCollectionKeys RT collections the bound source projects rows from
     * @return PageSourceReadinessTestBrowserContext The bound context, for the cases that ask it
     *     questions of their own
     */
    private function bindFacade(array $rtCollectionKeys): PageSourceReadinessTestBrowserContext
    {
        $context = new PageSourceReadinessTestBrowserContext($rtCollectionKeys);
        PageSourceReadinessTestHilos::initBrowser($context);

        return $context;
    }

    /**
     * @param list<string> $rtCollectionKeys RT collections the bound source projects rows from
     * @return WorkerManager Worker standing where the take-up happens
     */
    private function managerReading(array $rtCollectionKeys): WorkerManager
    {
        $this->bindFacade($rtCollectionKeys);

        return new PageSourceReadinessTestManager();
    }

    /**
     * Asks the worker what one page reads out of the runtime, which is what a take-up raises
     * interest over.
     *
     * @param WorkerManager $manager Worker under test
     * @param string $page Page being subscribed to
     * @return list<string> RT collections it reads, each named once
     */
    private function pageReadsRt(WorkerManager $manager, string $page): array
    {
        $read = Closure::bind(
            static fn(WorkerManager $worker, string $pageName): array => $worker->pageReadsRt($pageName),
            null,
            WorkerManager::class,
        );

        return $read($manager, $page);
    }
}

final class PageSourceReadinessTestBrowserContext extends BrowserContext
{
    public const string PAGE = 'page_source_readiness_page';

    /** Same topology, and no declaration of its own. */
    public const string PLAIN_PAGE = 'page_source_readiness_plain_page';

    /** Same topology, and a declaration written over its parent's. */
    public const string HEIR_PAGE = 'page_source_readiness_heir_page';

    public const string SIGNAL = 'page_source_readiness_signal';

    /** @var string Browser key the test page binds, and the only one this fixture knows */
    private const string BROWSER_KEY = 'pageSourceReadinessList';

    /**
     * The pages this fixture draws the same topology for, so the three declarations differ in
     * nothing but what their classes say.
     *
     * @var array<int, string>
     */
    private const array PAGES = [self::PAGE, self::PLAIN_PAGE, self::HEIR_PAGE];

    /**
     * @param list<string> $rtCollectionKeys RT collections the bound source projects rows from
     * @param list<string> $dbCollectionKeys DB collections it projects rows from
     */
    public function __construct(
        private readonly array $rtCollectionKeys,
        private readonly array $dbCollectionKeys = [],
    ) {
        parent::__construct();
    }

    /**
     * Resolves the guard-less test page config: readiness is judged ahead of any guard.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Guard-less page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if (!in_array($page, self::PAGES, true)) {
            return null;
        }

        return BrowserPageConfig::fromArray([
            BrowserConfigKey::SIGNAL => self::SIGNAL,
        ]);
    }

    /**
     * Binds the test page to the one source this fixture declares.
     *
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings The single binding, or none for any other page
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        if (!in_array($page, self::PAGES, true)) {
            return BrowserPageBindings::empty();
        }

        return BrowserPageBindings::fromArray([self::BROWSER_KEY => []]);
    }

    /**
     * Projects one row per injected collection key, standing in for a source's BROWSER constant.
     *
     * @param string $browserKey Browser table key
     * @return ?BrowserSourceConfig Row projections of the bound source, or null when unknown
     */
    protected function resolveBrowserOnlyConfig(string $browserKey): ?BrowserSourceConfig
    {
        if ($browserKey !== self::BROWSER_KEY) {
            return null;
        }

        $items = [];
        foreach ($this->rtCollectionKeys as $collectionKey) {
            $items[] = $this->rowConfig(BrowserSourceType::RT, $collectionKey);
        }
        foreach ($this->dbCollectionKeys as $collectionKey) {
            $items[] = $this->rowConfig(BrowserSourceType::DB, $collectionKey);
        }

        return BrowserSourceConfig::fromArray([BrowserListConfigKey::ITEMS => $items]);
    }

    /**
     * @param string $type Source type, RT or DB
     * @param string $collectionKey Collection the row draws from
     * @return array<string, mixed> One row projection config
     */
    private function rowConfig(string $type, string $collectionKey): array
    {
        return [
            BrowserFieldKey::SOURCE => [
                BrowserSourceKey::TYPE => $type,
                BrowserSourceKey::KEY => $collectionKey,
            ],
        ];
    }
}

/**
 * A page that names a runtime collection no table of its own draws from.
 */
class PageSourceReadinessTestReadingPage extends AbstractPage
{
    public const string PAGE = PageSourceReadinessTestBrowserContext::PAGE;

    public const array READS_RT = [PageSourceReadinessTest::OTHER_COLLECTION];
}

/**
 * A page that declares nothing and reads its tables alone.
 */
final class PageSourceReadinessTestPlainPage extends AbstractPage
{
    public const string PAGE = PageSourceReadinessTestBrowserContext::PLAIN_PAGE;
}

/**
 * An heir writing its own list over the one its parent declared.
 */
final class PageSourceReadinessTestHeirPage extends PageSourceReadinessTestReadingPage
{
    public const string PAGE = PageSourceReadinessTestBrowserContext::HEIR_PAGE;

    public const array READS_RT = [PageSourceReadinessTest::HEIR_COLLECTION];
}

/**
 * Project facade standing in for a real one: it registers the three test pages and nothing else.
 */
final class PageSourceReadinessTestHilos extends Hilos
{
    public const array PAGES = [
        PageSourceReadinessTestReadingPage::PAGE => PageSourceReadinessTestReadingPage::class,
        PageSourceReadinessTestPlainPage::PAGE => PageSourceReadinessTestPlainPage::class,
        PageSourceReadinessTestHeirPage::PAGE => PageSourceReadinessTestHeirPage::class,
    ];

    /**
     * @return HilosDbContext Test DB context, for the abstract facade contract alone
     */
    protected static function createDb(): HilosDbContext
    {
        return new PageSourceReadinessTestDbContext();
    }
}

/**
 * Project facade binding one page to a table registered in TABLES and to no browser-only source.
 */
final class PageSourceReadinessTestTablesHilos extends Hilos
{
    public const string PAGE = 'page_source_readiness_tables_page';

    public const array TABLES = [
        PageSourceReadinessTestRegisteredTable::TABLE => PageSourceReadinessTestRegisteredTable::class,
    ];

    public const array PAGE_TABLES = [
        self::PAGE => [PageSourceReadinessTestRegisteredTable::TABLE => []],
    ];

    /**
     * @return HilosDbContext Test DB context, for the abstract facade contract alone
     */
    protected static function createDb(): HilosDbContext
    {
        return new PageSourceReadinessTestDbContext();
    }
}

/**
 * Project facade binding one page to the framework's delivery journal, as a project activating it does.
 */
final class PageSourceReadinessTestJournalHilos extends Hilos
{
    public const string PAGE = 'page_source_readiness_journal_page';

    public const array TABLES = [
        HilosNotificationDeliveriesTable::TABLE => HilosNotificationDeliveriesTable::class,
    ];

    public const array PAGE_TABLES = [
        self::PAGE => [HilosNotificationDeliveriesTable::TABLE => []],
    ];

    /**
     * @return HilosDbContext Test DB context, for the abstract facade contract alone
     */
    protected static function createDb(): HilosDbContext
    {
        return new PageSourceReadinessTestDbContext();
    }
}

/**
 * A registered table drawing its rows from one RT and one DB collection.
 */
final class PageSourceReadinessTestRegisteredTable extends TableDefinition
{
    public const string TABLE = 'pageSourceReadinessRegistered';

    public const array BROWSER = [
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::RT,
                    BrowserSourceKey::KEY => PageSourceReadinessTest::COLLECTION,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB,
                    BrowserSourceKey::KEY => PageSourceReadinessTest::OTHER_COLLECTION,
                ],
            ],
        ],
    ];

    /**
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Never returned; these cases build no snapshot
     * @throws RuntimeException Always
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        throw new RuntimeException('not used in test');
    }
}

/**
 * No-op DB configuration: these cases touch no database.
 */
final class PageSourceReadinessTestDbContext extends HilosDbContext
{
    public function configure(): void
    {
    }
}

/**
 * Worker manager standing in for a real one: it opens no connection and starts no agent, and
 * what these cases ask it is one declaration reader.
 */
final class PageSourceReadinessTestManager extends WorkerManager
{
    public function __construct()
    {
        parent::__construct(1);
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new PageSourceReadinessTestAgentManager();
    }
}

/**
 * Agent manager standing in for a real one: these cases start no agent.
 */
final class PageSourceReadinessTestAgentManager extends AgentManager
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentInterface Never returned; these cases start no agent
     * @throws RuntimeException Always
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        throw new RuntimeException('not used in test');
    }
}
