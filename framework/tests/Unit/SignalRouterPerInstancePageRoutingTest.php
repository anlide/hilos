<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Config\PageAgentIndexKey;
use Hilos\Core\Page\Config\PageAgentIndexSource;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Database\Context\DbContext;
use Hilos\Hilos as HilosFacade;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Routing a page subscription to the agent of ONE entity instance (HIL-627).
 *
 * The address is resolved once, by the master, and then read off the subscription record by
 * everything that follows. These cases pin both halves: that two connections on the same page
 * end up at two different agents, and that every later signal of a subscription keeps going to
 * the agent the subscribe picked - including the unsubscribe, which carries no params at all
 * and so has nothing of its own to resolve from.
 *
 * The fixture topology is the carrier on purpose: no demo page is per-instance yet, and the
 * page that moves first belongs to another leaf.
 */
final class SignalRouterPerInstancePageRoutingTest extends TestCase
{
    private const string ALICE = 'per-instance-alice';
    private const string BOB = 'per-instance-bob';

    protected function tearDown(): void
    {
        HilosFacade::$sr = null;
        HilosFacade::$browser = null;

        parent::tearDown();
    }

    public function testTwoConnectionsOnTheSamePageAreServedByTwoDifferentInstances(): void
    {
        $manager = $this->manager();
        $manager->subscribe(self::ALICE, PerInstanceChatPage::PAGE, ['chatId' => '42']);
        $manager->subscribe(self::BOB, PerInstanceChatPage::PAGE, ['chatId' => '43']);

        $this->assertEquals(
            [new AgentDestination(PerInstanceChatPage::SUBSCRIPTION_AGENT_TYPE, '42')],
            HilosFacade::$sr->getDestinations($this->viewport(self::ALICE, PerInstanceChatPage::PAGE)),
        );
        $this->assertEquals(
            [new AgentDestination(PerInstanceChatPage::SUBSCRIPTION_AGENT_TYPE, '43')],
            HilosFacade::$sr->getDestinations($this->viewport(self::BOB, PerInstanceChatPage::PAGE)),
        );
    }

    public function testSubscribeItselfIsAlreadyAddressedToTheInstance(): void
    {
        $manager = $this->manager();
        $signal = $this->subscribeSignal(self::ALICE, PerInstanceChatPage::PAGE, ['chatId' => '42']);
        $manager->applySubscriptionUpdate($signal);

        $this->assertEquals(
            [new AgentDestination(PerInstanceChatPage::SUBSCRIPTION_AGENT_TYPE, '42')],
            HilosFacade::$sr->getDestinations($signal),
        );
    }

    public function testAnUndeterminableInstanceGoesToTheFallbackAgentThePageDeclared(): void
    {
        $manager = $this->manager();
        $manager->subscribe(self::ALICE, PerInstanceChatPage::PAGE, []);

        $this->assertEquals(
            [new AgentDestination(PerInstanceFallbackAgent::AGENT_TYPE, null)],
            HilosFacade::$sr->getDestinations($this->viewport(self::ALICE, PerInstanceChatPage::PAGE)),
        );
    }

