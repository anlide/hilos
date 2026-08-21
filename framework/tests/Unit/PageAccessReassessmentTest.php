<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAccessReassessment;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use RuntimeException;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests both ends of the access re-decision (HIL-621): the sweep that queues one frame per
 * open page of the user whose rights changed, and the receiving end that re-runs the
 * subscribe verdict for it.
 *
 * The receiving end is driven through the worker manager rather than the router alone,
 * because half of what makes a re-decision different from a re-subscribe lives there: the
 * manager must route this type WITHOUT the subscribe's bookkeeping.
 */
final class PageAccessReassessmentTest extends TestCase
{
    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new ReassessTestBrowser();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testTheSweepQueuesOneFramePerOpenPageOfThatUser(): void
    {
        $this->subscribe('ak-guarded', ReassessTestGuardedPage::PAGE, ['userId' => '5']);
        $this->subscribe('ak-private', ReassessTestPrivatePage::PAGE, []);

        PageAccessReassessment::forUser(ReassessTestBrowser::USER);

        $queued = $this->queuedReassessments();
        $this->assertCount(2, $queued);
        $this->assertSame(
            [
                ['ak-guarded', ReassessTestGuardedPage::PAGE, ['userId' => '5']],
                ['ak-private', ReassessTestPrivatePage::PAGE, []],
            ],
            $queued,
        );
    }

    /**
     * The sweep is addressed to one person. A connection that belongs to somebody else -
     * or to nobody the worker can name yet - is not theirs to re-decide.
     */
    public function testAnotherUsersOpenPageIsNotSwept(): void
    {
        $this->subscribe('ak-stranger', ReassessTestPrivatePage::PAGE, []);
        $this->subscribe('ak-pending', ReassessTestPrivatePage::PAGE, []);

        PageAccessReassessment::forUser(ReassessTestBrowser::USER);

        $this->assertSame([], $this->queuedReassessments());
    }

    /**
     * A PUBLIC page declaring no browser guards answers the same thing to everyone, so a
     * rights change cannot move it. Answering anyway would push a full page answer into
     * every open tab of the person on every grant.
     */
    public function testAPublicPageWithNoGuardsIsAnsweredByNothing(): void
    {
        $manager = $this->subscribedManager('ak-open', ReassessTestOpenPage::PAGE);

        $this->handle($manager, $this->reassessSignal('ak-open', ReassessTestOpenPage::PAGE));

        $this->assertSame(1, $this->page($manager, ReassessTestOpenPage::PAGE)->subscribeCount);
    }

    /**
     * The other half of the same rule, read off the page's declared level: a page closed to
     * an anonymous session is put back through the subscribe frame, so its answer is rebuilt
     * from the rights the person holds now.
     */
    public function testAPageClosedByItsLevelIsReJudged(): void
    {
        $manager = $this->subscribedManager('ak-private', ReassessTestPrivatePage::PAGE);

        $this->handle($manager, $this->reassessSignal('ak-private', ReassessTestPrivatePage::PAGE));

        $this->assertSame(2, $this->page($manager, ReassessTestPrivatePage::PAGE)->subscribeCount);
    }

    /**
     * And the half the declared level cannot see: a PUBLIC page whose own browser guard can
     * still refuse one particular person is re-judged too. Reading the level alone would
     * have skipped it.
     */
    public function testAPublicPageWithAGuardIsReJudged(): void
    {
        $manager = $this->subscribedManager('ak-guarded', ReassessTestGuardedPage::PAGE);

        $this->handle($manager, $this->reassessSignal('ak-guarded', ReassessTestGuardedPage::PAGE));

        $this->assertSame(2, $this->page($manager, ReassessTestGuardedPage::PAGE)->subscribeCount);
    }

    /**
     * The tab navigated away between the grant and the frame. The subscription the frame
     * names is gone, and answering would push a page the client has left.
     */
    public function testAFrameForAPageTheConnectionNoLongerHoldsIsDropped(): void
    {
        $manager = $this->subscribedManager('ak-private', ReassessTestPrivatePage::PAGE);

        $this->handle($manager, $this->reassessSignal('ak-private', ReassessTestGuardedPage::PAGE));

        $this->assertSame(0, $this->page($manager, ReassessTestGuardedPage::PAGE)->subscribeCount);
        $this->assertSame(1, $this->page($manager, ReassessTestPrivatePage::PAGE)->subscribeCount);
    }

