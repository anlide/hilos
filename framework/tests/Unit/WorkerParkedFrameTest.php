<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\AgentConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\SystemSignalDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartFailedDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A consumer waiting for its state holds its own frames and nobody else's (HIL-1012).
 *
 * The wait used to run inside the frame handler: it polled the daemon link for up to its whole
 * deadline, put every other frame of the link by for that long, and kept every agent of the worker
 * from ticking. Under a protected-mode freeze the owner of the state is stopped on purpose, so that
 * wait could not end any way but on its deadline, and a command for an agent living on this very
 * worker stood behind it. The cases pin the replacement: the frame that cannot be answered yet is
 * parked with the frames addressed to the same consumer, everything else goes straight through,
 * and a pass releases the wait once the state lands - or once the deadline passes, into the very
 * answers the blocking wait gave.
 */
final class WorkerParkedFrameTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-parked';

    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    private WorkerParkedFrameTestManager $manager;

    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, WorkerParkedFrameTestHilos::class);
        Hilos::$sr = new SignalRouter();
        // The wait only exists where the state is delivered, so the cases stand where a worker stands.
        SourceInterestRegistry::readsWhatIsDelivered();

        $this->manager = new WorkerParkedFrameTestManager();
    }

    protected function tearDown(): void
    {
        foreach ([WorkerParkedFrameTestReader::AGENT_TYPE, WorkerParkedFrameTestAgent::AGENT_TYPE] as $agentId) {
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
        }
        SourceInterestRegistry::releaseConsumer(SourceConsumer::page(self::ACCEPT_KEY));
        SourceInterestRegistry::readsWhatItMounts();
        Hilos::$sr = null;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->boundAppClass);

        parent::tearDown();
    }

    public function testACommandForAnotherAgentIsHandledWhileAStartIsParked(): void
    {
        $this->manager->handleDaemonMessage(new AgentStartDTO(WorkerParkedFrameTestAgent::AGENT_TYPE));
        $this->manager->handleDaemonMessage(new AgentStartDTO(WorkerParkedFrameTestReader::AGENT_TYPE));

        $this->manager->handleDaemonMessage($this->systemSignal(WorkerParkedFrameTestAgent::AGENT_TYPE, 'ping'));

        $this->assertSame(['ping'], $this->manager->agent(WorkerParkedFrameTestAgent::AGENT_TYPE)?->heard);
        $this->assertNull(
            $this->manager->agent(WorkerParkedFrameTestReader::AGENT_TYPE),
            'The start waits for its state: nothing is created before it lands.',
        );
    }

    public function testAFrameForTheWaitingAgentIsHeldBehindItsStartAndReleasedInArrivalOrder(): void
    {
        $this->manager->handleDaemonMessage(new AgentStartDTO(WorkerParkedFrameTestReader::AGENT_TYPE));
        $this->manager->handleDaemonMessage($this->systemSignal(WorkerParkedFrameTestReader::AGENT_TYPE, 'first'));
        $this->manager->handleDaemonMessage($this->systemSignal(WorkerParkedFrameTestReader::AGENT_TYPE, 'second'));

        $this->manager->pass(microtime(true));
        $this->assertNull($this->manager->agent(WorkerParkedFrameTestReader::AGENT_TYPE), 'No state, no deadline: still waiting.');

        SourceInterestRegistry::markReady(SourceChange::KIND_RT, WorkerParkedFrameTestReader::COLLECTION);
        $this->manager->pass(microtime(true));

        // Handled now, either frame would have met no agent and been dropped as `agent not found`.
        $this->assertSame(['first', 'second'], $this->manager->agent(WorkerParkedFrameTestReader::AGENT_TYPE)?->heard);
        $this->assertSame([], $this->manager->failures);
    }

    public function testAParkedStartLeavesTheHandlerAtOnceSoThePassGoesOnToTheAgents(): void
    {
        // The other half of what the wait cost: inside the handler, the pass never reached the
        // agent tick or the signal dispatch for the length of the wait. Parked, the handler returns
        // and the loop goes on as if the frame had not arrived yet.
        $startedAt = microtime(true);
        $this->manager->handleDaemonMessage(new AgentStartDTO(WorkerParkedFrameTestReader::AGENT_TYPE));

        $this->assertLessThan(AgentConstants::START_DEADLINE_SECONDS / 2, microtime(true) - $startedAt);
        $this->assertTrue(
            SourceInterestRegistry::isDeclared(SourceChange::KIND_RT, WorkerParkedFrameTestReader::COLLECTION),
            'The interest is raised before the start parks, or the state would never be sent.',
        );
    }

    public function testAStartWhoseStateNeverArrivesIsRefusedOnTheDeadlineAsItWasBefore(): void
    {
        $this->manager->handleDaemonMessage(new AgentStartDTO(WorkerParkedFrameTestReader::AGENT_TYPE));

        $this->manager->pass(microtime(true) + AgentConstants::START_DEADLINE_SECONDS + 1.0);

        $this->assertNull($this->manager->agent(WorkerParkedFrameTestReader::AGENT_TYPE));
        $this->assertCount(1, $this->manager->failures);
        $this->assertInstanceOf(AgentCreationFailedException::class, $this->manager->failures[0]->failure);
        $this->assertFalse(
            SourceInterestRegistry::isDeclared(SourceChange::KIND_RT, WorkerParkedFrameTestReader::COLLECTION),
            'An agent that never started must not leave this worker asking for frames on its behalf.',
        );
        $this->assertCount(1, $this->manager->client->sentOf(WorkerAgentStartFailedDTO::class));

        // Released once: a later pass has nothing left to refuse.
        $this->manager->pass(microtime(true) + 2 * AgentConstants::START_DEADLINE_SECONDS);
        $this->assertCount(1, $this->manager->failures);
    }

    public function testAParkedSubscriptionRunsOnceItsStateLandsAndTheReplacedPageLetsGoOnce(): void
    {
        $this->manager->handleDaemonMessage(new AgentStartDTO(WorkerParkedFrameTestAgent::AGENT_TYPE));
        $this->manager->handleDaemonMessage($this->pageSubscribe(WorkerParkedFrameTestQuietPage::PAGE));

        $this->manager->handleDaemonMessage($this->pageSubscribe(WorkerParkedFrameTestReadingPage::PAGE));

        $agent = $this->manager->agent(WorkerParkedFrameTestAgent::AGENT_TYPE);
        $this->assertSame([WorkerParkedFrameTestQuietPage::PAGE], $agent?->subscribedPages);
        $this->assertSame([self::ACCEPT_KEY], $this->manager->unsubscribedFrom(WorkerParkedFrameTestQuietPage::PAGE));

        SourceInterestRegistry::markReady(SourceChange::KIND_RT, WorkerParkedFrameTestReader::COLLECTION);
        $this->manager->pass(microtime(true));

        $this->assertSame(
            [WorkerParkedFrameTestQuietPage::PAGE, WorkerParkedFrameTestReadingPage::PAGE],
            $agent?->subscribedPages,
        );
        $this->assertSame([self::ACCEPT_KEY], $this->manager->unsubscribedFrom(WorkerParkedFrameTestQuietPage::PAGE));
        $this->assertSame([], $this->manager->failures);
    }

    public function testASecondSubscriptionHeldBehindAReleasedOneWaitsForItsOwnState(): void
    {
        // Released on its deadline, the first subscription is answered without its state; the one
        // held behind it is a request of its own, and gets a wait of its own rather than riding
        // the release of the first.
        $this->manager->handleDaemonMessage(new AgentStartDTO(WorkerParkedFrameTestAgent::AGENT_TYPE));
        $this->manager->handleDaemonMessage($this->pageSubscribe(WorkerParkedFrameTestReadingPage::PAGE));
        $this->manager->handleDaemonMessage($this->pageSubscribe(WorkerParkedFrameTestReadingPage::PAGE));

        $this->manager->pass(microtime(true) + AgentConstants::START_DEADLINE_SECONDS + 1.0);

        $agent = $this->manager->agent(WorkerParkedFrameTestAgent::AGENT_TYPE);
        $this->assertCount(1, $agent?->subscribedPages ?? [], 'Only the released subscription runs.');

        SourceInterestRegistry::markReady(SourceChange::KIND_RT, WorkerParkedFrameTestReader::COLLECTION);
        $this->manager->pass(microtime(true));

        $this->assertSame(
            [WorkerParkedFrameTestReadingPage::PAGE, WorkerParkedFrameTestReadingPage::PAGE],
            $agent?->subscribedPages,
        );
    }

    public function testAConnectionThatClosesTakesItsParkedFramesWithIt(): void
    {
        $this->manager->handleDaemonMessage(new AgentStartDTO(WorkerParkedFrameTestAgent::AGENT_TYPE));
        $this->manager->handleDaemonMessage($this->pageSubscribe(WorkerParkedFrameTestReadingPage::PAGE));

        // Not held behind its own connection: nobody is left to answer.
        $this->manager->handleDaemonMessage($this->connectionClose());

        $agent = $this->manager->agent(WorkerParkedFrameTestAgent::AGENT_TYPE);
        $this->assertSame([self::ACCEPT_KEY], $agent?->closedConnections);
        $this->assertFalse(
            SourceInterestRegistry::isDeclared(SourceChange::KIND_RT, WorkerParkedFrameTestReader::COLLECTION),
            'Nothing will read the state the dropped subscription asked for.',
        );

        SourceInterestRegistry::markReady(SourceChange::KIND_RT, WorkerParkedFrameTestReader::COLLECTION);
        $this->manager->pass(microtime(true) + AgentConstants::START_DEADLINE_SECONDS + 1.0);

        $this->assertSame([], $agent?->subscribedPages, 'A dropped subscription is never run at a closed socket.');
    }

    /**
     * @param string $agentId Agent the signal is addressed to
     * @param string $name Signal name the agent records
     * @return DaemonAgentMessageDTO System signal as the daemon hands it to the worker
     */
    private function systemSignal(string $agentId, string $name): DaemonAgentMessageDTO
    {
        return new DaemonAgentMessageDTO($agentId, new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::SYSTEM),
            new SignalName($name),
            new SystemSignalDTO($name),
        ));
    }

    /**
     * @param string $page Page the connection subscribes to
     * @return DaemonAgentMessageDTO Page subscription of the test connection
     */
    private function pageSubscribe(string $page): DaemonAgentMessageDTO
    {
        return new DaemonAgentMessageDTO(WorkerParkedFrameTestAgent::AGENT_TYPE, new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName($page),
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, $page),
        ));
    }

    /**
     * @return DaemonAgentMessageDTO Close of the test connection
     */
    private function connectionClose(): DaemonAgentMessageDTO
    {
        return new DaemonAgentMessageDTO(WorkerParkedFrameTestAgent::AGENT_TYPE, new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::CONNECTION_CLOSE),
            new SignalName(SignalTypeConstants::CONNECTION_CLOSE),
            new WebSocketCloseSignalDTO(self::ACCEPT_KEY),
        ));
    }
}

