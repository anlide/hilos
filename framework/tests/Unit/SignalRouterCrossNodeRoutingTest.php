<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\WorkerPlacement;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\Destination;
use Hilos\Core\Router\Destination\RemoteAgentDestination;
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
 * placement lookup reports on another node into a RemoteAgentDestination. A local agent,
 * a null lookup result, and non-agent destinations are left untouched; off-cluster the
 * post-pass is inert.
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
        $this->installPlacement(['remote_agent:7' => 'node-B']);

        $destinations = (new CrossNodeTestRouter())->getDestinations($this->noopSignal());

        $this->assertEquals([
            new RemoteAgentDestination('node-B', 'remote_agent', '7'),
            new AgentDestination('local_agent'),
            new WebSocketDestination('ws-key'),
        ], $destinations);
    }

    public function testLocalAgentStaysAgentDestinationWhenLookupReturnsNull(): void
    {
        // No mapping: every nodeFor() returns null, so nothing is rewritten.
        $this->installPlacement([]);

        $destinations = (new CrossNodeTestRouter())->getDestinations($this->noopSignal());

        $this->assertEquals([
            new AgentDestination('remote_agent', '7'),
            new AgentDestination('local_agent'),
            new WebSocketDestination('ws-key'),
        ], $destinations);
    }

    public function testDestinationsAreUnchangedOffCluster(): void
    {
        Hilos::$cluster = null;

        $destinations = (new CrossNodeTestRouter())->getDestinations($this->noopSignal());

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

        $destinations = (new CrossNodeTestRouter())->getDestinations($this->noopSignal());

        $this->assertEquals([
            new AgentDestination('remote_agent', '7'),
            new AgentDestination('local_agent'),
            new WebSocketDestination('ws-key'),
        ], $destinations);
    }

    /**
     * Registers a fake placement lookup mapping "type:index" (or "type") to a node id.
     *
     * @param array<string, string> $placements Agent id to hosting node id
     */
    private function installPlacement(array $placements): void
    {
        $context = new ClusterContext();
        $context->registerWorkerPlacement(new class ($placements) implements WorkerPlacement {
            /**
             * @param array<string, string> $placements Agent id to hosting node id
             */
            public function __construct(private readonly array $placements)
            {
            }

            public function nodeFor(string $agentType, ?string $agentIndex): ?string
            {
                $agentId = $agentIndex !== null ? "{$agentType}:{$agentIndex}" : $agentType;

                return $this->placements[$agentId] ?? null;
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
