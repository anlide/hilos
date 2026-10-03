<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageBadRequestException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableFacetTally;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Hilos;
use Hilos\Pages\AbstractHilosDashboardPage;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for what the page_response frame of AbstractPage::onSubscribe carries: the page's
 * own payload, the identity the catalog holds for it, the browser part beneath them - all in the
 * one frame (HIL-1236) - and, on the dashboard, its cards.
 */
final class AbstractPagePageResponseEmitTest extends TestCase
{
    private ?RtContext $previousRt = null;

    protected function setUp(): void
    {
        $this->previousRt = Hilos::$rt;
        Hilos::$sr = new AbstractPagePageResponseEmitTestRouter();
        // Binds the base facade, whose page catalog provider adds nothing, so the identity a page
        // gets here is the framework catalog and not whatever project fixture ran before. The
        // base creates no browser context, so this clears the browser in the same call.
        Hilos::initBrowser();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$browser = null;
        Hilos::$table = null;
        Hilos::$rt = $this->previousRt;

        parent::tearDown();
    }

    public function testSubscribeEmitsPageResponseForAPayloadBearingPage(): void
    {
        $page = new AbstractPagePageResponseEmitTestPayloadPage(new AbstractPagePageResponseEmitTestAgent());

        $page->onSubscribe('ak-1', new PageRouteParams([]));

        $signal = Hilos::$sr->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame(
            [
                PageResponseSignalData::page => AbstractPagePageResponseEmitTestPayloadPage::PAGE,
                PageResponseSignalData::payload => [
                    PagePayload::entities => ['currentUser' => ['id' => 7, 'name' => 'Ada']],
                ],
            ],
            $signal->data->data->toArray(),
        );
    }

    /**
     * A page that contributes nothing still answers. The frame is what the
     * client waits on before it shows the page, so silence would leave it with
     * nothing to wait for — and only the option of showing the page ahead of a
     * denial that may still be in flight.
     */
    public function testSubscribeEmitsAnEmptyPageResponseForADefaultPage(): void
    {
        $page = new AbstractPagePageResponseEmitTestDefaultPage(new AbstractPagePageResponseEmitTestAgent());

        $page->onSubscribe('ak-1', new PageRouteParams([]));

        $signal = Hilos::$sr->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        // No payload key at all: an empty PHP array would cross as the JSON
        // array `[]` and the client's object-shaped schema would reject the
        // frame it is waiting on.
        $this->assertSame(
            [PageResponseSignalData::page => AbstractPagePageResponseEmitTestDefaultPage::PAGE],
            $signal->data->data->toArray(),
        );
    }

    /**
     * A page the catalog knows answers with its heading, its lead, its breadcrumb and its children,
     * so the one subscription carries everything the page needs to draw itself. A leaf gets the
     * children key all the same, empty: a key that comes and goes would make the frontend ask
     * whether the backend forgot it.
     */
    public function testSubscribeCarriesTheCatalogIdentityOfTheSubscribedPage(): void
    {
        $page = new AbstractPagePageResponseEmitTestCatalogPage(new AbstractPagePageResponseEmitTestAgent());

        $page->onSubscribe('ak-1', new PageRouteParams([]));

        $signal = Hilos::$sr->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame(
            [
                PageResponseSignalData::page => HilosPageConstants::HILOS_LOGS_KEYS,
                PageResponseSignalData::payload => [
                    PagePayload::data => [
                        PageCatalogConstants::WIRE_PAGE_LABEL => 'By key',
                        PageCatalogConstants::WIRE_PAGE_LEAD => 'Log volume grouped by log key.',
                        PageCatalogConstants::WIRE_PAGE_BREADCRUMB => [
                            [
                                PageCatalogConstants::WIRE_CRUMB_PAGE => HilosPageConstants::HILOS_DASHBOARD,
                                PageCatalogConstants::WIRE_CRUMB_LABEL => 'Hilos',
                            ],
                            [
                                PageCatalogConstants::WIRE_CRUMB_PAGE => HilosPageConstants::HILOS_LOGS,
                                PageCatalogConstants::WIRE_CRUMB_LABEL => 'Logs',
                            ],
                            [
                                PageCatalogConstants::WIRE_CRUMB_PAGE => HilosPageConstants::HILOS_LOGS_KEYS,
                                PageCatalogConstants::WIRE_CRUMB_LABEL => 'By key',
                            ],
                        ],
                        PageCatalogConstants::WIRE_PAGE_CHILDREN => [],
                    ],
                ],
            ],
            $signal->data->data->toArray(),
        );
    }