    /**
     * A page whose browser declaration is broken cannot be shown to be identity-independent,
     * so it is re-judged like any other and the subscribe path answers its declaration with
     * the same internal error a fresh subscribe answers. What must NOT happen is the
     * throwable reaching the worker's message loop, which has no catch on this path: it
     * would take the worker down on every admin grant.
     */
    public function testABrokenPageDeclarationIsAnsweredRatherThanThrown(): void
    {
        $manager = $this->subscribedManager('ak-broken', ReassessTestBrokenPage::PAGE);
        $this->drainQueue();

        $this->handle($manager, $this->reassessSignal('ak-broken', ReassessTestBrokenPage::PAGE));

        $this->assertSame(
            [SignalConstants::SUBSCRIPTION_PAGE_ERROR],
            $this->drainQueue(),
        );
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

    /**
     * Registers one live page subscription in the router mirror.
     *
     * @param string $acceptKey Connection holding the subscription
     * @param string $page Page it holds
     * @param array<string, string> $params Route params of the subscription
     */
    private function subscribe(string $acceptKey, string $page, array $params): void
    {
        Hilos::$sr?->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO($acceptKey, $page, $params));
    }

    /**
     * Drains the router queue and reports the re-decision frames it carried.
     *
     * @return list<array{0: string, 1: ?string, 2: array<string, string>}> Accept key, page and params per frame
     */
    private function queuedReassessments(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->assertSame(SignalTypeConstants::PAGE_ACCESS_REASSESS, $signal->signalType->getType());
            $data = $signal->data;
            $this->assertInstanceOf(WebSocketPageSubscribeSignalDTO::class, $data);
            $this->assertSame($data->page, $signal->signalName->getName());
            $frames[] = [$data->acceptKey, $data->page, $data->params];
        }

        return $frames;
    }

    /**
     * Builds a manager whose connection already holds the given page.
     *
     * @param string $acceptKey Connection to subscribe
     * @param string $page Page to subscribe it to
     * @return ReassessTestManager Manager with a live subscription
     */
    private function subscribedManager(string $acceptKey, string $page): ReassessTestManager
    {
        $manager = new ReassessTestManager();
        $this->handle($manager, new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName($page),
            new WebSocketPageSubscribeSignalDTO($acceptKey, $page),
        ));

        return $manager;
    }

    /**
     * Builds one re-decision frame, shaped exactly as the sweep queues it.
     *
     * @param string $acceptKey Connection the frame is addressed to
     * @param string $page Page the frame re-decides
     * @return SignalDTO Re-decision signal
     */
    private function reassessSignal(string $acceptKey, string $page): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::WORKER),
            new SignalType(SignalTypeConstants::PAGE_ACCESS_REASSESS),
            new SignalName($page),
            new WebSocketPageSubscribeSignalDTO($acceptKey, $page),
        );
    }

    /**
     * Returns the fixture page the manager routed the signals to.
     *
     * @param ReassessTestManager $manager Manager under test
     * @param string $page Page to read
     * @return ReassessTestPage Fixture page
     * @throws PageNotFoundException When the fixture factory does not know the page
     */
    private function page(ReassessTestManager $manager, string $page): ReassessTestPage
    {
        $instance = $manager->pageFactory->getPage($page);
        $this->assertInstanceOf(ReassessTestPage::class, $instance);

        return $instance;
    }

    /**
     * Delivers one daemon-to-worker signal to the manager under test.
     *
     * @param WorkerManager $manager Manager under test
     * @param SignalDTO $signal Signal to deliver
     */
    private function handle(WorkerManager $manager, SignalDTO $signal): void
    {
        $handle = Closure::bind(
            static function (WorkerManager $manager, SignalDTO $signal): void {
                $manager->handleAgentMessage(new DaemonAgentMessageDTO(
                    ReassessTestManager::AGENT_ID,
                    $signal,
                ));
            },
            null,
            WorkerManager::class,
        );

        $handle($manager, $signal);
    }
}

final class ReassessTestManager extends WorkerManager
{
    public const string AGENT_ID = 'unit_page_access_reassess_agent';

    public readonly ReassessTestPageFactory $pageFactory;

