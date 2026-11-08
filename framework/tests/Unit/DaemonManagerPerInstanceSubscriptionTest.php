<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Generator;
use Hilos\Cluster\AgentSignalMesh;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\AgentDeliveryOutcome;
use Hilos\Core\Daemon\SignalDispatchPrefix;
use Hilos\Core\Daemon\SignalDispatch;
use Hilos\Core\Daemon\ParkedSignal;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\PageSubscription;
use Hilos\Core\Router\Destination\AgentAddressedDestination;
use Hilos\Core\Router\Destination\RemoteAgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Hilos;
use Hilos\Environment\EnvAccessor;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use WeakReference;

require_once __DIR__ . '/SignalRouterPerInstancePageRoutingTest.php';
require_once __DIR__ . '/ConnectionRosterReconcilerTest.php';

/**
 * What the master does around a subscription addressed to one entity instance (HIL-627).
 *
 * Three of these are about ORDER, which is the whole difficulty of the leaf. The address has
 * to be resolved before routing and the record dropped after it; the agent losing a
 * subscription has to hear about it before the agent gaining it does; and a subscription
 * whose instance is the person behind the connection cannot be addressed at all until that
 * person is known. Each of the three fails silently when it is wrong - a phantom subscription
 * on an agent nobody talks to any more, or a signed-in person served as a guest - so they are
 * pinned here rather than left to an end-to-end run to notice.
 */
final class DaemonManagerPerInstanceSubscriptionTest extends TestCase
{
    private const string ACCEPT_KEY = 'per-instance-master-ak';

    private ?EnvAccessor $previousEnv = null;

    private ?ClusterContext $previousCluster = null;

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousCluster = Hilos::$cluster;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$browser = null;
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        putenv('CLUSTER_ENABLED');
        putenv('CLUSTER_NODE_ID');
        putenv('CLUSTER_NODE_NAME');
        putenv('CLUSTER_NODE_ROLE');
        putenv('CLUSTER_PEER_ADVERTISE');