    /**
     * The catalog fills what the page left unsaid and overwrites nothing: a page that wrote its
     * own heading keeps it, which is the seam a detail page will use to put the name of its
     * entity where the catalog holds a static caption.
     */
    public function testAPageKeepsAnIdentityKeyItWroteItself(): void
    {
        $page = new AbstractPagePageResponseEmitTestOwnLabelPage(new AbstractPagePageResponseEmitTestAgent());

        $page->onSubscribe('ak-1', new PageRouteParams([]));

        $signal = Hilos::$sr->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);

        $data = $signal->data->data->toArray()[PageResponseSignalData::payload][PagePayload::data];

        $this->assertSame('Russian', $data[PageCatalogConstants::WIRE_PAGE_LABEL]);
        $this->assertSame(
            'A single language: locale settings and status.',
            $data[PageCatalogConstants::WIRE_PAGE_LEAD],
        );
    }

    /**
     * The dashboard is the one page that needs more of the catalog than its own entry, and the
     * cards ride the same frame: each item arrives with the page key the frontend builds its URL
     * from, plus the caption, lead and icon it is drawn with.
     */
    public function testTheDashboardCarriesItsCardsBesideItsOwnIdentity(): void
    {
        $page = new AbstractPagePageResponseEmitTestDashboardPage(new AbstractPagePageResponseEmitTestAgent());

        $page->onSubscribe('ak-1', new PageRouteParams([]));

        $signal = Hilos::$sr->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);

        $data = $signal->data->data->toArray()[PageResponseSignalData::payload][PagePayload::data];
        $sections = $data[PageCatalogConstants::WIRE_DASHBOARD_SECTIONS];

        $this->assertSame('Hilos', $data[PageCatalogConstants::WIRE_PAGE_LABEL]);
        $this->assertCount(5, $sections);
        $this->assertSame('Access & identity', $sections[0][PageCatalogConstants::SECTION_TITLE]);
        $this->assertSame(
            [
                PageCatalogConstants::WIRE_ITEM_PAGE => HilosPageConstants::HILOS_USERS,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Users',
                PageCatalogConstants::CATALOG_ENTRY_LEAD =>
                    'Application users and panel operators: presence, roles, and access.',
                PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-people',
            ],
            $sections[0][PageCatalogConstants::SECTION_ITEMS][0],
        );
    }

    public function testTheDashboardOmitsCardsForPagesTheProjectDoesNotServe(): void
    {
        $page = new AbstractPagePageResponseEmitTestDashboardPage(new AbstractPagePageResponseEmitTestAgent());

        $page->onSubscribe('ak-1', new PageRouteParams([]));

        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $sections = $signal->data->data->toArray()[PageResponseSignalData::payload][PagePayload::data]
            [PageCatalogConstants::WIRE_DASHBOARD_SECTIONS];

        $this->assertSame(
            [HilosPageConstants::HILOS_USERS],
            array_column($sections[0][PageCatalogConstants::SECTION_ITEMS], PageCatalogConstants::WIRE_ITEM_PAGE),
        );
    }

    public function testTheDashboardOmitsASectionWithNoServedCards(): void
    {
        Hilos::$sr = new AbstractPagePageResponseEmitTestUsersOnlyRouter();
        $page = new AbstractPagePageResponseEmitTestDashboardPage(new AbstractPagePageResponseEmitTestAgent());

        $page->onSubscribe('ak-1', new PageRouteParams([]));

        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $sections = $signal->data->data->toArray()[PageResponseSignalData::payload][PagePayload::data]
            [PageCatalogConstants::WIRE_DASHBOARD_SECTIONS];

        $this->assertCount(1, $sections);
        $this->assertSame('Access & identity', $sections[0][PageCatalogConstants::SECTION_TITLE]);
    }

    /**
     * A section answers with the cards of the pages under it, in the order the catalog declares
     * them - that is the navigation of the twenty-five screens that are not the dashboard, and it
     * rides the frame the section already sends.
     */
    public function testASectionCarriesTheCardsOfItsChildren(): void
    {
        $page = new AbstractPagePageResponseEmitTestSectionPage(new AbstractPagePageResponseEmitTestAgent());

        $page->onSubscribe('ak-1', new PageRouteParams([]));

        $signal = Hilos::$sr->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);

        $data = $signal->data->data->toArray()[PageResponseSignalData::payload][PagePayload::data];

        $this->assertSame(
            [
                HilosPageConstants::HILOS_LOGS_KEYS,
                HilosPageConstants::HILOS_LOGS_WORKERS,
                HilosPageConstants::HILOS_LOGS_ROTATIONS,
                HilosPageConstants::HILOS_LOGS_SETTINGS,
                HilosPageConstants::HILOS_LOGS_VIEW,
            ],
            array_column($data[PageCatalogConstants::WIRE_PAGE_CHILDREN], PageCatalogConstants::WIRE_CHILD_PAGE),
        );
        $this->assertSame(
            [
                PageCatalogConstants::WIRE_CHILD_PAGE => HilosPageConstants::HILOS_LOGS_KEYS,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'By key',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Log volume grouped by log key.',
            ],
            $data[PageCatalogConstants::WIRE_PAGE_CHILDREN][0],
        );
    }

    public function testASectionOmitsCardsForChildrenTheProjectDoesNotServe(): void
    {
        Hilos::$sr = new AbstractPagePageResponseEmitTestLogsKeyOnlyRouter();
        $page = new AbstractPagePageResponseEmitTestSectionPage(new AbstractPagePageResponseEmitTestAgent());

        $page->onSubscribe('ak-1', new PageRouteParams([]));

        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $children = $signal->data->data->toArray()[PageResponseSignalData::payload][PagePayload::data]
            [PageCatalogConstants::WIRE_PAGE_CHILDREN];

        $this->assertSame(
            [HilosPageConstants::HILOS_LOGS_KEYS],
            array_column($children, PageCatalogConstants::WIRE_CHILD_PAGE),
        );
    }

    /**
     * The children key follows the same rule as the heading: a page that wrote its own list keeps
     * it, which is how a section whose subsections depend on the row being viewed narrows them.
     */
    public function testAPageKeepsTheChildrenListItWroteItself(): void
    {
        $page = new AbstractPagePageResponseEmitTestOwnChildrenPage(new AbstractPagePageResponseEmitTestAgent());

        $page->onSubscribe('ak-1', new PageRouteParams([]));

        $signal = Hilos::$sr->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);

        $data = $signal->data->data->toArray()[PageResponseSignalData::payload][PagePayload::data];

        $this->assertSame(
            [[PageCatalogConstants::WIRE_CHILD_PAGE => HilosPageConstants::HILOS_BACKUP]],
            $data[PageCatalogConstants::WIRE_PAGE_CHILDREN],
        );
    }

    /**
     * The before-response hook answers ahead of the frame, which is the seam a page needs when a
     * snapshot of its own has to reach the client before the page is released.
     */
    public function testTheBeforeResponseHookAnswersAheadOfTheFrame(): void
    {
        $page = new AbstractPagePageResponseEmitTestHookPage(new AbstractPagePageResponseEmitTestAgent());

        $page->onSubscribe('ak-1', new PageRouteParams([]));

        $names = $this->queuedSignalNames();

        $this->assertSame(AbstractPagePageResponseEmitTestHookPage::SIGNAL_BEFORE, $names[0] ?? null);
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $names[1] ?? null);
    }

    /**
     * The after-response hook answers behind the frame, so a side effect a refused subscription
     * must not leave behind runs only once the client has been answered.
     */
    public function testTheAfterResponseHookAnswersBehindTheFrame(): void
    {
        $page = new AbstractPagePageResponseEmitTestHookPage(new AbstractPagePageResponseEmitTestAgent());

        $page->onSubscribe('ak-1', new PageRouteParams([]));

        $names = $this->queuedSignalNames();

        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $names[1] ?? null);
        $this->assertSame(AbstractPagePageResponseEmitTestHookPage::SIGNAL_AFTER, $names[2] ?? null);
    }

    /**
     * A refusal in the before-response hook sends no answer at all: the client waits on the
     * subscription_page_error the router makes of it, not on a frame that says the page is ready.
     */
    public function testARefusalInTheBeforeResponseHookLeavesTheQueueWithoutAFrame(): void
    {
        $page = new AbstractPagePageResponseEmitTestRefusingPage(new AbstractPagePageResponseEmitTestAgent());

        try {
            $page->onSubscribe('ak-1', new PageRouteParams([]));
            $this->fail('The refusing hook must not let the subscription through.');
        } catch (PageBadRequestException $exception) {
            $this->assertSame('Refused before the answer', $exception->getMessage());
        }

        $this->assertSame([], $this->queuedSignalNames());
    }

    /**
     * A page with a part of its own and a browser part answers with one frame carrying both (HIL-1236).
     *
     * The client releases the page on the first page_response it receives, so the browser part sent as
     * a frame of its own drew the page without its own sections and finished it a moment later.
     */
    public function testAPageWithItsOwnPartAndABrowserListAnswersWithOneFrameCarryingBoth(): void
    {
        $this->bootOneFrameBrowser();
        $page = new AbstractPagePageResponseEmitTestOwnAndListPage(new AbstractPagePageResponseEmitTestAgent());

        $page->onSubscribe('ak-1', new PageRouteParams([]));

        $answers = $this->queuedPageResponses();
        $this->assertCount(1, $answers);
        $this->assertSame(
            [
                PageResponseSignalData::page => AbstractPagePageResponseEmitTestOwnAndListPage::PAGE,
                PageResponseSignalData::payload => [
                    PagePayload::data => ['own' => 'mine'],
                    PagePayload::lists => [
                        OneFrameTestList::LIST => [
                            PagePayload::items => [
                                [
                                    PagePayload::itemKey => '1',
                                    PagePayload::slots => [OneFrameTestRtContext::ROWS => ['id' => '1', 'name' => 'Ada']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            $answers[0],
        );
    }

    /**
     * Where both parts write one key, the page's own wins - which is what the client made of the two
     * frames it used to receive, the browser part first and the page's own over it.
     */
    public function testAKeyThePageWroteItselfWinsOverTheBrowserPart(): void
    {
        $this->bootOneFrameBrowser();
        $page = new AbstractPagePageResponseEmitTestOwnListPage(new AbstractPagePageResponseEmitTestAgent());

        $page->onSubscribe('ak-1', new PageRouteParams([]));

        $answers = $this->queuedPageResponses();
        $this->assertCount(1, $answers);
        $this->assertSame(
            [OneFrameTestList::LIST => AbstractPagePageResponseEmitTestOwnListPage::OWN_LIST],
            $answers[0][PageResponseSignalData::payload][PagePayload::lists] ?? null,
        );
    }

    /**
     * The counts beside a window's filters are a frame of their own, and they follow the one answer:
     * the client has somewhere to put them only once that answer has opened the window.
     */
    public function testTheCountsBesideAWindowFollowTheOneAnswer(): void
    {
        $this->bootOneFrameBrowser();
        Hilos::$sr?->reportTableWindows('ak-1', [
            OneFrameTestTable::TABLE => new TableWindowDescriptorDTO(facets: [OneFrameTestTable::FILTER_NAME => ['Ada']]),
        ]);
        $page = new AbstractPagePageResponseEmitTestOwnAndWindowPage(new AbstractPagePageResponseEmitTestAgent());

        $page->onSubscribe('ak-1', new PageRouteParams([]));

        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal?->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame(['own' => 'mine'], $signal->data->data->payload->data);
        $this->assertArrayHasKey(OneFrameTestTable::TABLE, $signal->data->data->payload->windows);
        $this->assertSame(SignalTypeConstants::TABLE_FACET_COUNTS, Hilos::$sr?->getNextQueuedSignal()?->signalName->getName());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /**
     * The page's own part is built before the browser part, so a page that refuses there leaves no
     * window behind it: nothing is sent, and the live road knows no window of this connection.
     */
    public function testAPageThatRefusesItsOwnPartSendsNothingAndOpensNoWindow(): void
    {
        $this->bootOneFrameBrowser();
        $page = new AbstractPagePageResponseEmitTestRefusingOwnPartPage(new AbstractPagePageResponseEmitTestAgent());

        try {
            $page->onSubscribe('ak-1', new PageRouteParams([]));
            $this->fail('The refusing page must not let the subscription through.');
        } catch (PageBadRequestException $exception) {
            $this->assertSame('Refused while building its own part', $exception->getMessage());
        }

        $this->assertSame([], $this->queuedSignalNames());
        $this->assertNull(Hilos::$sr?->getTableViewport('ak-1', OneFrameTestTable::TABLE));
    }

    /**
     * Mounts the browser part the one-frame pages read: one runtime row behind the list and three rows
     * in the table whose window the window page opens.
     */
    private function bootOneFrameBrowser(): void
    {
        $runtime = new OneFrameTestRtContext();
        $runtime->configure();
        $runtime->addRow(OneFrameTestState::create('1', 'Ada'));
        Hilos::$rt = $runtime;
        Hilos::$table = new OneFrameTestTableContext();
        Hilos::$table->configure();
        Hilos::$browser = new OneFrameTestBrowser();
    }

    /**
     * Drains the queue into the page_response frames it holds.
     *
     * @return list<array<string, mixed>> Wire form of every queued page_response, oldest first
     */
    private function queuedPageResponses(): array
    {
        $answers = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE) {
                continue;
            }
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
            $answers[] = $signal->data->data->toArray();
        }

        return $answers;
    }

    /**
     * Drains the queue into the signal names it holds.
     *
     * @return list<string> Queued signal names, oldest first
     */
    private function queuedSignalNames(): array
    {
        $names = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $names[] = $signal->signalName->getName();
        }

        return $names;
    }
}

/**
 * Database context page-route fixture facades never initialize.
 */
final class AbstractPagePageResponseEmitTestDbContext extends HilosDbContext
{
    /**
     * Configures no collections for these catalog-only tests.
     */
    public function configure(): void
    {
    }
}

/**
 * Facade base that gives page-route fixtures a no-op database context.
 */
abstract class AbstractPagePageResponseEmitTestBaseHilos extends Hilos
{
    /**
     * @return HilosDbContext Empty fixture database context
     */
    protected static function createDb(): HilosDbContext
    {
        return new AbstractPagePageResponseEmitTestDbContext();
    }
}

/**
 * Base for pages present only to make fixture topology routes explicit.
 */
abstract class AbstractPagePageResponseEmitTestRegisteredPage extends AbstractPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = 'test';
}

/**
 * Test page contributing an entity-only page payload on subscription.
 */
final class AbstractPagePageResponseEmitTestPayloadPage extends AbstractPage
{
    public const string PAGE = 'probe_payload';

    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        return new PagePayload(entities: ['currentUser' => ['id' => 7, 'name' => 'Ada']]);
    }
}

/**
 * Test page that keeps the default empty page payload.
 */
final class AbstractPagePageResponseEmitTestDefaultPage extends AbstractPage
{
    public const string PAGE = 'probe_default';
}

/**
 * Test page standing on a framework admin key, so the catalog holds an entry for it.
 */
final class AbstractPagePageResponseEmitTestCatalogPage extends AbstractPagePageResponseEmitTestRegisteredPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS_KEYS;
}

/**
 * Test page standing on a framework section, so the catalog holds children under its key.
 */
final class AbstractPagePageResponseEmitTestSectionPage extends AbstractPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS;
}

