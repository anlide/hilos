<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Throttle\DTO\ThrottleCheckSignalData;
use Hilos\Auth\Throttle\DTO\ThrottleVerdictSignalData;
use Hilos\Auth\Throttle\ThrottleScope;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\Definition\AuthThrottleFeature;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\ActionRateLimitedException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos as HilosFacade;
use Hilos\Runtime\State\Item\AuthAttempt as StateAuthAttempt;
use Hilos\Runtime\View\Collection\AuthAttempts;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Unit tests for the deferred-action pool the throttle guard parks into (HIL-420).
 *
 * What is pinned here is that waiting costs the connection its turn and nothing else: a
 * throttled action does not reach its handler before a verdict, reaches it unchanged once
 * every key allowed it, and is refused with a retry-after the moment any key does not. The
 * two ways a wait can end badly are pinned too, because both are silent failures otherwise
 * - a verdict that never arrives must run the action rather than strand it, and a block
 * already visible in this worker's replica must refuse without a signal at all.
 */
final class PageSignalRouterThrottlePoolTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-throttle-1';

    private const string CLIENT_IP = '203.0.113.7';

    private const string SESSION_IDENTITY = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';

    private const string AGENT_ID = 'unit-throttle-host';

    private const int RETRY_AFTER = 120;

    private ?SignalRouter $previousSignalRouter = null;

    private ?EnvAccessor $previousEnv = null;

    private ?RtContext $previousRt = null;

    protected function setUp(): void
    {
        $this->previousSignalRouter = HilosFacade::$sr;
        $this->previousEnv = HilosFacade::$env;
        $this->previousRt = HilosFacade::$rt;
        HilosFacade::$sr = new SignalRouter();
        HilosFacade::$env = new EnvAccessor(EnvCatalogStub::class);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregister(StateAuthAttempt::RT_COLLECTION, self::AGENT_ID);
        ExecutionContext::clear();
        putenv(EnvConstants::HILOS_AUTH_THROTTLE_VERDICT_TIMEOUT_MS->name);
        putenv(EnvConstants::HILOS_AUTH_THROTTLE_ENABLED->name);
        HilosFacade::$rt = $this->previousRt;
        HilosFacade::$env = $this->previousEnv;
        HilosFacade::$sr = $this->previousSignalRouter;
        HilosFacade::resetBrowser();

        parent::tearDown();
    }

    public function testAThrottledActionAsksAboutEveryKeyAndRunsForNone(): void
    {
        $page = $this->dispatchThrottledAction();

        $this->assertFalse($page->handled);
        $this->assertNull($page->actionException);

        $checks = $this->queuedChecks();
        $this->assertCount(2, $checks);
        $this->assertSame(
            [ThrottleScope::IP, ThrottleScope::SESSION],
            array_map(static fn(ThrottleCheckSignalData $check): string => $check->scope, $checks),
        );
        $this->assertSame([self::CLIENT_IP, self::SESSION_IDENTITY], array_map(
            static fn(ThrottleCheckSignalData $check): string => $check->identity,
            $checks,
        ));
        $this->assertSame($checks[0]->requestKey, $checks[1]->requestKey);
    }

    public function testTheActionRunsOnlyOnceEveryKeyHasAllowedIt(): void
    {
        $page = $this->dispatchThrottledAction();
        $router = $page->router;
        $requestKey = $this->queuedChecks()[0]->requestKey;

        $this->deliverVerdict($router, new ThrottleVerdictSignalData($requestKey, true));
        $this->assertFalse($page->handled);

        $this->deliverVerdict($router, new ThrottleVerdictSignalData($requestKey, true));
        $this->assertTrue($page->handled);
        $this->assertNull($page->actionException);
    }

    public function testOneRefusalAnswersTheCallerAndSkipsTheHandler(): void
    {
        $page = $this->dispatchThrottledAction();
        $requestKey = $this->queuedChecks()[0]->requestKey;

        $this->deliverVerdict(
            $page->router,
            new ThrottleVerdictSignalData($requestKey, false, self::RETRY_AFTER),
        );

        $this->assertFalse($page->handled);
        $this->assertInstanceOf(ActionRateLimitedException::class, $page->actionException);
        $this->assertSame(ActionRateLimitedException::ERROR_CODE, $page->actionException->errorCode);
        $this->assertSame(self::RETRY_AFTER, $page->actionException->retryAfter);
    }

    public function testATrackedCallerIsToldHowLongToWait(): void
    {
        $page = $this->dispatchThrottledAction(requestId: 'req-1');
        $requestKey = $this->queuedChecks()[0]->requestKey;

        $this->deliverVerdict(
            $page->router,
            new ThrottleVerdictSignalData($requestKey, false, self::RETRY_AFTER),
        );

        $this->assertFalse($page->handled);
        $this->assertInstanceOf(ActionRateLimitedException::class, $page->trackedFailure);
        $this->assertSame(ActionRateLimitedException::ERROR_CODE, $page->trackedFailure->errorCode);
        $this->assertSame(self::RETRY_AFTER, $page->trackedFailure->retryAfter);
        // The fixture page keeps the PUBLIC access level of AbstractPage, so the dispatcher
        // withholds the admin-only detail - the gate that decides it lives right here, at
        // the call, and nothing else in the suite reaches it (HIL-779).
        $this->assertFalse($page->trackedDetailAllowed);
    }

    public function testAVerdictThatNeverArrivesRunsTheActionRatherThanStrandingIt(): void
    {
        putenv(EnvConstants::HILOS_AUTH_THROTTLE_VERDICT_TIMEOUT_MS->name . '=1');
        $page = $this->dispatchThrottledAction();
        $this->assertFalse($page->handled);

        usleep(5000);
        $page->router->releaseExpiredDeferredActions();

        $this->assertTrue($page->handled);
        $this->assertNull($page->actionException);
    }

    public function testABlockAlreadyInForceRefusesWithoutAskingTheAgent(): void
    {
        $this->blockKey(ThrottleScope::SESSION, self::SESSION_IDENTITY);

        $page = $this->dispatchThrottledAction();

        $this->assertFalse($page->handled);
        $this->assertInstanceOf(ActionRateLimitedException::class, $page->actionException);
        $this->assertSame([], $this->queuedChecks());
    }

    public function testAnActionThePageDidNotThrottleIsNotParked(): void
    {
        $page = $this->dispatchAction(ThrottlePoolTestPage::OPEN_ACTION);

        $this->assertTrue($page->handled);
        $this->assertSame([], $this->queuedChecks());
    }

    public function testASwitchedOffLayerRunsTheActionAndQueuesNothing(): void
    {
        putenv(EnvConstants::HILOS_AUTH_THROTTLE_ENABLED->name . '=false');

        $page = $this->dispatchThrottledAction();

        $this->assertTrue($page->handled);
        $this->assertNull($page->actionException);
        $this->assertSame([], $this->queuedChecks());
    }

    /**
     * Dispatches the throttled action and returns the page it was routed to.
     *
     * @param ?string $requestId Client-minted request id, or null for an untracked action
     * @return ThrottlePoolTestPage Page the action was routed to
     * @throws PageNotFoundException When the fixture page cannot be resolved
     */
    private function dispatchThrottledAction(?string $requestId = null): ThrottlePoolTestPage
    {
        return $this->dispatchAction(ThrottlePoolTestPage::THROTTLED_ACTION, $requestId);
    }

    /**
     * Dispatches one action through a fresh router and returns the page it was routed to.
     *
     * @param string $action Action name to dispatch
     * @param ?string $requestId Client-minted request id, or null for an untracked action
     * @return ThrottlePoolTestPage Page the action was routed to
     * @throws PageNotFoundException When the fixture page cannot be resolved
     */
    private function dispatchAction(string $action, ?string $requestId = null): ThrottlePoolTestPage
    {
        $factory = new ThrottlePoolTestPageFactory(new ThrottlePoolTestAgent());
        $router = new PageSignalRouter(
            $factory,
            new ActionRouteConfig([
                ThrottlePoolTestPage::THROTTLED_ACTION => ThrottlePoolTestPage::PAGE,
                ThrottlePoolTestPage::OPEN_ACTION => ThrottlePoolTestPage::PAGE,
            ]),
        );

        $router->dispatchAction(
            new WebSocketActionSignalDTO(
                acceptKey: self::ACCEPT_KEY,
                action: $action,
                requestId: $requestId,
                clientIp: self::CLIENT_IP,
                sessionIdentity: self::SESSION_IDENTITY,
            ),
            SignalSource::WEBSOCKET,
        );

        $page = $factory->getPage(ThrottlePoolTestPage::PAGE);
        $this->assertInstanceOf(ThrottlePoolTestPage::class, $page);
        $page->router = $router;

        return $page;
    }

    /**
     * Hands one verdict to the router the way a routed agent signal would.
     *
     * @param PageSignalRouter $router Router holding the parked action
     * @param ThrottleVerdictSignalData $verdict Verdict to deliver
     */
    private function deliverVerdict(PageSignalRouter $router, ThrottleVerdictSignalData $verdict): void
    {
        $router->dispatchAgentSignal(
            new AgentSignalData($verdict),
            SignalSource::AGENT,
            HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT,
        );
    }

    /**
     * Drains the signal queue and returns the verdict requests it holds.
     *
     * @return list<ThrottleCheckSignalData> Checks queued for the throttle agent, in order
     */
    private function queuedChecks(): array
    {
        $checks = [];
        while (($signal = HilosFacade::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            if ($signal->signalName->getName() !== HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK) {
                continue;
            }

            $payload = $signal->data instanceof AgentSignalData ? $signal->data->data : null;
            if ($payload instanceof ThrottleCheckSignalData) {
                $checks[] = $payload;
            }
        }

        return $checks;
    }

    /**
     * Mounts the counters and puts one key under a block this worker can already see.
     *
     * @param string $scope Throttle scope to block
     * @param string $identity Identity to block
     */
    private function blockKey(string $scope, string $identity): void
    {
        $rt = new ThrottlePoolTestRtContext();
        $rt->mountFeatureRuntime([new AuthThrottleFeature()]);
        HilosFacade::$rt = $rt;

        RtTruthSourceRegistry::register(StateAuthAttempt::RT_COLLECTION, true, self::AGENT_ID);
        ExecutionContext::setCurrentAgentId(self::AGENT_ID);

        $attempts = $rt->hilosAuthAttempts;
        $this->assertInstanceOf(AuthAttempts::class, $attempts);
        $attempts->actions->open($scope, $identity, ThrottlePoolTestPage::THROTTLED_ACTION);
        $attempts[StateAuthAttempt::keyFor($scope, $identity, ThrottlePoolTestPage::THROTTLED_ACTION)]
            ?->actions->block(1, microtime(true) + self::RETRY_AFTER, microtime(true));
    }
}