        parent::tearDown();
    }

    public function testReplacingASubscriptionTellsTheOldInstanceBeforeTheNewOneIsAddressed(): void
    {
        $manager = $this->manager();
        $this->queueSubscribe(PerInstanceChatPage::PAGE, ['chatId' => '42']);
        $manager->drainQueue();
        $manager->forgetDeliveries();

        $this->queueSubscribe(PerInstanceChatPage::PAGE, ['chatId' => '43']);
        $manager->drainQueue();

        $this->assertSame(
            [
                SignalTypeConstants::PAGE_UNSUBSCRIBE . '@' . PerInstanceChatPage::SUBSCRIPTION_AGENT_TYPE . ':42',
                SignalTypeConstants::PAGE_SUBSCRIBE . '@' . PerInstanceChatPage::SUBSCRIPTION_AGENT_TYPE . ':43',
            ],
            $manager->deliveries(),
        );
    }

    public function testAnUnsubscribeIsRoutedBeforeItsRecordIsDropped(): void
    {
        $manager = $this->manager();
        $this->queueSubscribe(PerInstanceChatPage::PAGE, ['chatId' => '42']);
        $manager->drainQueue();
        $manager->forgetDeliveries();

        $this->queue(
            SignalTypeConstants::PAGE_UNSUBSCRIBE,
            PerInstanceChatPage::PAGE,
            new WebSocketPageUnsubscribeSignalDTO(self::ACCEPT_KEY),
        );
        $manager->drainQueue();

        $this->assertSame(
            [SignalTypeConstants::PAGE_UNSUBSCRIBE . '@' . PerInstanceChatPage::SUBSCRIPTION_AGENT_TYPE . ':42'],
            $manager->deliveries(),
        );
        $this->assertNull(Hilos::$sr->pageSubscription(self::ACCEPT_KEY));
    }

    public function testAConnectionCloseReachesTheInstanceItHeldASubscriptionOn(): void
    {
        $manager = $this->manager();
        $this->queueSubscribe(PerInstanceChatPage::PAGE, ['chatId' => '42']);
        $manager->drainQueue();
        $manager->forgetDeliveries();

        $this->queue(
            SignalTypeConstants::CONNECTION_CLOSE,
            SignalTypeConstants::CONNECTION_CLOSE,
            new WebSocketCloseSignalDTO(self::ACCEPT_KEY),
        );
        $manager->drainQueue();

        $this->assertContains(
            SignalTypeConstants::CONNECTION_CLOSE . '@' . PerInstanceChatPage::SUBSCRIPTION_AGENT_TYPE . ':42',
            $manager->deliveries(),
        );
        $this->assertNull(Hilos::$sr->pageSubscription(self::ACCEPT_KEY));
    }

    /**
     * A page served by its type leaves no bound address, but its agent still owes cleanup.
     */
    public function testAConnectionCloseReachesTheAgentServingAPagePlacedByItsType(): void
    {
        $manager = $this->manager();
        $this->queueSubscribe(PerInstancePlainPage::PAGE, []);
        $manager->drainQueue();
        $manager->forgetDeliveries();

        $this->queue(
            SignalTypeConstants::CONNECTION_CLOSE,
            SignalTypeConstants::CONNECTION_CLOSE,
            new WebSocketCloseSignalDTO(self::ACCEPT_KEY),
        );
        $manager->drainQueue();

        $this->assertContains(
            SignalTypeConstants::CONNECTION_CLOSE . '@' . PerInstancePlainPage::SUBSCRIPTION_AGENT_TYPE,
            $manager->deliveries(),
        );
        $this->assertNull(Hilos::$sr->pageSubscription(self::ACCEPT_KEY));
    }

    /**
     * The previous worker hears the unsubscribe before the new agent hears the subscribe.
     */
    public function testLeavingAPageServedByItsTypeForAnotherAgentTellsTheOldAgentFirst(): void
    {
        $manager = $this->manager();
        $this->queueSubscribe(PerInstancePlainPage::PAGE, []);
        $manager->drainQueue();
        $manager->forgetDeliveries();

        $this->queueSubscribe(PerInstanceChatPage::PAGE, ['chatId' => '42']);
        $manager->drainQueue();

        $this->assertSame(
            [
                SignalTypeConstants::PAGE_UNSUBSCRIBE . '@' . PerInstancePlainPage::SUBSCRIPTION_AGENT_TYPE,
                SignalTypeConstants::PAGE_SUBSCRIBE . '@' . PerInstanceChatPage::SUBSCRIPTION_AGENT_TYPE . ':42',
            ],
            $manager->deliveries(),
        );
    }

    /**
     * Replacement inside one agent belongs to its worker, whose mirror holds the old page.
     */
    public function testMovingWithinTheSameAgentLeavesTheMasterSilent(): void
    {
        $manager = $this->manager();
        $this->queueSubscribe(PerInstancePlainPage::PAGE, []);
        $manager->drainQueue();
        $manager->forgetDeliveries();

        $this->queueSubscribe(PerInstancePlainPage::PAGE, ['chatId' => '7']);
        $manager->drainQueue();

        $this->assertSame(
            [SignalTypeConstants::PAGE_SUBSCRIBE . '@' . PerInstancePlainPage::SUBSCRIPTION_AGENT_TYPE],
            $manager->deliveries(),
        );
    }

    /**
     * The ordinary lifecycle delivery already reaches this page's agent without a bound record.
     */
    public function testAPageOfTheLifecycleAgentPlacedByItsTypeIsClosedOnce(): void
    {
        $manager = $this->manager();
        $this->queueSubscribe(PerInstanceLifecyclePage::PAGE, []);
        $manager->drainQueue();
        $manager->forgetDeliveries();

        $this->queue(
            SignalTypeConstants::CONNECTION_CLOSE,
            SignalTypeConstants::CONNECTION_CLOSE,
            new WebSocketCloseSignalDTO(self::ACCEPT_KEY),
        );
        $manager->drainQueue();

        $this->assertSame(
            [SignalTypeConstants::CONNECTION_CLOSE . '@' . PerInstanceFallbackAgent::AGENT_TYPE],
            $manager->deliveries(),
        );
        $this->assertNull(Hilos::$sr->pageSubscription(self::ACCEPT_KEY));
    }

    /**
     * The refusal has to stop the frame too, not just the record. An update let through is
     * applied on the far side - the worker merges the new params into its own mirror and
     * re-renders the page from them - and the master would then address instance 42 while
     * the client is being shown instance 43, with nothing later to notice the disagreement.
     */
    public function testAnUpdateThatWouldMoveTheInstanceIsRefusedAndLeavesTheSubscriptionAlone(): void
    {
        $manager = $this->manager();
        $this->queueSubscribe(PerInstanceChatPage::PAGE, ['chatId' => '42']);
        $manager->drainQueue();
        $manager->forgetDeliveries();

        $this->queue(
            SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION,
            PerInstanceChatPage::PAGE,
            new WebSocketPageUpdateSubscriptionSignalDTO(self::ACCEPT_KEY, PerInstanceChatPage::PAGE, ['chatId' => '43']),
        );
        $manager->drainQueue();

        $subscription = Hilos::$sr->pageSubscription(self::ACCEPT_KEY);
        $this->assertSame('42', $subscription->params['chatId']);
        $this->assertSame('42', $subscription->agentIndex);
        $this->assertSame([], $manager->deliveries());
    }

    public function testANodeChangeInsideAnUpdateIsRefused(): void
    {
        $this->enableCluster();
        $manager = $this->manager();
        $this->queueSubscribe(PerInstanceNodePage::PAGE, ['nodeId' => 'node-A']);
        $manager->drainQueue();
        $manager->forgetDeliveries();

        $this->queue(
            SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION,
            PerInstanceNodePage::PAGE,
            new WebSocketPageUpdateSubscriptionSignalDTO(
                self::ACCEPT_KEY, PerInstanceNodePage::PAGE, ['nodeId' => 'node-B'],
            ),
        );
        $manager->drainQueue();

        $this->assertSame('node-A', Hilos::$sr->pageSubscription(self::ACCEPT_KEY)->params['nodeId']);
        $this->assertSame([], $manager->deliveries());
    }

    public function testNodeLeavingAndRejoiningRebindsThePageAndSendsFreshSubscribes(): void
    {
        $this->enableCluster();
        $peer = NodeIdentity::of('node-B', 'Node node-B', NodeRole::Master, []);
        Hilos::$cluster->registry()->recordPeer($peer, microtime(true));
        $manager = $this->manager();
        $this->queueSubscribe(PerInstanceNodePage::PAGE, ['nodeId' => 'node-B']);
        $manager->drainQueue();
        $this->assertSame('node-B', Hilos::$sr->pageSubscription(self::ACCEPT_KEY)->agentNode);
        $manager->forgetDeliveries();

        Hilos::$cluster->registry()->markOffline('node-B', microtime(true));
        $manager->onNodeLeft(ClusterNode::fromIdentity($peer, false, microtime(true)));
        $this->assertSame(PerInstanceFallbackAgent::AGENT_TYPE, Hilos::$sr->pageSubscription(self::ACCEPT_KEY)->agentType);
        $this->assertContains(
            SignalTypeConstants::PAGE_SUBSCRIBE . '@' . PerInstanceFallbackAgent::AGENT_TYPE,
            $manager->deliveries(),
        );

        Hilos::$cluster->registry()->recordPeer($peer, microtime(true));
        $manager->onNodeJoined(ClusterNode::fromIdentity($peer, true, microtime(true)));
        $this->assertSame('node-B', Hilos::$sr->pageSubscription(self::ACCEPT_KEY)->agentNode);
        $this->assertContains(
            SignalTypeConstants::PAGE_SUBSCRIBE . '@node-B/' . PerInstanceNodePage::SUBSCRIPTION_AGENT_TYPE,
            $manager->addressed(),
        );
    }

    public function testNodeRerouteRetainsItsIndexedCohortAndDoesNotOverwriteANewerSubscription(): void
    {
        $this->enableCluster();
        $peer = NodeIdentity::of('node-B', 'Node node-B', NodeRole::Master, []);
        Hilos::$cluster->registry()->recordPeer($peer, microtime(true));
        $manager = $this->manager();
        Hilos::$sr = new PerInstanceIndexOnlyTestRouter();
        for ($index = 0; $index < 260; $index++) {
            $acceptKey = 'node-viewer-' . $index;
            Hilos::$sr->subscribeToPage(PerInstanceNodePage::PAGE,
                new WebSocketPageSubscribeSignalDTO($acceptKey, PerInstanceNodePage::PAGE, ['nodeId' => 'node-B']));
            Hilos::$sr->bindPageAgent($acceptKey, PerInstanceNodePage::PAGE, PerInstanceNodePage::SUBSCRIPTION_AGENT_TYPE, null, 'node-B');
        }
        Hilos::$cluster->registry()->markOffline('node-B', microtime(true));
        $manager->onNodeLeft(ClusterNode::fromIdentity($peer, false, microtime(true)));
        self::assertSame('node-B', Hilos::$sr->pageSubscription('node-viewer-259')->agentNode,
            'The membership hook must leave its indexed physical remainder for guarded dispatch');
        Hilos::$sr->subscribeToPage(PerInstanceChatPage::PAGE,
            new WebSocketPageSubscribeSignalDTO('node-viewer-259', PerInstanceChatPage::PAGE, ['chatId' => 'replacement']));
        Hilos::$sr->bindPageAgent('node-viewer-259', PerInstanceChatPage::PAGE, PerInstanceChatPage::SUBSCRIPTION_AGENT_TYPE, 'replacement');
        $replacement = Hilos::$sr->pageSubscription('node-viewer-259');
        for ($portion = 0; $portion < 256; $portion++) {
            $manager->drainQueue();
        }
        self::assertSame($replacement, Hilos::$sr->pageSubscription('node-viewer-259'));
        self::assertSame(PerInstanceFallbackAgent::AGENT_TYPE, Hilos::$sr->pageSubscription('node-viewer-258')->agentType);
        self::assertSame(array_map(static fn(int $index): string => 'node-viewer-' . $index, range(0, 258)),
            new ReflectionProperty(PerInstanceMasterTestManager::class, 'workerServer')->getValue($manager)->subscribeAcceptKeys);
    }

    public function testAQueuedOldOwnerUnsubscribeCannotUseAReplacementSubscription(): void
    {
        $manager = $this->manager();
        $this->queueSubscribe(PerInstanceChatPage::PAGE, ['chatId' => '42']);
        $manager->drainQueue();
        $manager->forgetDeliveries();
        new ReflectionClass(DaemonManager::class)->getMethod('queueSignalEffect')->invoke($manager, static function (): Generator {
            yield;
            for ($step = 0; $step < 256; $step++) {
                yield;
            }
        });
        new ReflectionClass(DaemonManager::class)->getMethod('unsubscribeReplacedPageAgent')->invoke(
            $manager, self::ACCEPT_KEY, PerInstancePlainPage::PAGE, null,
        );
        self::assertSame([], $manager->deliveries(), 'The compatibility adapter must retain its physical continuation');
        Hilos::$sr->subscribeToPage(PerInstanceChatPage::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, PerInstanceChatPage::PAGE, ['chatId' => '43']));
        Hilos::$sr->bindPageAgent(self::ACCEPT_KEY, PerInstanceChatPage::PAGE, PerInstanceChatPage::SUBSCRIPTION_AGENT_TYPE, '43');
        $replacement = Hilos::$sr->pageSubscription(self::ACCEPT_KEY);
        for ($portion = 0; $portion < 32; $portion++) {
            $manager->drainQueue();
        }
        self::assertSame($replacement, Hilos::$sr->pageSubscription(self::ACCEPT_KEY));
        self::assertSame([], $manager->deliveries());
    }

    public function testAnOldOwnerUnsubscribeRechecksTheOriginalRecordBetweenPhysicalSteps(): void
    {
        $manager = $this->manager();
        $this->queueSubscribe(PerInstanceChatPage::PAGE, ['chatId' => '42']);
        $manager->drainQueue();
        $manager->forgetDeliveries();
        $delivery = new ReflectionClass(DaemonManager::class)->getMethod('unsubscribeReplacedPageDelivery')->invoke(
            $manager, self::ACCEPT_KEY, PerInstancePlainPage::PAGE, null,
        );
        $delivery->current();
        $delivery->next();
        self::assertSame([], $manager->deliveries());
        Hilos::$sr->subscribeToPage(PerInstanceChatPage::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, PerInstanceChatPage::PAGE, ['chatId' => '43']));
        Hilos::$sr->bindPageAgent(self::ACCEPT_KEY, PerInstanceChatPage::PAGE, PerInstanceChatPage::SUBSCRIPTION_AGENT_TYPE, '43');
        $replacement = Hilos::$sr->pageSubscription(self::ACCEPT_KEY);
        while ($delivery->valid()) {
            $delivery->next();
        }
        self::assertFalse($delivery->getReturn()->isCurrent());
        self::assertSame($replacement, Hilos::$sr->pageSubscription(self::ACCEPT_KEY));
        self::assertSame([], $manager->deliveries());
    }

    public function testAnAbandonedRerouteCandidateReleasesItsOriginalBeforeTheNextCandidateRuns(): void
    {
        $this->enableCluster();
        $manager = $this->manager();
        foreach (['first', 'second'] as $acceptKey) {
            Hilos::$sr->subscribeToPage(PerInstanceNodePage::PAGE,
                new WebSocketPageSubscribeSignalDTO($acceptKey, PerInstanceNodePage::PAGE, ['nodeId' => 'node-B']));
            Hilos::$sr->bindPageAgent($acceptKey, PerInstanceNodePage::PAGE, PerInstanceNodePage::SUBSCRIPTION_AGENT_TYPE, null, 'node-B');
        }
        $reference = WeakReference::create(Hilos::$sr->pageSubscription('first'));
        $work = new ReflectionClass(DaemonManager::class)->getMethod('nodePageReroute')->invoke(
            $manager, Hilos::$sr, 'node-B', Hilos::$sr->nodePageCandidates('node-B'),
        );
        $dispatch = new SignalDispatch(null, static fn(): Generator => $work);
        $advance = new ReflectionClass(DaemonManager::class)->getMethod('runSignalPortion');
        $advance->invoke($manager, $dispatch, 2);
        self::assertNotNull($reference->get());
        Hilos::$sr->subscribeToPage(PerInstancePlainPage::PAGE,
            new WebSocketPageSubscribeSignalDTO('first', PerInstancePlainPage::PAGE));
        $advance->invoke($manager, $dispatch, 1);
        self::assertTrue($dispatch->hasWork(), 'The second original candidate still waits');
        self::assertNull($reference->get(), 'An abandoned candidate cannot pin its old record across the next candidate yield');
        $dispatch->cancel();
    }

    public function testALateNodeRerouteFailureIsContainedAndFailsOnlyItsCapturedBoundary(): void
    {
        $this->enableCluster();
        $manager = $this->manager();
        $router = new PerInstanceIndexOnlyTestRouter();
        Hilos::$sr = $router;
        for ($index = 0; $index < 260; $index++) {
            $acceptKey = 'node-viewer-' . $index;
            $router->subscribeToPage(PerInstanceNodePage::PAGE,
                new WebSocketPageSubscribeSignalDTO($acceptKey, PerInstanceNodePage::PAGE, ['nodeId' => 'node-B']));
            $router->bindPageAgent($acceptKey, PerInstanceNodePage::PAGE, PerInstanceNodePage::SUBSCRIPTION_AGENT_TYPE, null, 'node-B');
        }
        new ReflectionClass(DaemonManager::class)->getMethod('rerouteNodeAddressedPages')->invoke($manager, 'node-B');
        $state = new ReflectionProperty(DaemonManager::class, 'signalDispatchState')->getValue($manager);
        self::assertNotEmpty($state->effects);
        $boundary = new SignalDispatchPrefix(1, $state->intake, [], 0, 0, 0, effectWatermark: $state->effectSequence);
        $state->browsers = $boundary;
        $router->failPageRead = true;
        $manager->drainQueue();
        self::assertTrue($boundary->failed);
        self::assertFalse($boundary->complete);
        self::assertSame([], $state->effects);
    }

    /**
     * A subscription that named no instance is served by the page's fallback agent, and an
     * update handing it one moves it just as surely as an update handing it a different one:
     * the record would keep the fallback address while the worker merged the param and
     * re-rendered the page for instance 42.
     */
    public function testAnUpdateThatNamesAnInstanceForAnUnindexedSubscriptionIsRefused(): void
    {
        $manager = $this->manager();
        $this->queueSubscribe(PerInstanceChatPage::PAGE, []);
        $manager->drainQueue();
        $manager->forgetDeliveries();

        $this->queue(
            SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION,
            PerInstanceChatPage::PAGE,
            new WebSocketPageUpdateSubscriptionSignalDTO(
                self::ACCEPT_KEY,
                PerInstanceChatPage::PAGE,
                ['chatId' => '42'],
            ),
        );
        $manager->drainQueue();

        $subscription = Hilos::$sr->pageSubscription(self::ACCEPT_KEY);
        $this->assertArrayNotHasKey('chatId', $subscription->params);
        $this->assertSame(PerInstanceFallbackAgent::AGENT_TYPE, $subscription->agentType);
        $this->assertNull($subscription->agentIndex);
        $this->assertSame([], $manager->deliveries());
    }

    public function testAnUpdateInsideTheSameInstanceIsAppliedAndKeepsTheAddress(): void
    {
        $manager = $this->manager();
        $this->queueSubscribe(PerInstanceChatPage::PAGE, ['chatId' => '42']);
        $manager->drainQueue();

        $this->queue(
            SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION,
            PerInstanceChatPage::PAGE,
            new WebSocketPageUpdateSubscriptionSignalDTO(
                self::ACCEPT_KEY,
                PerInstanceChatPage::PAGE,
                ['chatId' => '42', 'tab' => 'files'],
            ),
        );
        $manager->drainQueue();

        $subscription = Hilos::$sr->pageSubscription(self::ACCEPT_KEY);
        $this->assertSame('files', $subscription->params['tab']);
        $this->assertSame('42', $subscription->agentIndex);
    }

    /**
     * A re-decision is built from a WORKER's mirror of the subscriptions, so it can name a
     * page this connection has already navigated away from. Acting on it would take the page
     * the connection IS on away from the agent serving it and leave that record addressed to
     * the agent of the page it left - a live page dead with nothing to notice it.
     */
    public function testAReDecisionNamingAPageTheConnectionHasLeftChangesNothing(): void
    {
        $manager = $this->manager();
        Hilos::$browser->identity = ConnectionIdentity::resolved(7);
        $this->queueSubscribe(PerInstanceProfilePage::PAGE, []);
        $manager->drainQueue();

        $this->queueSubscribe(PerInstanceChatPage::PAGE, ['chatId' => '42']);
        $manager->drainQueue();
        $manager->forgetDeliveries();

        $this->queue(
            SignalTypeConstants::PAGE_ACCESS_REASSESS,
            PerInstanceProfilePage::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, PerInstanceProfilePage::PAGE, []),
        );
        $manager->drainQueue();

        $subscription = Hilos::$sr->pageSubscription(self::ACCEPT_KEY);
        $this->assertSame(PerInstanceChatPage::PAGE, $subscription->page);
        $this->assertSame(PerInstanceChatPage::SUBSCRIPTION_AGENT_TYPE, $subscription->agentType);
        $this->assertSame('42', $subscription->agentIndex);
        $this->assertSame([], $manager->deliveries());
    }

    public function testASubscriptionWaitsForTheIdentityAndIsServedByThePersonsInstanceOnceItArrives(): void
    {
        $manager = $this->manager();
        Hilos::$browser->identity = ConnectionIdentity::pending();

        $this->queueSubscribe(PerInstanceProfilePage::PAGE, []);
        $manager->drainQueue();

        $this->assertSame([], $manager->deliveries());
        $this->assertNull(Hilos::$sr->pageSubscription(self::ACCEPT_KEY));

        Hilos::$browser->identity = ConnectionIdentity::resolved(7);
        new ReflectionClass(DaemonManager::class)->getMethod('queueIdentityDiscovery')->invoke($manager, self::ACCEPT_KEY);
        $manager->drainQueue();

        $this->assertSame(
            [SignalTypeConstants::PAGE_SUBSCRIBE . '@' . PerInstanceProfilePage::SUBSCRIPTION_AGENT_TYPE . ':7'],
            $manager->deliveries(),
        );
    }

    /** @return iterable<string, array{bool, ?string}> Parked and ready lifetimes, by page and by connection */
    public static function identityCancellationStages(): iterable
    {
        yield 'parked-page' => [false, PerInstanceProfilePage::PAGE];
        yield 'parked-connection' => [false, null];
        yield 'ready-page' => [true, PerInstanceProfilePage::PAGE];
        yield 'ready-connection' => [true, null];
    }

    /**
     * @param bool $ready Whether the original identity cohort was already released
     * @param ?string $page Original page, absent for a whole connection
     */
    #[DataProvider('identityCancellationStages')]
    public function testIdentityCancellationInvalidatesBeforeItsQueuedCleanupAndPreservesFutureNavigation(bool $ready, ?string $page): void
    {
        $manager = $this->manager();
        Hilos::$browser->identity = ConnectionIdentity::pending();
        $park = new ReflectionClass(DaemonManager::class)->getMethod('parkUntilIdentified');
        for ($index = 0; $index < 260; $index++) {
            $signal = new SignalDTO(new SignalSource(SignalSource::WEBSOCKET), new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
                new SignalName(PerInstanceProfilePage::PAGE), new WebSocketPageSubscribeSignalDTO(
                    $index < 130 ? 'live-' . $index : self::ACCEPT_KEY, PerInstanceProfilePage::PAGE,
                ));
            self::assertTrue($park->invoke($manager, $signal));
        }
        $last = WeakReference::create($signal->data);
        unset($signal);
        new ReflectionClass(DaemonManager::class)->getMethod('queueSignalEffect')->invoke($manager, static function (): Generator {
            yield;
            for ($step = 0; $step < 1024; $step++) {
                yield;
            }
        });
        $state = new ReflectionProperty(DaemonManager::class, 'signalDispatchState')->getValue($manager);
        $probe = new ReflectionClass(DaemonManager::class)->getMethod('probeIdentitySignals')->invoke($manager);
        if ($ready) {
            Hilos::$browser->identity = ConnectionIdentity::resolved(7);
            iterator_to_array($probe);
            self::assertCount(260, $state->identities);
        } else {
            $probe->current();
            $state->identityProbe = $probe;
        }
        new ReflectionClass(DaemonManager::class)->getMethod('cancelPendingPageDeliveries')->invoke($manager, self::ACCEPT_KEY, $page);
        self::assertNotNull($last->get(), 'Cancellation must retain its payload until a bounded physical cleanup step');
        Hilos::$browser->identity = ConnectionIdentity::pending();
        self::assertTrue($park->invoke($manager, new SignalDTO(new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE), new SignalName(PerInstanceProfilePage::PAGE),
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, PerInstanceProfilePage::PAGE, ['new' => true]),
        )));
        Hilos::$browser->identity = ConnectionIdentity::resolved(7);
        new ReflectionClass(DaemonManager::class)->getMethod('queueIdentityDiscovery')->invoke($manager);
        for ($portion = 0; $portion < 256; $portion++) {
            $manager->drainQueue();
        }
        self::assertNull($last->get());
        self::assertSame([], $state->identities);
        self::assertSame([], new ReflectionProperty(DaemonManager::class, 'parkedSignals')->getValue($manager));
        $worker = new ReflectionProperty(PerInstanceMasterTestManager::class, 'workerServer')->getValue($manager);
        self::assertSame([...array_map(static fn(int $index): string => 'live-' . $index, range(0, 129)), self::ACCEPT_KEY],
            $worker->subscribeAcceptKeys);
        self::assertSame(array_fill(0, 131, SignalTypeConstants::PAGE_SUBSCRIBE . '@' . PerInstanceProfilePage::SUBSCRIPTION_AGENT_TYPE . ':7'),
            $manager->deliveries());
    }

    /**
     * The deadline is the promise that a subscription is eventually served on what is known.
     * Releasing on it and then parking again would renew it every pass and keep that promise
     * forever - the one outcome the deadline exists to prevent.
     */
    public function testASubscriptionWhoseIdentityNeverArrivesIsRoutedOnceTheDeadlinePasses(): void
    {
        $manager = $this->manager();
        Hilos::$browser->identity = ConnectionIdentity::pending();

        $this->queueSubscribe(PerInstanceProfilePage::PAGE, []);
        $manager->drainQueue();
        $this->assertSame([], $manager->deliveries());

        // The identity stays pending for good; only the deadline can let the signal through.
        $this->expireIdentity($manager);
        $manager->drainQueue();

        $this->assertSame(
            [SignalTypeConstants::PAGE_SUBSCRIBE . '@' . PerInstanceFallbackAgent::AGENT_TYPE],
            $manager->deliveries(),
        );

        // And it is gone from the hold: a third pass has nothing left to deliver.
        $manager->forgetDeliveries();
        $manager->drainQueue();
        $this->assertSame([], $manager->deliveries());
    }

    /**
     * The fallback agent a page declares is normally the project's lifecycle agent, which the
     * ordinary route already hands the close to. Fanning out on top of that would run one
     * agent's close hook twice for one disconnect.
     */
    public function testAConnectionCloseIsNotDeliveredTwiceToTheSameAgent(): void
    {
        $manager = $this->manager();
        $this->queueSubscribe(PerInstanceChatPage::PAGE, []);
        $manager->drainQueue();
        $manager->forgetDeliveries();

        $this->queue(
            SignalTypeConstants::CONNECTION_CLOSE,
            SignalTypeConstants::CONNECTION_CLOSE,
            new WebSocketCloseSignalDTO(self::ACCEPT_KEY),
        );
        $manager->drainQueue();

        $closes = array_values(array_filter(
            $manager->deliveries(),
            static fn(string $delivery): bool => str_starts_with(
                $delivery,
                SignalTypeConstants::CONNECTION_CLOSE . '@' . PerInstanceFallbackAgent::AGENT_TYPE,
            ),
        ));
        $this->assertCount(1, $closes);
    }

    /**
     * What a connection was waiting to have addressed dies with the connection. Otherwise the
     * held subscribe sits out its deadline, binds a record for a dead accept key, and is
     * delivered to an agent that answers a socket nobody is listening on - and the close that
     * would have cleaned that record is already past.
     */
    public function testAParkedSubscriptionDiesWithItsConnection(): void
    {
        $manager = $this->manager();
        Hilos::$browser->identity = ConnectionIdentity::pending();

        $this->queueSubscribe(PerInstanceProfilePage::PAGE, []);
        $manager->drainQueue();

        $this->queue(
            SignalTypeConstants::CONNECTION_CLOSE,
            SignalTypeConstants::CONNECTION_CLOSE,
            new WebSocketCloseSignalDTO(self::ACCEPT_KEY),
        );
        $manager->drainQueue();
        $manager->forgetDeliveries();

        new ReflectionClass(DaemonManager::class)->getMethod('queueIdentityDiscovery')->invoke($manager, null, true);
        $manager->drainQueue();

        $this->assertSame([], $manager->deliveries());
        $this->assertNull(Hilos::$sr->pageSubscription(self::ACCEPT_KEY));
    }

    /** Native due tickets cannot survive a rebind or unrelated dispatch, and the original wait stays fixed. */
    public function testIdentityDeadlineSurvivesUnrelatedTrafficAndRebind(): void
    {
        $manager = $this->manager();
        Hilos::$browser->identity = ConnectionIdentity::pending();
        $loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($manager, $loop);
        $attach = new ReflectionClass(DaemonManager::class)->getMethod('attachSignalDispatchEventLoop');
        $detach = new ReflectionClass(DaemonManager::class)->getMethod('detachSignalDispatchEventLoop');
        $attach->invoke($manager);
        try {
            $this->queueSubscribe(PerInstanceProfilePage::PAGE, []);
            $manager->drainQueue();
            $timer = new ReflectionProperty(DaemonManager::class, 'identitySignalTimer')->getValue($manager);
            self::assertInstanceOf(EventLoopTimer::class, $timer);
            $original = $timer->getDeadline();
            self::assertGreaterThan(hrtime(true) / 1e9, $original);
            for ($portion = 0; $portion < 4; $portion++) {
                $manager->drainQueue();
                $loop->tick();
            }
            self::assertSame($original, $timer->getDeadline());
            self::assertFalse($loop->hasReadyWork());
            self::assertSame([], $manager->deliveries());
            $timer->armAt(hrtime(true) / 1e9 - 1.0);
            $attach->invoke($manager);
            $loop->tick();
            self::assertNull($timer->getDeadline());
            $current = new ReflectionProperty(DaemonManager::class, 'identitySignalTimer')->getValue($manager);
            self::assertSame($original, $current->getDeadline());
            self::assertSame([], $manager->deliveries());

            $this->expireIdentity($manager);
            $loop->tick();
            self::assertTrue($loop->hasReadyWork());
            $manager->drainQueue();
            self::assertSame([SignalTypeConstants::PAGE_SUBSCRIBE . '@' . PerInstanceFallbackAgent::AGENT_TYPE], $manager->deliveries());
            self::assertNull($current->getDeadline());
            $loop->tick();
            self::assertFalse($loop->hasReadyWork());
        } finally {
            $detach->invoke($manager);
        }
    }

    /** Applying a row wakes only its connection's original obligations; unrelated RT input leaves them idle. */
    public function testAppliedIdentityFactDiscoversOnlyItsDependentConnection(): void
    {
        $previousRt = Hilos::$rt;
        try {
            Hilos::$rt = new RosterRtContext();
            Hilos::$rt->configure();
            $manager = $this->manager();
            $manager->drainQueue();
            Hilos::$browser->identity = ConnectionIdentity::pending();
            $park = new ReflectionClass(DaemonManager::class)->getMethod('parkUntilIdentified');
            foreach ([self::ACCEPT_KEY, 'another-connection'] as $acceptKey) {
                self::assertTrue($park->invoke($manager, new SignalDTO(new SignalSource(SignalSource::WEBSOCKET),
                    new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE), new SignalName(PerInstanceProfilePage::PAGE),
                    new WebSocketPageSubscribeSignalDTO($acceptKey, PerInstanceProfilePage::PAGE))));
            }
            Hilos::$browser->identity = ConnectionIdentity::resolved(7);
            $apply = new ReflectionClass(DaemonManager::class)->getMethod('handleDaemonSignal');
            foreach (['unrelated-connection', self::ACCEPT_KEY] as $acceptKey) {
                $apply->invoke($manager, new SignalDTO(new SignalSource(SignalSource::DAEMON),
                    new SignalType(SignalTypeConstants::RT_SYNC_CREATED), new SignalName(SignalTypeConstants::RT_SYNC_CREATED),
                    new RtSyncCreatedSignalData(RosterRtContext::connections, $acceptKey, RosterConnection::create($acceptKey, 7)->toArray())));
                $manager->drainQueue();
                if ($acceptKey !== self::ACCEPT_KEY) {
                    self::assertSame([], $manager->deliveries());
                }
            }
            self::assertSame([SignalTypeConstants::PAGE_SUBSCRIBE . '@' . PerInstanceProfilePage::SUBSCRIPTION_AGENT_TYPE . ':7'],
                $manager->deliveries());
            self::assertCount(1, new ReflectionProperty(DaemonManager::class, 'parkedSignals')->getValue($manager));
            self::assertFalse(new ReflectionClass(DaemonManager::class)->getMethod('hasRunnableSignalDispatch')->invoke($manager));
        } finally {
            Hilos::$rt = $previousRt;
        }
    }

    /**
     * A page nobody declared per-instance must not start waiting for an identity it never needed.
     */
    public function testAPlainPageIsNeverHeldForAnIdentity(): void
    {
        $manager = $this->manager();
        Hilos::$browser->identity = ConnectionIdentity::pending();

        $this->queueSubscribe(PerInstancePlainPage::PAGE, []);
        $manager->drainQueue();

        $this->assertSame(
            [SignalTypeConstants::PAGE_SUBSCRIBE . '@' . PerInstancePlainPage::SUBSCRIPTION_AGENT_TYPE],
            $manager->deliveries(),
        );
    }

    /**
     * @param PerInstanceMasterTestManager $manager Existing parked subscription owner
     */
    private function expireIdentity(PerInstanceMasterTestManager $manager): void
    {
        $property = new ReflectionProperty(DaemonManager::class, 'parkedSignals');
        $parked = $property->getValue($manager);
        foreach ($parked as $id => $wait) {
            $parked[$id] = new ParkedSignal($wait->signal, hrtime(true) / 1e9 - 1.0);
        }
        $property->setValue($manager, $parked);
        new ReflectionClass(DaemonManager::class)->getMethod('rearmIdentitySignalTimer')->invoke($manager);
        if (new ReflectionProperty(DaemonManager::class, 'identitySignalTimer')->getValue($manager) === null) {
            new ReflectionClass(DaemonManager::class)->getMethod('queueIdentityDiscovery')->invoke($manager, null, true);
        }
    }

    /**
     * Mounts the fixture browser and returns a master carrying a recording worker server.
     *
     * @return PerInstanceMasterTestManager Master exposing its queue drain and its deliveries
     */
    private function manager(): PerInstanceMasterTestManager
    {
        $manager = new PerInstanceMasterTestManager();
        Hilos::$browser = new PerInstanceTestBrowser();

        return $manager;
    }

    private function enableCluster(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-A');
        putenv('CLUSTER_NODE_NAME=Local node');
        putenv('CLUSTER_NODE_ROLE=' . NodeRole::Master->value);
        putenv('CLUSTER_PEER_ADVERTISE=10.0.0.1:7000');
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
    }

    /**
     * @param string $page Page being subscribed to
     * @param array<string, string> $params Subscription params
     */
    private function queueSubscribe(string $page, array $params): void
    {
        $this->queue(
            SignalTypeConstants::PAGE_SUBSCRIBE,
            $page,
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, $page, $params),
        );
    }

    /**
     * @param string $signalType Signal type to queue
     * @param string $signalName Signal name to queue under
     * @param SignalDataInterface $data Payload as the WebSocket layer parsed it
     */
    private function queue(string $signalType, string $signalName, SignalDataInterface $data): void
    {
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType($signalType),
            new SignalName($signalName),
            $data,
        );
    }
}

