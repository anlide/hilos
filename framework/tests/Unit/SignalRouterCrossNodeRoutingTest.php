<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Placement\AgentLocation;
use Hilos\Cluster\WorkerPlacement;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\Destination;
use Hilos\Core\Router\Destination\RemoteAgentDestination;
use Hilos\Core\Router\Destination\UnknownAgentDestination;
use Hilos\Core\Router\Destination\WebSocketDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Tests the cross-node routing post-pass in SignalRouter::getDestinations() (HIL-180).
 *
 * The router resolves destinations exactly as before, then rewrites any agent that the
 * placement lookup reports on another node into a RemoteAgentDestination. An agent the
 * lookup places here and non-agent destinations are left untouched; off-cluster the
 * post-pass is inert. The lookup's third answer - nobody knows where the agent runs - is
 * {@see SignalRouterUnknownAgentTest}.
 *
 * The same post-pass is also reachable for ONE agent at a time, through
 * {@see SignalRouter::placeAgentDestination()} (HIL-745): the master has two deliveries built
 * from a subscription record rather than from a route, and they had no way to ask this question
 * at all. Those cases are here because the answers are the post-pass's own - what the two
 * callers then do with them is {@see DaemonManagerPlacedFanOutTest}.
 */
final class SignalRouterCrossNodeRoutingTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$cluster = null;

        parent::tearDown();
    }

    public function testRemoteAgentDestinationReplacesAgentOnAnotherNode(): void
    {
        $this->installPlacement(['remote_agent:7' => AgentLocation::onNode('node-B')]);

        $destinations = new CrossNodeTestRouter()->getDestinations($this->noopSignal());

        $this->assertEquals([
            new RemoteAgentDestination('node-B', 'remote_agent', '7'),
            new AgentDestination('local_agent'),
            new WebSocketDestination('ws-key'),
        ], $destinations);
    }

    public function testLocalAgentStaysAgentDestinationWhenLookupPlacesItHere(): void
    {
        // No mapping: every locate() answers "here", so nothing is rewritten.
        $this->installPlacement([]);

        $destinations = new CrossNodeTestRouter()->getDestinations($this->noopSignal());

        $this->assertEquals([
            new AgentDestination('remote_agent', '7'),
            new AgentDestination('local_agent'),
            new WebSocketDestination('ws-key'),
        ], $destinations);
    }

    public function testDestinationsAreUnchangedOffCluster(): void
    {
        Hilos::$cluster = null;

        $destinations = new CrossNodeTestRouter()->getDestinations($this->noopSignal());

        $this->assertEquals([
            new AgentDestination('remote_agent', '7'),
            new AgentDestination('local_agent'),
            new WebSocketDestination('ws-key'),
        ], $destinations);
    }

    public function testDestinationsAreUnchangedWhenNoPlacementRegistered(): void
    {
        // Cluster context present, but no worker-placement lookup registered (opt-in).
        Hilos::$cluster = new ClusterContext();

        $destinations = new CrossNodeTestRouter()->getDestinations($this->noopSignal());

        $this->assertEquals([
            new AgentDestination('remote_agent', '7'),
            new AgentDestination('local_agent'),
            new WebSocketDestination('ws-key'),
        ], $destinations);
    }

    public function testPlacingOneAgentKeepsItLocalWhenTheLookupPlacesItHere(): void
    {
        $this->installPlacement([]);

        $placed = new CrossNodeTestRouter()->placeAgentDestination(new AgentDestination('local_agent'));

        $this->assertEquals(new AgentDestination('local_agent'), $placed);
    }

    public function testPlacingOneAgentNamesTheNodeHostingIt(): void
    {
        $this->installPlacement(['remote_agent:7' => AgentLocation::onNode('node-B')]);

        $placed = new CrossNodeTestRouter()->placeAgentDestination(new AgentDestination('remote_agent', '7'));

        $this->assertEquals(new RemoteAgentDestination('node-B', 'remote_agent', '7'), $placed);
    }

    /**
     * The answer the caller must not confuse with "here": before the seam, a delivery built from
     * a subscription record went into this node's own workers whatever the lookup thought, and
     * an unplaced agent is exactly the case where those workers run no such agent.
     */
    public function testPlacingOneAgentMarksItUnknownWhenNoNodeIsKnownToHostIt(): void
    {
        $this->installPlacement(['remote_agent:7' => AgentLocation::unknown()]);

        $placed = new CrossNodeTestRouter()->placeAgentDestination(new AgentDestination('remote_agent', '7'));

        $this->assertEquals(new UnknownAgentDestination('remote_agent', '7'), $placed);
    }

    public function testPlacingOneAgentIsInertOffCluster(): void
    {
        Hilos::$cluster = null;

        $placed = new CrossNodeTestRouter()->placeAgentDestination(new AgentDestination('remote_agent', '7'));

        $this->assertEquals(new AgentDestination('remote_agent', '7'), $placed);
    }

    /**
     * Registers a fake placement lookup mapping "type:index" (or "type") to a location.
     *
     * An agent the map does not name is answered "here", which is what an ordinary single
     * node answers for everything it runs.
     *
     * @param array<string, AgentLocation> $placements Agent id to the location it is at
     */
    private function installPlacement(array $placements): void
    {
        $context = new ClusterContext();
        $context->registerWorkerPlacement(new class ($placements) implements WorkerPlacement {
            /**
             * @param array<string, AgentLocation> $placements Agent id to the location it is at
             */
            public function __construct(private readonly array $placements)
            {
            }

            public function locate(string $agentType, ?string $agentIndex): AgentLocation
            {
                $agentId = $agentIndex !== null ? "{$agentType}:{$agentIndex}" : $agentType;

                return $this->placements[$agentId] ?? AgentLocation::here();
            }
        });

        Hilos::$cluster = $context;
    }

    /**
     * Builds a benign signal that trips no framework routing contributor.
     */
    private function noopSignal(): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType('noop'),
            new SignalName('noop'),
            new SignalData([]),
        );
    }
}

/**
 * Router that contributes a fixed mix of agent and WebSocket destinations, so the test
 * exercises the placement post-pass without wiring project topology.
 */
final class CrossNodeTestRouter extends SignalRouter
{
    /**
     * @param SignalDTO $signal Signal DTO
     * @return list<Destination> Fixed destination mix
     */
    protected function additionalDestinations(SignalDTO $signal): array
    {
        return [
            new AgentDestination('remote_agent', '7'),
            new AgentDestination('local_agent'),
            new WebSocketDestination('ws-key'),
        ];
    }
}