/**
 * Project facade registering the two agents and the two pages the cases start and subscribe.
 *
 * Abstract because only its registry constants are read.
 */
abstract class WorkerParkedFrameTestHilos extends Hilos
{
    public const array AGENTS = [
        WorkerParkedFrameTestAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => WorkerParkedFrameTestAgent::class],
        WorkerParkedFrameTestReader::AGENT_TYPE => [AgentRegistryKey::WORKER => WorkerParkedFrameTestReader::class],
    ];

    public const array PAGES = [
        WorkerParkedFrameTestQuietPage::PAGE => WorkerParkedFrameTestQuietPage::class,
        WorkerParkedFrameTestReadingPage::PAGE => WorkerParkedFrameTestReadingPage::class,
    ];
}

/**
 * Worker manager with a daemon link that answers nothing, and a pass the case can take by hand.
 */
final class WorkerParkedFrameTestManager extends WorkerManager
{
    /** Link standing in for the daemon's: connected, and recording what the worker sends */
    public readonly WorkerParkedFrameTestClient $client;

    /** @var list<ContainedFailure> Failures the worker contained, in order */
    public array $failures = [];

    /** @var array<string, WorkerParkedFrameTestPageFactory> Page factories by the agent they serve */
    private array $pageFactories = [];

    public function __construct()
    {
        parent::__construct(1);
        $this->client = new WorkerParkedFrameTestClient();
        $this->daemonClient = $this->client;
    }

