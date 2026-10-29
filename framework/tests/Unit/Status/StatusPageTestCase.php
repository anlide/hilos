<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Status;

use Closure;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Database\Database;
use Hilos\Database\View\Collection\Users;
use Hilos\Database\View\Collection\UserMerges;
use Hilos\Database\View\Collection\AccountDeletions;
use Hilos\Hilos;
use Hilos\Users\AccountStandingResolver;
use ReflectionProperty;
use Hilos\Pages\Status\AbstractHilosStatusPage;
use Hilos\Pages\Status\AbstractHilosStatusComponentsPage;
use Hilos\Pages\Status\AbstractHilosStatusSubscribersPage;
use Hilos\Pages\Status\AbstractHilosStatusWorksPage;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Status\HilosStatusComponentsTable;

/** Production pages, worker re-send and viewport bridge over the real manual read stores. */
abstract class StatusPageTestCase extends StatusReadTestCase
{
    protected StatusPageBrowser $browser;
    protected StatusPageWorker $worker;
    private ?BrowserContext $previousBrowser;
    private array $previousStanding = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['standings', 'lapsedUserIds', 'epoch'] as $property) {
            $this->previousStanding[$property] = (new ReflectionProperty(AccountStandingResolver::class, $property))->getValue();
        }
        (new ReflectionProperty(AccountStandingResolver::class, 'standings'))->setValue(null, []);
        $this->database->guardReaders($this->createStub(Users::class), $this->createStub(UserMerges::class),
            $this->createStub(AccountDeletions::class));
        $this->previousBrowser = Hilos::$browser;
        $this->browser = new StatusPageBrowser();
        StatusPageHilos::initBrowser($this->browser);
        $this->worker = new StatusPageWorker(1);
        $this->browser->bindPageResender($this->worker);
        Hilos::$table = new StatusPageTables();
        Hilos::$table->configure();
        $this->observe('zeta', 2);
        $this->observe('alpha', 2);
        $this->browser->flushToSignalRouter();
        $this->drain();
    }

    protected function tearDown(): void
    {
        Database::transactionRollback();
        foreach (['overview', 'second', 'secret'] as $key) {
            SourceInterestRegistry::releaseConsumer(SourceConsumer::page($key));
        }
        RtStaleness::clear(ProtectedModeRuntime::RT_ITEM, [ProtectedModeRuntime::RT_ITEM]);
        foreach ($this->previousStanding as $property => $value) {
            (new ReflectionProperty(AccountStandingResolver::class, $property))->setValue(null, $value);
        }
        Hilos::$browser = $this->previousBrowser;
        parent::tearDown();
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param string $page Registered page
     */
    protected function subscribe(string $acceptKey = 'overview', string $page = AbstractHilosStatusPage::PAGE): void
    {
        Hilos::$sr->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO($acceptKey, $page));
        Hilos::$sr->bindPageAgent($acceptKey, $page, HilosAgentType::HILOS_INDEX, null);
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param string $page Page whose final subscription builder is invoked
     */
    protected function initial(string $acceptKey = 'overview', string $page = AbstractHilosStatusPage::PAGE): void
    {
        $this->subscribe($acceptKey, $page);
        $factory = new HilosPageFactory(new StatusPageIndexAgent(), StatusPageHilos::class);
        $factory->getPage($page)->onSubscribe($acceptKey, new PageRouteParams([]));
    }

    protected function flush(): void
    {
        self::assertSame([], $this->browser->flushToSignalRouter());
    }
}

abstract class StatusPageHilos extends StatusProjectionHilos
{
    public const array PAGES = [StatusPage::PAGE => StatusPage::class, StatusComponentsPage::PAGE => StatusComponentsPage::class,
        StatusSubscribersPage::PAGE => StatusSubscribersPage::class, StatusWorksPage::PAGE => StatusWorksPage::class];
    public const array PAGE_TABLES = [StatusComponentsPage::PAGE => [HilosStatusComponentsTable::TABLE => []]];
}

final class StatusPage extends AbstractHilosStatusPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
}

final class StatusComponentsPage extends AbstractHilosStatusComponentsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
}

final class StatusSubscribersPage extends AbstractHilosStatusSubscribersPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
}

final class StatusWorksPage extends AbstractHilosStatusWorksPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
}

final class StatusPageIndexAgent extends AbstractHilosIndexAgent
{
}

final class StatusPageBrowser extends BrowserContext
{
    public bool $admin = true;
    public bool $viewer = false;
    public ?int $userId = 1;
    public ?Closure $duringIdentity = null;

    /**
     * @param string $acceptKey Judged connection
     * @return ConnectionIdentity Current fixture identity
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        $callback = $this->duringIdentity;
        $this->duringIdentity = null;
        $callback?->__invoke();
        return ConnectionIdentity::resolved($this->userId);
    }

    /**
     * @param int $userId Judged account
     * @return bool Current privilege verdict
     */
    public function isAdmin(int $userId): bool
    {
        return $this->admin;
    }

    /**
     * @param string $pageClass Page class
     * @param string $acceptKey Judged connection
     * @return bool Current view-mode verdict
     */
    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }
}

final class StatusPageWorker extends WorkerManager
{
    /** @return SignalRouter Router bound to the fixture topology */
    protected function createSignalRouter(): SignalRouter
    {
        return new StatusPageRouter();
    }

    /** @return AgentManager Real index agent without starting the daemon loop */
    protected function createAgentManager(): AgentManager
    {
        $manager = new StatusPageAgentManager();
        $agent = new StatusPageIndexAgent();
        $manager->addAgent($agent->getId(), $agent);
        return $manager;
    }

    /**
     * @param AgentInterface $agent Serving index agent
     * @return PageSignalRouter Production builder and guards
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        assert($agent instanceof PageAgentInterface);
        return new PageSignalRouter(new HilosPageFactory($agent, StatusPageHilos::class), new ActionRouteConfig());
    }
}

final class StatusPageAgentManager extends AgentManager
{
    /**
     * @param string $agentType Requested type
     * @param ?string $agentIndex Requested index
     * @return AgentInterface Never returns; only the registered index agent is available
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        throw new AgentCreationFailedException($agentType, $agentIndex);
    }
}

final class StatusPageRouter extends SignalRouter
{
    /** @return class-string<Hilos> Current test facade */
    protected function hilosClass(): string
    {
        return Hilos::appClass();
    }
}

final class StatusPageTables extends TableContext
{
    /** Registers the production Status table. */
    public function configure(): void
    {
        $this->register(HilosStatusComponentsTable::TABLE, new HilosStatusComponentsTable());
    }
}