final class ThrottlePoolTestPage extends AbstractPage
{
    public const string PAGE = 'throttle_pool';

    public const string THROTTLED_ACTION = 'sign_in';

    public const string OPEN_ACTION = 'read_something';

    public const array THROTTLED_ACTIONS = [self::THROTTLED_ACTION];

    public bool $handled = false;

    public ?Throwable $actionException = null;

    public ?Throwable $trackedFailure = null;

    public ?bool $trackedDetailAllowed = null;

    /** Router that dispatched into this page, so a test can drive its pool. */
    public ?PageSignalRouter $router = null;

    /**
     * Records that the handler ran.
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
     * Captures the untracked failure so a test can assert the refusal.
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

    /**
     * Captures the tracked failure ack instead of queuing it to a connection.
     *
     * @param string $acceptKey WebSocket accept key (unused)
     * @param string $action Action name (unused)
     * @param string $requestId Client-minted request id (unused)
     * @param Throwable $e Failure the ack would have been built from
     * @param bool $detailAllowed Whether the dispatcher proved the caller is an administrator
     */
    public function sendActionFailure(
        string $acceptKey,
        string $action,
        string $requestId,
        Throwable $e,
        bool $detailAllowed,
    ): void {
        $this->trackedFailure = $e;
        $this->trackedDetailAllowed = $detailAllowed;
    }
}

