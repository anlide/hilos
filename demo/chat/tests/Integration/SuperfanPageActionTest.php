<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosSuperfanAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Superfan\SuperfanPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\AI\Agent\SuperfanAiAgentId;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Hilos\AbstractHilosSuperfanAgent;
use Hilos\Core\Agent\Hilos\SuperfanRunStatus;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\Exception\ActionUnauthorizedException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use RuntimeException;

/**
 * A refused superfan run start or stop writes nothing to runtime state (HIL-1252).
 *
 * The frames go through the real page router, because the guard that refuses them is the
 * router's: a page called directly would skip it and prove nothing. The refusal still reaches
 * the page's failure hook - that is how an untracked caller learns about it - so what these
 * cases pin is that the hook no longer records FAILED, while a start the agent was really
 * asked for and which threw still does.
 */
final class SuperfanPageActionTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    private const string UNKNOWN_SUPERFAN_ID = 'no-such-superfan';

    private const string REQUEST_ID = 'hil-1252-request';

    protected function setUp(): void
    {
        parent::setUp();

        Hilos::initSignalRouter(new ChatSignalRouter());
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        $this->drainQueuedSignals();
    }

    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        $this->drainQueuedSignals();
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testAnonymousStartOnAnInventedIdIsRefusedAndWritesNothing(): void
    {
        $agent = new DemoHilosSuperfanAgent();
        $this->startAgent($agent);

        try {
            Hilos::$rt->connections->actions->register('superfan-anon-ak', null);
            $before = $this->statusSnapshot();

            $this->dispatch($agent, 'superfan-anon-ak', ChatSignalConstants::SUPERFAN_AGENT_RUN_START, self::UNKNOWN_SUPERFAN_ID);

            $this->assertSame($before, $this->statusSnapshot());
            $this->assertArrayNotHasKey(self::UNKNOWN_SUPERFAN_ID, $this->statusSnapshot());
            $error = $this->takeQueuedActionError();
            $this->assertNotNull($error);
            $this->assertSame(ActionUnauthorizedException::ERROR_CODE, $error->errorCode);
        } finally {
            $this->stopAgent($agent);
        }
    }

    public function testSignedInNonAdminStartAndStopAreRefusedAndLeaveTheStatus(): void
    {
        $agent = new DemoHilosSuperfanAgent();
        $this->startAgent($agent);
        $superfanId = SuperfanAiAgentId::cases()[0]->value;

        try {
            $user = Hilos::$db->users->actions->createWithName('Superfan Viewer');
            Hilos::$rt->connections->actions->register('superfan-user-ak', $user->id);

            foreach ([ChatSignalConstants::SUPERFAN_AGENT_RUN_START, ChatSignalConstants::SUPERFAN_AGENT_RUN_STOP] as $action) {
                $this->dispatch($agent, 'superfan-user-ak', $action, $superfanId);

                $this->assertSame(SuperfanRunStatus::NOT_STARTED->value, $this->statusSnapshot()[$superfanId] ?? null);
                $this->assertNotNull($this->takeQueuedActionError(), "Expected {$action} to be refused.");
            }
        } finally {
            $this->stopAgent($agent);
        }
    }

    public function testAdminStartStillRunsTheSuperfan(): void
    {
        $agent = new DemoHilosSuperfanAgent();
        $this->startAgent($agent);
        $superfanId = SuperfanAiAgentId::cases()[0]->value;

        try {
            $this->registerAdmin('superfan-admin-ak');

            $this->dispatch($agent, 'superfan-admin-ak', ChatSignalConstants::SUPERFAN_AGENT_RUN_START, $superfanId);

            $this->assertSame(SuperfanRunStatus::IN_PROGRESS->value, $this->statusSnapshot()[$superfanId] ?? null);
            $this->assertNull($this->takeQueuedActionError());
        } finally {
            $this->stopAgent($agent);
        }
    }

    public function testAdminStartTheAgentThrowsOnIsMarkedFailed(): void
    {
        $agent = new FailingSuperfanAgent();
        RtTruthSourceRegistry::register(ChatRtContext::superfanAgentStatuses, TruthSourceKeys::all(), $agent->getId());

        try {
            $this->registerAdmin('superfan-admin-ak');

            $this->dispatch($agent, 'superfan-admin-ak', ChatSignalConstants::SUPERFAN_AGENT_RUN_START, FailingSuperfanAgent::SUPERFAN_ID);

            $this->assertSame(SuperfanRunStatus::FAILED->value, $this->statusSnapshot()[FailingSuperfanAgent::SUPERFAN_ID] ?? null);
            $error = $this->takeQueuedActionError();
            $this->assertNotNull($error);
            $this->assertNull($error->requestId);
        } finally {
            $this->stopAgent($agent);
        }
    }

    public function testTrackedAdminStartTheAgentThrowsOnIsMarkedFailed(): void
    {
        $agent = new FailingSuperfanAgent();
        RtTruthSourceRegistry::register(ChatRtContext::superfanAgentStatuses, TruthSourceKeys::all(), $agent->getId());

        try {
            $this->registerAdmin('superfan-admin-ak');

            $this->dispatch(
                $agent,
                'superfan-admin-ak',
                ChatSignalConstants::SUPERFAN_AGENT_RUN_START,
                FailingSuperfanAgent::SUPERFAN_ID,
                self::REQUEST_ID,
            );

            // Before this leaf a tracked failure never reached the hook, so it wrote no FAILED at all.
            $this->assertSame(SuperfanRunStatus::FAILED->value, $this->statusSnapshot()[FailingSuperfanAgent::SUPERFAN_ID] ?? null);
            $error = $this->takeQueuedActionError();
            $this->assertNotNull($error);
            $this->assertSame(self::REQUEST_ID, $error->requestId);
        } finally {
            $this->stopAgent($agent);
        }
    }

    /**
     * Sends one superfan action frame through the real page router, as the agent serving the page.
     *
     * @param AbstractAgent $agent Agent the page is served by
     * @param string $acceptKey Acting connection accept key
     * @param string $action Superfan action name
     * @param string $superfanId Superfan agent id the frame names
     * @param ?string $requestId Client-minted request id, or null for an untracked action
     */
    private function dispatch(
        AbstractAgent $agent,
        string $acceptKey,
        string $action,
        string $superfanId,
        ?string $requestId = null,
    ): void {
        $router = new PageSignalRouter(
            new HilosPageFactory($agent, Hilos::class),
            new ActionRouteConfig([
                ChatSignalConstants::SUPERFAN_AGENT_RUN_START => SuperfanPage::PAGE,
                ChatSignalConstants::SUPERFAN_AGENT_RUN_STOP => SuperfanPage::PAGE,
            ]),
        );
        $frame = new WebSocketActionSignalDTO($acceptKey, $action, ['agentId' => $superfanId], $requestId);

        $this->underAgent($agent, static fn () => $router->dispatchAction($frame, 'websocket'));
    }

    /**
     * Registers a signed-in administrator behind one accept key.
     *
     * @param string $acceptKey Accept key the administrator acts from
     */
    private function registerAdmin(string $acceptKey): void
    {
        $admin = Hilos::$db->users->actions->createWithName('Superfan Admin');
        Hilos::$db->users[$admin->id]->actions->setAdmin(true);
        Hilos::$rt->connections->actions->register($acceptKey, $admin->id);
    }

    /**
     * Reads the superfan run statuses as they stand in runtime state.
     *
     * @return array<string, string> Status value keyed by superfan agent id
     */
    private function statusSnapshot(): array
    {
        $snapshot = [];
        foreach (Hilos::$rt->superfanAgentStatuses as $status) {
            $snapshot[$status->agentId] = $status->status;
        }
        ksort($snapshot);

        return $snapshot;
    }

    /**
     * Stops one superfan agent under its own id, so the runtime rows it owns are cleared.
     *
     * @param AbstractAgent $agent Agent to stop
     */
    private function stopAgent(AbstractAgent $agent): void
    {
        $this->underAgent($agent, static fn () => $agent->onStop());
        RtTruthSourceRegistry::unregisterAgent($agent->getId());
    }

    /**
     * Takes the first queued action-error frame, dropping the frames queued before it.
     *
     * @return ?PageActionErrorSignalData The error frame, or null when none was queued
     */
    private function takeQueuedActionError(): ?PageActionErrorSignalData
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalConstants::ACTION_ERROR) {
                continue;
            }

            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $this->assertInstanceOf(PageActionErrorSignalData::class, $signal->data->data);

            return $signal->data->data;
        }

        return null;
    }

    /**
     * Drops every frame left in the signal queue.
     */
    private function drainQueuedSignals(): void
    {
        while (Hilos::$sr?->getNextQueuedSignal() !== null) {
            continue;
        }
    }
}

/**
 * Superfan agent whose every start throws, standing in for a run that fails once begun.
 */
final class FailingSuperfanAgent extends AbstractHilosSuperfanAgent
{
    public const string SUPERFAN_ID = 'hil-1252-failing-superfan';

    /**
     * Throws the way a start that broke on the way would.
     *
     * @param string $agentId Superfan agent identifier
     * @return SuperfanRunStatus Never returned
     * @throws RuntimeException Always
     */
    public function startSuperfanRun(string $agentId): SuperfanRunStatus
    {
        throw new RuntimeException("Superfan run {$agentId} failed to start.");
    }

    /**
     * Clears the superfan statuses this double wrote.
     */
    public function onStop(): void
    {
        Hilos::$rt->superfanAgentStatuses->actions->clear();
        $this->resetSuperfanRunStates();
    }

    /**
     * @return list<string> The one superfan id this double knows
     */
    protected function getKnownSuperfanAgentIds(): array
    {
        return [self::SUPERFAN_ID];
    }
}
