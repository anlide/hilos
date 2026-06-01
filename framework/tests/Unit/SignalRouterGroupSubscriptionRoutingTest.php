<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Group\AbstractGroup;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Database\Context\DbContext;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos as HilosFacade;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUpdateSubscriptionSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests topology-driven routing for WebSocket group subscription signals.
 */
final class SignalRouterGroupSubscriptionRoutingTest extends TestCase
{
    public function testGroupSubscribeRoutesThroughProjectTopology(): void
    {
        $this->assertEquals(
            [
                new AgentDestination(SignalRouterTopologyTestGroup::SUBSCRIPTION_AGENT_TYPE),
            ],
            (new SignalRouterGroupTopologyTestRouter())->getDestinations(
                $this->groupSubscribeSignal(SignalRouterTopologyTestGroup::GROUP),
            ),
        );
    }

    public function testGroupUpdateSubscriptionRoutesThroughProjectTopology(): void
    {
        $this->assertEquals(
            [
                new AgentDestination(SignalRouterTopologyTestGroup::SUBSCRIPTION_AGENT_TYPE),
            ],
            (new SignalRouterGroupTopologyTestRouter())->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION),
                new SignalName(SignalRouterTopologyTestGroup::GROUP),
                new WebSocketGroupUpdateSubscriptionSignalDTO('accept-key', SignalRouterTopologyTestGroup::GROUP),
            )),
        );
    }

    public function testGroupUnsubscribeRoutesThroughProjectTopology(): void
    {
        $this->assertEquals(
            [
                new AgentDestination(SignalRouterTopologyTestGroup::SUBSCRIPTION_AGENT_TYPE),
            ],
            (new SignalRouterGroupTopologyTestRouter())->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::GROUP_UNSUBSCRIBE),
                new SignalName(SignalRouterTopologyTestGroup::GROUP),
                new WebSocketGroupUnsubscribeSignalDTO('accept-key', SignalRouterTopologyTestGroup::GROUP),
            )),
        );
    }

    public function testUnregisteredGroupUsesProjectFallback(): void
    {
        $this->assertEquals(
            [
                new AgentDestination(SignalRouterTopologyFallbackTestRouter::FALLBACK_AGENT_TYPE),
            ],
            (new SignalRouterGroupTopologyFallbackTestRouter())->getDestinations(
                $this->groupSubscribeSignal('unregistered_group'),
            ),
        );
    }

    public function testBaseRouterDoesNotRouteUnregisteredGroupWithoutFallback(): void
    {
        $this->assertEquals(
            [],
            (new SignalRouter())->getDestinations($this->groupSubscribeSignal('unregistered_group')),
        );
    }

    /**
     * Creates a group subscribe signal for routing tests.
     *
     * @param string $group Group name
     * @return SignalDTO Subscribe signal
     */
    private function groupSubscribeSignal(string $group): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::GROUP_SUBSCRIBE),
            new SignalName($group),
            new WebSocketGroupSubscribeSignalDTO('accept-key', $group),
        );
    }
}

final class SignalRouterGroupTopologyTestRouter extends SignalRouter
{
    /**
     * Returns fixture facade for topology reads.
     *
     * @return class-string<HilosFacade> Fixture facade class
     */
    protected function hilosClass(): string
    {
        return SignalRouterGroupTopologyTestHilos::class;
    }
}

final class SignalRouterGroupTopologyFallbackTestRouter extends SignalRouter
{
    public const string FALLBACK_AGENT_TYPE = 'fallback_agent';

    /**
     * Returns fixture facade for topology reads.
     *
     * @return class-string<HilosFacade> Fixture facade class
     */
    protected function hilosClass(): string
    {
        return SignalRouterGroupTopologyTestHilos::class;
    }

    /**
     * Returns fixture fallback agent for unregistered groups.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultGroupSubscriptionAgentType(): ?string
    {
        return self::FALLBACK_AGENT_TYPE;
    }
}

final class SignalRouterTopologyTestGroup extends AbstractGroup
{
    public const string GROUP = 'topology_group';

    public const string SUBSCRIPTION_AGENT_TYPE = 'topology_agent';
}

final class SignalRouterGroupTopologyTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for router topology tests.
     */
    public function configure(): void
    {
    }
}

final class SignalRouterGroupTopologyTestHilos extends HilosFacade
{
    public const array GROUPS = [
        SignalRouterTopologyTestGroup::GROUP => SignalRouterTopologyTestGroup::class,
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new SignalRouterGroupTopologyTestDbContext();
    }
}