/**
 * Master carrying a recording worker server, the one thing the drain needs before it looks at
 * a signal at all.
 */
final class PerInstanceMasterTestManager extends DaemonManager
{
    /** The stand-in worker server the drain finds and delivers through */
    private PerInstanceMasterTestWorkerServer $workerServer;

    /** @var list<string> Agent addresses the master attempted to deliver to */
    private array $addressed = [];

    public function __construct()
    {
        parent::__construct();

        $this->workerServer = new PerInstanceMasterTestWorkerServer();
        $this->registerServer($this->workerServer);
    }

    /**
     * Runs the private queue drain the daemon loop runs at the end of each iteration.
     */
    public function drainQueue(): void
    {
        new ReflectionClass(DaemonManager::class)->getMethod('dispatchSignals')->invoke($this);
    }

    /**
     * @return list<string> Deliveries as `<signal type>@<agent type>[:<index>]`, in order
     */
    public function deliveries(): array
    {
        return $this->workerServer->deliveries;
    }

    /**
     * Clears the recorded deliveries so a case can assert only what came after its setup.
     */
    public function forgetDeliveries(): void
    {
        $this->workerServer->deliveries = [];
    }

    /** @return list<string> Delivery attempts including remote addresses */
    public function addressed(): array
    {
        return $this->addressed;
    }