/**
 * Test page writing its own subsection cards over the ones the catalog holds under its key.
 */
final class AbstractPagePageResponseEmitTestOwnChildrenPage extends AbstractPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS;

    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        return new PagePayload(data: [
            PageCatalogConstants::WIRE_PAGE_CHILDREN => [
                [PageCatalogConstants::WIRE_CHILD_PAGE => HilosPageConstants::HILOS_BACKUP],
            ],
        ]);
    }
}

/**
 * Test page writing its own heading over the static caption the catalog holds for it.
 */
final class AbstractPagePageResponseEmitTestOwnLabelPage extends AbstractPage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_LANGUAGE;

    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        return new PagePayload(data: [PageCatalogConstants::WIRE_PAGE_LABEL => 'Russian']);
    }
}

/**
 * Test page that answers from both subscribe hooks, so the queue shows where each hook sits
 * relative to the frame.
 */
final class AbstractPagePageResponseEmitTestHookPage extends AbstractPage
{
    public const string PAGE = 'probe_hooks';

    public const string SIGNAL_BEFORE = 'probe_hook_before';

    public const string SIGNAL_AFTER = 'probe_hook_after';

    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(self::SIGNAL_BEFORE, $acceptKey, new SignalData());
    }

    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(self::SIGNAL_AFTER, $acceptKey, new SignalData());
    }
}

