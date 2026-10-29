<?php

declare(strict_types=1);

namespace Hilos\Tests\Fixtures;

use Closure;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Pages\Status\AbstractHilosStatusPage;
use Hilos\Tables\Status\HilosStatusWorksTable;
use Hilos\Tables\Status\HilosStatusHistoryTable;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\Definition\StatusFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Mail\HilosMailer;
use Hilos\Pages\Status\AbstractHilosStatusWorksPage;
use Hilos\Pages\Status\AbstractHilosStatusHistoryPage;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Status\StatusAgent;
use Hilos\Status\StatusSettingsCatalog;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountStandingResolver;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Assert;
use ReflectionProperty;
use Throwable;

/** Real commit fixture shared by work-action and mail cases; production page and owner bodies are never replaced. */
final class StatusWorksEnvironment
{
    private const array TABLES = [
        'hilos_user', 'hilos_user_merge', 'hilos_account_deletion', 'hilos_setting', 'hilos_status_incident',
        'hilos_status_incident_update', 'hilos_status_subscriber', 'hilos_status_work',
        'hilos_status_work_component', 'hilos_status_maintenance_period',
    ];

    public StatusAgent $owner;
    public StatusWorksRuntime $runtime;
    public PageSignalRouter $gatekeeper;
    public StatusWorksWorker $worker;
    public StatusPublicVolume $volume;

    private string $previousApp;
    /** @var array Facades restored after the case */
    private array $previousGlobals;
    /** @var array Source registries restored after probes */
    private array $previousBus;
    /** @var array Account-standing cache restored with its source */
    private array $previousStanding;
    /** @var array Source interest state borrowed for this fixture's lifetime */
    private array $previousInterests = [];
    /** @var array<class-string, array> Ownership grants borrowed for this fixture's lifetime */
    private array $previousClaims = [];
    /** @var array<string, string|false> Environment entries restored after the case */
    private array $previousEnvironment = [];
    /** @var list<string> Page interests opened by this environment */
    private array $pageConnections = [];

    /** Mounts isolated production chains and runs real startup/reconciliation. */
    public function __construct()
    {
        $this->previousApp = Hilos::appClass();
        $this->previousGlobals = [Hilos::$db, Hilos::$rt, Hilos::$sr, Hilos::$mail, Hilos::$env,
            Hilos::$setting, Hilos::$notify, Hilos::$browser, Hilos::$table];
        foreach (['mirrors', 'reactions', 'provenance'] as $property) {
            $this->previousBus[$property] = new ReflectionProperty(SourceChangeBus::class, $property)->getValue();
        }
        foreach (['standings', 'lapsedUserIds', 'epoch'] as $property) {
            $this->previousStanding[$property] = new ReflectionProperty(AccountStandingResolver::class, $property)->getValue();
        }
        foreach (['byConsumer', 'states', 'readsWhatIsDelivered'] as $property) {
            $this->previousInterests[$property] = new ReflectionProperty(SourceInterestRegistry::class, $property)->getValue();
        }
        foreach ([TruthSourceRegistry::class, RtTruthSourceRegistry::class] as $registry) {
            $this->previousClaims[$registry] = new ReflectionProperty($registry, 'sources')->getValue();
        }
        try {
            $this->mount();
        } catch (Throwable $failure) {
            $this->close();
            throw $failure;
        }
    }

