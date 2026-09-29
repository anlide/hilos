<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\ActionForbiddenException;
use Hilos\Core\Page\Exception\ActionUnauthorizedException;
use Hilos\Core\Page\Exception\ActionViewModeException;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\Exception\PageUnauthorizedException;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAccessVerdict;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tests\Unit\Fixtures\AdminViewModeTestNode;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Unit tests for the page access gate: the ACCESS_LEVEL matrix on the gate
 * itself, the fail-closed default without a browser context, the action-path
 * conversion to the action exception family, and the subscribe-path order
 * (denied before onSubscribe, so no page payload leaves).
 *
 * And its third answer (HIL-1251): with the admin view mode on, a viewer of an
 * ADMIN page views - may look, and act only through the page's reading actions;
 * everything else is refused with the view mode as the reason, answered with an
 * impersonal frame and journaled as INFO.
 */
final class PageAccessGateTest extends TestCase
{
    private ?RtContext $previousRt = null;

    /** Temporary main log file the journal lines are read back from */
    private string $logFile = '';

    public function setUp(): void
    {
        parent::setUp();

        $this->previousRt = Hilos::$rt;
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-page-access-gate');
        Logger::setLogFile($this->logFile);
    }

    public function tearDown(): void
    {
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        AdminViewModeTestNode::unmount();
        Hilos::$rt = $this->previousRt;
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

    /**
     * The whole matrix of the gate's answer: mode × who × level.
     *
     * @return array<string, array{bool, class-string<AbstractPage>, ?int, bool, PageAccessVerdict|class-string<Throwable>}>
     */
    public static function verdictMatrix(): array
    {
        $cases = [];
        foreach ([false, true] as $modeOn) {
            $mode = $modeOn ? 'mode on' : 'mode off';
            $cases["{$mode}, public page, no account"] = [$modeOn, AccessGateTestPublicPage::class, null, false, PageAccessVerdict::ALLOW];
            $cases["{$mode}, public page, not an admin"] = [$modeOn, AccessGateTestPublicPage::class, 5, false, PageAccessVerdict::ALLOW];
            $cases["{$mode}, public page, admin"] = [$modeOn, AccessGateTestPublicPage::class, 7, true, PageAccessVerdict::ALLOW];
            $cases["{$mode}, authenticated page, no account"] = [
                $modeOn,
                AccessGateTestAuthenticatedPage::class,
                null,
                false,
                PageUnauthorizedException::class,
            ];
            $cases["{$mode}, authenticated page, not an admin"] = [
                $modeOn,
                AccessGateTestAuthenticatedPage::class,
                5,
                false,
                PageAccessVerdict::ALLOW,
            ];
            $cases["{$mode}, authenticated page, admin"] = [$modeOn, AccessGateTestAuthenticatedPage::class, 7, true, PageAccessVerdict::ALLOW];
            $cases["{$mode}, admin page, admin"] = [$modeOn, AccessGateTestAdminPage::class, 7, true, PageAccessVerdict::ALLOW];
        }
        $cases['mode off, admin page, no account'] = [false, AccessGateTestAdminPage::class, null, false, PageUnauthorizedException::class];
        $cases['mode off, admin page, not an admin'] = [false, AccessGateTestAdminPage::class, 5, false, PageForbiddenException::class];
        $cases['mode on, admin page, no account'] = [true, AccessGateTestAdminPage::class, null, false, PageAccessVerdict::VIEW];
        $cases['mode on, admin page, not an admin'] = [true, AccessGateTestAdminPage::class, 5, false, PageAccessVerdict::VIEW];

        return $cases;
    }

    /**
     * @param bool $modeOn Whether the admin view mode of the node is on
     * @param class-string<AbstractPage> $pageClass Page asked about
     * @param ?int $userId User behind the connection, or null for a session without an account
     * @param bool $admin Whether that user is an admin
     * @param PageAccessVerdict|class-string<Throwable> $expected The verdict, or the refusal raised instead
     */
    #[DataProvider('verdictMatrix')]
    public function testTheVerdictAnswersByModeWhoAndLevel(
        bool $modeOn,
        string $pageClass,
        ?int $userId,
        bool $admin,
        PageAccessVerdict|string $expected,
    ): void {
        $this->switchMode($modeOn);
        Hilos::$browser = new AccessGateTestBrowser(userId: $userId, admin: $admin);

        if (is_string($expected)) {
            $this->expectException($expected);
        }
        $verdict = PageAccessGate::verdict($pageClass, 'ak-1');

        $this->assertSame($expected, $verdict);
    }

    public function testAViewerPassesTheQuestionOfLooking(): void
    {
        $this->switchMode(true);
        Hilos::$browser = new AccessGateTestBrowser(userId: null, admin: false);

        PageAccessGate::assert(AccessGateTestAdminPage::class, 'ak-1');

        $this->addToAssertionCount(1);
    }

    public function testAFailedAdminLookupMakesAViewerWithTheModeOn(): void
    {
        $this->switchMode(true);
        Hilos::$browser = new AccessGateTestBrowser(userId: 5, admin: true, adminReadFails: true);

        $this->assertSame(PageAccessVerdict::VIEW, PageAccessGate::verdict(AccessGateTestAdminPage::class, 'ak-1'));
    }

    public function testAFailedAdminLookupStillThrowsWithTheModeOff(): void
    {
        $this->switchMode(false);
        Hilos::$browser = new AccessGateTestBrowser(userId: 5, admin: true, adminReadFails: true);

        $this->expectException(DatabaseException::class);
        PageAccessGate::verdict(AccessGateTestAdminPage::class, 'ak-1');
    }

    public function testWithoutABrowserContextTheModeOnStillRefusesA401(): void
    {
        $this->switchMode(true);

        $this->expectException(PageUnauthorizedException::class);
        PageAccessGate::verdict(AccessGateTestAdminPage::class, 'ak-1');
    }

    public function testAViewerIsRefusedAnActionThePageDidNotDeclareReading(): void
    {
        $this->switchMode(true);
        Hilos::$browser = new AccessGateTestBrowser(userId: 5, admin: false);

        $this->expectException(ActionViewModeException::class);
        PageAccessGate::assertAction(AccessGateTestAdminPage::class, 'ak-1', AccessGateTestAdminPage::ACTION);
    }

    public function testAViewerRunsAnActionThePageDeclaredReading(): void
    {
        $this->switchMode(true);
        Hilos::$browser = new AccessGateTestBrowser(userId: null, admin: false);

        PageAccessGate::assertAction(AccessGateTestAdminPage::class, 'ak-1', AccessGateTestAdminPage::READ_ACTION);

        $this->addToAssertionCount(1);
    }

    public function testAnAdminRunsEveryActionWithTheModeOn(): void
    {
        $this->switchMode(true);
        Hilos::$browser = new AccessGateTestBrowser(userId: 7, admin: true);

        PageAccessGate::assertAction(AccessGateTestAdminPage::class, 'ak-1', AccessGateTestAdminPage::ACTION);

        $this->addToAssertionCount(1);
    }

    public function testOnlyAnAdminAllowedOnAnAdminPageProvesAnAdmin(): void
    {
        $this->switchMode(true);
        Hilos::$browser = new AccessGateTestBrowser(userId: 7, admin: true);
        $this->assertTrue(PageAccessGate::provesAdmin(AccessGateTestAdminPage::class, 'ak-1'));
        // An admin on a page that is not ADMIN has proved nothing the page asked for.
        $this->assertFalse(PageAccessGate::provesAdmin(AccessGateTestAuthenticatedPage::class, 'ak-1'));

        Hilos::$browser = new AccessGateTestBrowser(userId: 5, admin: false);
        $this->assertFalse(PageAccessGate::provesAdmin(AccessGateTestAdminPage::class, 'ak-1'));

        Hilos::$browser = new AccessGateTestBrowser(userId: 7, admin: true, adminReadFails: true);
        $this->assertFalse(PageAccessGate::provesAdmin(AccessGateTestAdminPage::class, 'ak-1'));

        $this->switchMode(false);
        $this->assertFalse(PageAccessGate::provesAdmin(AccessGateTestAdminPage::class, 'ak-1'));

        Hilos::$browser = new AccessGateTestBrowser(userId: 5, admin: false);
        $this->assertFalse(PageAccessGate::provesAdmin(AccessGateTestAdminPage::class, 'ak-1'));
    }

    public function testAViewersWritingActionNeverReachesTheHandler(): void
    {
        $this->switchMode(true);
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
        $this->assertInstanceOf(ActionViewModeException::class, $page->actionException);
    }

    public function testAViewersReadingActionRunsTheHandler(): void
    {
        $this->switchMode(true);
        Hilos::$browser = new AccessGateTestBrowser(userId: null, admin: false);
        $factory = new AccessGateTestPageFactory(new AccessGateTestAgent());
        $router = $this->actionRouter($factory);

        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', AccessGateTestAdminPage::READ_ACTION),
            'websocket',
        );

        $page = $factory->getPage(AccessGateTestAdminPage::PAGE);
        $this->assertInstanceOf(AccessGateTestAdminPage::class, $page);
        $this->assertTrue($page->handled);
        $this->assertNull($page->actionException);
    }

