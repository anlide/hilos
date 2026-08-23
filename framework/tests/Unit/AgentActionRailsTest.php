<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Throttle\DTO\ThrottleCheckSignalData;
use Hilos\Auth\Throttle\DTO\ThrottleVerdictSignalData;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\Exception\ActionUnauthorizedException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Context\DbContext;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos as HilosFacade;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the rails an agent-owned action rides since HIL-622.
 *
 * Until this seam, an action declared in AGENT_ACTIONS was called straight from the worker
 * and so had none of what a page-owned action has had for a year: no reply to a tracked
 * caller, no anti-abuse park, no 401 on a guarded name. What is pinned here is that the
 * agent now meets the same conveyor a page meets - the same ack frames, the same parked
 * wait, the same refusal - and that it meets them in the same order.
 */
final class AgentActionRailsTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-agent-1';

    private const string CLIENT_IP = '203.0.113.9';

    private const string SESSION_IDENTITY = '1b4f0e9851971998e732078544c96b36c3d01cedf7caa332359d6f1d83567014';

    private ?SignalRouter $previousSignalRouter = null;

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousSignalRouter = HilosFacade::$sr;
        $this->previousEnv = HilosFacade::$env;
        HilosFacade::$sr = new AgentRailsTestSignalRouter();
        HilosFacade::$env = new EnvAccessor(EnvCatalogStub::class);
    }

    protected function tearDown(): void
    {
        ExecutionContext::clear();
        putenv(EnvConstants::HILOS_AUTH_THROTTLE_ENABLED->name);
        HilosFacade::$env = $this->previousEnv;
        HilosFacade::$sr = $this->previousSignalRouter;
        HilosFacade::resetBrowser();

        parent::tearDown();
    }

    public function testTrackedAgentActionAnswersItsCallerWithTheHandlerReply(): void
    {
        $agent = new AgentRailsTestAgent();

        $this->dispatch($agent, AgentRailsTestAgent::OPEN_ACTION, 'req-1');

        $this->assertSame(self::ACCEPT_KEY, $agent->handledAcceptKey);
        $ack = $this->successAck();
        $this->assertInstanceOf(PageActionSuccessSignalData::class, $ack);
        $this->assertSame('req-1', $ack->requestId);
        $this->assertSame(['ok' => true], $ack->reply);
    }

    public function testUntrackedAgentActionRunsAndAnswersNothing(): void
    {
        $agent = new AgentRailsTestAgent();

        $this->dispatch($agent, AgentRailsTestAgent::OPEN_ACTION);

        $this->assertSame(self::ACCEPT_KEY, $agent->handledAcceptKey);
        $this->assertNull($this->successAck());
    }

    public function testDeferringHandlerAnswersNothingAndIsHandedTheRequestId(): void
    {
        $agent = new AgentRailsTestAgent();

        $this->dispatch($agent, AgentRailsTestAgent::DEFERRED_ACTION, 'req-deferred');

        $this->assertSame(self::ACCEPT_KEY, $agent->handledAcceptKey);
        $this->assertSame('req-deferred', $agent->handedOffRequestId);
        $this->assertNull($this->successAck());
    }

    public function testDeferralDoesNotSurviveIntoTheNextAction(): void
    {
        $agent = new AgentRailsTestAgent();

        $this->dispatch($agent, AgentRailsTestAgent::DEFERRED_ACTION, 'req-deferred');
        $this->dispatch($agent, AgentRailsTestAgent::OPEN_ACTION, 'req-after');

        $ack = $this->successAck();
        $this->assertInstanceOf(PageActionSuccessSignalData::class, $ack);
        $this->assertSame('req-after', $ack->requestId);
    }

    /**
     * The request id belongs to its dispatch and is unreadable once that dispatch is over.
     *
     * The library builds a hand-off frame from what the rails say is running, and one of its
     * frames - the ending of a provider login - is built from a signal handler, outside any
     * action. Left readable, the id of the last action answered would ride that frame and
     * name a caller who has already been told the answer (HIL-622).
     */
    public function testTheRequestIdIsUnreadableOnceTheDispatchIsOver(): void
    {
        $agent = new AgentRailsTestAgent();

        $this->dispatch($agent, AgentRailsTestAgent::OPEN_ACTION, 'req-done');

        $this->assertSame('req-done', $agent->requestIdDuringDispatch);
        $this->assertNull($agent->currentActionRequestId());
    }

    public function testGuardedAgentActionIsDeniedToAnAnonymousSessionBeforeTheHandler(): void
    {
        HilosFacade::$browser = new AgentRailsTestBrowser(null);
        $agent = new AgentRailsTestAgent();

        $this->dispatch($agent, AgentRailsTestAgent::GUARDED_ACTION, 'req-2');

        $this->assertNull($agent->handledAcceptKey);
        $error = $this->errorAck();
        $this->assertInstanceOf(PageActionErrorSignalData::class, $error);
        $this->assertSame('req-2', $error->requestId);
        $this->assertSame(ActionUnauthorizedException::ERROR_CODE, $error->errorCode);
    }

    public function testGuardedAgentActionRunsForAnAuthenticatedSession(): void
    {
        HilosFacade::$browser = new AgentRailsTestBrowser(42);
        $agent = new AgentRailsTestAgent();

        $this->dispatch($agent, AgentRailsTestAgent::GUARDED_ACTION, 'req-3');

        $this->assertSame(self::ACCEPT_KEY, $agent->handledAcceptKey);
        $this->assertInstanceOf(PageActionSuccessSignalData::class, $this->successAck());
    }

    public function testThrottledAgentActionWaitsForItsVerdictBeforeTheHandler(): void
    {
        $agent = new AgentRailsTestAgent();

        $router = $this->dispatch($agent, AgentRailsTestAgent::THROTTLED_ACTION);
        $checks = $this->queuedChecks();

        $this->assertNull($agent->handledAcceptKey);
        $this->assertNotSame([], $checks);

        foreach ($checks as $check) {
            $router->dispatchAgentSignal(
                new AgentSignalData(new ThrottleVerdictSignalData($check->requestKey, true)),
                SignalSource::AGENT,
                HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT,
            );
        }

        $this->assertSame(self::ACCEPT_KEY, $agent->handledAcceptKey);
    }

    public function testAnUnthrottledAgentActionAsksTheAntiAbuseLayerNothing(): void
    {
        $agent = new AgentRailsTestAgent();

        $this->dispatch($agent, AgentRailsTestAgent::OPEN_ACTION);

        $this->assertSame([], $this->queuedChecks());
    }

    /**
     * Dispatches one agent-owned action through a router built over that agent.
     *
     * @param AgentRailsTestAgent $agent Agent owning the action
     * @param string $action Action name to dispatch
     * @param ?string $requestId Client-minted request id, or null for an untracked action
     * @return PageSignalRouter Router the action went through, for verdict delivery
     */
    private function dispatch(AgentRailsTestAgent $agent, string $action, ?string $requestId = null): PageSignalRouter
    {
        $router = new PageSignalRouter(new AgentRailsTestPageFactory($agent), new ActionRouteConfig([]));
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

        return $router;
    }

    /**
     * Finds the success ack among the frames queued to the connection.
     *
     * @return ?PageActionSuccessSignalData Success ack, or null when none was queued
     */
    private function successAck(): ?PageActionSuccessSignalData
    {
        $data = $this->queuedToUser(SignalConstants::ACTION_SUCCESS);

        return $data instanceof PageActionSuccessSignalData ? $data : null;
    }

    /**
     * Finds the error ack among the frames queued to the connection.
     *
     * @return ?PageActionErrorSignalData Error ack, or null when none was queued
     */
    private function errorAck(): ?PageActionErrorSignalData
    {
        $data = $this->queuedToUser(SignalConstants::ACTION_ERROR);

        return $data instanceof PageActionErrorSignalData ? $data : null;
    }

    /**
     * Drains the queue and returns the first payload delivered under one signal name.
     *
     * @param string $signalName Signal name to match
     * @return ?object Wrapped payload, or null when the name is absent
     */
    private function queuedToUser(string $signalName): ?object
    {
        $found = null;
        while (($signal = HilosFacade::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $envelope = $signal->data;
            if ($found === null && $signal->signalName->getName() === $signalName && $envelope instanceof WebSocketSignalData) {
                $found = $envelope->data;
            }
        }

        return $found;
    }

    /**
     * Drains the queue and returns the anti-abuse checks it holds.
     *
     * @return list<ThrottleCheckSignalData> Checks queued for the throttle agent, in order
     */
    private function queuedChecks(): array
    {
        $checks = [];
        while (($signal = HilosFacade::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $payload = $signal->data instanceof AgentSignalData ? $signal->data->data : null;
            if ($payload instanceof ThrottleCheckSignalData) {
                $checks[] = $payload;
            }
        }

        return $checks;
    }
}

/**
 * Reply payload the fixture agent answers its tracked action with.
 */
final class AgentRailsReplyStub extends ActionReplyDTO
{
    /**
     * @return array<string, mixed> Flat reply the ack carries
     */
    public function toArray(): array
    {
        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $data Wire payload (unused by the fixture)
     * @return static Reply instance
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}

/**
 * Payload DTO of every fixture action; the fixture actions carry no data.
 */
final class AgentRailsActionDTO extends ActionPayloadDTO
{
    /**
     * @return string Action name this payload stands for
     */
    public function getAction(): string
    {
        return AgentRailsTestAgent::OPEN_ACTION;
    }

    /**
     * @return array<string, mixed> Empty wire payload
     */
    public function toArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data Wire payload (unused by the fixture)
     * @return static Payload instance
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}

/**
 * Agent owning one open action, one guarded action, and one throttled action.
 */
final class AgentRailsTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'agent_rails_test';

    public const string OPEN_ACTION = 'agent_rails_open';

    public const string GUARDED_ACTION = 'agent_rails_guarded';

    public const string THROTTLED_ACTION = 'agent_rails_throttled';

    public const string DEFERRED_ACTION = 'agent_rails_deferred';

    public const array AGENT_ACTIONS = [
        self::OPEN_ACTION => AgentRailsActionDTO::class,
        self::GUARDED_ACTION => AgentRailsActionDTO::class,
        self::THROTTLED_ACTION => AgentRailsActionDTO::class,
        self::DEFERRED_ACTION => AgentRailsActionDTO::class,
    ];

    public const array AUTH_ACTIONS = [self::GUARDED_ACTION];

    public const array THROTTLED_ACTIONS = [self::THROTTLED_ACTION];

    /** @var ?string Accept key the handler ran for, or null while it has not run */
    public ?string $handledAcceptKey = null;

    /** @var ?string Request id the handler saw, standing in for the one a hand-off frame carries */
    public ?string $handedOffRequestId = null;

    /** @var ?string Request id readable while the dispatch was running */
    public ?string $requestIdDuringDispatch = null;

    /**
     * Records the dispatch and answers with the fixture reply.
     *
     * The deferring action stands in for a sign-in: it reads the request id the way a
     * hand-off frame would carry it, says the answer is owed elsewhere, and returns
     * nothing of its own.
     *
     * @param string $acceptKey Acting connection accept key
     * @param string $action Owned action name
     * @param ActionPayloadDTO $dto Parsed action payload (unused)
     * @return ?ActionReplyDTO Fixture reply, so the tracked ack has something to carry
     */
    public function onAgentAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        $this->handledAcceptKey = $acceptKey;
        $this->requestIdDuringDispatch = $this->currentActionRequestId();
        if ($action === self::DEFERRED_ACTION) {
            $this->handedOffRequestId = $this->currentActionRequestId();
            $this->deferActionReply();

            return null;
        }

        return new AgentRailsReplyStub();
    }

    /**
     * Fixture agent stops with nothing to unwind.
     */
    public function onStop(): void
    {
    }
}