    /** Drops isolated storage and releases every local claim, source and facade. */
    public function close(): void
    {
        try {
            SourceInterestRegistry::readsWhatItMounts();
            foreach (array_reverse(self::TABLES) as $table) {
                $this->stub($table, true);
            }
        } finally {
            if (isset($this->owner)) {
                TruthSourceRegistry::unregisterAgent($this->owner->getId());
                RtTruthSourceRegistry::unregisterAgent($this->owner->getId());
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->owner->getId()));
            }
            foreach (array_unique(['ak-status', ...$this->pageConnections]) as $acceptKey) {
                SourceInterestRegistry::releaseConsumer(SourceConsumer::page($acceptKey));
            }
            RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
            Schema::reset();
            try {
                if (isset($this->volume)) {
                    $this->volume->close();
                }
            } finally {
                foreach ($this->previousBus as $property => $value) {
                    new ReflectionProperty(SourceChangeBus::class, $property)->setValue(null, $value);
                }
                foreach ($this->previousStanding as $property => $value) {
                    new ReflectionProperty(AccountStandingResolver::class, $property)->setValue(null, $value);
                }
                foreach ($this->previousInterests as $property => $value) {
                    new ReflectionProperty(SourceInterestRegistry::class, $property)->setValue(null, $value);
                }
                foreach ($this->previousClaims as $registry => $sources) {
                    new ReflectionProperty($registry, 'sources')->setValue(null, $sources);
                }
                new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
                [Hilos::$db, Hilos::$rt, Hilos::$sr, Hilos::$mail, Hilos::$env,
                    Hilos::$setting, Hilos::$notify, Hilos::$browser, Hilos::$table] = $this->previousGlobals;
                foreach ($this->previousEnvironment as $key => $value) {
                    putenv($value === false ? $key : $key . '=' . $value);
                }
            }
        }
    }

    /**
     * @param string $page Registered page
     * @param string $acceptKey Independent viewer connection
     */
    public function open(string $page = StatusWorksPage::PAGE, string $acceptKey = 'ak-status'): void
    {
        $this->pageConnections[] = $acceptKey;
        $this->worker->pageInterests($page, $acceptKey);
        $subscribe = new WebSocketPageSubscribeSignalDTO($acceptKey, $page);
        Hilos::$sr->subscribeToPage($page, $subscribe);
        Hilos::$sr->bindPageAgent($acceptKey, $page, StatusWorksGatekeeper::AGENT_TYPE, null);
        $this->gatekeeper->dispatchPageSubscribe($subscribe, 'websocket', 'page_subscribe');
    }

    /**
     * @template T
     * @param Closure(): T $operation Work under the real owner's declared claims
     * @return T Operation result
     */
    public function asOwner(Closure $operation): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->owner->getId()), $operation);
    }

    /**
     * @param string $action Real page action
     * @param array<string, mixed> $payload Business values
     * @param string $requestId Envelope correlation
     * @param string $acceptKey Ordering browser connection
     * @return SignalDTO Forwarded ask, with no premature client ack
     */
    public function forward(string $action, array $payload, string $requestId = 'button-1', string $acceptKey = 'ak-status'): SignalDTO
    {
        Assert::assertSame([], $this->drain());
        $this->gatekeeper->dispatchAction(new WebSocketActionSignalDTO($acceptKey, $action, $payload, $requestId), 'websocket');
        $frames = $this->drain();
        Assert::assertCount(1, $frames);
        Assert::assertInstanceOf(AgentSignalData::class, $frames[0]->data);
        return $frames[0];
    }

    /**
     * @param SignalDTO $frame Real gatekeeper ask or forged internal ask
     * @return HandoverAnswerSignalData Owner's answer after its transaction
     */
    public function land(SignalDTO $frame): HandoverAnswerSignalData
    {
        $this->worker->deliver($frame);
        $frames = $this->drain();
        Assert::assertNotEmpty($frames);
        $done = $frames[array_key_last($frames)];
        Assert::assertSame(HilosSignalConstants::HILOS_STATUS_WORK_WRITE_DONE, $done->signalName->getName());
        Assert::assertInstanceOf(HandoverAnswerSignalData::class, $done->data->data);
        Assert::assertFalse(Database::hasOpenTransactions());
        return $done->data->data;
    }

    /**
     * @param string $action Real page action
     * @param array<string, mixed> $payload Business values
     * @param string $requestId Envelope correlation
     * @param string $acceptKey Ordering browser connection
     * @return HandoverAnswerSignalData Committed outcome, also passed through the real page answer handler
     */
    public function submit(string $action, array $payload, string $requestId = 'button-1', string $acceptKey = 'ak-status'): HandoverAnswerSignalData
    {
        $answer = $this->land($this->forward($action, $payload, $requestId, $acceptKey));
        $this->gatekeeper->dispatchAgentSignal(new AgentSignalData($answer), HilosAgentType::HILOS_STATUS,
            HilosSignalConstants::HILOS_STATUS_WORK_WRITE_DONE);
        $acks = $this->drain();
        Assert::assertCount(1, $acks);
        Assert::assertSame($answer->error === null ? SignalConstants::ACTION_SUCCESS : SignalConstants::ACTION_ERROR,
            $acks[0]->signalName->getName());
        Assert::assertSame($action, $acks[0]->data->data->action);
        Assert::assertSame($requestId, $acks[0]->data->data->requestId);
        Assert::assertSame($acceptKey, $acks[0]->data->targetAcceptKey);
        return $answer;
    }

    /**
     * @param string $email Subscription address
     * @param bool $confirmed Whether confirmed by event time
     * @param int $at Subscription and confirmation clock
     * @return int Persisted primary id
     */
    public function recipient(string $email, bool $confirmed = true, int $at = 10): int
    {
        $id = $this->asOwner(fn () => Database::transaction('primary', static function () use ($email, $confirmed, $at): int {
            $issued = Hilos::$db->statusSubscribers->actions->create($email, $at, 'Europe/Warsaw');
            if ($confirmed) {
                Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->confirm($issued->token, $at);
            }
            return $issued->subscriberId;
        }));
        $this->drain();
        return $id;
    }

    /**
     * @param ?int $at Tick clock, defaulting to the current server time
     */
    public function tick(?int $at = null): void
    {
        $this->asOwner(fn () => $this->owner->tickAt($at ?? TimeHelper::nowMs()));
    }

    /** @return list<SignalDTO> All queued transport frames */
    public function drain(): array
    {
        $frames = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $frames[] = $frame;
        }
        return $frames;
    }

    /** Installs the case environment before the managed rollback harness can open its scope. */
    private function mount(): void
    {
        new ReflectionProperty(AccountStandingResolver::class, 'standings')->setValue(null, []);
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, StatusWorksHilos::class);
        foreach ([EnvConstants::APP_ENV->name => 'test',
            EnvConstants::HILOS_STATUS_URL->name => 'https://service.example.test/status',
            EnvConstants::MAIL_FROM_NAME->name => 'Example Service', EnvConstants::MAIL_WORKER_COUNT->name => '4',
            EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name => 'recipient', EnvConstants::HILOS_PROJECT_TIME_ZONE->name => 'UTC']
            as $key => $value) {
            $this->previousEnvironment[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        Hilos::$notify = null;
        Hilos::$table = new StatusWorksTables();
        Hilos::$table->configure();
        foreach (array_reverse(self::TABLES) as $table) {
            $this->stub($table, true);
        }
        foreach (self::TABLES as $table) {
            $this->stub($table, false);
        }
        Database::sqlRun("INSERT INTO hilos_user (id, name, admin) VALUES (7, 'Admin', 1), (8, 'Editor', 1), (5, 'Viewer', 0)");
        Schema::reset();
        Schema::initialize();
        Hilos::$db = new StatusWorksDbContext();
        Hilos::$db->configure();
        Hilos::$setting = new SettingsAccessor(StatusSettingsCatalog::class);
        Hilos::$sr = new StatusWorksRouter();
        Hilos::$mail = new HilosMailer();
        $this->volume = new StatusPublicVolume();
        Hilos::$fs->status->ensureDirectory();
        $this->runtime = new StatusWorksRuntime();
        $this->runtime->mountFeatureRuntime([new StatusFeature()]);
        $this->runtime->configure();
        $this->runtime->bindStateCollectionNames();
        Hilos::$rt = $this->runtime;
        StatusWorksHilos::initBrowser(new StatusWorksBrowser(7, true));
        $this->owner = new StatusAgent();
        OwnershipDeclaration::claimAll($this->owner);
        $this->worker = new StatusWorksWorker($this->owner);
        $this->gatekeeper = new PageSignalRouter(new HilosPageFactory(new StatusWorksGatekeeper(), StatusWorksHilos::class),
            new ActionRouteConfig(StatusWorksHilos::getPageActionRoutes()), new SignalRouteConfig(StatusWorksHilos::getPageSignalRoutes()));
        $this->asOwner(fn () => $this->owner->onStart());
        $this->asOwner(fn () => $this->owner->reconcileMaintenanceBeforeWrite(TimeHelper::nowMs()));
        $this->drain();
        Hilos::$browser->bindPageResender($this->worker);
        SourceChangeBus::subscribe(new StatusWorksBrowserChanges());
    }

    /**
     * @param string $table Framework table with a canonical migration stub
     * @param bool $down Whether to drop instead of creating
     */
    private function stub(string $table, bool $down): void
    {
        if ($down) {
            $table .= '_down';
        }
        Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . '.sql'));
    }
}