/**
 * Test page whose before-response hook refuses the subscription, the way a route-param check does.
 */
final class AbstractPagePageResponseEmitTestRefusingPage extends AbstractPage
{
    public const string PAGE = 'probe_refusing';

    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        throw new PageBadRequestException('Refused before the answer');
    }
}

/**
 * Test page with data of its own, standing on the page whose browser part is a list.
 */
final class AbstractPagePageResponseEmitTestOwnAndListPage extends AbstractPage
{
    public const string PAGE = OneFrameTestBrowser::LIST_PAGE;

    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        return new PagePayload(data: ['own' => 'mine']);
    }
}

/**
 * Test page writing the very list its browser part carries, so the two parts meet on one key.
 */
final class AbstractPagePageResponseEmitTestOwnListPage extends AbstractPage
{
    public const string PAGE = OneFrameTestBrowser::LIST_PAGE;

    /** The list as the page writes it itself. */
    public const array OWN_LIST = [PagePayload::items => []];

    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        return new PagePayload(lists: [OneFrameTestList::LIST => self::OWN_LIST]);
    }
}

/**
 * Test page with data of its own, standing on the page whose browser part is a table window.
 */
final class AbstractPagePageResponseEmitTestOwnAndWindowPage extends AbstractPage
{
    public const string PAGE = OneFrameTestBrowser::WINDOW_PAGE;

    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        return new PagePayload(data: ['own' => 'mine']);
    }
}

