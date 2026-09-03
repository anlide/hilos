<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the order of a page subscription: the whole verdict — freeze,
 * route params, the page's browser guards — is reached before the page builds
 * anything, so a refused session costs no payload build and no page side effect.
 *
 * Also covers the two pages the old order left unjudged, because the snapshot it
 * judged inside returned early for both: a page with no browser config at all, and
 * a page that declares guards but names no browser signal.
 */
final class PageSubscribeGuardOrderTest extends TestCase
{
    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$rt = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testAGuardDenialCostsNoPayloadBuild(): void
    {
        Hilos::$browser = new SubscribeGuardOrderTestBrowser(null);
        $factory = new SubscribeGuardOrderTestPageFactory(new SubscribeGuardOrderTestAgent());
        $router = new PageSignalRouter($factory, new ActionRouteConfig());

        $router->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO('ak-1', SubscribeGuardOrderTestPage::PAGE),
            'websocket',
            SubscribeGuardOrderTestPage::PAGE,
        );

        $page = $factory->getPage(SubscribeGuardOrderTestPage::PAGE);
        $this->assertInstanceOf(SubscribeGuardOrderTestPage::class, $page);
        $this->assertSame(0, $page->payloadBuilds);
        $this->assertSubscriptionError(SubscribeGuardOrderTestPage::PAGE, 401, 'unauthorized');
    }

    public function testAPassingGuardLetsThePageAnswerAsBefore(): void
    {
        Hilos::$browser = new SubscribeGuardOrderTestBrowser(7);
        $factory = new SubscribeGuardOrderTestPageFactory(new SubscribeGuardOrderTestAgent());
        $router = new PageSignalRouter($factory, new ActionRouteConfig());

        $router->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO('ak-1', SubscribeGuardOrderTestPage::PAGE),
            'websocket',
            SubscribeGuardOrderTestPage::PAGE,
        );

        $page = $factory->getPage(SubscribeGuardOrderTestPage::PAGE);
        $this->assertInstanceOf(SubscribeGuardOrderTestPage::class, $page);
        $this->assertSame(1, $page->payloadBuilds);

        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
    }

    /**
     * A page with no browser config declares no guards, and used to be judged by
     * nothing at all: the snapshot that owned the checks returned on the missing
     * config before it reached them, so the freeze was held only by the client's
     * own placeholder. The freeze is server-side now.
     */
    public function testAPageWithoutABrowserConfigIsStillRefusedUnderTheFreeze(): void
    {
        $this->freezeFor('ak-restorer');
        Hilos::$browser = new SubscribeGuardOrderTestBrowser(7);
        $factory = new SubscribeGuardOrderTestPageFactory(new SubscribeGuardOrderTestAgent());
        $router = new PageSignalRouter($factory, new ActionRouteConfig());

        $router->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO('ak-1', SubscribeGuardOrderTestConfiglessPage::PAGE),
            'websocket',
            SubscribeGuardOrderTestConfiglessPage::PAGE,
        );

        $page = $factory->getPage(SubscribeGuardOrderTestConfiglessPage::PAGE);
        $this->assertInstanceOf(SubscribeGuardOrderTestConfiglessPage::class, $page);
        $this->assertSame(0, $page->payloadBuilds);
        $this->assertSubscriptionError(SubscribeGuardOrderTestConfiglessPage::PAGE, 503, 'service_unavailable');
    }

    /**
     * The same early exit swallowed the guards of a page that declares them but
     * names no browser signal — the signal only decides whether a snapshot is sent.
     * No live page is in that state today; nothing but this test stops one.
     */
    public function testDeclaredGuardsRunForAPageThatNamesNoSignal(): void
    {
        Hilos::$browser = new SubscribeGuardOrderTestBrowser(null);
        $factory = new SubscribeGuardOrderTestPageFactory(new SubscribeGuardOrderTestAgent());
        $router = new PageSignalRouter($factory, new ActionRouteConfig());

        $router->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO('ak-1', SubscribeGuardOrderTestSignallessPage::PAGE),
            'websocket',
            SubscribeGuardOrderTestSignallessPage::PAGE,
        );

        $page = $factory->getPage(SubscribeGuardOrderTestSignallessPage::PAGE);
        $this->assertInstanceOf(SubscribeGuardOrderTestSignallessPage::class, $page);
        $this->assertSame(0, $page->payloadBuilds);
        $this->assertSubscriptionError(SubscribeGuardOrderTestSignallessPage::PAGE, 401, 'unauthorized');
    }

    /**
     * Asserts the only queued signal is the subscription error the client expects,
     * which also asserts no page_response went out ahead of it.
     *
     * @param string $page Page the error answers for
     * @param int $httpCode Expected refusal code
     * @param string $errorCode Expected refusal error code
     */
    private function assertSubscriptionError(string $page, int $httpCode, string $errorCode): void
    {
        $signal = Hilos::$sr->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $signal->data->data);
        $this->assertSame($page, $signal->data->data->page);
        $this->assertSame($httpCode, $signal->data->data->httpCode);
        $this->assertSame($errorCode, $signal->data->data->errorCode);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * Mounts the protected-mode runtime singleton frozen for one initiator key.
     *
     * @param string $initiatorAcceptKey Accept key the freeze records; it lets nobody through
     *                                   before the verification window
     */
    private function freezeFor(string $initiatorAcceptKey): void
    {
        $state = ProtectedModeRuntime::create();
        $state->phase = ProtectedModeRuntime::PHASE_ACTIVE;
        $state->initiatorAcceptKey = $initiatorAcceptKey;

        Hilos::$rt = new SubscribeGuardOrderTestRtContext($state);
        Hilos::$rt->configure();
    }
}