abstract class StatusWorksHilos extends Hilos
{
    public const array AGENTS = [
        'probe' => [AgentRegistryKey::SCOPE => AgentScope::CLUSTER],
        HilosAgentType::HILOS_STATUS => [AgentRegistryKey::WORKER => StatusAgent::class],
        StatusWorksGatekeeper::AGENT_TYPE => [AgentRegistryKey::WORKER => StatusWorksGatekeeper::class],
    ];
    public const array PAGES = [AbstractHilosStatusWorksPage::PAGE => StatusWorksPage::class,
        AbstractHilosStatusPage::PAGE => StatusWorksOverviewPage::class, StatusHistoryPage::PAGE => StatusHistoryPage::class];
    public const array TABLES = [HilosStatusWorksTable::TABLE => HilosStatusWorksTable::class,
        HilosStatusHistoryTable::TABLE => HilosStatusHistoryTable::class];
    public const array PAGE_TABLES = [StatusWorksPage::PAGE => [HilosStatusWorksTable::TABLE => []],
        StatusHistoryPage::PAGE => [HilosStatusHistoryTable::TABLE => []]];
    protected const array FEATURES = [HilosFeature::STATUS];
    protected const array STATUS_COMPONENTS = [
        'service' => ['name' => 'Service', 'description' => null, 'agentType' => 'probe'],
        'mail' => ['name' => 'Mail', 'description' => null, 'agentType' => 'probe'],
    ];
}

