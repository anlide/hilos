<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests page teardown on a dropped connection (HIL-547).
 *
 * The daemon used to queue a page_unsubscribe of its own from WebSocketClient::onClose(),
 * with no page to name it by - a no-op the router dropped. Teardown is the worker's,
 * which knows the page from its own subscription mirror, and this holds with the daemon
 * signal gone.
 */
final class WorkerManagerConnectionClosePageUnsubscribeTest extends TestCase
{
    protected function tearDown(): void
    {
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testConnectionCloseUnsubscribesTheTrackedPage(): void
    {
        $manager = new WorkerManagerConnectionCloseTestManager();

        $this->handle($manager, new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName(WorkerManagerConnectionCloseTestPage::PAGE),
            new WebSocketPageSubscribeSignalDTO('accept-key', WorkerManagerConnectionCloseTestPage::PAGE),
        ));
        $this->handle($manager, $this->connectionCloseSignal());

        $page = $manager->pageFactory->getPage(WorkerManagerConnectionCloseTestPage::PAGE);
        $this->assertInstanceOf(WorkerManagerConnectionCloseTestPage::class, $page);
        $this->assertSame(['accept-key'], $page->unsubscribedAcceptKeys);
    }

    public function testConnectionCloseWithoutASubscriptionUnsubscribesNothing(): void
    {
        $manager = new WorkerManagerConnectionCloseTestManager();

        $this->handle($manager, $this->connectionCloseSignal());

        $this->assertSame(0, $manager->pageFactory->createPageCount);
    }

    /**
     * Builds the close signal the daemon sends when a connection drops.
     *
     * @return SignalDTO Close signal named after its own type
     */
    private function connectionCloseSignal(): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::CONNECTION_CLOSE),
            new SignalName(SignalTypeConstants::CONNECTION_CLOSE),
            new WebSocketCloseSignalDTO('accept-key'),
        );
    }

    /**
     * Delivers one daemon-to-worker agent signal to the manager under test.
     *
     * @param WorkerManager $manager Manager under test
     * @param SignalDTO $signal Signal to deliver
     */
    private function handle(WorkerManager $manager, SignalDTO $signal): void
    {
        $handle = Closure::bind(
            static function (WorkerManager $manager, SignalDTO $signal): void {
                $manager->handleAgentMessage(new DaemonAgentMessageDTO(
                    WorkerManagerConnectionCloseTestManager::AGENT_ID,
                    $signal,
                ));
            },
            null,
            WorkerManager::class,
        );

        $handle($manager, $signal);
    }
}

final class WorkerManagerConnectionCloseTestManager extends WorkerManager
{
    public const string AGENT_ID = 'unit_connection_close_agent';

    public readonly WorkerManagerConnectionCloseTestPageFactory $pageFactory;

    public function __construct()
    {
        parent::__construct(1);
        $agent = new WorkerManagerConnectionCloseTestAgent();
        $this->agentManager->addAgent(self::AGENT_ID, $agent);
        $this->pageFactory = new WorkerManagerConnectionCloseTestPageFactory($agent);
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
        return new WorkerManagerConnectionCloseTestAgentManager();
    }

    /**
     * @param AgentInterface $agent Agent receiving page signals (unused)
     * @return PageSignalRouter Router over the fixture page factory
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        return new PageSignalRouter($this->pageFactory, new ActionRouteConfig());
    }
}

final class WorkerManagerConnectionCloseTestAgentManager extends AgentManager
{
    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @return AgentInterface Fixture agent
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new WorkerManagerConnectionCloseTestAgent();
    }
}

final class WorkerManagerConnectionCloseTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_connection_close';

    public function onStop(): void
    {
    }
}

/**
 * Page factory fixture exposing the single page the connection subscribed to.
 *
 * @extends AbstractPageFactory<WorkerManagerConnectionCloseTestAgent>
 */
final class WorkerManagerConnectionCloseTestPageFactory extends AbstractPageFactory
{
    /** @var int How many times a page had to be built, so "nothing happened" is assertable */
    public int $createPageCount = 0;

    /**
     * @param string $pageName Page name
     * @return AbstractPage Fixture page
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        $this->createPageCount++;

        if ($pageName === WorkerManagerConnectionCloseTestPage::PAGE) {
            return new WorkerManagerConnectionCloseTestPage($this->agent);
        }

        throw new PageNotFoundException($pageName);
    }

    /**
     * @param string $pageName Page name
     * @return bool True for the single fixture page
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === WorkerManagerConnectionCloseTestPage::PAGE;
    }
}

final class WorkerManagerConnectionCloseTestPage extends AbstractPage
{
    public const string PAGE = 'connection_close_page';

    /** @var list<string> Accept keys the page was torn down for */
    public array $unsubscribedAcceptKeys = [];

    /**
     * Records the teardown so the test can assert it ran for the closed connection.
     *
     * @param string $acceptKey WebSocket accept key
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        $this->unsubscribedAcceptKeys[] = $acceptKey;
    }
}