/**
 * Test page on the window page that refuses while building its own part.
 */
final class AbstractPagePageResponseEmitTestRefusingOwnPartPage extends AbstractPage
{
    public const string PAGE = OneFrameTestBrowser::WINDOW_PAGE;

    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        throw new PageBadRequestException('Refused while building its own part');
    }
}

/**
 * Concrete stand-in for a project's dashboard page, which adds nothing of its own.
 */
final class AbstractPagePageResponseEmitTestDashboardPage extends AbstractHilosDashboardPage
{
}

/**
 * Router fixture backed by the pages needed by the catalog assertions.
 */
final class AbstractPagePageResponseEmitTestRouter extends SignalRouter
{
    /**
     * @return class-string<Hilos> Fixture facade class
     */
    protected function hilosClass(): string
    {
        return AbstractPagePageResponseEmitTestHilos::class;
    }
}

/**
 * Router fixture serving only the first dashboard section's first card.
 */
final class AbstractPagePageResponseEmitTestUsersOnlyRouter extends SignalRouter
{
    /**
     * @return class-string<Hilos> Fixture facade class
     */
    protected function hilosClass(): string
    {
        return AbstractPagePageResponseEmitTestUsersOnlyHilos::class;
    }
}

/**
 * Router fixture serving only one child of the logs section.
 */
