<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Browser\BrowserSubscriptionEvent;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WorkerManager browser context hooks.
 */
final class WorkerManagerBrowserContextTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$browser = null;
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testWorkerGroupSubscribeRecordsBrowserEvent(): void
    {
        $browser = new WorkerManagerBrowserContextTestBrowserContext();
        Hilos::$browser = $browser;

        $manager = new WorkerManagerBrowserContextTestManager(new WorkerManagerBrowserContextTestAgent());
        $manager->handleDaemonMessage(new AgentStartDTO(WorkerManagerBrowserContextTestAgent::AGENT_TYPE));

        $dto = new WebSocketGroupSubscribeSignalDTO('unit-ak', 'unit-group', ['filter' => 'active']);
        $manager->handleDaemonMessage(new DaemonAgentMessageDTO(
            WorkerManagerBrowserContextTestAgent::AGENT_TYPE,
            new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::GROUP_SUBSCRIBE),
                new SignalName('unit-group'),
                $dto,
            ),
        ));

        $this->assertTrue($browser->hasSubscriptionEvents());

        $browser->flushToSignalRouter();

        $this->assertCount(1, $browser->dispatchedEvents);
        $this->assertSame(BrowserContext::EVENT_GROUP_SUBSCRIBED, $browser->dispatchedEvents[0]->event);
        $this->assertSame($dto, $browser->dispatchedEvents[0]->signalData);
        $this->assertSame(SignalSource::WEBSOCKET, $browser->dispatchedEvents[0]->source);
        $this->assertSame('unit-group', $browser->dispatchedEvents[0]->name);
    }
}

final class WorkerManagerBrowserContextTestBrowserContext extends BrowserContext
{
    /** @var list<BrowserSubscriptionEvent> */
    public array $dispatchedEvents = [];

    public function configure(): void
    {
    }

    /**
     * Records the events that reached the final browser dispatch hook.
     */
    protected function dispatchBrowserSignals(): void
    {
        $this->dispatchedEvents = $this->subscriptionEvents;
    }
}

final class WorkerManagerBrowserContextTestManager extends WorkerManager
{
    public function __construct(
        private readonly WorkerManagerBrowserContextTestAgent $testAgent,
    ) {
        parent::__construct(1);
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new WorkerManagerBrowserContextTestAgentManager($this->testAgent);
    }
}

final class WorkerManagerBrowserContextTestAgentManager extends AgentManager
{
    public function __construct(
        private readonly WorkerManagerBrowserContextTestAgent $testAgent,
    ) {
    }

    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return $this->testAgent;
    }
}

final class WorkerManagerBrowserContextTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_browser_context';

    public function onStop(): void
    {
    }
}