final class StatusWorksPage extends AbstractHilosStatusWorksPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = StatusWorksGatekeeper::AGENT_TYPE;
}

final class StatusWorksOverviewPage extends AbstractHilosStatusPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = StatusWorksGatekeeper::AGENT_TYPE;
}

final class StatusHistoryPage extends AbstractHilosStatusHistoryPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = StatusWorksGatekeeper::AGENT_TYPE;
}

final class StatusWorksGatekeeper extends AbstractAgent
{
    public const string AGENT_TYPE = 'fixture_status_works_gatekeeper';

    /** No background work in this page-serving fixture. */
    public function onStop(): void
    {
    }
}

final class StatusWorksDbContext extends HilosDbContext
{
}

/** Two independent administrators for provenance races; all other connections use the first. */
final class StatusWorksBrowser extends IdentityTestBrowser
{
    /**
     * @param string $acceptKey Independent browser connection
     * @return ConnectionIdentity Administrator assigned to that connection
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved($acceptKey === 'ak-editor' ? 8 : 7);
    }
}

final class StatusWorksRuntime extends RtContext
{
    /** Real unconditional row initially inactive. */
    public function configure(): void
    {
        $this->phase(ProtectedModeRuntime::PHASE_INACTIVE);
    }

    /** @param ?string $phase Freeze phase, or missing source */
    public function phase(?string $phase): void
    {
        if ($phase === null) {
            unset($this->_stateItems[ProtectedModeRuntime::RT_ITEM]);
            return;
        }
        $row = ProtectedModeRuntime::create();
        $row->phase = $phase;
        $this->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, $row);
    }

    /** Enables anonymous admin viewing for the refusal tests. */
    public function viewer(): void
    {
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        $this->hilosAdminViewModeRuntime->actions->set(true);
    }
}

