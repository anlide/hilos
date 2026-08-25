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
 * The routing post-pass's third answer: an agent nobody knows how to reach (HIL-670).
 *
 * {@see SignalRouterCrossNodeRoutingTest} covers the two answers that existed before — here and
 * on a named node. This one covers the answer that used to collapse into "here": a node with no
 * placement picture delivered the signal into its own workers, which run no such agent, and the
 * send reported success. The post-pass now marks it undeliverable so the daemon can drop it with
 * a line and answer a browser that is waiting.
 */
final class SignalRouterUnknownAgentTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$cluster = null;

        parent::tearDown();
    }

    public function testAnAgentOfUnknownWhereaboutsBecomesAnUndeliverableDestination(): void
    {
        $this->installPlacement([
            'remote_agent:7' => AgentLocation::unknown(),
            'local_agent' => AgentLocation::here(),
        ]);

        $destinations = new UnknownAgentTestRouter()->getDestinations($this->noopSignal());

        $this->assertEquals([
            new UnknownAgentDestination('remote_agent', '7'),
            new AgentDestination('local_agent'),
            new WebSocketDestination('ws-key'),
        ], $destinations);
    }

    /**
     * The unknown answer touches only the agent it was given for. A signal fanning out to several
     * places must still reach the ones that ARE reachable — dropping the lot would turn one
     * missing address into a whole undelivered signal.
     */
    public function testTheOtherDestinationsOfTheSameSignalAreUntouched(): void
    {
        $this->installPlacement([
            'remote_agent:7' => AgentLocation::unknown(),
            'local_agent' => AgentLocation::onNode('node-B'),
        ]);

        $destinations = new UnknownAgentTestRouter()->getDestinations($this->noopSignal());

        $this->assertEquals([
            new UnknownAgentDestination('remote_agent', '7'),
            new RemoteAgentDestination('node-B', 'local_agent'),
            new WebSocketDestination('ws-key'),
        ], $destinations);
    }

    /**
     * Off-cluster there is no lookup to ask, so no agent is ever unknown and the post-pass stays
     * inert: a single node runs what it runs, and "here" is the only address there is.
     */
    public function testNothingIsUnknownOffCluster(): void
    {
        Hilos::$cluster = null;

        $destinations = new UnknownAgentTestRouter()->getDestinations($this->noopSignal());

        $this->assertEquals([
            new AgentDestination('remote_agent', '7'),
            new AgentDestination('local_agent'),
            new WebSocketDestination('ws-key'),
        ], $destinations);
    }

    /**
     * Registers a fake placement lookup mapping "type:index" (or "type") to a location.
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
     *
     * @return SignalDTO Signal carrying nothing any contributor claims
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
 * Router contributing a fixed mix of agent and WebSocket destinations, so the post-pass is
 * exercised without wiring project topology.
 */
final class UnknownAgentTestRouter extends SignalRouter
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
