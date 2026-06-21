<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Database\Context\DbContext;
use Hilos\Hilos as HilosFacade;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;
use Hilos\Socket\Worker\DTO\CronSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests topology-driven routing from project page and agent declarations.
 */
final class SignalRouterPageSubscriptionRoutingTest extends TestCase
{
    public function testPageSubscribeRoutesThroughProjectTopology(): void
    {
        $this->assertEquals(
            [
                new AgentDestination(SignalRouterTopologyTestPage::SUBSCRIPTION_AGENT_TYPE),
            ],
            (new SignalRouterTopologyTestRouter())->getDestinations(
                $this->pageSubscribeSignal(SignalRouterTopologyTestPage::PAGE),
            ),
        );
    }

    public function testTableViewportRoutesThroughProjectTopology(): void
    {
        $this->assertEquals(
            [
                new AgentDestination(SignalRouterTopologyTestPage::SUBSCRIPTION_AGENT_TYPE),
            ],
            (new SignalRouterTopologyTestRouter())->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::TABLE_VIEWPORT),
                new SignalName(SignalRouterTopologyTestPage::PAGE),
                new WebSocketTableViewportSignalDTO('accept-key', SignalRouterTopologyTestPage::PAGE, 'settings'),
            )),
        );
    }

    public function testBinaryFrameRoutesThroughPageSignalTopology(): void
    {
        $this->assertEquals(
            [
                new AgentDestination(SignalRouterTopologyTestPage::SUBSCRIPTION_AGENT_TYPE),
            ],
            (new SignalRouterTopologyTestRouter())->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::FRAME_BINARY),
                new SignalName(SignalName::EMPTY),
                new WebSocketFrameBinarySignalDTO('accept-key', 'payload'),
            )),
        );
    }

    public function testNamedAgentSignalRoutesThroughPageSignalTopology(): void
    {
        $this->assertEquals(
            [
                new AgentDestination(SignalRouterTopologyTestPage::SUBSCRIPTION_AGENT_TYPE),
            ],
            (new SignalRouterTopologyTestRouter())->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::AGENT),
                new SignalType(SignalTypeConstants::AGENT_SIGNAL),
                new SignalName(SignalRouterTopologyTestPage::PAGE_AGENT_SIGNAL),
                new AgentSignalData(new SignalData()),
            )),
        );
    }

    public function testPageOwnedSignalDoesNotRouteFromWrongSource(): void
    {
        $this->assertEquals(
            [],
            (new SignalRouterTopologyTestRouter())->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::DAEMON),
                new SignalType(SignalTypeConstants::FRAME_BINARY),
                new SignalName(SignalName::EMPTY),
                new WebSocketFrameBinarySignalDTO('accept-key', 'payload'),
            )),
        );
    }

    public function testNamedCronSignalRoutesThroughPageSignalTopology(): void
    {
        $this->assertEquals(
            [
                new AgentDestination(SignalRouterTopologyTestPage::SUBSCRIPTION_AGENT_TYPE),
            ],
            (new SignalRouterTopologyTestRouter())->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::DAEMON),
                new SignalType(SignalTypeConstants::CRON),
                new SignalName(SignalRouterTopologyTestPage::TOPOLOGY_CRON),
                new CronSignalDTO(SignalRouterTopologyTestPage::TOPOLOGY_CRON),
            )),
        );
    }

    public function testPageOwnedCronDoesNotRouteFromWrongSource(): void
    {
        $this->assertEquals(
            [],
            (new SignalRouterTopologyTestRouter())->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::CRON),
                new SignalName(SignalRouterTopologyTestPage::TOPOLOGY_CRON),
                new CronSignalDTO(SignalRouterTopologyTestPage::TOPOLOGY_CRON),
            )),
        );
    }

    public function testActionRoutesThroughProjectTopology(): void
    {
        $this->assertEquals(
            [
                new AgentDestination(SignalRouterTopologyTestPage::SUBSCRIPTION_AGENT_TYPE),
            ],
            (new SignalRouterTopologyTestRouter())->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::ACTION),
                new SignalName(SignalRouterTopologyTestPage::TOPOLOGY_ACTION),
                new WebSocketActionSignalDTO('accept-key', SignalRouterTopologyTestPage::TOPOLOGY_ACTION),
            )),
        );
    }

    public function testAgentOwnedSignalRoutesThroughProjectTopology(): void
    {
        $this->assertEquals(
            [
                new AgentDestination(SignalRouterTopologySignalTestAgent::AGENT_TYPE),
            ],
            (new SignalRouterTopologyTestRouter())->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::AGENT),
                new SignalType(SignalTypeConstants::AGENT_SIGNAL),
                new SignalName(SignalRouterTopologySignalTestAgent::TOPOLOGY_AGENT_SIGNAL),
                new AgentSignalData(new SignalData()),
            )),
        );
    }

    public function testAgentOwnedSignalDoesNotRouteFromWrongSource(): void
    {
        $this->assertEquals(
            [],
            (new SignalRouterTopologyTestRouter())->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::AGENT_SIGNAL),
                new SignalName(SignalRouterTopologySignalTestAgent::TOPOLOGY_AGENT_SIGNAL),
                new AgentSignalData(new SignalData()),
            )),
        );
    }

    public function testUnregisteredPageUsesProjectFallback(): void
    {
        $this->assertEquals(
            [
                new AgentDestination(SignalRouterTopologyFallbackTestRouter::FALLBACK_AGENT_TYPE),
            ],
            (new SignalRouterTopologyFallbackTestRouter())->getDestinations(
                $this->pageSubscribeSignal('unregistered_page'),
            ),
        );
    }

    public function testBaseRouterDoesNotRouteUnregisteredPageWithoutFallback(): void
    {
        $this->assertEquals(
            [],
            (new SignalRouter())->getDestinations($this->pageSubscribeSignal('unregistered_page')),
        );
    }

    /**
     * Creates a page subscribe signal for routing tests.
     *
     * @param string $page Page name
     * @return SignalDTO Subscribe signal
     */
    private function pageSubscribeSignal(string $page): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName($page),
            new WebSocketPageSubscribeSignalDTO('accept-key', $page),
        );
    }
}