    public function testAnUnsubscribeStillReachesTheInstanceThatServedTheSubscription(): void
    {
        $manager = $this->manager();
        $manager->subscribe(self::ALICE, PerInstanceChatPage::PAGE, ['chatId' => '42']);

        $unsubscribe = new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_UNSUBSCRIBE),
            new SignalName(PerInstanceChatPage::PAGE),
            new WebSocketPageUnsubscribeSignalDTO(self::ALICE),
        );

        $this->assertEquals(
            [new AgentDestination(PerInstanceChatPage::SUBSCRIPTION_AGENT_TYPE, '42')],
            HilosFacade::$sr->getDestinations($unsubscribe),
        );
    }

    public function testAnActionIsAddressedByTheLiveSubscriptionOfItsCaller(): void
    {
        $manager = $this->manager();
        $manager->subscribe(self::ALICE, PerInstanceChatPage::PAGE, ['chatId' => '42']);
        $manager->subscribe(self::BOB, PerInstanceChatPage::PAGE, ['chatId' => '43']);

        $this->assertEquals(
            [new AgentDestination(PerInstanceChatPage::SUBSCRIPTION_AGENT_TYPE, '42')],
            HilosFacade::$sr->getDestinations($this->action(self::ALICE)),
        );
        $this->assertEquals(
            [new AgentDestination(PerInstanceChatPage::SUBSCRIPTION_AGENT_TYPE, '43')],
            HilosFacade::$sr->getDestinations($this->action(self::BOB)),
        );
    }

    public function testAnActionWithoutALiveSubscriptionHasNoDestination(): void
    {
        $this->manager();

        $this->assertSame([], HilosFacade::$sr->getDestinations($this->action(self::ALICE)));
    }

    public function testAReDecisionRecomputesTheAddressAfterTheGuestSignsIn(): void
    {
        $manager = $this->manager();
        $browser = HilosFacade::$browser;
        $browser->identity = ConnectionIdentity::resolved(null);
        $manager->subscribe(self::ALICE, PerInstanceProfilePage::PAGE, []);

        $this->assertEquals(
            [new AgentDestination(PerInstanceFallbackAgent::AGENT_TYPE, null)],
            HilosFacade::$sr->getDestinations($this->viewport(self::ALICE, PerInstanceProfilePage::PAGE)),
        );

        $browser->identity = ConnectionIdentity::resolved(7);
        $manager->applySubscriptionUpdate(new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_ACCESS_REASSESS),
            new SignalName(PerInstanceProfilePage::PAGE),
            new WebSocketPageSubscribeSignalDTO(self::ALICE, PerInstanceProfilePage::PAGE, []),
        ));

        $this->assertEquals(
            [new AgentDestination(PerInstanceProfilePage::SUBSCRIPTION_AGENT_TYPE, '7')],
            HilosFacade::$sr->getDestinations($this->viewport(self::ALICE, PerInstanceProfilePage::PAGE)),
        );
    }

    /**
     * A per-instance page has no type-level addressee to fall back to. Its agent type is an
     * indexed one, so naming it without an index would ask the worker server to start an
     * instance nobody asked for - and the exception that raises is not one the master's
     * delivery catches, so the whole node would go down over one stray frame.
     */
    public function testASignalNamingAPerInstancePageWithNoSubscriptionHasNoDestination(): void
    {
        $this->manager();

        $this->assertSame(
            [],
            HilosFacade::$sr->getDestinations($this->viewport(self::ALICE, PerInstanceChatPage::PAGE)),
        );
    }

    public function testAPageWithoutAPerInstanceDeclarationIsRoutedByItsAgentTypeAsBefore(): void
    {
        $manager = $this->manager();
        $manager->subscribe(self::ALICE, PerInstancePlainPage::PAGE, ['chatId' => '42']);

        $this->assertEquals(
            [new AgentDestination(PerInstancePlainPage::SUBSCRIPTION_AGENT_TYPE)],
            HilosFacade::$sr->getDestinations($this->viewport(self::ALICE, PerInstancePlainPage::PAGE)),
        );
        $this->assertNull(HilosFacade::$sr->pageSubscription(self::ALICE)->agentType);
    }

    /**
     * Mounts the fixture router and browser and returns the master that resolves addresses.
     *
     * @return PerInstanceTestDaemonManager Master exposing its subscription step
     */
    private function manager(): PerInstanceTestDaemonManager
    {
        $manager = new PerInstanceTestDaemonManager();
        HilosFacade::$browser = new PerInstanceTestBrowser();

        return $manager;
    }

    /**
     * @param string $acceptKey Connection sending the subscribe
     * @param string $page Page being subscribed to
     * @param array<string, string> $params Subscription params
     * @return SignalDTO Subscribe signal as the WebSocket layer parsed it
     */
    private function subscribeSignal(string $acceptKey, string $page, array $params): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName($page),
            new WebSocketPageSubscribeSignalDTO($acceptKey, $page, $params),
        );
    }

    /**
     * @param string $acceptKey Connection the viewport belongs to
     * @param string $page Page the viewport belongs to
     * @return SignalDTO Table viewport signal
     */
    private function viewport(string $acceptKey, string $page): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::TABLE_VIEWPORT),
            new SignalName($page),
            new WebSocketTableViewportSignalDTO($acceptKey, $page, 'messages'),
        );
    }

    /**
     * @param string $acceptKey Connection invoking the action
     * @return SignalDTO Action signal
     */
    private function action(string $acceptKey): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::ACTION),
            new SignalName(PerInstanceChatPage::SEND_MESSAGE),
            new WebSocketActionSignalDTO($acceptKey, PerInstanceChatPage::SEND_MESSAGE),
        );
    }
}

final class PerInstanceChatPage extends AbstractPage
{
    public const string PAGE = 'per_instance_chat';

    public const string SUBSCRIPTION_AGENT_TYPE = 'per_instance_chat_agent';

    public const string SEND_MESSAGE = 'per_instance_send_message';

    public const array ACTIONS = [
        self::SEND_MESSAGE => PerInstanceActionPayloadDTO::class,
    ];