final class AbstractPagePageResponseEmitTestLogsKeyOnlyRouter extends SignalRouter
{
    /**
     * @return class-string<Hilos> Fixture facade class
     */
    protected function hilosClass(): string
    {
        return AbstractPagePageResponseEmitTestLogsKeyOnlyHilos::class;
    }
}

/**
 * Facade fixture registering the catalog pages the existing response assertions exercise.
 */
final class AbstractPagePageResponseEmitTestHilos extends AbstractPagePageResponseEmitTestBaseHilos
{
    public const array PAGES = [
        AbstractPagePageResponseEmitTestUsersPage::PAGE => AbstractPagePageResponseEmitTestUsersPage::class,
        AbstractPagePageResponseEmitTestI18nPage::PAGE => AbstractPagePageResponseEmitTestI18nPage::class,
        AbstractPagePageResponseEmitTestSecurityPage::PAGE => AbstractPagePageResponseEmitTestSecurityPage::class,
        AbstractPagePageResponseEmitTestSettingsPage::PAGE => AbstractPagePageResponseEmitTestSettingsPage::class,
        AbstractPagePageResponseEmitTestAnalyticsPage::PAGE => AbstractPagePageResponseEmitTestAnalyticsPage::class,
        AbstractPagePageResponseEmitTestCatalogPage::PAGE => AbstractPagePageResponseEmitTestCatalogPage::class,
        AbstractPagePageResponseEmitTestLogsWorkersPage::PAGE => AbstractPagePageResponseEmitTestLogsWorkersPage::class,
        AbstractPagePageResponseEmitTestLogsRotationsPage::PAGE => AbstractPagePageResponseEmitTestLogsRotationsPage::class,
        AbstractPagePageResponseEmitTestLogsSettingsPage::PAGE => AbstractPagePageResponseEmitTestLogsSettingsPage::class,
        AbstractPagePageResponseEmitTestLogsViewPage::PAGE => AbstractPagePageResponseEmitTestLogsViewPage::class,
    ];
}

