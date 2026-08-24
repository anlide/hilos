<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$browser = null;

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
        $manager->drainQueue();

        $this->assertSame(
            [SignalTypeConstants::PAGE_SUBSCRIBE . '@' . PerInstanceProfilePage::SUBSCRIPTION_AGENT_TYPE . ':7'],
            $manager->deliveries(),
        );
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
        usleep(600_000);
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

        usleep(600_000);
        $manager->drainQueue();

        $this->assertSame([], $manager->deliveries());
        $this->assertNull(Hilos::$sr->pageSubscription(self::ACCEPT_KEY));
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
    }

    protected function onStart(): void
    {
    }
}
