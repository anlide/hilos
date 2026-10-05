<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Analytics\AnalyticsJournalRecord;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\ActionUnauthorizedException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Hilos as HilosFacade;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Unit tests for the action-level auth guard on the central action dispatcher:
 * a page's AUTH_ACTIONS deny an anonymous session before onAction, an
 * authenticated session runs the handler, and unguarded actions stay open.
 */
final class PageSignalRouterActionAuthGuardTest extends TestCase
{
    public function tearDown(): void
    {
        HilosFacade::resetBrowser();

        parent::tearDown();
    }

    public function testGuardedActionByAnonymousSessionIsDeniedBeforeHandler(): void
    {
        HilosFacade::$browser = new ActionAuthGuardTestBrowser(null);
        $factory = new ActionAuthGuardTestPageFactory(new ActionAuthGuardTestAgent());
        $router = new PageSignalRouter(
            $factory,
            new ActionRouteConfig([ActionAuthGuardTestPage::GUARDED_ACTION => ActionAuthGuardTestPage::PAGE]),
        );

        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', ActionAuthGuardTestPage::GUARDED_ACTION),
            'websocket',
        );

        $page = $factory->getPage(ActionAuthGuardTestPage::PAGE);
        $this->assertInstanceOf(ActionAuthGuardTestPage::class, $page);
        $this->assertFalse($page->handled);
        $this->assertInstanceOf(ActionUnauthorizedException::class, $page->actionException);
        $this->assertSame(ActionUnauthorizedException::ERROR_CODE, $page->actionException->errorCode);
    }

    public function testGuardedActionByAuthenticatedSessionRunsHandler(): void
    {
        HilosFacade::$browser = new ActionAuthGuardTestBrowser(42);
        $factory = new ActionAuthGuardTestPageFactory(new ActionAuthGuardTestAgent());
        $router = new PageSignalRouter(
            $factory,
            new ActionRouteConfig([ActionAuthGuardTestPage::GUARDED_ACTION => ActionAuthGuardTestPage::PAGE]),
        );

        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', ActionAuthGuardTestPage::GUARDED_ACTION),
            'websocket',
        );

        $page = $factory->getPage(ActionAuthGuardTestPage::PAGE);
        $this->assertInstanceOf(ActionAuthGuardTestPage::class, $page);
        $this->assertTrue($page->handled);
        $this->assertNull($page->actionException);
    }

    public function testUnguardedActionRunsForAnonymousSession(): void
    {
        HilosFacade::$browser = new ActionAuthGuardTestBrowser(null);
        $factory = new ActionAuthGuardTestPageFactory(new ActionAuthGuardTestAgent());
        $router = new PageSignalRouter(
            $factory,
            new ActionRouteConfig([ActionAuthGuardTestPage::OPEN_ACTION => ActionAuthGuardTestPage::PAGE]),
        );

        $router->dispatchAction(
            new WebSocketActionSignalDTO('ak-1', ActionAuthGuardTestPage::OPEN_ACTION),
            'websocket',
        );

        $page = $factory->getPage(ActionAuthGuardTestPage::PAGE);
        $this->assertInstanceOf(ActionAuthGuardTestPage::class, $page);
        $this->assertTrue($page->handled);
    }

    /** An accepted action is attributed before its handler, while a guest leaves no person row. */
    public function testAcceptedActionRecordsItsActorAndAddressWithoutItsBody(): void
    {
        $previousCollector = HilosFacade::$ac;
        $previousRouter = HilosFacade::$sr;
        $previousRt = HilosFacade::$rt;
        try {
            HilosFacade::$rt = null;
            HilosFacade::$sr = new SignalRouter();
            HilosFacade::$ac = new AnalyticsCollector();
            HilosFacade::$browser = new ActionAuthGuardTestBrowser(42);
            $router = new PageSignalRouter(
                new ActionAuthGuardTestPageFactory(new ActionAuthGuardTestAgent()),
                new ActionRouteConfig([ActionAuthGuardTestPage::GUARDED_ACTION => ActionAuthGuardTestPage::PAGE]),
            );
            $router->dispatchAction(new WebSocketActionSignalDTO(
                'ak-1', ActionAuthGuardTestPage::GUARDED_ACTION, ['secret' => 'hidden'], clientIp: '127.0.0.1',
            ), 'websocket');
            HilosFacade::$ac->flush();

            $signal = HilosFacade::$sr->getNextQueuedSignal();
            $this->assertInstanceOf(AgentSignalData::class, $signal?->data);
            $this->assertInstanceOf(AnalyticsJournalAppendSignalData::class, $signal->data->data);
            $record = json_decode($signal->data->data->lines[0], true);
            $this->assertSame(AnalyticsJournalRecord::TYPE_PERSON_EVENT, $record['t']);
            $this->assertSame(42, $record['userId']);
            $this->assertSame('127.0.0.1', $record['ip']);
            $this->assertSame(ActionAuthGuardTestPage::GUARDED_ACTION, $record['action']);
            $this->assertArrayNotHasKey('payload', $record);
        } finally {
            HilosFacade::$ac = $previousCollector;
            HilosFacade::$sr = $previousRouter;
            HilosFacade::$rt = $previousRt;
        }
    }
}

final class ActionAuthGuardTestPage extends AbstractPage
{
    public const string PAGE = 'guarded';
    public const string GUARDED_ACTION = 'guarded_action';
    public const string OPEN_ACTION = 'open_action';

    public const array AUTH_ACTIONS = [self::GUARDED_ACTION];

    public bool $handled = false;
    public ?Throwable $actionException = null;

    /**
     * Records that the handler ran (guarded actions only reach here when allowed).
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
     * Captures the action failure so the test can assert the auth denial.
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

final class ActionAuthGuardTestBrowser extends BrowserContext
{
    public function __construct(private readonly ?int $userId)
    {
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
}

/**
 * Page factory fixture exposing the guarded test page.
 *
 * @extends AbstractPageFactory<ActionAuthGuardTestAgent>
 */
final class ActionAuthGuardTestPageFactory extends AbstractPageFactory
{
    /**
     * Creates the guarded test page.
     *
     * @param string $pageName Page name
     * @return AbstractPage Test page instance
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        if ($pageName === ActionAuthGuardTestPage::PAGE) {
            return new ActionAuthGuardTestPage($this->agent);
        }

        throw new PageNotFoundException($pageName);
    }

    /**
     * Reports whether the guarded test page is available.
     *
     * @param string $pageName Page name
     * @return bool True for the guarded test page
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === ActionAuthGuardTestPage::PAGE;
    }
}

final class ActionAuthGuardTestAgent implements PageAgentInterface
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