/**
 * Facade fixture with one served dashboard card.
 */
final class AbstractPagePageResponseEmitTestUsersOnlyHilos extends AbstractPagePageResponseEmitTestBaseHilos
{
    public const array PAGES = [
        AbstractPagePageResponseEmitTestUsersPage::PAGE => AbstractPagePageResponseEmitTestUsersPage::class,
    ];
}

/**
 * Facade fixture with one served logs child.
 */
final class AbstractPagePageResponseEmitTestLogsKeyOnlyHilos extends AbstractPagePageResponseEmitTestBaseHilos
{
    public const array PAGES = [
        AbstractPagePageResponseEmitTestCatalogPage::PAGE => AbstractPagePageResponseEmitTestCatalogPage::class,
    ];
}

final class AbstractPagePageResponseEmitTestUsersPage extends AbstractPagePageResponseEmitTestRegisteredPage
{
    public const string PAGE = HilosPageConstants::HILOS_USERS;
}

final class AbstractPagePageResponseEmitTestI18nPage extends AbstractPagePageResponseEmitTestRegisteredPage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N;
}

final class AbstractPagePageResponseEmitTestSecurityPage extends AbstractPagePageResponseEmitTestRegisteredPage
{
    public const string PAGE = HilosPageConstants::HILOS_SECURITY;
}

final class AbstractPagePageResponseEmitTestSettingsPage extends AbstractPagePageResponseEmitTestRegisteredPage
{
    public const string PAGE = HilosPageConstants::HILOS_SETTINGS;
}

final class AbstractPagePageResponseEmitTestAnalyticsPage extends AbstractPagePageResponseEmitTestRegisteredPage
{
    public const string PAGE = HilosPageConstants::HILOS_ANALYTICS;
}

final class AbstractPagePageResponseEmitTestLogsWorkersPage extends AbstractPagePageResponseEmitTestRegisteredPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS_WORKERS;
}

final class AbstractPagePageResponseEmitTestLogsRotationsPage extends AbstractPagePageResponseEmitTestRegisteredPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS_ROTATIONS;
}

final class AbstractPagePageResponseEmitTestLogsSettingsPage extends AbstractPagePageResponseEmitTestRegisteredPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS_SETTINGS;
}

final class AbstractPagePageResponseEmitTestLogsViewPage extends AbstractPagePageResponseEmitTestRegisteredPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS_VIEW;
}

/**
 * Minimal page agent providing a signal source for sendToUser.
 */
final class AbstractPagePageResponseEmitTestAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'test-agent';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'test');
    }
}

/**
 * Facade the one-frame browser context reads its source kinds from: one runtime list.
 */
final class OneFrameTestHilos extends AbstractPagePageResponseEmitTestBaseHilos
{
    public const array BROWSER_LISTS = [OneFrameTestList::LIST => OneFrameTestList::class];
}

/**
 * Declares the browser list of the one-frame pages; its `LIST` constant is what makes it a list.
 */
final class OneFrameTestList
{
    public const string LIST = 'oneFrameProbeList';
}

/**
 * Serves the browser part of two pages: a runtime list on one, a table window on the other.
 */
final class OneFrameTestBrowser extends BrowserContext
{
    public const string LIST_PAGE = 'probe_one_frame_list';
    public const string WINDOW_PAGE = 'probe_one_frame_window';

    private const string SIGNAL = 'probe_one_frame_signal';