final class SignalRouterTopologyTestRouter extends SignalRouter
{
    /**
     * Returns fixture facade for topology reads.
     *
     * @return class-string<HilosFacade> Fixture facade class
     */
    protected function hilosClass(): string
    {
        return SignalRouterTopologyTestHilos::class;
    }
}

final class SignalRouterTopologyFallbackTestRouter extends SignalRouter
{
    public const string FALLBACK_AGENT_TYPE = 'fallback_agent';

    /**
     * Returns fixture facade for topology reads.
     *
     * @return class-string<HilosFacade> Fixture facade class
     */
    protected function hilosClass(): string
    {
        return SignalRouterTopologyTestHilos::class;
    }

    /**
     * Returns fixture fallback agent for unregistered pages.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultPageSubscriptionAgentType(): ?string
    {
        return self::FALLBACK_AGENT_TYPE;
    }
}

final class SignalRouterTopologyTestPage extends AbstractPage
{
    public const string PAGE = 'topology_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'topology_agent';

    public const string PAGE_AGENT_SIGNAL = 'page_agent_signal';

    public const string TOPOLOGY_ACTION = 'topology_action';

    public const string TOPOLOGY_CRON = 'topology_cron';

    public const array ACTIONS = [
        self::TOPOLOGY_ACTION => SignalRouterTopologyTestActionPayloadDTO::class,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::FRAME_BINARY => [],
        SignalTypeConstants::CRON => [
            self::TOPOLOGY_CRON,
        ],
        SignalTypeConstants::AGENT_SIGNAL => [
            self::PAGE_AGENT_SIGNAL,
        ],
    ];
}

final class SignalRouterTopologyTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for router topology tests.
     */
    public function configure(): void
    {
    }
}

final class SignalRouterTopologyTestHilos extends HilosFacade
{
    public const array PAGES = [
        SignalRouterTopologyTestPage::PAGE => SignalRouterTopologyTestPage::class,
    ];

    public const array AGENTS = [
        SignalRouterTopologySignalTestAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => SignalRouterTopologySignalTestAgent::class,
            AgentRegistryKey::DAEMON => SignalRouterTopologySignalTestAgentDaemon::class,
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new SignalRouterTopologyTestDbContext();
    }
}

final class SignalRouterTopologySignalTestAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'topology_direct_agent';
}

final class SignalRouterTopologySignalTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'topology_direct_agent';

    public const string TOPOLOGY_AGENT_SIGNAL = 'topology_agent_signal';

    public const array AGENT_SIGNALS = [
        self::TOPOLOGY_AGENT_SIGNAL,
    ];

    /**
     * No-op stop hook for router topology tests.
     */
    public function onStop(): void
    {
    }
}

final class SignalRouterTopologyTestActionPayloadDTO extends ActionPayloadDTO
{
    /**
     * Creates a no-op router topology test action payload DTO.
     *
     * @param array<string, mixed> $data Payload data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }

    /**
     * Returns the router topology test action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return 'topology_action';
    }

    /**
     * Converts the DTO to array.
     *
     * @return array<string, mixed> Empty payload
     */
    public function toArray(): array
    {
        return [];
    }
}