    protected function deliverToAgentDestination(
        WorkerServer $workerServer,
        ?AgentSignalMesh $mesh,
        AgentAddressedDestination $destination,
        SignalDTO $signal,
    ): AgentDeliveryOutcome {
        $address = $signal->signalType->getType() . '@';
        if ($destination instanceof RemoteAgentDestination) {
            $address .= $destination->nodeId . '/';
        }
        $this->addressed[] = $address . $destination->agentType;

        return parent::deliverToAgentDestination($workerServer, $mesh, $destination, $signal);
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new PerInstanceTestRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new PerInstanceMasterTestAgentManagerDaemon();
    }
}

final class PerInstanceMasterTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * Every agent these cases address counts as up, so the drain hands it the frame rather than
     * holding the frame for a start report no worker of this test will send (HIL-629).
     *
     * @param string $agentId Agent the drain asks about
     * @return bool Always true
     */
    public function isAgentStarted(string $agentId): bool
    {
        return true;
    }

    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

/**
 * A worker server that records the handoff instead of starting a process.
 */
final class PerInstanceMasterTestWorkerServer extends WorkerServer
{
    /** @var list<string> Deliveries as `<signal type>@<agent type>[:<index>]`, in order */
    public array $deliveries = [];

    /** @var list<string> Actual subscription destinations, without retaining their payloads */
    public array $subscribeAcceptKeys = [];

