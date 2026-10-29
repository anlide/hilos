<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Fixtures;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Actions\Item\ProtectedModeRuntimeActions;
use Hilos\Runtime\View\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Users\AccountStandingResolver;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Pages\Maintenance\AbstractHilosMaintenanceNodesPage;
use Hilos\Tables\ProtectedMode\HilosMaintenanceNodesTable;
use PHPUnit\Framework\TestCase;

/** Installs only the process-local sources that a Nodes page reads. */
abstract class MaintenanceNodesTestCase extends TestCase
{
    protected StateProtectedModeRuntime $state;
    private array $previousGlobals;
    private array $previousVariables = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousGlobals = [Hilos::$env, Hilos::$cluster, Hilos::$rt, Hilos::$sr, Hilos::$db, Hilos::$table, Hilos::$browser];
        foreach (['CLUSTER_ENABLED' => 'true', 'CLUSTER_NODE_ID' => 'node-a', 'CLUSTER_NODE_ROLE' => 'master',
            'SOCKET_READ_BUFFER_SIZE' => '65536', 'CLUSTER_LINK_KEEPALIVE_INTERVAL_MS' => '60000',
            'CLUSTER_LINK_TIMEOUT_MS' => '60000'] as $key => $value) {
            $this->previousVariables[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
        Hilos::$sr = new MaintenanceNodesTestRouter();
        Hilos::$db = null;
        AccountStandingResolver::forgetAll();
        $this->state = StateProtectedModeRuntime::create();
        Hilos::$rt = new MaintenanceNodesTestRuntime($this->state);
        Hilos::$rt->configure();
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        Hilos::$table = new MaintenanceNodesTestTables();
        Hilos::$table->configure();
        MaintenanceNodesTestHilos::initBrowser(new MaintenanceNodesTestBrowser());
    }

    protected function tearDown(): void
    {
        foreach (SourceInterestRegistry::consumersOf('rt', StateProtectedModeRuntime::RT_ITEM) as $consumer) {
            SourceInterestRegistry::releaseConsumer($consumer);
        }
        SourceInterestRegistry::readsWhatItMounts();
        AccountStandingResolver::forgetAll();
        RtTruthSourceRegistry::unregisterDaemon(StateProtectedModeRuntime::RT_ITEM);
        [Hilos::$env, Hilos::$cluster, Hilos::$rt, Hilos::$sr, Hilos::$db, Hilos::$table, $browser] = $this->previousGlobals;
        Hilos::initBrowser($browser);
        foreach ($this->previousVariables as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        parent::tearDown();
    }

    protected static function node(string $id, ?string $phase = 'inactive', ?bool $quiesced = null): array
    {
        return ['nodeId' => $id, 'phase' => $phase, 'quiesced' => $quiesced,
            'isLeader' => false, 'isInitiator' => false, 'reportState' => 'current'];
    }

    protected function publish(?array $nodes): void
    {
        Hilos::$rt->hilosProtectedModeRuntime->actions->updateMaintenanceNodes($nodes);
    }
}

final class MaintenanceNodesTestRuntime extends RtContext
{
    public function __construct(private readonly StateProtectedModeRuntime $state)
    {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->_stateItems[StateProtectedModeRuntime::RT_ITEM] = $this->state;
        $this->setRepresentItem(StateProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::class, ProtectedModeRuntimeActions::class);
    }
}

final class MaintenanceNodesTestPage extends AbstractHilosMaintenanceNodesPage
{
}

abstract class MaintenanceNodesTestHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_MAINTENANCE => MaintenanceNodesTestOverview::class,
        MaintenanceNodesTestPage::PAGE => MaintenanceNodesTestPage::class,
    ];
    public const array TABLES = [HilosMaintenanceNodesTable::TABLE => HilosMaintenanceNodesTable::class];
    public const array PAGE_TABLES = [MaintenanceNodesTestPage::PAGE => [HilosMaintenanceNodesTable::TABLE => []]];

    protected static function createDb(): HilosDbContext
    {
        return new MaintenanceNodesTestDb();
    }
}

final class MaintenanceNodesTestDb extends HilosDbContext
{
    public function configure(): void
    {
    }
}

final class MaintenanceNodesTestTables extends TableContext
{
    public function configure(): void
    {
        $this->register(HilosMaintenanceNodesTable::TABLE, new HilosMaintenanceNodesTable());
    }
}

/** Identity and viewer doubles leave the real freeze and table admission paths intact. */
final class MaintenanceNodesTestBrowser extends IdentityTestBrowser
{
    public bool $viewer = false;
    public bool $administrator = true;

    public function __construct()
    {
        parent::__construct(1, true);
    }

    public function isAdmin(int $userId): bool
    {
        return $this->administrator;
    }

    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }
}

final class MaintenanceNodesTestOverview extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_MAINTENANCE;
}

final class MaintenanceNodesTestRouter extends SignalRouter
{
    protected function hilosClass(): string
    {
        return Hilos::appClass();
    }
}
