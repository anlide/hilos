<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\PageBadRequestException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageResource;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Observable life of the DB row an open page is about (HIL-1600).
 *
 * Read through SignalRouter::pageResource(). A later subscribeToPage registration keeps the row
 * the answer bound. A real PAGE_SUBSCRIBE clears the previous row before dispatch, and the new
 * answer replaces it. A matched unsubscribe and a disconnect drop it. A late unsubscribe of
 * another page, an update, and an agent rebind do not.
 */
final class SubscriptionRegistryPageResourceTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-1';

    private const string COLLECTION = 'unit_rows';

    /** Temporary main log file, so a refused answer does not print into the run */
    private string $logFile = '';

    private PageResourceWorkerTestManager $manager;

    private ?SignalRouter $previousSr = null;

    private ?BrowserContext $previousBrowser = null;

    private ?AnalyticsCollector $previousAc = null;

    protected function setUp(): void
    {
        $this->previousSr = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousAc = Hilos::$ac;
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-page-resource');
        Logger::setLogFile($this->logFile);
        Hilos::$browser = null;
        Hilos::$ac = null;
        $this->manager = new PageResourceWorkerTestManager();
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        Hilos::$sr = $this->previousSr;
        Hilos::$browser = $this->previousBrowser;
        Hilos::$ac = $this->previousAc;

        parent::tearDown();
    }

    public function testTheFirstAnswerSurvivesSubscribeToPageAndARealSubscribeReplacesIt(): void
    {
        $page = $this->answer(PageResource::db(self::COLLECTION, 7));

        $this->assertSame('7', $this->boundId());
        $this->assertSame('7', $page->idDuringAfterResponse);
        $this->assertSame([null], $page->boundIdAtResolve);

        Hilos::$sr?->subscribeToPage(PageResourceTestPage::PAGE, $this->subscribeFrame());

        $this->assertSame('7', $this->boundId());

        $page->boundIdAtResolve = [];
        $this->answer(PageResource::db(self::COLLECTION, 'row-9'));

        $this->assertSame([null], $page->boundIdAtResolve);
        $this->assertNull($page->idDuringBeforeResponse);
        $this->assertSame('row-9', $page->idDuringAfterResponse);
        $this->assertSame('row-9', $this->boundId());
        $this->assertSame(self::COLLECTION, Hilos::$sr?->pageResource(self::ACCEPT_KEY)?->collectionKey);
    }

    public function testARefusalBeforeTheAnswerBindsNothing(): void
    {
        $page = $this->answer(PageResource::db(self::COLLECTION, 7));
        $page->refuseBeforeResponse = true;

        $this->answer(PageResource::db(self::COLLECTION, 'should-not-stick'));

        $this->assertNull($page->idDuringBeforeResponse);
        $this->assertNull($this->boundId());
    }

    public function testAnAfterResponseFailureDropsTheRowTheAnswerBound(): void
    {
        $page = $this->page();
        $page->throwAfterResponse = true;

        $this->answer(PageResource::db(self::COLLECTION, 'row-9'));

        $this->assertSame('row-9', $page->idDuringAfterResponse);
        $this->assertNull($this->boundId());
    }

    public function testMatchedUnsubscribeAndDisconnectDropTheRow(): void
    {
        $this->answer(PageResource::db(self::COLLECTION, 7));

        Hilos::$sr?->unsubscribeFromPage(
            PageResourceTestPage::PAGE,
            new WebSocketPageUnsubscribeSignalDTO(self::ACCEPT_KEY),
        );

        $this->assertNull($this->boundId());

        $this->answer(PageResource::db(self::COLLECTION, 7));
        $this->handle(new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::CONNECTION_CLOSE),
            new SignalName(SignalTypeConstants::CONNECTION_CLOSE),
            new WebSocketCloseSignalDTO(self::ACCEPT_KEY),
        ));

        $this->assertNull($this->boundId());
    }

    public function testAForeignUnsubscribeAnUpdateAndAnAgentRebindLeaveTheRow(): void
    {
        $this->answer(PageResource::db(self::COLLECTION, 7));

        Hilos::$sr?->unsubscribeFromPage('other-page', new WebSocketPageUnsubscribeSignalDTO(self::ACCEPT_KEY));
        Hilos::$sr?->updatePageSubscription(
            PageResourceTestPage::PAGE,
            new WebSocketPageUpdateSubscriptionSignalDTO(self::ACCEPT_KEY, PageResourceTestPage::PAGE, ['tab' => 'one']),
        );
        Hilos::$sr?->bindPageAgent(self::ACCEPT_KEY, PageResourceTestPage::PAGE, 'unit-agent', '1');

        $this->assertSame('7', $this->boundId());
    }

    public function testAnEmptyCollectionIsAnInternalError(): void
    {
        $this->expectException(PageInternalErrorException::class);

        PageResource::db('', 1);
    }

    public function testAnEmptyIdIsAnInternalError(): void
    {
        $this->expectException(PageInternalErrorException::class);

        PageResource::db(self::COLLECTION, '');
    }

    public function testAPrimaryKeyIsStoredAsItsCanonicalString(): void
    {
        $zero = PageResource::db(self::COLLECTION, 0);
        $named = PageResource::db(self::COLLECTION, 'row-9');

        $this->assertSame(self::COLLECTION, $zero->collectionKey);
        $this->assertSame('0', $zero->idString);
        $this->assertSame('row-9', $named->idString);
    }

    /**
     * @return PageResourceTestPage The one page this worker serves
     */
    private function page(): PageResourceTestPage
    {
        $page = $this->manager->pageFactory->getPage(PageResourceTestPage::PAGE);
        $this->assertInstanceOf(PageResourceTestPage::class, $page);

        return $page;
    }

    /**
     * Answers one real page subscribe with the named row.
     *
     * Same page and same params as the previous answer, so the worker does not unsubscribe
     * the page it is already on. A cleared row was cleared by the subscribe itself.
     *
     * @param PageResource $resource Row the answer names
     * @return PageResourceTestPage Page that served the answer
     */
    private function answer(PageResource $resource): PageResourceTestPage
    {
        $page = $this->page();
        $page->nextResource = $resource;
        $this->handle(new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName(PageResourceTestPage::PAGE),
            $this->subscribeFrame(),
        ));

        return $page;
    }

    /**
     * @return WebSocketPageSubscribeSignalDTO Subscribe frame for the fixture connection
     */
    private function subscribeFrame(): WebSocketPageSubscribeSignalDTO
    {
        return new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, PageResourceTestPage::PAGE);
    }

    /**
     * @return ?string Canonical id bound for the fixture connection, or null when none is
     */
    private function boundId(): ?string
    {
        return Hilos::$sr?->pageResource(self::ACCEPT_KEY)?->idString;
    }

    /**
     * Delivers one daemon-to-worker agent signal to the manager under test.
     *
     * @param SignalDTO $signal Signal to deliver
     */
    private function handle(SignalDTO $signal): void
    {
        $handle = Closure::bind(
            static function (WorkerManager $manager, SignalDTO $signal): void {
                $manager->handleAgentMessage(new DaemonAgentMessageDTO(
                    PageResourceWorkerTestManager::AGENT_ID,
                    $signal,
                ));
            },
            null,
            WorkerManager::class,
        );

        $handle($this->manager, $signal);
    }
}

