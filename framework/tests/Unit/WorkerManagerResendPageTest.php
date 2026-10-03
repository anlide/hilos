<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageResendOutcome;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests the worker's half of the re-send after a failed delivery (HIL-1236).
 *
 * The browser fan-out finds out that a page is owed whole, and the worker answers it through the
 * page of the agent serving the subscription. Which agent that is, the worker learns when the
 * subscribe - or a re-decision of rights, which may hand the subscription to another instance -
 * reaches it, and writes beside the subscription in its own mirror; until this leaf only the
 * master wrote it. The re-send then runs under that agent and that connection, the way a message
 * to the agent does.
 */
final class WorkerManagerResendPageTest extends TestCase
{
    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new ResendWorkerTestBrowser();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testASubscribeRemembersTheAgentThatServedIt(): void
    {
        $this->subscribedManager(ResendWorkerTestManager::FIRST);

        $subscription = Hilos::$sr?->pageSubscription('ak-1');
        $this->assertSame(ResendWorkerTestAgent::AGENT_TYPE, $subscription?->agentType);
        $this->assertSame(ResendWorkerTestManager::FIRST, $subscription->agentIndex);
    }

    /**
     * A re-decision of rights may hand the subscription to another instance of the agent (HIL-627),
     * and the page owed after a failed delivery is that instance's to answer.
     */
    public function testAReDecisionRemembersTheAgentThatServedIt(): void
    {
        $manager = $this->subscribedManager(ResendWorkerTestManager::FIRST);

        $this->handle($manager, ResendWorkerTestManager::SECOND, SignalTypeConstants::PAGE_ACCESS_REASSESS);

        $this->assertSame(ResendWorkerTestManager::SECOND, Hilos::$sr?->pageSubscription('ak-1')?->agentIndex);
    }

    public function testTheReSendRunsThePageOfTheServingAgentUnderThatAgentAndConnection(): void
    {
        $manager = $this->subscribedManager(ResendWorkerTestManager::FIRST);
        $page = $this->servedPage($manager, ResendWorkerTestManager::FIRST);
        $this->drainQueue();

        $outcome = $manager->resendPage(ResendWorkerTestPage::PAGE, 'ak-1', []);

        $this->assertSame(PageResendOutcome::Answered, $outcome);
        $this->assertSame(2, $page->subscribeCount);
        $this->assertSame(
            [ResendWorkerTestAgent::AGENT_TYPE . ':' . ResendWorkerTestManager::FIRST, 'ak-1'],
            $page->lastFrame,
        );
        $this->assertSame(0, $this->servedPage($manager, ResendWorkerTestManager::SECOND)->subscribeCount);
        $this->assertSame([SignalTypeConstants::PAGE_RESPONSE], $this->drainQueue());
    }

    public function testAConnectionWithNoSubscriptionHereIsUnserved(): void
    {
        $manager = $this->subscribedManager(ResendWorkerTestManager::FIRST);

        $this->assertSame(PageResendOutcome::Unserved, $manager->resendPage(ResendWorkerTestPage::PAGE, 'ak-other', []));
    }

    public function testAConnectionOnAnotherPageIsUnserved(): void
    {
        $manager = $this->subscribedManager(ResendWorkerTestManager::FIRST);

        $this->assertSame(PageResendOutcome::Unserved, $manager->resendPage('resend_worker_other_page', 'ak-1', []));
    }

    public function testASubscriptionWhoseAgentIsGoneIsUnserved(): void
    {
        $manager = $this->subscribedManager(ResendWorkerTestManager::FIRST);
        $manager->removeAgent(ResendWorkerTestManager::FIRST);
        $this->drainQueue();

        $this->assertSame(PageResendOutcome::Unserved, $manager->resendPage(ResendWorkerTestPage::PAGE, 'ak-1', []));
        $this->assertSame([], $this->drainQueue());
    }

    /**
     * Builds a manager whose connection 'ak-1' holds the fixture page, served by the named instance.
     *
     * @param string $index Index of the agent instance the subscribe reaches
     * @return ResendWorkerTestManager Manager with a live subscription
     */
    private function subscribedManager(string $index): ResendWorkerTestManager
    {
        $manager = new ResendWorkerTestManager();
        $this->handle($manager, $index, SignalTypeConstants::PAGE_SUBSCRIBE);

        return $manager;
    }

    /**
     * Returns the fixture page one agent instance of the manager serves.
     *
     * @param ResendWorkerTestManager $manager Manager under test
     * @param string $index Index of the agent instance
     * @return ResendWorkerTestPage Fixture page
     * @throws PageNotFoundException When the fixture factory does not know the page
     */
    private function servedPage(ResendWorkerTestManager $manager, string $index): ResendWorkerTestPage
    {
        $page = $manager->page($index);
        $this->assertInstanceOf(ResendWorkerTestPage::class, $page);

        return $page;
    }