    /**
     * Takes the release step of one loop pass.
     *
     * @param float $now Microtime the pass stands at
     */
    public function pass(float $now): void
    {
        $this->releaseParkedFrames($now);
    }

    /**
     * @param string $agentId Agent id to look up
     * @return ?WorkerParkedFrameTestAgent The started agent, or null when this worker holds none
     */
    public function agent(string $agentId): ?WorkerParkedFrameTestAgent
    {
        $agent = $this->agentManager->getAgent($agentId);

        return $agent instanceof WorkerParkedFrameTestAgent ? $agent : null;
    }

    /**
     * @param string $page Page to ask about
     * @return list<string> Accept keys that page was torn down for, across every page factory
     */
    public function unsubscribedFrom(string $page): array
    {
        $acceptKeys = [];
        foreach ($this->pageFactories as $factory) {
            $acceptKeys = [...$acceptKeys, ...($factory->unsubscribed[$page] ?? [])];
        }

        return $acceptKeys;
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new WorkerParkedFrameTestAgentManager();
    }

    /**
     * @param AgentInterface $agent Agent the router serves pages for
     * @return PageSignalRouter Router over a fixture page factory
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        $factory = new WorkerParkedFrameTestPageFactory($agent);
        $this->pageFactories[$agent->getId()] = $factory;

        return new PageSignalRouter($factory, new ActionRouteConfig());
    }

    /**
     * @param ContainedFailure $failure Failure the loop contained
     */
    protected function onTickFailure(ContainedFailure $failure): void
    {
        $this->failures[] = $failure;
    }
}

