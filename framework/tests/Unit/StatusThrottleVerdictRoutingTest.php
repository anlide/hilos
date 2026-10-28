<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Throttle\DTO\ThrottleCheckSignalData;
use Hilos\Auth\Throttle\DTO\ThrottleVerdictSignalData;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Status\StatusAgent;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Throwable;

/** The real Status and users handlers must pass typed verdicts to their own worker pools without an error. */
final class StatusThrottleVerdictRoutingTest extends TestCase
{
    /** @var array{mixed, mixed, mixed, mixed, mixed} Facade state restored after delivery */
    private array $previousGlobals;
    private string $previousApp;
    private string $logDirectory;

    protected function setUp(): void
    {
        $this->previousApp = Hilos::appClass();
        $this->previousGlobals = [Hilos::$sr, Hilos::$env, Hilos::$rt, Hilos::$setting, Hilos::$cluster];
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, StatusVerdictRoutingHilos::class);
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        Hilos::$rt = null;
        Hilos::$setting = null;
        Hilos::$cluster = null;
        $this->logDirectory = sys_get_temp_dir() . '/hilos-status-verdict-' . bin2hex(random_bytes(8));
        mkdir($this->logDirectory);
        Logger::setLogFile($this->logDirectory . '/daemon.log');
    }

    protected function tearDown(): void
    {
        [Hilos::$sr, Hilos::$env, Hilos::$rt, Hilos::$setting, Hilos::$cluster] = $this->previousGlobals;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
        Logger::resetLogFile();
        foreach (glob($this->logDirectory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->logDirectory);
    }

    /** @return iterable<string, array{string, ?string, string, bool}> Answer name, address and decision */
    public static function hosts(): iterable
    {
        foreach ([true, false] as $allow) {
            $decision = $allow ? 'allow' : 'deny';
            yield "users {$decision}" => [HilosAgentType::HILOS_USERS_LIBRARY, null, HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT, $allow];
            yield "Status {$decision}" => [HilosAgentType::HILOS_STATUS, null, HilosSignalConstants::STATUS_THROTTLE_VERDICT, $allow];
            yield "indexed {$decision}" => [StatusVerdictIndexedAgent::AGENT_TYPE, '42', StatusVerdictIndexedAgent::THROTTLE_VERDICT_SIGNAL, $allow];
        }
    }

    #[DataProvider('hosts')]
    public function testRoutingIpcAndWorkerParsingResumeOnlyTheAddressedPool(string $type, ?string $index, string $name, bool $allowed): void
    {
        $manager = new StatusVerdictWorkerManager(1);
        $agent = match ($type) {
            HilosAgentType::HILOS_USERS_LIBRARY => new StatusVerdictUsersAgent(),
            HilosAgentType::HILOS_STATUS => new StatusAgent(),
            default => new StatusVerdictIndexedAgent($index),
        };
        $page = $manager->park($agent);
        $checks = [];
        while (($queued = Hilos::$sr->getNextQueuedSignal()) instanceof SignalDTO) {
            $this->assertInstanceOf(AgentSignalData::class, $queued->data);
            $this->assertInstanceOf(ThrottleCheckSignalData::class, $queued->data->data);
            $checks[] = $queued->data->data;
        }
        $this->assertCount(2, $checks);
        $this->assertSame($type, $checks[0]->agentType);
        $this->assertSame($index, $checks[0]->agentIndex);
        $this->assertSame(0, $page->calls);

        foreach ($checks as $position => $check) {
            $signal = new SignalDTO(
                new SignalSource(SignalSource::AGENT, HilosAgentType::HILOS_AUTH_THROTTLE),
                new SignalType(SignalTypeConstants::AGENT_SIGNAL),
                new SignalName($name),
                // Generic after IPC: only the worker's topology parser knows the inner type.
                new AgentSignalData(new SignalData(
                    new ThrottleVerdictSignalData($check->requestKey, $allowed, $allowed ? null : 17, $index)->toArray(),
                )),
            );
            $this->assertEquals([new AgentDestination($type, $index)], Hilos::$sr->getDestinations($signal));
            $packet = WorkerDTO::factoryWorkerDTO(new DaemonAgentMessageDTO($agent->getId(), $signal)->toJson());
            $this->assertInstanceOf(DaemonAgentMessageDTO::class, $packet);
            $this->assertInstanceOf(SignalData::class, $packet->signal->data->data);
            $manager->deliver($packet);
            $this->assertSame($allowed ? $position : 0, $page->calls);
        }
        $this->assertSame(!$allowed, $page->failure !== null);
        $this->assertCount(2, Hilos::$sr->parsed);
        foreach (Hilos::$sr->parsed as $parsed) {
            $this->assertInstanceOf(ThrottleVerdictSignalData::class, $parsed->data);
            $this->assertSame($index, $parsed->data->agentIndex);
        }
        foreach (glob($this->logDirectory . '/agent-*.error.log') as $file) {
            $this->assertSame('', file_get_contents($file), 'Agent handler must accept its declared verdict');
        }
    }
}

final class StatusVerdictUsersAgent extends AbstractUsersLibraryAgent
{
}

