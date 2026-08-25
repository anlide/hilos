<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\ActionForbiddenException;
use Hilos\Core\Page\Exception\ActionUnauthorizedException;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\Exception\PageUnauthorizedException;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Unit tests for the page access gate: the ACCESS_LEVEL matrix on the gate
 * itself, the fail-closed default without a browser context, the action-path
 * conversion to the action exception family, and the subscribe-path order
 * (denied before onSubscribe, so no page payload leaves).
 */
final class PageAccessGateTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testPublicPagePassesAnAnonymousSessionWithoutABrowserContext(): void
    {
        PageAccessGate::assert(AccessGateTestPublicPage::class, 'ak-1');

        $this->addToAssertionCount(1);
    }

    public function testAuthenticatedPageDeniesAGuest(): void
    {
        Hilos::$browser = new AccessGateTestBrowser(userId: null, admin: false);

        $this->expectException(PageUnauthorizedException::class);
        PageAccessGate::assert(AccessGateTestAuthenticatedPage::class, 'ak-1');
    }

    public function testAuthenticatedPagePassesASignedInUser(): void
    {
        Hilos::$browser = new AccessGateTestBrowser(userId: 5, admin: false);

        PageAccessGate::assert(AccessGateTestAuthenticatedPage::class, 'ak-1');

        $this->addToAssertionCount(1);
    }

    public function testAdminPageDeniesAGuest(): void
    {
        Hilos::$browser = new AccessGateTestBrowser(userId: null, admin: false);

        $this->expectException(PageUnauthorizedException::class);
        PageAccessGate::assert(AccessGateTestAdminPage::class, 'ak-1');
    }

    public function testAdminPageDeniesAnAuthenticatedNonAdmin(): void
    {
        Hilos::$browser = new AccessGateTestBrowser(userId: 5, admin: false);

        $this->expectException(PageForbiddenException::class);
        PageAccessGate::assert(AccessGateTestAdminPage::class, 'ak-1');
    }

    public function testAdminPagePassesAnAdmin(): void
    {
        Hilos::$browser = new AccessGateTestBrowser(userId: 7, admin: true);

        PageAccessGate::assert(AccessGateTestAdminPage::class, 'ak-1');

        $this->addToAssertionCount(1);
    }

    public function testMissingBrowserContextFailsClosedWithA401(): void
    {
        // No browser context mounted: identity is unresolvable, so the admin
        // surface denies instead of opening.
        $this->expectException(PageUnauthorizedException::class);
        PageAccessGate::assert(AccessGateTestAdminPage::class, 'ak-1');
    }

    public function testActionOnAdminPageByAGuestConvertsToActionUnauthorized(): void
    {
        Hilos::$browser = new AccessGateTestBrowser(userId: null, admin: false);
        $factory = new AccessGateTestPageFactory(new AccessGateTestAgent());
        $router = $this->actionRouter($factory);

        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', AccessGateTestAdminPage::ACTION),
            'websocket',
        );

        $page = $factory->getPage(AccessGateTestAdminPage::PAGE);
        $this->assertInstanceOf(AccessGateTestAdminPage::class, $page);
        $this->assertFalse($page->handled);
        $this->assertInstanceOf(ActionUnauthorizedException::class, $page->actionException);
    }

    public function testActionOnAdminPageByANonAdminConvertsToActionForbidden(): void
    {
        Hilos::$browser = new AccessGateTestBrowser(userId: 5, admin: false);
        $factory = new AccessGateTestPageFactory(new AccessGateTestAgent());
        $router = $this->actionRouter($factory);

        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', AccessGateTestAdminPage::ACTION),
            'websocket',
        );

        $page = $factory->getPage(AccessGateTestAdminPage::PAGE);
        $this->assertInstanceOf(AccessGateTestAdminPage::class, $page);
        $this->assertFalse($page->handled);
        $this->assertInstanceOf(ActionForbiddenException::class, $page->actionException);
        $this->assertSame(ActionForbiddenException::ERROR_CODE, $page->actionException->errorCode);
    }

    public function testActionOnAdminPageByAnAdminRunsTheHandler(): void
    {
        Hilos::$browser = new AccessGateTestBrowser(userId: 7, admin: true);
        $factory = new AccessGateTestPageFactory(new AccessGateTestAgent());
        $router = $this->actionRouter($factory);

        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', AccessGateTestAdminPage::ACTION),
            'websocket',
        );

        $page = $factory->getPage(AccessGateTestAdminPage::PAGE);
        $this->assertInstanceOf(AccessGateTestAdminPage::class, $page);
        $this->assertTrue($page->handled);
        $this->assertNull($page->actionException);
    }

    public function testAnonymousSubscribeToAdminPageIsDeniedBeforeOnSubscribe(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new AccessGateTestBrowser(userId: null, admin: false);
        $factory = new AccessGateTestPageFactory(new AccessGateTestAgent());
        $router = $this->actionRouter($factory);

        $router->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO('ak-1', AccessGateTestAdminPage::PAGE, []),
            'websocket',
            AccessGateTestAdminPage::PAGE,
        );

        // The gate stands BEFORE onSubscribe: the handler never ran, so no page
        // payload was built or sent to the denied session.
        $page = $factory->getPage(AccessGateTestAdminPage::PAGE);
        $this->assertInstanceOf(AccessGateTestAdminPage::class, $page);
        $this->assertFalse($page->subscribed);

        // The denial reaches the client as the structured subscription error.
        $queued = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($queued);
        $this->assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $queued->signalName->getName());
        $data = $queued->data;
        $this->assertInstanceOf(WebSocketSignalData::class, $data);
        $error = $data->data;
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $error);
        $this->assertSame(401, $error->httpCode);
        $this->assertSame('unauthorized', $error->errorCode);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * Builds the router with the admin test page's action route registered.
     *
     * @param AccessGateTestPageFactory $factory Page factory fixture
     * @return PageSignalRouter Router under test
     */
    private function actionRouter(AccessGateTestPageFactory $factory): PageSignalRouter
    {
        return new PageSignalRouter(
            $factory,
            new ActionRouteConfig([AccessGateTestAdminPage::ACTION => AccessGateTestAdminPage::PAGE]),
        );
    }
}