final class PageResourceWorkerTestManager extends WorkerManager
{
    public const string AGENT_ID = 'unit_page_resource_agent';

    public readonly PageResourceTestPageFactory $pageFactory;

    public function __construct()
    {
        parent::__construct(1);
        $agent = new PageResourceTestAgent();
        $this->agentManager->addAgent(self::AGENT_ID, $agent);
        $this->pageFactory = new PageResourceTestPageFactory($agent);
    }

    /**
     * @return SignalRouter Plain router, enough for subscription bookkeeping
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /**
     * @return AgentManager Manager the test fills by hand
     */
    protected function createAgentManager(): AgentManager
    {
        return new PageResourceTestAgentManager();
    }

    /**
     * @param AgentInterface $agent Agent receiving page signals
     * @return PageSignalRouter Router over the fixture page factory
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        return new PageSignalRouter($this->pageFactory, new ActionRouteConfig());
    }
}

final class PageResourceTestAgentManager extends AgentManager
{
    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @return AgentInterface Fixture agent
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new PageResourceTestAgent();
    }
}

final class PageResourceTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_page_resource';

    public function onStop(): void
    {
    }
}

/**
 * Page factory fixture exposing the single page whose row the tests bind.
 *
 * @extends AbstractPageFactory<PageResourceTestAgent>
 */
final class PageResourceTestPageFactory extends AbstractPageFactory
{
    /**
     * @param string $pageName Page name
     * @return AbstractPage Fixture page
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        if ($pageName === PageResourceTestPage::PAGE) {
            return new PageResourceTestPage($this->agent);
        }

        throw new PageNotFoundException($pageName);
    }

    /**
     * @param string $pageName Page name
     * @return bool True for the single fixture page
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === PageResourceTestPage::PAGE;
    }
}

/**
 * Page that names whatever row the test handed it, and records when that row was bound.
 */
final class PageResourceTestPage extends AbstractPage
{
    public const string PAGE = 'page_resource_page';

    /** Row the next answer names, or null when the test names none. */
    public ?PageResource $nextResource = null;

    /** When true, the before-response hook refuses the subscription. */
    public bool $refuseBeforeResponse = false;

    /** When true, the after-response hook throws after the answer has bound its row. */
    public bool $throwAfterResponse = false;

    /** @var list<?string> Id bound when the resource hook ran, null when none was */
    public array $boundIdAtResolve = [];

    /** Id bound during the before-response hook, null when none was. */
    public ?string $idDuringBeforeResponse = null;

    /** Id bound during the after-response hook, null when none was. */
    public ?string $idDuringAfterResponse = null;

    /**
     * Records the row already bound, then returns the row this test's answer names.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params from page subscription
     * @return ?PageResource Row this answer names, or null when the test names none
     */
    protected function resolvePageResource(string $acceptKey, PageRouteParams $params): ?PageResource
    {
        $this->boundIdAtResolve[] = Hilos::$sr?->pageResource($acceptKey)?->idString;

        return $this->nextResource;
    }

    /**
     * Records the row bound before the answer, and refuses when the test asks it to.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params from page subscription
     * @throws PageBadRequestException When the test refuses the subscription before the answer
     */
    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $this->idDuringBeforeResponse = Hilos::$sr?->pageResource($acceptKey)?->idString;
        if ($this->refuseBeforeResponse) {
            throw new PageBadRequestException('Refused before the answer');
        }
    }

    /**
     * Records the row bound after the answer, and fails when the test asks it to.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params from page subscription
     * @throws RuntimeException When the test fails the after-response hook
     */
    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        $this->idDuringAfterResponse = Hilos::$sr?->pageResource($acceptKey)?->idString;
        if ($this->throwAfterResponse) {
            throw new RuntimeException('After-response failed');
        }
    }
}