/**
 * Daemon link that is always up and keeps what the worker sent.
 */
final class WorkerParkedFrameTestClient extends WorkerDaemonClient
{
    /** @var list<WorkerDTO|array<string, mixed>> Messages the worker sent, in order */
    public array $sent = [];

    /**
     * @param WorkerDTO|array<string, mixed> $data Message the worker sent
     */
    public function send(WorkerDTO|array $data): void
    {
        $this->sent[] = $data;
    }

    public function isConnected(): bool
    {
        return true;
    }

    /**
     * @param class-string<WorkerDTO> $class Message class to pick
     * @return list<WorkerDTO> Messages of that class the worker sent
     */
    public function sentOf(string $class): array
    {
        return array_values(array_filter($this->sent, static fn(WorkerDTO|array $message): bool => $message instanceof $class));
    }
}

final class WorkerParkedFrameTestAgentManager extends AgentManager
{
    /**
     * @param string $agentType Agent type the worker was asked to start
     * @param ?string $agentIndex Agent index (unused)
     * @return AgentInterface Fixture agent of that type
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return $agentType === WorkerParkedFrameTestReader::AGENT_TYPE
            ? new WorkerParkedFrameTestReader()
            : new WorkerParkedFrameTestAgent();
    }
}

/**
 * Agent that reads nothing, so it starts at once, and records what reaches it.
 */
class WorkerParkedFrameTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_parked_live';

    /** @var list<string> Names of the system signals it was handed, in order */
    public array $heard = [];

    /** @var list<string> Pages its connections subscribed to, in order */
    public array $subscribedPages = [];

    /** @var list<string> Accept keys of the connections it was told closed */
    public array $closedConnections = [];

    public function onSignalSystem(SignalDataInterface $data, string $source, string $name): void
    {
        $this->heard[] = $name;
    }

    public function onSignalPageSubscribe(WebSocketPageSubscribeSignalDTO $data, string $source, string $name): void
    {
        $this->subscribedPages[] = $data->page ?? $name;
    }

    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void
    {
        $this->closedConnections[] = $data->acceptKey;
    }

    public function onStop(): void
    {
    }
}

/**
 * Agent whose start waits for one RT collection, which nothing in the case delivers unless told to.
 */
final class WorkerParkedFrameTestReader extends WorkerParkedFrameTestAgent
{
    public const string AGENT_TYPE = 'unit_parked_reader';

    public const string COLLECTION = 'unitParkedRows';

    public const array READS_RT = [self::COLLECTION];
}

/**
 * Page factory over the two fixture pages, recording which connections each page was torn down for.
 *
 * @extends AbstractPageFactory<AgentInterface>
 */
final class WorkerParkedFrameTestPageFactory extends AbstractPageFactory
{
    /** @var array<string, list<string>> Accept keys each page was torn down for */
    public array $unsubscribed = [];

    /**
     * @param string $pageName Page name
     * @return AbstractPage Fixture page
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        return match ($pageName) {
            WorkerParkedFrameTestQuietPage::PAGE => new WorkerParkedFrameTestQuietPage($this->agent, $this),
            WorkerParkedFrameTestReadingPage::PAGE => new WorkerParkedFrameTestReadingPage($this->agent, $this),
            default => throw new PageNotFoundException($pageName),
        };
    }

    /**
     * @param string $pageName Page name
     * @return bool True for the two fixture pages
     */
    public function hasPage(string $pageName): bool
    {
        return in_array($pageName, [WorkerParkedFrameTestQuietPage::PAGE, WorkerParkedFrameTestReadingPage::PAGE], true);
    }
}

/**
 * Page that reads nothing, so a subscription to it is answered at once.
 */
class WorkerParkedFrameTestQuietPage extends AbstractPage
{
    public const string PAGE = 'unit_parked_quiet';

    /**
     * @param AgentInterface $agent Agent serving the page
     * @param WorkerParkedFrameTestPageFactory $factory Factory keeping the teardown record
     */
    public function __construct(AgentInterface $agent, private readonly WorkerParkedFrameTestPageFactory $factory)
    {
        parent::__construct($agent);
    }

    /**
     * @param string $acceptKey Connection the page is torn down for
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        $this->factory->unsubscribed[static::PAGE][] = $acceptKey;
    }
}

/**
 * Page that reads the collection the case withholds, so a subscription to it waits.
 */
final class WorkerParkedFrameTestReadingPage extends WorkerParkedFrameTestQuietPage
{
    public const string PAGE = 'unit_parked_reading';

    public const array READS_RT = [WorkerParkedFrameTestReader::COLLECTION];
}