    public function testAViewersTrackedRefusalIsImpersonalAndJournaledAsInfo(): void
    {
        $this->switchMode(true);
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new AccessGateTestBrowser(userId: 5, admin: false);
        $factory = new AccessGateTestPageFactory(new AccessGateTestAgent());
        $router = $this->actionRouter($factory);

        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', AccessGateTestAdminPage::ACTION, [], 'req-1'),
            'websocket',
        );

        $page = $factory->getPage(AccessGateTestAdminPage::PAGE);
        $this->assertInstanceOf(AccessGateTestAdminPage::class, $page);
        $this->assertFalse($page->handled);

        $error = null;
        while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($queued->signalName->getName() !== SignalConstants::ACTION_ERROR) {
                continue;
            }
            $envelope = $queued->data;
            $this->assertInstanceOf(WebSocketSignalData::class, $envelope);
            $error = $envelope->data;
        }
        $this->assertInstanceOf(PageActionErrorSignalData::class, $error);
        $this->assertSame('req-1', $error->requestId);
        $this->assertSame(ActionViewModeException::ERROR_CODE, $error->errorCode);
        $this->assertSame(SignalConstants::ACTION_FAILED_REASON, $error->reason);
        $this->assertNull($error->errorType);
        $this->assertNull($error->errorDetail);

        $journal = (string)file_get_contents($this->logFile);
        $this->assertStringContainsString(
            'Action refused in the admin view mode: host=' . $page->actionHostName()
                . ', action=' . AccessGateTestAdminPage::ACTION . ', acceptKey=ak-1',
            $journal,
        );
        $this->assertStringNotContainsString('ERROR', $journal);
        $this->assertStringNotContainsString('Action failed', $journal);
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
     * Builds the router with the admin test page's action routes registered.
     *
     * @param AccessGateTestPageFactory $factory Page factory fixture
     * @return PageSignalRouter Router under test
     */
    private function actionRouter(AccessGateTestPageFactory $factory): PageSignalRouter
    {
        return new PageSignalRouter(
            $factory,
            new ActionRouteConfig([
                AccessGateTestAdminPage::ACTION => AccessGateTestAdminPage::PAGE,
                AccessGateTestAdminPage::READ_ACTION => AccessGateTestAdminPage::PAGE,
            ]),
        );
    }

    /**
     * Mounts a node whose admin view mode is on or off.
     *
     * @param bool $enabled Whether the mode is on
     */
    private function switchMode(bool $enabled): void
    {
        AdminViewModeTestNode::mount($enabled);
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
    public const string READ_ACTION = 'access_gate_admin_read_action';

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;

    public const array READING_ACTIONS = [self::READ_ACTION];

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
        private readonly bool $adminReadFails = false,
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
     * @throws DatabaseException When the fixture was told the read of the flag fails
     */
    public function isAdmin(int $userId): bool
    {
        if ($this->adminReadFails) {
            throw new DatabaseException('The person table could not be read');
        }

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