final class StatusVerdictIndexedAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'indexed_verdict_host';
    public const string THROTTLE_VERDICT_SIGNAL = 'indexed_throttle_verdict';
    public const array AGENT_SIGNALS = [self::THROTTLE_VERDICT_SIGNAL => [
        AgentSignalConfigKey::DTO => ThrottleVerdictSignalData::class,
        AgentSignalConfigKey::INDEX_FIELD => ThrottleVerdictSignalData::agentIndex,
    ]];

    /** @param ?string $index Instance address */
    public function __construct(?string $index)
    {
        $this->agentIndex = $index;
    }

    /** No resources were started. */
    public function onStop(): void
    {
    }
}

final class StatusVerdictRoutingHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_USERS_LIBRARY => [AgentRegistryKey::WORKER => StatusVerdictUsersAgent::class],
        HilosAgentType::HILOS_STATUS => [AgentRegistryKey::WORKER => StatusAgent::class],
        StatusVerdictIndexedAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => StatusVerdictIndexedAgent::class, AgentRegistryKey::INDEXED => true],
    ];

    /** @return HilosDbContext Unconfigured context; routing uses no database */
    protected static function createDb(): HilosDbContext
    {
        return new HilosDbContext();
    }
}

final class StatusVerdictSignalRouter extends SignalRouter
{
    /** @var list<AgentSignalData> Payloads parsed by the worker dispatch */
    public array $parsed = [];

    /**
     * @param string $signalName Answer name
     * @param AgentSignalData $signalData Incoming generic payload
     * @return AgentSignalData Topology-parsed payload
     */
    public function createAgentSignalPayloadDTO(string $signalName, AgentSignalData $signalData): AgentSignalData
    {
        $parsed = parent::createAgentSignalPayloadDTO($signalName, $signalData);
        $this->parsed[] = $parsed;
        return $parsed;
    }

    /** @return class-string<Hilos> Fixture topology */
    protected function hilosClass(): string
    {
        return StatusVerdictRoutingHilos::class;
    }
}

final class StatusVerdictWorkerManager extends WorkerManager
{
    /** @param DaemonAgentMessageDTO $packet Decoded IPC frame */
    public function deliver(DaemonAgentMessageDTO $packet): void
    {
        Closure::bind(function () use ($packet): void {
            $this->handleAgentMessage($packet);
        }, $this, WorkerManager::class)();
    }

    /**
     * @param AbstractAgent $agent Parking host
     * @return StatusVerdictPage Its page holding the deferred action
     */
    public function park(AbstractAgent $agent): StatusVerdictPage
    {
        $this->agentManager->addAgent($agent->getId(), $agent);
        $router = Closure::bind(function () use ($agent): PageSignalRouter {
            return $this->getPageSignalRouter($agent->getId(), $agent);
        }, $this, WorkerManager::class)();
        $router->dispatchAction(new WebSocketActionSignalDTO(
            acceptKey: 'same-ak',
            action: StatusVerdictPage::ACTION,
            data: [],
            clientIp: '203.0.113.7',
            sessionIdentity: 'session-hash',
        ), SignalSource::WEBSOCKET);
        $factory = new ReflectionProperty(PageSignalRouter::class, 'pageFactory')->getValue($router);
        return $factory->getPage(StatusVerdictPage::PAGE);
    }

    /** @return SignalRouter Topology-aware worker parser */
    protected function createSignalRouter(): SignalRouter
    {
        return new StatusVerdictSignalRouter();
    }

    /** @return AgentManager Empty test manager populated by park() */
    protected function createAgentManager(): AgentManager
    {
        return new class extends AgentManager {
            /**
             * @param string $agentType Unused
             * @param ?string $agentIndex Unused
             * @return AgentInterface Status instance
             */
            protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
            {
                return new StatusAgent();
            }
        };
    }

    /**
     * @param AgentInterface $agent Registered parking host
     * @return PageSignalRouter Pool configured from its host's single declaration
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        return new PageSignalRouter(
            new StatusVerdictPageFactory($agent),
            new ActionRouteConfig([StatusVerdictPage::ACTION => StatusVerdictPage::PAGE]),
            throttleVerdictSignal: $agent::THROTTLE_VERDICT_SIGNAL,
        );
    }
}

final class StatusVerdictPageFactory extends AbstractPageFactory
{
    /**
     * @param string $pageName Fixture page name
     * @return AbstractPage Fixture action host
     */
    protected function createPage(string $pageName): AbstractPage
    {
        return new StatusVerdictPage($this->agent);
    }

    /**
     * @param string $pageName Fixture page name
     * @return bool Whether it names the fixture
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === StatusVerdictPage::PAGE;
    }
}

final class StatusVerdictPage extends AbstractPage
{
    public const string PAGE = 'verdict_probe';
    public const string ACTION = 'verdict_probe_action';
    public const array THROTTLED_ACTIONS = [self::ACTION];
    public int $calls = 0;
    public ?Throwable $failure = null;

    /**
     * @param string $acceptKey Caller
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Original payload
     * @param Throwable $e Refusal from the real deferred-action pool
     */
    public function onActionException(string $acceptKey, string $action, ActionPayloadDTO $dto, Throwable $e): void
    {
        $this->failure = $e;
    }

    /**
     * @param string $acceptKey Caller
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Original parsed payload
     * @return ?ActionReplyDTO No tracked reply
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        $this->calls++;
        return null;
    }
}
