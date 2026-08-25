<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageBadRequestException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Hilos;
use Hilos\Pages\AbstractHilosDashboardPage;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for what the page_response frame of AbstractPage::onSubscribe carries: the page's
 * own payload, the identity the catalog holds for it, and - on the dashboard - its cards.
 */
final class AbstractPagePageResponseEmitTest extends TestCase
{
    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        // Binds the base facade, whose page catalog provider adds nothing, so the identity a page
        // gets here is the framework catalog and not whatever project fixture ran before. The
        // base creates no browser context, so this clears the browser in the same call.
        Hilos::initBrowser();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$browser = null;

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
     * A page the catalog knows answers with its heading, its lead and its breadcrumb, so the one
     * subscription carries everything the page needs to draw itself.
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
 * Test page contributing an entity-only page payload on subscription.
 */
final class AbstractPagePageResponseEmitTestPayloadPage extends AbstractPage
{
    public const string PAGE = 'probe_payload';

    protected function buildPagePayload(PageRouteParams $params): ?PagePayload
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
final class AbstractPagePageResponseEmitTestCatalogPage extends AbstractPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS_KEYS;
}

/**
 * Test page writing its own heading over the static caption the catalog holds for it.
 */
final class AbstractPagePageResponseEmitTestOwnLabelPage extends AbstractPage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_LANGUAGE;

    protected function buildPagePayload(PageRouteParams $params): ?PagePayload
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
 * Concrete stand-in for a project's dashboard page, which adds nothing of its own.
 */
final class AbstractPagePageResponseEmitTestDashboardPage extends AbstractHilosDashboardPage
{
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
