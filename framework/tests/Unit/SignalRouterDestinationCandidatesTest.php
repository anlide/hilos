<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\Destination;
use Hilos\Core\Router\Destination\WebSocketDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\GroupSubscription;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\SubscriptionRegistry;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/** Candidate budgets count the physical subscription roster, including nonmatches. */
final class SignalRouterDestinationCandidatesTest extends TestCase
{
    private ?ClusterContext $previousCluster;

    protected function setUp(): void
    {
        $this->previousCluster = Hilos::$cluster;
        Hilos::$cluster = null;
    }

    protected function tearDown(): void
    {
        Hilos::$cluster = $this->previousCluster;
    }

    public function testGroupWalkVisitsNonmatchesAndCapturesBeforeFirstAdvance(): void
    {
        $router = new SignalRouter();
        for ($index = 0; $index < 130; $index++) {
            $router->subscribeToGroup('other', new WebSocketGroupSubscribeSignalDTO('other-' . $index, 'other'));
        }
        $router->subscribeToGroup('other', new WebSocketGroupSubscribeSignalDTO('old', 'other'));
        $router->subscribeToGroup('g', new WebSocketGroupSubscribeSignalDTO('old', 'g'));
        $router->subscribeToGroup('g', new WebSocketGroupSubscribeSignalDTO('kept', 'g'));
        $walk = $router->destinationCandidates($this->signal(SignalTypeConstants::WS_GROUP));
        $router->unsubscribeFromGroup('g', new WebSocketGroupUnsubscribeSignalDTO('old', 'g'));
        $router->subscribeToGroup('g', new WebSocketGroupSubscribeSignalDTO('old', 'g'));
        $router->subscribeToGroup('g', new WebSocketGroupSubscribeSignalDTO('new', 'g'));

        $visits = [];
        foreach ($walk as $destination) {
            $visits[] = $destination;
        }

        self::assertCount(132, $visits);
        self::assertSame(array_fill(0, 131, null), array_slice($visits, 0, 131));
        self::assertInstanceOf(WebSocketDestination::class, $visits[131]);
        self::assertSame('kept', $visits[131]->acceptKey);
        self::assertSame('g', $visits[131]->group);
        self::assertInstanceOf(GroupSubscription::class, $visits[131]->subscription);
        self::assertTrue($router->isCurrentClientDestination($visits[131]));
    }

    public function testPageChangesBetweenPortionsCannotReceiveTheOldBroadcast(): void
    {
        $router = new SignalRouter();
        foreach (['first', 'changed', 'last'] as $key) {
            $router->subscribeToPage('p', new WebSocketPageSubscribeSignalDTO($key, 'p'));
        }
        $walk = $router->destinationCandidates($this->signal(SignalTypeConstants::WS_ALL));
        self::assertEquals(new WebSocketDestination('first', $router->pageSubscription('first')), $walk->current());
        $router->subscribeToPage('p', new WebSocketPageSubscribeSignalDTO('changed', 'p'));
        $router->subscribeToPage('p', new WebSocketPageSubscribeSignalDTO('late', 'p'));
        $walk->next();
        self::assertNull($walk->current());
        $walk->next();
        self::assertEquals(new WebSocketDestination('last', $router->pageSubscription('last')), $walk->current());
        $walk->next();
        self::assertFalse($walk->valid());
    }

    public function testInheritedRoutingKeepsContributorOrderAndDeduplication(): void
    {
        $router = new class extends SignalRouter {
            /**
             * @param SignalDTO $signal Envelope under test
             * @return list<Destination> Extra route, including a duplicate browser
             */
            protected function additionalDestinations(SignalDTO $signal): array
            {
                return [new WebSocketDestination('a'), new AgentDestination('owner'), new AgentDestination('owner')];
            }
        };
        $router->subscribeToPage('p', new WebSocketPageSubscribeSignalDTO('a', 'p'));
        $router->subscribeToPage('p', new WebSocketPageSubscribeSignalDTO('b', 'p'));
        $signal = $this->signal(SignalTypeConstants::WS_ALL);
        $visits = iterator_to_array($router->destinationCandidates($signal), false);

        self::assertCount(5, $visits);
        self::assertEquals([new WebSocketDestination('a', $router->pageSubscription('a')),
            new WebSocketDestination('b', $router->pageSubscription('b')), null,
            new AgentDestination('owner'), null], $visits);
        self::assertEquals($router->getDestinations($signal), array_values(array_filter(array_map(
            static fn(?Destination $destination): ?Destination => $destination instanceof WebSocketDestination
                ? new WebSocketDestination($destination->acceptKey) : $destination,
            $visits,
        ))));
    }

    public function testAnOverrideMayFilterParentDestinationsAndKeepsItsExactResult(): void
    {
        $router = new class extends SignalRouter {
            protected const bool USE_DESTINATION_CALLBACK = true;

            public int $calls = 0;

            /**
             * @param SignalDTO $signal Envelope under test
             * @return list<Destination> Only the browser selected by the project callback
             */
            public function getDestinations(SignalDTO $signal): array
            {
                $this->calls++;
                return array_values(array_filter(parent::getDestinations($signal),
                    static fn(Destination $destination): bool => $destination instanceof WebSocketDestination
                        && $destination->acceptKey === 'b'));
            }
        };
        foreach (['a', 'b'] as $key) {
            $router->subscribeToPage('p', new WebSocketPageSubscribeSignalDTO($key, 'p'));
        }
        $walk = $router->destinationCandidates($this->signal(SignalTypeConstants::WS_ALL));
        self::assertSame(1, $router->calls);
        self::assertEquals([new WebSocketDestination('b')], iterator_to_array($walk, false));
        self::assertSame(1, $router->calls);
    }

    public function testLocalOverrideRetainsTheOneHopCallback(): void
    {
        $router = new class extends SignalRouter {
            protected const bool USE_LOCAL_CLIENT_CALLBACK = true;

            /**
             * @param SignalDTO $signal Forwarded envelope
             * @return list<Destination> Explicit project client route
             */
            public function localClientDestinations(SignalDTO $signal): array
            {
                return [new WebSocketDestination('project')];
            }
        };

        self::assertEquals([new WebSocketDestination('project')], iterator_to_array(
            $router->localClientCandidates($this->signal(SignalTypeConstants::WS_GROUP)), false,
        ));
    }

    public function testGroupNameLookupSpendsNonmatchesAndExcludesARejoinedMembership(): void
    {
        $registry = new SubscriptionRegistry();
        for ($index = 0; $index < 65; $index++) {
            $registry->subscribeToGroup('browser', 'other:' . $index, []);
        }
        $registry->subscribeToGroup('browser', 'g:old', []);
        $walk = $registry->groupNameCandidates('browser', 'g');
        self::assertTrue($walk->valid());
        for ($index = 0; $index < 64; $index++) {
            $walk->next();
        }
        self::assertTrue($walk->valid());
        $registry->unsubscribeFromGroup('browser', 'g:old');
        $registry->subscribeToGroup('browser', 'g:old', []);
        $registry->subscribeToGroup('browser', 'g:late', []);
        while ($walk->valid()) {
            $walk->next();
        }
        self::assertNull($walk->getReturn());
        self::assertSame('g:old', $registry->groupSubscriptionName('browser', 'g'));
    }

    /**
     * @param string $type Existing browser signal type
     * @return SignalDTO Browser fan-out with a group target
     */
    private function signal(string $type): SignalDTO
    {
        return new SignalDTO(new SignalSource(SignalSource::AGENT), new SignalType($type), new SignalName('marker'),
            new WebSocketSignalData(new SignalData(), targetGroup: 'g'));
    }
}