final class StatusWorksRouter extends SignalRouter
{
    /** @return class-string<Hilos> Fixture topology with production action/signal declarations */
    protected function hilosClass(): string
    {
        return StatusWorksHilos::class;
    }
}

final class StatusWorksWorker extends WorkerManager
{
    /** @param StatusAgent $owner Real production owner */
    public function __construct(StatusAgent $owner)
    {
        parent::__construct(1);
        $this->agentManager->addAgent($owner->getId(), $owner);
        $gatekeeper = new StatusWorksGatekeeper();
        $this->agentManager->addAgent($gatekeeper->getId(), $gatekeeper);
    }

    /** @param SignalDTO $frame Real transport entry with topology parsing and origin stamping */
    public function deliver(SignalDTO $frame): void
    {
        $dispatch = Closure::bind(static function (self $worker, SignalDTO $frame): void {
            $worker->handleAgentMessage(new DaemonAgentMessageDTO(HilosAgentType::HILOS_STATUS, $frame));
        }, null, WorkerManager::class);
        ExecutionContext::run(new ExecutionFrame(agentId: HilosAgentType::HILOS_STATUS), fn () => $dispatch($this, $frame));
    }

    /**
     * @param string $page Registered page
     * @param string $acceptKey Connection holding its ordinary production interest declaration
     * @param bool $worksReady Whether work and component sources have arrived
     */
    public function pageInterests(string $page, string $acceptKey, bool $worksReady = true): void
    {
        $reads = Closure::bind(fn (): array => $this->pageSourceReads($page), $this, WorkerManager::class)();
        foreach ($reads as $kind => $keys) {
            foreach ($keys as $key) {
                SourceInterestRegistry::register($kind, $key, SourceConsumer::page($acceptKey));
                if ($worksReady || $kind !== SourceChange::KIND_DB
                    || !in_array($key, [HilosDbContext::statusWorks, HilosDbContext::statusWorkComponents], true)) {
                    SourceInterestRegistry::markReady($kind, $key);
                }
            }
        }
    }

    /** @return SignalRouter Real topology-driven routing */
    protected function createSignalRouter(): SignalRouter
    {
        return new StatusWorksRouter();
    }

    /**
     * @param AgentInterface $agent Real page-serving agent
     * @return PageSignalRouter Production parsing over fixture topology
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        return new PageSignalRouter(new HilosPageFactory($agent, StatusWorksHilos::class),
            new ActionRouteConfig(StatusWorksHilos::getPageActionRoutes()), new SignalRouteConfig(StatusWorksHilos::getPageSignalRoutes()));
    }

    /** @return AgentManager Local registry for the actual Status owner */
    protected function createAgentManager(): AgentManager
    {
        return new StatusWorksAgentManager();
    }
}

final class StatusWorksAgentManager extends AgentManager
{
    /**
     * @param string $agentType Unused fixture type
     * @param ?string $agentIndex Unused fixture index
     * @return AgentInterface Real Status owner
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new StatusAgent();
    }
}

final class StatusWorksTables extends TableContext
{
    /** Registers the production work viewport. */
    public function configure(): void
    {
        $this->register(HilosStatusWorksTable::TABLE, new HilosStatusWorksTable());
        $this->register(HilosStatusHistoryTable::TABLE, new HilosStatusHistoryTable());
    }
}

final class StatusWorksBrowserChanges implements SourceChangeSubscriberInterface
{
    /**
     * @param SourceChange $change Committed production source fact
     * @param SourceChangeProvenance $provenance Source context, unused
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        Hilos::$browser?->record($change);
    }
}
