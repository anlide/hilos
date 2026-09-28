<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosGuardianAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Guardian\GuardianAgentPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\AI\Agent\GuardianAiAgentId;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Hilos\AbstractHilosGuardianAgent;
use Hilos\Core\Agent\Hilos\GuardianRunStatus;
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
 * A refused guardian run start or stop writes nothing to runtime state (HIL-1252).
 *
 * The frames go through the real page router, because the guard that refuses them is the
 * router's: a page called directly would skip it and prove nothing. The refusal still reaches
 * the page's failure hook - that is how an untracked caller learns about it - so what these
 * cases pin is that the hook no longer records FAILED, while a start the agent was really
 * asked for and which threw still does.
 */
final class GuardianAgentPageActionTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    private const string UNKNOWN_GUARDIAN_ID = 'no-such-guardian';

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
        $agent = new DemoHilosGuardianAgent();
        $this->startAgent($agent);

        try {
            Hilos::$rt->connections->actions->register('guardian-anon-ak', null);
            $before = $this->statusSnapshot();

            $this->dispatch($agent, 'guardian-anon-ak', ChatSignalConstants::GUARDIAN_AGENT_RUN_START, self::UNKNOWN_GUARDIAN_ID);

            $this->assertSame($before, $this->statusSnapshot());
            $this->assertArrayNotHasKey(self::UNKNOWN_GUARDIAN_ID, $this->statusSnapshot());
            $error = $this->takeQueuedActionError();
            $this->assertNotNull($error);
            $this->assertSame(ActionUnauthorizedException::ERROR_CODE, $error->errorCode);
        } finally {
            $this->stopAgent($agent);
        }
    }

    public function testSignedInNonAdminStartAndStopAreRefusedAndLeaveTheStatus(): void
    {
        $agent = new DemoHilosGuardianAgent();
        $this->startAgent($agent);
        $guardianId = GuardianAiAgentId::cases()[0]->value;

        try {
            $user = Hilos::$db->users->actions->createWithName('Guardian Viewer');
            Hilos::$rt->connections->actions->register('guardian-user-ak', $user->id);

            foreach ([ChatSignalConstants::GUARDIAN_AGENT_RUN_START, ChatSignalConstants::GUARDIAN_AGENT_RUN_STOP] as $action) {
                $this->dispatch($agent, 'guardian-user-ak', $action, $guardianId);

                $this->assertSame(GuardianRunStatus::NOT_STARTED->value, $this->statusSnapshot()[$guardianId] ?? null);
                $this->assertNotNull($this->takeQueuedActionError(), "Expected {$action} to be refused.");
            }
        } finally {
            $this->stopAgent($agent);
        }
    }

    public function testAdminStartStillRunsTheGuardian(): void
    {
        $agent = new DemoHilosGuardianAgent();
        $this->startAgent($agent);
        $guardianId = GuardianAiAgentId::cases()[0]->value;

        try {
            $this->registerAdmin('guardian-admin-ak');

            $this->dispatch($agent, 'guardian-admin-ak', ChatSignalConstants::GUARDIAN_AGENT_RUN_START, $guardianId);

            $this->assertSame(GuardianRunStatus::IN_PROGRESS->value, $this->statusSnapshot()[$guardianId] ?? null);
            $this->assertNull($this->takeQueuedActionError());
        } finally {
            $this->stopAgent($agent);
        }
    }

    public function testAdminStartTheAgentThrowsOnIsMarkedFailed(): void
    {
        $agent = new FailingGuardianAgent();
        RtTruthSourceRegistry::register(ChatRtContext::guardianAgentStatuses, TruthSourceKeys::all(), $agent->getId());

        try {
            $this->registerAdmin('guardian-admin-ak');

            $this->dispatch($agent, 'guardian-admin-ak', ChatSignalConstants::GUARDIAN_AGENT_RUN_START, FailingGuardianAgent::GUARDIAN_ID);

            $this->assertSame(GuardianRunStatus::FAILED->value, $this->statusSnapshot()[FailingGuardianAgent::GUARDIAN_ID] ?? null);
            $error = $this->takeQueuedActionError();
            $this->assertNotNull($error);
            $this->assertNull($error->requestId);
        } finally {
            $this->stopAgent($agent);
        }
    }

    public function testTrackedAdminStartTheAgentThrowsOnIsMarkedFailed(): void
    {
        $agent = new FailingGuardianAgent();
        RtTruthSourceRegistry::register(ChatRtContext::guardianAgentStatuses, TruthSourceKeys::all(), $agent->getId());

        try {
            $this->registerAdmin('guardian-admin-ak');

            $this->dispatch(
                $agent,
                'guardian-admin-ak',
                ChatSignalConstants::GUARDIAN_AGENT_RUN_START,
                FailingGuardianAgent::GUARDIAN_ID,
                self::REQUEST_ID,
            );

            // Before this leaf a tracked failure never reached the hook, so it wrote no FAILED at all.
            $this->assertSame(GuardianRunStatus::FAILED->value, $this->statusSnapshot()[FailingGuardianAgent::GUARDIAN_ID] ?? null);
            $error = $this->takeQueuedActionError();
            $this->assertNotNull($error);
            $this->assertSame(self::REQUEST_ID, $error->requestId);
        } finally {
            $this->stopAgent($agent);
        }
    }

    /**
     * Sends one guardian action frame through the real page router, as the agent serving the page.
     *
     * @param AbstractAgent $agent Agent the page is served by
     * @param string $acceptKey Acting connection accept key
     * @param string $action Guardian action name
     * @param string $guardianId Guardian agent id the frame names
     * @param ?string $requestId Client-minted request id, or null for an untracked action
     */
    private function dispatch(
        AbstractAgent $agent,
        string $acceptKey,
        string $action,
        string $guardianId,
        ?string $requestId = null,
    ): void {
        $router = new PageSignalRouter(
            new HilosPageFactory($agent, Hilos::class),
            new ActionRouteConfig([
                ChatSignalConstants::GUARDIAN_AGENT_RUN_START => GuardianAgentPage::PAGE,
                ChatSignalConstants::GUARDIAN_AGENT_RUN_STOP => GuardianAgentPage::PAGE,
            ]),
        );
        $frame = new WebSocketActionSignalDTO($acceptKey, $action, ['agentId' => $guardianId], $requestId);

        $this->underAgent($agent, static fn () => $router->dispatchAction($frame, 'websocket'));
    }

    /**
     * Registers a signed-in administrator behind one accept key.
     *
     * @param string $acceptKey Accept key the administrator acts from
     */
    private function registerAdmin(string $acceptKey): void
    {
        $admin = Hilos::$db->users->actions->createWithName('Guardian Admin');
        Hilos::$db->users[$admin->id]->actions->setAdmin(true);
        Hilos::$rt->connections->actions->register($acceptKey, $admin->id);
    }

    /**
     * Reads the guardian run statuses as they stand in runtime state.
     *
     * @return array<string, string> Status value keyed by guardian agent id
     */
    private function statusSnapshot(): array
    {
        $snapshot = [];
        foreach (Hilos::$rt->guardianAgentStatuses as $status) {
            $snapshot[$status->agentId] = $status->status;
        }
        ksort($snapshot);

        return $snapshot;
    }

    /**
     * Stops one guardian agent under its own id, so the runtime rows it owns are cleared.
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
 * Guardian agent whose every start throws, standing in for a run that fails once begun.
 */
final class FailingGuardianAgent extends AbstractHilosGuardianAgent
{
    public const string GUARDIAN_ID = 'hil-1252-failing-guardian';

    /**
     * Throws the way a start that broke on the way would.
     *
     * @param string $agentId Guardian agent identifier
     * @return GuardianRunStatus Never returned
     * @throws RuntimeException Always
     */
    public function startGuardianRun(string $agentId): GuardianRunStatus
    {
        throw new RuntimeException("Guardian run {$agentId} failed to start.");
    }

    /**
     * Clears the guardian statuses this double wrote.
     */
    public function onStop(): void
    {
        Hilos::$rt->guardianAgentStatuses->actions->clear();
        $this->resetGuardianRunStates();
    }

    /**
     * @return list<string> The one guardian id this double knows
     */
    protected function getKnownGuardianAgentIds(): array
    {
        return [self::GUARDIAN_ID];
    }
}