final class AccessGateTestPublicPage extends AbstractPage
{
    public const string PAGE = 'access_gate_public';
}

final class AccessGateTestAuthenticatedPage extends AbstractPage
{
    public const string PAGE = 'access_gate_authenticated';

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;
}

final class AccessGateTestAdminPage extends AbstractPage
{
    public const string PAGE = 'access_gate_admin';
    public const string ACTION = 'access_gate_admin_action';

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;

    public bool $handled = false;
    public bool $subscribed = false;
    public ?Throwable $actionException = null;

    /**
     * Records that the subscribe handler ran (only reachable when the gate passed).
     *
     * @param string $acceptKey WebSocket accept key (unused)
     * @param PageRouteParams $params Route params (unused)
     */
    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $this->subscribed = true;
    }

    /**
     * Records that the action handler ran (only reachable when the gate passed).
     *
     * @param string $acceptKey WebSocket accept key (unused)
     * @param string $action Action name (unused)
     * @param ActionPayloadDTO $dto Action payload (unused)
     * @return ?ActionReplyDTO Always null; the fixture only records that it ran
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        $this->handled = true;

        return null;
    }

    /**
     * Captures the action failure so the test can assert the denial type.
     *
     * @param string $acceptKey WebSocket accept key (unused)
     * @param string $action Action name (unused)
     * @param ActionPayloadDTO $dto Action payload (unused)
     * @param Throwable $e Action failure exposed to the client
     */
    public function onActionException(string $acceptKey, string $action, ActionPayloadDTO $dto, Throwable $e): void
    {
        $this->actionException = $e;
    }
}

final class AccessGateTestBrowser extends BrowserContext
{
    public function __construct(
        private readonly ?int $userId,
        private readonly bool $admin,
    ) {
        parent::__construct();
    }

    /**
     * Returns the injected user id as a settled identity, standing in for the connection registry.
     *
     * @param string $acceptKey Acting connection accept key (unused in the fixture)
     * @return ConnectionIdentity Settled identity carrying the injected user id
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved($this->userId);
    }

    /**
     * Returns the injected admin verdict, standing in for the project's admin flag.
     *
     * @param int $userId Authenticated durable user id (unused in the fixture)
     * @return bool Injected admin verdict
     */
    public function isAdmin(int $userId): bool
    {
        return $this->admin;
    }
}

/**
 * Page factory fixture exposing the access-gate test pages.
 *
 * @extends AbstractPageFactory<AccessGateTestAgent>
 */
final class AccessGateTestPageFactory extends AbstractPageFactory
{
    /**
     * Creates the requested access-gate test page.
     *
     * @param string $pageName Page name
     * @return AbstractPage Test page instance
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        return match ($pageName) {
            AccessGateTestPublicPage::PAGE => new AccessGateTestPublicPage($this->agent),
            AccessGateTestAuthenticatedPage::PAGE => new AccessGateTestAuthenticatedPage($this->agent),
            AccessGateTestAdminPage::PAGE => new AccessGateTestAdminPage($this->agent),
            default => throw new PageNotFoundException($pageName),
        };
    }

    /**
     * Reports whether the requested page is one of the access-gate fixtures.
     *
     * @param string $pageName Page name
     * @return bool True for the access-gate test pages
     */
    public function hasPage(string $pageName): bool
    {
        return in_array($pageName, [
            AccessGateTestPublicPage::PAGE,
            AccessGateTestAuthenticatedPage::PAGE,
            AccessGateTestAdminPage::PAGE,
        ], true);
    }
}

final class AccessGateTestAgent implements PageAgentInterface
{
    /**
     * Returns the fixture agent id.
     *
     * @return string Agent id
     */
    public function getId(): string
    {
        return 'access-gate-test-agent';
    }

    /**
     * Returns the fixture signal source for page helpers.
     *
     * @return SignalSourceInterface Signal source
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'access-gate-test');
    }
}