/**
 * Page declaring an AUTHENTICATED browser guard, counting its payload builds.
 */
class SubscribeGuardOrderTestPage extends AbstractPage
{
    public const string PAGE = 'subscribe_guard_order_page';

    /** How many times the subscription asked this page to build its payload. */
    public int $payloadBuilds = 0;

    /**
     * Counts the build and contributes nothing.
     *
     * @param string $acceptKey WebSocket accept key of the subscribing connection (unused)
     * @param PageRouteParams $params Route params from page subscription (unused)
     * @return ?PagePayload Always null; the fixture only records that it ran
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        $this->payloadBuilds++;

        return null;
    }
}

/**
 * Page the browser context resolves no config for.
 */
final class SubscribeGuardOrderTestConfiglessPage extends SubscribeGuardOrderTestPage
{
    public const string PAGE = 'subscribe_guard_order_configless_page';
}

/**
 * Page declaring guards but naming no browser signal.
 */
final class SubscribeGuardOrderTestSignallessPage extends SubscribeGuardOrderTestPage
{
    public const string PAGE = 'subscribe_guard_order_signalless_page';
}

final class SubscribeGuardOrderTestBrowser extends BrowserContext
{
    public function __construct(private readonly ?int $currentUserId)
    {
        parent::__construct();
    }

    /**
     * Returns the injected user id as a settled identity, standing in for the connection registry.
     *
     * @param string $acceptKey Subscriber accept key (unused in the fixture)
     * @return ConnectionIdentity Settled identity carrying the injected user id
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved($this->currentUserId);
    }

    /**
     * Resolves the guarded and the signal-less test pages, and nothing for the third.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Page metadata, or null when the page declares none
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        $guards = [
            [BrowserGuardKey::TYPE => BrowserGuardType::AUTHENTICATED],
        ];

        if ($page === SubscribeGuardOrderTestPage::PAGE) {
            return BrowserPageConfig::fromArray([
                BrowserConfigKey::SIGNAL => 'subscribe_guard_order_signal',
                BrowserConfigKey::GUARDS => $guards,
            ]);
        }

        if ($page === SubscribeGuardOrderTestSignallessPage::PAGE) {
            return BrowserPageConfig::fromArray([BrowserConfigKey::GUARDS => $guards]);
        }

        return null;
    }

    /**
     * The test pages have no table bindings.
     *
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Empty bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return BrowserPageBindings::empty();
    }
}

final class SubscribeGuardOrderTestRtContext extends RtContext
{
    public function __construct(private readonly ProtectedModeRuntime $state)
    {
        parent::__construct();
    }

    /**
     * Mounts the injected protected-mode runtime singleton flat, as ClusterRtContext does.
     */
    public function configure(): void
    {
        $this->_stateItems[ProtectedModeRuntime::RT_ITEM] = $this->state;
    }
}

/**
 * Page factory fixture exposing the three subscription test pages.
 *
 * @extends AbstractPageFactory<SubscribeGuardOrderTestAgent>
 */
final class SubscribeGuardOrderTestPageFactory extends AbstractPageFactory
{
    /**
     * Creates the requested subscription test page.
     *
     * @param string $pageName Page name
     * @return AbstractPage Test page instance
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        return match ($pageName) {
            SubscribeGuardOrderTestPage::PAGE => new SubscribeGuardOrderTestPage($this->agent),
            SubscribeGuardOrderTestConfiglessPage::PAGE => new SubscribeGuardOrderTestConfiglessPage($this->agent),
            SubscribeGuardOrderTestSignallessPage::PAGE => new SubscribeGuardOrderTestSignallessPage($this->agent),
            default => throw new PageNotFoundException($pageName),
        };
    }

    /**
     * Reports whether the page is one of the subscription test pages.
     *
     * @param string $pageName Page name
     * @return bool True for the three test pages
     */
    public function hasPage(string $pageName): bool
    {
        return in_array($pageName, [
            SubscribeGuardOrderTestPage::PAGE,
            SubscribeGuardOrderTestConfiglessPage::PAGE,
            SubscribeGuardOrderTestSignallessPage::PAGE,
        ], true);
    }
}

final class SubscribeGuardOrderTestAgent implements PageAgentInterface
{
    /**
     * Returns the fixture agent id.
     *
     * @return string Agent id
     */
    public function getId(): string
    {
        return 'test-agent';
    }

    /**
     * Returns the fixture signal source for page helpers.
     *
     * @return SignalSourceInterface Signal source
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'test');
    }
}