/**
 * DB context the fixture facade must return; no test here reaches the database.
 */
final class AgentRailsTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for the agent-action rails tests.
     */
    public function configure(): void
    {
    }
}

/**
 * Topology fixture: the registry the computed agent-action routes are read from.
 */
final class AgentRailsTestHilos extends HilosFacade
{
    public const array AGENTS = [
        AgentRailsTestAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => AgentRailsTestAgent::class],
    ];

    /**
     * Creates the no-op DB context the fixture facade is built with.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new AgentRailsTestDbContext();
    }
}

/**
 * Signal router reading routes from the fixture topology instead of the empty framework one.
 */
final class AgentRailsTestSignalRouter extends SignalRouter
{
    /**
     * @return string Fixture Hilos facade class holding the test agent registry
     */
    protected function hilosClass(): string
    {
        return AgentRailsTestHilos::class;
    }
}

/**
 * Page factory over the fixture agent; the fixture declares no pages at all.
 *
 * @extends AbstractPageFactory<AgentRailsTestAgent>
 */
final class AgentRailsTestPageFactory extends AbstractPageFactory
{
    /**
     * Refuses every page name: an agent-owned action must never reach a page.
     *
     * @param string $pageName Page name
     * @return AbstractPage Never returns
     * @throws PageNotFoundException Always
     */
    protected function createPage(string $pageName): AbstractPage
    {
        throw new PageNotFoundException($pageName);
    }

    /**
     * @param string $pageName Page name (unused)
     * @return bool Always false; the fixture has no pages
     */
    public function hasPage(string $pageName): bool
    {
        return false;
    }
}

/**
 * Browser context resolving every connection to one injected user id.
 */
final class AgentRailsTestBrowser extends BrowserContext
{
    /**
     * @param ?int $userId User id behind every connection, or null for an anonymous one
     */
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