/**
 * Page factory fixture exposing the throttled test page.
 *
 * @extends AbstractPageFactory<ThrottlePoolTestAgent>
 */
final class ThrottlePoolTestPageFactory extends AbstractPageFactory
{
    /**
     * Creates the throttled test page.
     *
     * @param string $pageName Page name
     * @return AbstractPage Test page instance
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        if ($pageName === ThrottlePoolTestPage::PAGE) {
            return new ThrottlePoolTestPage($this->agent);
        }

        throw new PageNotFoundException($pageName);
    }

    /**
     * Reports whether the throttled test page is available.
     *
     * @param string $pageName Page name
     * @return bool True for the throttled test page
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === ThrottlePoolTestPage::PAGE;
    }
}

final class ThrottlePoolTestAgent implements PageAgentInterface
{
    /**
     * Returns the fixture agent id.
     *
     * @return string Agent id
     */
    public function getId(): string
    {
        return 'unit-throttle-page-agent';
    }

    /**
     * Returns the fixture signal source, which is also the verdict's return address.
     *
     * @return SignalSourceInterface Signal source
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'throttle_pool_agent');
    }
}

/**
 * Runtime context fixture that mounts only what a declared feature asks for.
 */
final class ThrottlePoolTestRtContext extends RtContext
{
    /**
     * Declares no project runtime of its own; the feature mount is the whole fixture.
     */
    public function configure(): void
    {
    }
}