    public function __construct()
    {
    }

    /**
     * @param string $agentType Agent type the signal was routed to
     * @param ?string $agentIndex Agent index for an indexed agent, or null
     * @param DaemonAgentMessageDTO $messageDto Signal wrapped for the worker
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
        $address = $agentIndex === null ? $agentType : "{$agentType}:{$agentIndex}";
        $this->deliveries[] = $messageDto->signal->signalType->getType() . '@' . $address;
        if ($messageDto->signal->data instanceof WebSocketPageSubscribeSignalDTO) {
            $this->subscribeAcceptKeys[] = $messageDto->signal->data->acceptKey;
        }
    }

    protected function onStart(): void
    {
    }
}

final class PerInstanceIndexOnlyTestRouter extends SignalRouter
{
    public bool $failPageRead = false;

    /** @return class-string<Hilos> Existing per-instance fixture topology */
    protected function hilosClass(): string
    {
        return PerInstanceTestHilos::class;
    }

    /** @return array<string, array{page: string, params: array<string, string>}> No global roster may be materialized */
    public function getPageSubscriptions(): array
    {
        TestCase::fail('Node-addressed reroute must use the requested-node index');
    }

    /**
     * @param string $acceptKey Actual connection whose record is requested
     * @return ?PageSubscription Original record unless the late-error case refuses its lookup
     */
    public function pageSubscription(string $acceptKey): ?PageSubscription
    {
        if ($this->failPageRead) {
            throw new InvalidFormatException('Late node reroute read refused');
        }
        return parent::pageSubscription($acceptKey);
    }
}