    public const array SUBSCRIPTION_AGENT_INDEX = [
        PageAgentIndexKey::SOURCE => PageAgentIndexSource::PARAM,
        PageAgentIndexKey::PARAM => 'chatId',
        PageAgentIndexKey::FALLBACK_AGENT_TYPE => PerInstanceFallbackAgent::AGENT_TYPE,
    ];
}

final class PerInstanceProfilePage extends AbstractPage
{
    public const string PAGE = 'per_instance_profile';

    public const string SUBSCRIPTION_AGENT_TYPE = 'per_instance_profile_agent';

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;

    public const array SUBSCRIPTION_AGENT_INDEX = [
        PageAgentIndexKey::SOURCE => PageAgentIndexSource::SESSION_USER,
        PageAgentIndexKey::FALLBACK_AGENT_TYPE => PerInstanceFallbackAgent::AGENT_TYPE,
    ];
}

final class PerInstancePlainPage extends AbstractPage
{
    public const string PAGE = 'per_instance_plain';

    public const string SUBSCRIPTION_AGENT_TYPE = 'per_instance_plain_agent';
}

final class PerInstanceActionPayloadDTO extends ActionPayloadDTO
{
    /**
     * Creates a no-op per-instance routing test action payload DTO.
     *
     * @param array<string, mixed> $data Payload data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }

    /**
     * Returns the per-instance routing test action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return PerInstanceChatPage::SEND_MESSAGE;
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

final class PerInstanceFallbackAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'per_instance_fallback_agent';

    /**
     * No-op stop hook for per-instance routing tests.
     */
    public function onStop(): void
    {
    }
}

final class PerInstanceFallbackAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'per_instance_fallback_agent';
}

final class PerInstanceTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for per-instance routing tests.
     */
    public function configure(): void
    {
    }
}

final class PerInstanceTestHilos extends HilosFacade
{
    public const array PAGES = [
        PerInstanceChatPage::PAGE => PerInstanceChatPage::class,
        PerInstanceProfilePage::PAGE => PerInstanceProfilePage::class,
        PerInstancePlainPage::PAGE => PerInstancePlainPage::class,
    ];

    public const array AGENTS = [
        PerInstanceFallbackAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => PerInstanceFallbackAgent::class,
            AgentRegistryKey::DAEMON => PerInstanceFallbackAgentDaemon::class,
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new PerInstanceTestDbContext();
    }
}

/**
 * Router reading the per-instance fixture topology.
 */
final class PerInstanceTestRouter extends SignalRouter
{
    /**
     * Returns the fixture facade for topology reads.
     *
     * @return class-string<HilosFacade> Fixture facade class
     */
    protected function hilosClass(): string
    {
        return PerInstanceTestHilos::class;
    }

    /**
     * Returns the fixture owner of WebSocket lifecycle signals.
     *
     * Deliberately the same agent the fixture pages name as their fallback: that is the
     * ordinary project shape, and it is what makes a doubled connection_close reachable.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return PerInstanceFallbackAgent::AGENT_TYPE;
    }
}

/**
 * Browser context fixture answering one identity the test controls.
 */
final class PerInstanceTestBrowser extends BrowserContext
{
    /** Identity this fixture reports for every accept key. */
    public ConnectionIdentity $identity;

    public function __construct()
    {
        parent::__construct();
        $this->identity = ConnectionIdentity::resolved(null);
    }

    /**
     * Returns the identity the test set, standing in for the project's connection registry.
     *
     * @param string $acceptKey Subscriber accept key (unused in the fixture)
     * @return ConnectionIdentity Identity the test is currently reporting
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return $this->identity;
    }
}

/**
 * Master exposing the subscription step that resolves and binds the address.
 */
final class PerInstanceTestDaemonManager extends DaemonManager
{
    /**
     * Runs one signal through the subscription step, the way dispatchSignals() does before routing.
     *
     * @param SignalDTO $signal Signal to apply
     * @throws AgentDaemonCreationFailedException When a replaced subscription's agent daemon cannot be created
     */
    public function applySubscriptionUpdate(SignalDTO $signal): void
    {
        $this->updateSubscriptions($signal);
    }

    /**
     * Subscribes one connection to one page through the master's own step.
     *
     * @param string $acceptKey Connection sending the subscribe
     * @param string $page Page being subscribed to
     * @param array<string, string> $params Subscription params
     * @throws AgentDaemonCreationFailedException When a replaced subscription's agent daemon cannot be created
     */
    public function subscribe(string $acceptKey, string $page, array $params): void
    {
        $this->updateSubscriptions(new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName($page),
            new WebSocketPageSubscribeSignalDTO($acceptKey, $page, $params),
        ));
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new PerInstanceTestRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new PerInstanceTestAgentManagerDaemon();
    }
}

final class PerInstanceTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}
