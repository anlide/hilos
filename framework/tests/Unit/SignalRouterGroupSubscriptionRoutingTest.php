<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Group\AbstractGroup;
use Hilos\Core\Group\DTO\GroupSubscriptionErrorSignalData;
use Hilos\Core\Group\GroupErrorCode;
use Hilos\Core\Group\GroupSubscriptionDispatcher;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Database\Context\DbContext;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
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
    protected function tearDown(): void
    {
        // One test installs a router on the facade; the global has to leave with it.
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testGroupSubscribeRoutesThroughProjectTopology(): void
    {
        $this->assertEquals(
            [
                new AgentDestination(SignalRouterTopologyTestGroup::SUBSCRIPTION_AGENT_TYPE),
            ],
            new SignalRouterGroupTopologyTestRouter()->getDestinations(
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
            new SignalRouterGroupTopologyTestRouter()->getDestinations(new SignalDTO(
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
            new SignalRouterGroupTopologyTestRouter()->getDestinations(new SignalDTO(
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
            new SignalRouterGroupTopologyFallbackTestRouter()->getDestinations(
                $this->groupSubscribeSignal('unregistered_group'),
            ),
        );
    }

    public function testBaseRouterDoesNotRouteUnregisteredGroupWithoutFallback(): void
    {
        $this->assertEquals(
            [],
            new SignalRouter()->getDestinations($this->groupSubscribeSignal('unregistered_group')),
        );
    }

    public function testGroupSubscribeWithoutAGroupBuildsNoRoute(): void
    {
        // The name carries a routable group on purpose: subscribe reads the group
        // from its payload, so a payload without one builds no route regardless.
        $this->assertSame(
            [],
            new SignalRouterGroupTopologyTestRouter()->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::GROUP_SUBSCRIBE),
                new SignalName(SignalRouterTopologyTestGroup::GROUP),
                new WebSocketGroupSubscribeSignalDTO('accept-key'),
            )),
        );
    }

    public function testResolveGroupNameReadsTheProjectRegistry(): void
    {
        $match = new SignalRouterGroupTopologyTestRouter()->resolveGroupName(
            SignalRouterTopologyTestGroup::GROUP,
        );

        self::assertNotNull($match);
        self::assertSame(SignalRouterTopologyTestGroup::class, $match->groupClass);
        self::assertNull($match->param);
    }

    public function testResolveGroupNameMatchesByTheHeadOfAParameterizedName(): void
    {
        $match = new SignalRouterGroupTopologyTestRouter()->resolveGroupName(
            SignalRouterTopologyTestGroup::GROUP . ':42',
        );

        self::assertNotNull($match);
        self::assertSame(SignalRouterTopologyTestGroup::class, $match->groupClass);
        self::assertSame('42', $match->param);
    }

    public function testBaseRouterResolvesNoGroupClassBecauseItsRegistryIsEmpty(): void
    {
        // The reason resolution is the router's job and not a static read of the facade by
        // name: the registry lives on the PROJECT facade, and the framework's own is empty by
        // construction. Code that read `Hilos::getGroupClasses()` directly would land here -
        // on nothing - while looking exactly like code that works, and every group join in
        // every demo would be refused as unserved (HIL-721).
        self::assertNull(
            new SignalRouter()->resolveGroupName(SignalRouterTopologyTestGroup::GROUP),
        );
    }

    public function testDispatcherFindsTheGroupClassThroughTheRouter(): void
    {
        // The regression guard for the defect that cost a full run (HIL-721): the dispatcher
        // used to read the registry off the facade by name, which resolves to the framework's
        // own empty one, so every join in every demo was refused as unserved. The fixture group
        // declares no admission, so a dispatcher that FOUND it refuses with group_forbidden -
        // and one that did not, with group_not_served. The two codes tell the paths apart.
        Hilos::$sr = new SignalRouterGroupTopologyTestRouter();

        new GroupSubscriptionDispatcher(new SignalRouterGroupTestAgent())->dispatchSubscribe(
            new WebSocketGroupSubscribeSignalDTO('accept-key', SignalRouterTopologyTestGroup::GROUP),
            SignalRouterTopologyTestGroup::GROUP,
        );

        $queued = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($queued);
        self::assertSame(SignalConstants::SUBSCRIPTION_GROUP_ERROR, $queued->signalName->getName());

        $error = $queued->data;
        self::assertInstanceOf(WebSocketSignalData::class, $error);
        $payload = $error->data;
        self::assertInstanceOf(GroupSubscriptionErrorSignalData::class, $payload);
        self::assertSame(GroupErrorCode::FORBIDDEN, $payload->errorCode);
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

final class SignalRouterGroupTestAgent implements PageAgentInterface
{
    /**
     * @return string Fixture agent id
     */
    public function getId(): string
    {
        return 'group-test-agent';
    }

    /**
     * @return SignalSourceInterface Fixture signal source the dispatcher signs its frames with
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT);
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
