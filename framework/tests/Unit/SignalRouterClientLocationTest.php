<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClientLocation;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Connections\ClusterClientLocation;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\AllClientsDestination;
use Hilos\Core\Router\Destination\Destination;
use Hilos\Core\Router\Destination\RemoteClientDestination;
use Hilos\Core\Router\Destination\RemoteFanoutDestination;
use Hilos\Core\Router\Destination\WebSocketDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Tests how SignalRouter::getDestinations() places a browser on a cluster (HIL-668).
 *
 * Two mechanisms, split by whether the signal names its target. An addressed one is REWRITTEN
 * by the client-location post-pass, which asks where that one browser hangs; a fan-out gets a
 * marker ADDED beside its local destinations, because no lookup can answer who is subscribed
 * on a node that keeps its own registry. Both are exercised here, and the reason they are one
 * file is that they are the same decision seen from two sides: a signal to a browser is placed
 * once, at the end, by the transport, and the application code that answers a browser goes on
 * naming an accept key and nothing else.
 *
 * What must not be rewritten is as load-bearing as what must. {@see AllClientsDestination} is
 * not an address but an instruction to fan out, and turning it into one node's problem would
 * silently narrow a broadcast to whoever happened to be looked up.
 */
final class SignalRouterClientLocationTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$cluster = null;

        parent::tearDown();
    }

    public function testAConnectionOnAnotherNodeBecomesARemoteDestination(): void
    {
        $this->installLocation(['ws-key' => 'node-B']);

        $destinations = new ClientLocationTestRouter()->getDestinations($this->noopSignal());

        $this->assertEquals([
            new AgentDestination('local_agent'),
            new RemoteClientDestination('node-B', 'ws-key'),
            new AllClientsDestination(null),
        ], $destinations);
    }

    /**
     * A key attached here, and a key nobody has announced, answer the same null - and both go
     * on being written to locally, which for the second one means dropped exactly as before.
     */
    public function testAConnectionThisNodeHoldsStaysLocal(): void
    {
        $this->installLocation([]);

        $destinations = new ClientLocationTestRouter()->getDestinations($this->noopSignal());

        $this->assertEquals([
            new AgentDestination('local_agent'),
            new WebSocketDestination('ws-key'),
            new AllClientsDestination(null),
        ], $destinations);
    }

    /**
     * The fan-out marker is left alone on purpose: it names no connection, so there is nothing
     * to look up, and the node holding the connections is the only one that can carry it out.
     */
    public function testTheAllClientsMarkerIsNeverRewritten(): void
    {
        $this->installLocation(['ws-key' => 'node-B']);

        $destinations = new ClientLocationTestRouter()->getDestinations($this->noopSignal());

        $this->assertEquals(new AllClientsDestination(null), $destinations[2]);
    }

    public function testDestinationsAreUnchangedOffCluster(): void
    {
        Hilos::$cluster = null;

        $destinations = new ClientLocationTestRouter()->getDestinations($this->noopSignal());

        $this->assertEquals([
            new AgentDestination('local_agent'),
            new WebSocketDestination('ws-key'),
            new AllClientsDestination(null),
        ], $destinations);
    }

    public function testDestinationsAreUnchangedWhenNoLookupIsRegistered(): void
    {
        // Cluster context present, but no connection lookup registered (opt-in).
        Hilos::$cluster = new ClusterContext();

        $destinations = new ClientLocationTestRouter()->getDestinations($this->noopSignal());

        $this->assertEquals([
            new AgentDestination('local_agent'),
            new WebSocketDestination('ws-key'),
            new AllClientsDestination(null),
        ], $destinations);
    }

    /**
     * The post-pass reads the real index, not only a fake: what the mesh announced is what the
     * router addresses, with no translation step in between to disagree about.
     */
    public function testTheRealIndexDrivesThePostPass(): void
    {
        $context = new ClusterContext();
        $index = new ClusterClientLocation();
        $index->applySnapshot('node-B', ['ws-key']);
        $context->registerClientConnections($index);
        $context->registerClientLocation($index);
        Hilos::$cluster = $context;

        $destinations = new ClientLocationTestRouter()->getDestinations($this->noopSignal());

        $this->assertEquals(new RemoteClientDestination('node-B', 'ws-key'), $destinations[1]);
    }

    /**
     * A fan-out has no address to look up: which browsers it reaches is answered by each node's
     * own subscription registry. So the local destinations stand as they are and a marker rides
     * along beside them, and it is that marker the daemon turns into one broadcast frame.
     */
    public function testAFanOutIsCarriedToTheOtherNodesBesideItsLocalDelivery(): void
    {
        $this->installLocation([]);

        $destinations = new SignalRouter()->getDestinations($this->broadcastSignal());

        $this->assertEquals([
            new AllClientsDestination(null),
            new RemoteFanoutDestination(),
        ], $destinations);
    }

    /**
     * And it rides on every fan-out, whatever the index happens to hold: the case above knows
     * of no connection anywhere else, this one knows of one, and both come out identical. That
     * sameness is the design — a node cannot tell from a signal whether another node holds a
     * SUBSCRIBER, so any attempt to send only "when needed" would be a flag computed from a
     * guess. It is also what closes today's hole, where a broadcast raised on one node reached
     * only the browsers attached to that node.
     */
    public function testAFanOutIsCarriedWhateverTheConnectionIndexHolds(): void
    {
        $this->installLocation(['ws-key' => 'node-B']);

        $destinations = new SignalRouter()->getDestinations($this->broadcastSignal());

        $this->assertEquals([
            new AllClientsDestination(null),
            new RemoteFanoutDestination(),
        ], $destinations);
    }

    /**
     * An addressed signal is the opposite case and must stay opposite: it names one browser,
     * the index says which node holds it, and it goes to that node alone. Handing it to the
     * mesh would deliver one person's answer to everybody who happens to be subscribed.
     */
    public function testAnAddressedSignalIsNeverFannedOut(): void
    {
        $this->installLocation(['ws-key' => 'node-B']);

        $destinations = new SignalRouter()->getDestinations($this->addressedSignal());

        $this->assertEquals([new RemoteClientDestination('node-B', 'ws-key')], $destinations);
    }

    public function testAFanOutCarriesNoMarkerOffCluster(): void
    {
        Hilos::$cluster = null;

        $destinations = new SignalRouter()->getDestinations($this->broadcastSignal());

        $this->assertEquals([new AllClientsDestination(null)], $destinations);
    }

    /**
     * The receiving end of a fan-out resolves the very same signal and must NOT come away with
     * the marker again: that is the one-hop rule, and without it one broadcast becomes a storm
     * that also delivers to every browser as many times as there are nodes.
     */
    public function testTheReceivingNodeResolvesTheSameFanOutWithoutTheMarker(): void
    {
        $this->installLocation([]);

        $destinations = new SignalRouter()->localClientDestinations($this->broadcastSignal());

        $this->assertEquals([new AllClientsDestination(null)], $destinations);
    }

    /**
     * Registers a fake connection lookup mapping an accept key to the node holding it.
     *
     * @param array<string, string> $locations Accept key to holding node id
     */
    private function installLocation(array $locations): void
    {
        $context = new ClusterContext();
        $context->registerClientLocation(new class ($locations) implements ClientLocation {
            /**
             * @param array<string, string> $locations Accept key to holding node id
             */
            public function __construct(private readonly array $locations)
            {
            }

            public function nodeFor(string $acceptKey): ?string
            {
                return $this->locations[$acceptKey] ?? null;
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

    /**
     * Builds the broadcast an agent raises for every connected browser.
     *
     * @return SignalDTO One ws_all_connected fan-out, addressed to nobody in particular
     */
    private function broadcastSignal(): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
            new SignalName('everyone_signal'),
            new WebSocketSignalData(data: new SignalData()),
        );
    }

    /**
     * Builds the answer an agent sends to one browser it is talking to.
     *
     * @return SignalDTO One ws_user signal naming a single accept key
     */
    private function addressedSignal(): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::WS_USER),
            new SignalName('user_signal'),
            new WebSocketSignalData(data: new SignalData(), targetAcceptKey: 'ws-key'),
        );
    }
}

/**
 * Router that contributes a fixed mix of agent, client and fan-out destinations, so the test
 * exercises the post-pass without wiring project topology.
 */
final class ClientLocationTestRouter extends SignalRouter
{
    /**
     * @param SignalDTO $signal Signal DTO
     * @return list<Destination> Fixed destination mix
     */
    protected function additionalDestinations(SignalDTO $signal): array
    {
        return [
            new AgentDestination('local_agent'),
            new WebSocketDestination('ws-key'),
            new AllClientsDestination(null),
        ];
    }
}
