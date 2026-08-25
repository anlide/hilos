<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests the guarded update-subscription path: the frame carries only the params it
 * changes, so the verdict is reached on the MERGED set, and only an accepted set
 * settles into the subscription mirror.
 *
 * Driven through the worker manager rather than the router alone: judging and applying
 * are one step in the router since HIL-689, but the manager is still what a real frame
 * arrives through, and the agent hook it runs first is part of the path being pinned.
 */
final class PageUpdateSubscriptionGuardTest extends TestCase
{
    protected function setUp(): void
    {
        Hilos::$browser = new UpdateSubscriptionGuardTestBrowser();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    /**
     * The subscription already holds the required param, and the frame does not
     * repeat it. Judging the frame alone would refuse the update for a param the
     * subscription never lost.
     */
    public function testAPartialFrameIsJudgedOnTheMergedParams(): void
    {
        $manager = $this->subscribedManager(['userId' => '5']);

        $this->handle($manager, $this->updateSignal(['tab' => 'info']));

        $this->assertSame(['userId' => '5', 'tab' => 'info'], $this->mirroredParams());
        $this->assertSame(
            [['userId' => '5', 'tab' => 'info']],
            $this->page($manager)->updatedWith,
        );
    }

    public function testARefusedUpdateLeavesTheSubscriptionOnItsOldParams(): void
    {
        $manager = $this->subscribedManager(['userId' => '5']);

        $this->handle($manager, $this->updateSignal(['userId' => 'not-an-id']));

        $this->assertSame(['userId' => '5'], $this->mirroredParams());
        $this->assertSame([], $this->page($manager)->updatedWith);
    }

    public function testAnAcceptedUpdateSettlesIntoTheSubscription(): void
    {
        $manager = $this->subscribedManager(['userId' => '5']);

        $this->handle($manager, $this->updateSignal(['userId' => '9']));

        $this->assertSame(['userId' => '9'], $this->mirroredParams());
        $this->assertSame([['userId' => '9']], $this->page($manager)->updatedWith);
    }

    /**
     * Builds a manager whose connection is already subscribed with the given params.
     *
     * @param array<string, string> $params Params the subscription starts on
     * @return UpdateSubscriptionGuardTestManager Manager with a live subscription
     */
    private function subscribedManager(array $params): UpdateSubscriptionGuardTestManager
    {
        $manager = new UpdateSubscriptionGuardTestManager();
        $this->handle($manager, new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName(UpdateSubscriptionGuardTestPage::PAGE),
            new WebSocketPageSubscribeSignalDTO(
                'ak-1',
                UpdateSubscriptionGuardTestPage::PAGE,
                $params,
            ),
        ));

        return $manager;
    }

    /**
     * Builds one update-subscription frame for the subscribed connection.
     *
     * @param array<string, string> $params Params the frame carries
     * @return SignalDTO Update-subscription signal
     */
    private function updateSignal(array $params): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION),
            new SignalName(UpdateSubscriptionGuardTestPage::PAGE),
            new WebSocketPageUpdateSubscriptionSignalDTO(
                'ak-1',
                UpdateSubscriptionGuardTestPage::PAGE,
                $params,
            ),
        );
    }

    /**
     * Returns the params the subscription mirror carries for the test connection.
     *
     * @return array<string, string> Mirrored subscription params
     */
    private function mirroredParams(): array
    {
        return Hilos::$sr?->getPageSubscriptions()['ak-1'][SignalPayloadConstants::SUBSCRIPTION_PARAMS_KEY] ?? [];
    }

    /**
     * Returns the fixture page the manager routed the signals to.
     *
     * @param UpdateSubscriptionGuardTestManager $manager Manager under test
     * @return UpdateSubscriptionGuardTestPage Fixture page
     */
    private function page(UpdateSubscriptionGuardTestManager $manager): UpdateSubscriptionGuardTestPage
    {
        $page = $manager->pageFactory->getPage(UpdateSubscriptionGuardTestPage::PAGE);
        $this->assertInstanceOf(UpdateSubscriptionGuardTestPage::class, $page);

        return $page;
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
                    UpdateSubscriptionGuardTestManager::AGENT_ID,
                    $signal,
                ));
            },
            null,
            WorkerManager::class,
        );

        $handle($manager, $signal);
    }
}

final class UpdateSubscriptionGuardTestManager extends WorkerManager
{
    public const string AGENT_ID = 'unit_update_subscription_agent';

    public readonly UpdateSubscriptionGuardTestPageFactory $pageFactory;

    public function __construct()
    {
        parent::__construct(1);
        $agent = new UpdateSubscriptionGuardTestAgent();
        $this->agentManager->addAgent(self::AGENT_ID, $agent);
        $this->pageFactory = new UpdateSubscriptionGuardTestPageFactory($agent);
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
        return new UpdateSubscriptionGuardTestAgentManager();
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

final class UpdateSubscriptionGuardTestAgentManager extends AgentManager
{
    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @return AgentInterface Fixture agent
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new UpdateSubscriptionGuardTestAgent();
    }
}

final class UpdateSubscriptionGuardTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_update_subscription';

    public function onStop(): void
    {
    }
}

/**
 * Page factory fixture exposing the single subscribed page.
 *
 * @extends AbstractPageFactory<UpdateSubscriptionGuardTestAgent>
 */
final class UpdateSubscriptionGuardTestPageFactory extends AbstractPageFactory
{
    /**
     * @param string $pageName Page name
     * @return AbstractPage Fixture page
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        if ($pageName === UpdateSubscriptionGuardTestPage::PAGE) {
            return new UpdateSubscriptionGuardTestPage($this->agent);
        }

        throw new PageNotFoundException($pageName);
    }

    /**
     * @param string $pageName Page name
     * @return bool True for the single fixture page
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === UpdateSubscriptionGuardTestPage::PAGE;
    }
}

final class UpdateSubscriptionGuardTestPage extends AbstractPage
{
    public const string PAGE = 'update_subscription_guard_page';

    /** @var list<array<string, string>> Param sets the page was refreshed with */
    public array $updatedWith = [];

    /**
     * Records the param set the refresh was handed, so the merge is assertable.
     *
     * @param string $acceptKey WebSocket accept key (unused)
     * @param PageRouteParams $params Merged route params for the subscription
     */
    public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->updatedWith[] = $params->toArray();
    }
}

final class UpdateSubscriptionGuardTestBrowser extends BrowserContext
{
    /**
     * Resolves the test page's config: one required positive-int route param.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($page !== UpdateSubscriptionGuardTestPage::PAGE) {
            return null;
        }

        return BrowserPageConfig::fromArray([
            BrowserConfigKey::SIGNAL => 'update_subscription_guard_signal',
            BrowserConfigKey::PARAMS => [
                'userId' => [
                    BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                    BrowserParamKey::REQUIRED => true,
                ],
            ],
        ]);
    }

    /**
     * The test page has no table bindings.
     *
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Empty bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return BrowserPageBindings::empty();
    }
}