    public function __construct()
    {
        parent::__construct(1);
        $agent = new ReassessTestAgent();
        $this->agentManager->addAgent(self::AGENT_ID, $agent);
        $this->pageFactory = new ReassessTestPageFactory($agent);
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
        return new ReassessTestAgentManager();
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

final class ReassessTestAgentManager extends AgentManager
{
    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @return AgentInterface Fixture agent
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new ReassessTestAgent();
    }
}

final class ReassessTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_page_access_reassess';

    public function onStop(): void
    {
    }
}

/**
 * Page factory fixture holding one page of each shape the re-decision tells apart.
 *
 * @extends AbstractPageFactory<ReassessTestAgent>
 */
final class ReassessTestPageFactory extends AbstractPageFactory
{
    /**
     * @param string $pageName Page name
     * @return AbstractPage Fixture page
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        return match ($pageName) {
            ReassessTestOpenPage::PAGE => new ReassessTestOpenPage($this->agent),
            ReassessTestGuardedPage::PAGE => new ReassessTestGuardedPage($this->agent),
            ReassessTestPrivatePage::PAGE => new ReassessTestPrivatePage($this->agent),
            ReassessTestBrokenPage::PAGE => new ReassessTestBrokenPage($this->agent),
            default => throw new PageNotFoundException($pageName),
        };
    }

    /**
     * @param string $pageName Page name
     * @return bool Whether this is one of the fixture pages
     */
    public function hasPage(string $pageName): bool
    {
        return in_array(
            $pageName,
            [
                ReassessTestOpenPage::PAGE,
                ReassessTestGuardedPage::PAGE,
                ReassessTestPrivatePage::PAGE,
                ReassessTestBrokenPage::PAGE,
            ],
            true,
        );
    }
}

abstract class ReassessTestPage extends AbstractPage
{
    /** @var int How many times this page answered a subscribe frame */
    public int $subscribeCount = 0;

    /**
     * Counts the answer without the browser snapshot the base page sends.
     *
     * @param string $acceptKey WebSocket accept key (unused)
     * @param PageRouteParams $params Route params (unused)
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->subscribeCount++;
    }
}

/** PUBLIC and guardless: nothing about it can turn on who is asking. */
final class ReassessTestOpenPage extends ReassessTestPage
{
    public const string PAGE = 'page_access_reassess_open_page';
}

/** PUBLIC, yet its own browser guard can still refuse one particular person. */
final class ReassessTestGuardedPage extends ReassessTestPage
{
    public const string PAGE = 'page_access_reassess_guarded_page';
}

/** Closed to an anonymous session by its declared level alone, guards or no guards. */
final class ReassessTestPrivatePage extends ReassessTestPage
{
    public const string PAGE = 'page_access_reassess_private_page';

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;
}

/** PUBLIC, and its project page-config hook raises instead of answering. */
final class ReassessTestBrokenPage extends ReassessTestPage
{
    public const string PAGE = 'page_access_reassess_broken_page';
}

final class ReassessTestBrowser extends BrowserContext
{
    /** The one user the fixture connections belong to. */
    public const int USER = 5;

    /**
     * Names the user behind each fixture connection: one stranger, one still unidentified.
     *
     * @param string $acceptKey Subscriber accept key
     * @return ConnectionIdentity Who is behind the connection, or the pending state
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return match ($acceptKey) {
            'ak-stranger' => ConnectionIdentity::resolved(9),
            'ak-pending' => ConnectionIdentity::pending(),
            default => ConnectionIdentity::resolved(self::USER),
        };
    }

    /**
     * Declares a browser guard on the guarded page only.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     * @throws RuntimeException Standing in for a project hook that raises outside the family
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($page === ReassessTestBrokenPage::PAGE) {
            // Deliberately outside the subscription family: a project hook may raise
            // anything at all, and the containment must not depend on which.
            throw new RuntimeException('Broken page declaration');
        }

        if ($page !== ReassessTestGuardedPage::PAGE) {
            return null;
        }

        return BrowserPageConfig::fromArray([
            BrowserConfigKey::SIGNAL => 'page_access_reassess_guarded_signal',
            BrowserConfigKey::GUARDS => [
                [BrowserGuardKey::TYPE => BrowserGuardType::AUTHENTICATED],
            ],
        ]);
    }

    /**
     * The fixture pages have no table bindings.
     *
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Empty bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return BrowserPageBindings::empty();
    }
}