    /**
     * Delivers one page frame for 'ak-1' to the named agent instance of the manager under test.
     *
     * @param WorkerManager $manager Manager under test
     * @param string $index Index of the agent instance the frame is addressed to
     * @param string $type Signal type of the frame
     */
    private function handle(WorkerManager $manager, string $index, string $type): void
    {
        $signal = new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType($type),
            new SignalName(ResendWorkerTestPage::PAGE),
            new WebSocketPageSubscribeSignalDTO('ak-1', ResendWorkerTestPage::PAGE),
        );
        $handle = Closure::bind(
            static function (WorkerManager $manager, string $agentId, SignalDTO $signal): void {
                $manager->handleAgentMessage(new DaemonAgentMessageDTO($agentId, $signal));
            },
            null,
            WorkerManager::class,
        );

        $handle($manager, ResendWorkerTestAgent::AGENT_TYPE . ':' . $index, $signal);
    }

    /**
     * Empties the router queue and reports what each signal was named.
     *
     * @return list<string> Signal names, in the order they were queued
     */
    private function drainQueue(): array
    {
        $names = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $names[] = $signal->signalName->getName();
        }

        return $names;
    }
}

final class ResendWorkerTestManager extends WorkerManager
{
    /** Index of the instance a subscribe reaches first. */
    public const string FIRST = '7';

    /** Index of the instance a re-decision may hand the subscription to. */
    public const string SECOND = '8';

    /** @var array<string, ResendWorkerTestPageFactory> Page factory of each agent instance, by index */
    private array $pageFactories = [];

    public function __construct()
    {
        parent::__construct(1);
        foreach ([self::FIRST, self::SECOND] as $index) {
            $agent = new ResendWorkerTestAgent($index);
            $this->agentManager->addAgent($agent->getId(), $agent);
            $this->pageFactories[$index] = new ResendWorkerTestPageFactory($agent);
        }
    }

    /**
     * Returns the fixture page of one agent instance.
     *
     * @param string $index Index of the agent instance
     * @return AbstractPage Fixture page that instance serves
     * @throws PageNotFoundException When the fixture factory does not know the page
     */
    public function page(string $index): AbstractPage
    {
        return $this->pageFactories[$index]->getPage(ResendWorkerTestPage::PAGE);
    }

    /**
     * Takes one agent instance out of this worker, as a stop does.
     *
     * @param string $index Index of the agent instance
     */
    public function removeAgent(string $index): void
    {
        $this->agentManager->removeAgent(ResendWorkerTestAgent::AGENT_TYPE . ':' . $index);
    }

    /**
     * @return SignalRouter Plain router, enough for subscription bookkeeping
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /**
     * @return AgentManager Manager the constructor fills by hand
     */
    protected function createAgentManager(): AgentManager
    {
        return new ResendWorkerTestAgentManager();
    }

    /**
     * @param AgentInterface $agent Agent receiving page signals
     * @return PageSignalRouter Router over that agent's own page factory
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        return new PageSignalRouter($this->pageFactories[(string)$agent->getIndex()], new ActionRouteConfig());
    }
}

final class ResendWorkerTestAgentManager extends AgentManager
{
    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @return AgentInterface Fixture agent
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new ResendWorkerTestAgent((string)$agentIndex);
    }
}

final class ResendWorkerTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_resend_page';

    /**
     * @param string $index Instance index
     */
    public function __construct(string $index)
    {
        $this->agentIndex = $index;
    }

    public function onStop(): void
    {
    }
}

/**
 * Page factory fixture holding the one page of one agent instance.
 *
 * @extends AbstractPageFactory<ResendWorkerTestAgent>
 */
final class ResendWorkerTestPageFactory extends AbstractPageFactory
{
    /**
     * @param string $pageName Page name
     * @return AbstractPage Fixture page
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        if ($pageName !== ResendWorkerTestPage::PAGE) {
            throw new PageNotFoundException($pageName);
        }

        return new ResendWorkerTestPage($this->agent);
    }

    /**
     * @param string $pageName Page name
     * @return bool Whether this is the fixture page
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === ResendWorkerTestPage::PAGE;
    }
}

/**
 * Page recording every answer it gives and the frame of execution it gave it under.
 */
final class ResendWorkerTestPage extends AbstractPage
{
    public const string PAGE = 'resend_worker_page';

    /** How many times this page answered a subscribe frame. */
    public int $subscribeCount = 0;

    /** @var ?array{0: ?string, 1: ?string} Agent and connection of the frame the last answer ran under */
    public ?array $lastFrame = null;

    /**
     * @param string $acceptKey WebSocket accept key (unused)
     * @param PageRouteParams $params Route params (unused)
     */
    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $this->subscribeCount++;
        $this->lastFrame = [ExecutionContext::currentAgentId(), ExecutionContext::currentAcceptKey()];
    }
}

final class ResendWorkerTestBrowser extends BrowserContext
{
    /**
     * @param string $acceptKey Subscriber accept key (unused in the fixture)
     * @return ConnectionIdentity Settled identity of user 5
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved(5);
    }

    /**
     * @param string $page Page name from the subscription mirror (unused)
     * @return ?BrowserPageConfig Always null: the fixture page declares no browser part
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        return null;
    }

    /**
     * @param string $page Page name from the subscription mirror (unused)
     * @return BrowserPageBindings Empty bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return BrowserPageBindings::empty();
    }
}