    public function __construct()
    {
        parent::__construct();
        $this->bindHilosFacade(OneFrameTestHilos::class);
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Test page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        return in_array($page, [self::LIST_PAGE, self::WINDOW_PAGE], true)
            ? BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => self::SIGNAL])
            : null;
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Test page bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return match ($page) {
            self::LIST_PAGE => BrowserPageBindings::fromArray([OneFrameTestList::LIST => []]),
            self::WINDOW_PAGE => BrowserPageBindings::fromArray([OneFrameTestTable::TABLE => []]),
            default => BrowserPageBindings::empty(),
        };
    }

    /**
     * @param string $browserKey Browser source key
     * @return ?BrowserSourceConfig The runtime list
     */
    protected function resolveBrowserOnlyConfig(string $browserKey): ?BrowserSourceConfig
    {
        if ($browserKey !== OneFrameTestList::LIST) {
            return null;
        }

        return BrowserSourceConfig::fromArray([
            BrowserTableConfigKey::ROWS => [[
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::RT,
                    BrowserSourceKey::KEY => OneFrameTestRtContext::ROWS,
                ],
                BrowserTableFieldKey::ROW_KEY => 'id',
                BrowserTableFieldKey::FIELDS => ['id', 'name'],
            ]],
        ]);
    }
}

final class OneFrameTestRtContext extends RtContext
{
    public const string ROWS = 'oneFrameProbeRows';

    public function configure(): void
    {
        $this->_stateCollections[self::ROWS] = OneFrameTestStates::init();
        $this->setRepresent(self::ROWS, OneFrameTestCollection::class);
    }

    /**
     * @param OneFrameTestState $row Row to add to the collection
     */
    public function addRow(OneFrameTestState $row): void
    {
        $this->_stateCollections[self::ROWS]->add($row);
    }
}

final class OneFrameTestStates extends RtStates
{
    public const string STATE_CLASS = OneFrameTestState::class;
}

final class OneFrameTestState extends RtState
{
    private function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {
        parent::__construct();
    }

    /**
     * @param string $id Row key
     * @param string $name Row label
     * @return self Row state
     */
    public static function create(string $id, string $name): self
    {
        return new self($id, $name);
    }

    /**
     * @return string Row key
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @param array<string, mixed> $row Raw row
     * @return static Row state
     */
    public static function fromRow(array $row): static
    {
        return new static((string)$row['id'], (string)$row['name']);
    }

    /**
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}

final class OneFrameTestCollection extends RtCollection
{
    /**
     * @param RtState $state Backing state
     * @return RtItem View item over the state
     */
    protected function createRtItem(RtState $state): RtItem
    {
        return new OneFrameTestItem($state);
    }
}

final class OneFrameTestItem extends RtItem
{
    /**
     * @param string $name Field name
     * @return mixed Field value
     */
    public function __get(string $name): mixed
    {
        return $this->toArray()[$name] ?? parent::__get($name);
    }

    /**
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return $this->getState()->toArray();
    }
}

final class OneFrameTestTableContext extends TableContext
{
    public function configure(): void
    {
        $this->register(OneFrameTestTable::TABLE, new OneFrameTestTable());
    }
}

/**
 * Table answering a window over three rows held in memory, and counting them by name.
 */
final class OneFrameTestTable extends TableDefinition implements SelfSnapshotTable
{
    public const string TABLE = 'oneFrameProbeTable';

    /** Filter key the fixture counts its rows by. */
    public const string FILTER_NAME = 'name';

    private const string SLOT = 'oneFrameProbeTableRows';

    private const array ROWS = [
        ['id' => 'a', 'name' => 'Ada'],
        ['id' => 'b', 'name' => 'Grace'],
        ['id' => 'c', 'name' => 'Edsger'],
    ];

    /**
     * Counts the rows by name.
     *
     * @param TableQueryDTO $query Window query whose filters describe the set
     * @param array<string, list<int|float|string|bool>> $wanted Options to count, by filter key
     * @return array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> Counts by filter key
     */
    public function facetCounts(TableQueryDTO $query, array $wanted): ?array
    {
        return TableFacetTally::forFilters(
            $query,
            array_intersect_key($wanted, [self::FILTER_NAME => true]),
            static fn(TableQueryDTO $set): TableFacetCountDTO => new TableFacetCountDTO(
                count(array_filter(
                    self::ROWS,
                    static fn(array $row): bool => !array_key_exists(self::FILTER_NAME, $set->filter)
                        || $set->filter[self::FILTER_NAME] === $row[self::FILTER_NAME],
                )),
                true,
            ),
        );
    }

    /**
     * No source-change reaction in this fixture.
     *
     * @param SourceChange $change Source change (unused)
     * @return ?TableRowMutationDTO Always null
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * Serializes a row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Self-snapshot row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::SLOT => $row->toArray()],
        ];
    }

    /**
     * Applies the in-memory filter to the rows.
     *
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Windowed snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory(self::ROWS, $query);
    }
}
