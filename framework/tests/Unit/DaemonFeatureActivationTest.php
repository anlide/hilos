<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\DaemonCollectorAgentDaemon;
use Hilos\Core\Agent\Daemon\DaemonNodeAgentDaemon;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\Core\Agent\Hilos\AbstractHilosDaemonAgent;
use Hilos\Core\Feature\Definition\DaemonFeature;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos as HilosFacade;
use Hilos\Pages\Daemon\AbstractHilosDaemonAgentsPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonCronPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonEnvMismatchPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonEnvOrphansPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonEnvPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonHttpServerPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonPlacementPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonNodeOverviewPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonWebsocketsPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonWorkersPage;
use Hilos\Tables\Daemon\HilosDaemonAgentsTable;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tables\Daemon\HilosDaemonEnvDivergedTable;
use Hilos\Tables\Daemon\HilosDaemonEnvKeysTable;
use Hilos\Tables\Daemon\HilosDaemonEnvOrphansTable;
use Hilos\Tables\Daemon\HilosDaemonEnvSourcesDifferTable;
use Hilos\Tables\Daemon\HilosDaemonHttpRoutesTable;
use Hilos\Tables\Daemon\HilosDaemonPlacementTable;
use Hilos\Tables\Daemon\HilosDaemonWorkersTable;
use PHPUnit\Framework\TestCase;

/** Pins the real Daemon feature definition and its startup refusal contract. */
final class DaemonFeatureActivationTest extends TestCase
{
    public function testDefinitionRequiresExactlyTenPagesAndThreeAgentsWithoutLogs(): void
    {
        $definition = (new FeatureRegistry())->definition(HilosFeature::DAEMON);

        self::assertInstanceOf(DaemonFeature::class, $definition);
        self::assertSame([
            AbstractHilosDaemonPage::class,
            AbstractHilosDaemonPlacementPage::class,
            AbstractHilosDaemonNodeOverviewPage::class,
            AbstractHilosDaemonWorkersPage::class,
            AbstractHilosDaemonAgentsPage::class,
            AbstractHilosDaemonCronPage::class,
            AbstractHilosDaemonWebsocketsPage::class,
            AbstractHilosDaemonHttpServerPage::class,
            AbstractHilosDaemonEnvPage::class,
            AbstractHilosDaemonEnvOrphansPage::class,
            AbstractHilosDaemonEnvMismatchPage::class,
        ], $definition->requirements()->requiredPages);
        self::assertSame([
            HilosAgentType::HILOS_DAEMON,
            HilosAgentType::HILOS_DAEMON_NODE,
            HilosAgentType::HILOS_DAEMON_COLLECTOR,
        ], $definition->requirements()->requiredAgents);
        self::assertSame([
            HilosDaemonCronTable::class,
            HilosDaemonWorkersTable::class,
            HilosDaemonEnvKeysTable::class,
            HilosDaemonEnvOrphansTable::class,
            HilosDaemonAgentsTable::class,
            HilosDaemonEnvDivergedTable::class,
            HilosDaemonEnvSourcesDifferTable::class,
            HilosDaemonHttpRoutesTable::class,
            HilosDaemonPlacementTable::class,
        ], $definition->requirements()->requiredTables);
        self::assertSame([
            AbstractHilosDaemonCronPage::class => HilosDaemonCronTable::class,
            AbstractHilosDaemonWorkersPage::class => HilosDaemonWorkersTable::class,
            AbstractHilosDaemonEnvPage::class => HilosDaemonEnvKeysTable::class,
            AbstractHilosDaemonEnvOrphansPage::class => HilosDaemonEnvOrphansTable::class,
            AbstractHilosDaemonAgentsPage::class => HilosDaemonAgentsTable::class,
            AbstractHilosDaemonEnvMismatchPage::class => [
                HilosDaemonEnvDivergedTable::class,
                HilosDaemonEnvSourcesDifferTable::class,
            ],
            AbstractHilosDaemonHttpServerPage::class => HilosDaemonHttpRoutesTable::class,
            AbstractHilosDaemonPlacementPage::class => HilosDaemonPlacementTable::class,
        ], $definition->requirements()->requiredPageTables);
        self::assertSame([], $definition->requirements()->requires);
    }

    public function testCompleteDaemonFeatureStartsWithoutLogs(): void
    {
        DaemonFeatureCompleteHilos::validateFeatureActivation();

        $this->addToAssertionCount(1);
    }

    public function testMissingPageRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::DAEMON is declared but no page in PAGES extends '
            . AbstractHilosDaemonWebsocketsPage::class);

        DaemonFeatureMissingPageHilos::validateFeatureActivation();
    }

    public function testMissingNodeOverviewRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::DAEMON is declared but no page in PAGES extends '
            . AbstractHilosDaemonNodeOverviewPage::class);

        DaemonFeatureMissingNodePageHilos::validateFeatureActivation();
    }

    public function testMissingCronTableRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::DAEMON is declared but no table in TABLES extends '
            . HilosDaemonCronTable::class);

        DaemonFeatureMissingCronTableHilos::validateFeatureActivation();
    }

    public function testMissingCronPageBindingRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::DAEMON is declared but PAGE_TABLES binds no page extending '
            . AbstractHilosDaemonCronPage::class . ' to ' . HilosDaemonCronTable::class);

        DaemonFeatureMissingCronBindingHilos::validateFeatureActivation();
    }

    public function testMissingWorkersTableRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::DAEMON is declared but no table in TABLES extends '
            . HilosDaemonWorkersTable::class);

        DaemonFeatureMissingWorkersTableHilos::validateFeatureActivation();
    }

    public function testMissingOrphansTableRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::DAEMON is declared but no table in TABLES extends '
            . HilosDaemonEnvOrphansTable::class);

        DaemonFeatureMissingOrphansTableHilos::validateFeatureActivation();
    }

    public function testMissingEnvironmentKeysBindingRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::DAEMON is declared but PAGE_TABLES binds no page extending '
            . AbstractHilosDaemonEnvPage::class . ' to ' . HilosDaemonEnvKeysTable::class);

        DaemonFeatureMissingEnvKeysBindingHilos::validateFeatureActivation();
    }

    public function testMissingWorkersPageBindingRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::DAEMON is declared but PAGE_TABLES binds no page extending '
            . AbstractHilosDaemonWorkersPage::class . ' to ' . HilosDaemonWorkersTable::class);

        DaemonFeatureMissingWorkersBindingHilos::validateFeatureActivation();
    }

    public function testMissingAgentsTableRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::DAEMON is declared but no table in TABLES extends '
            . HilosDaemonAgentsTable::class);

        DaemonFeatureMissingAgentsTableHilos::validateFeatureActivation();
    }

    public function testMissingAgentsPageBindingRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::DAEMON is declared but PAGE_TABLES binds no page extending '
            . AbstractHilosDaemonAgentsPage::class . ' to ' . HilosDaemonAgentsTable::class);

        DaemonFeatureMissingAgentsBindingHilos::validateFeatureActivation();
    }

    public function testMissingSourcesDifferTableRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::DAEMON is declared but no table in TABLES extends '
            . HilosDaemonEnvSourcesDifferTable::class);

        DaemonFeatureMissingSourcesDifferTableHilos::validateFeatureActivation();
    }

    public function testMissingMismatchPageBindingRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::DAEMON is declared but PAGE_TABLES binds no page extending '
            . AbstractHilosDaemonEnvMismatchPage::class . ' to ' . HilosDaemonEnvSourcesDifferTable::class);

        DaemonFeatureMissingMismatchBindingHilos::validateFeatureActivation();
    }

    public function testMissingHttpRoutesTableRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::DAEMON is declared but no table in TABLES extends '
            . HilosDaemonHttpRoutesTable::class);

        DaemonFeatureMissingHttpRoutesTableHilos::validateFeatureActivation();
    }

    public function testMissingHttpServerPageBindingRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::DAEMON is declared but PAGE_TABLES binds no page extending '
            . AbstractHilosDaemonHttpServerPage::class . ' to ' . HilosDaemonHttpRoutesTable::class);

        DaemonFeatureMissingHttpServerBindingHilos::validateFeatureActivation();
    }

    public function testMissingPlacementTableRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::DAEMON is declared but no table in TABLES extends '
            . HilosDaemonPlacementTable::class);

        DaemonFeatureMissingPlacementTableHilos::validateFeatureActivation();
    }

    public function testMissingPlacementPageBindingRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::DAEMON is declared but PAGE_TABLES binds no page extending '
            . AbstractHilosDaemonPlacementPage::class . ' to ' . HilosDaemonPlacementTable::class);

        DaemonFeatureMissingPlacementBindingHilos::validateFeatureActivation();
    }

    public function testMissingNewEnvironmentPageRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::DAEMON is declared but no page in PAGES extends '
            . AbstractHilosDaemonEnvMismatchPage::class);

        DaemonFeatureMissingEnvPageHilos::validateFeatureActivation();
    }

    public function testMissingAgentRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::DAEMON is declared but agent '
            . HilosAgentType::HILOS_DAEMON_COLLECTOR . ' is not registered in AGENTS');

        DaemonFeatureMissingAgentHilos::validateFeatureActivation();
    }

    public function testMissingDaemonProxyRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::DAEMON is declared but AGENTS['
            . HilosAgentType::HILOS_DAEMON_NODE . '] does not declare both a worker and a daemon class');

        DaemonFeatureMissingProxyHilos::validateFeatureActivation();
    }

    public function testRegistrationWithoutFeatureRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('AGENTS registers ' . HilosAgentType::HILOS_DAEMON_NODE
            . ' but HilosFeature::DAEMON is not declared in FEATURES');

        DaemonFeatureUndeclaredHilos::validateFeatureActivation();
    }
}

/** Complete, otherwise empty application declaration for the real feature. */
class DaemonFeatureCompleteHilos extends HilosFacade
{
    protected const array FEATURES = [HilosFeature::DAEMON];

    public const array PAGES = [
        DaemonFeaturePage::PAGE => DaemonFeaturePage::class,
        DaemonFeaturePlacementPage::PAGE => DaemonFeaturePlacementPage::class,
        DaemonFeatureNodePage::PAGE => DaemonFeatureNodePage::class,
        DaemonFeatureWorkersPage::PAGE => DaemonFeatureWorkersPage::class,
        DaemonFeatureAgentsPage::PAGE => DaemonFeatureAgentsPage::class,
        DaemonFeatureCronPage::PAGE => DaemonFeatureCronPage::class,
        DaemonFeatureWebsocketsPage::PAGE => DaemonFeatureWebsocketsPage::class,
        DaemonFeatureHttpServerPage::PAGE => DaemonFeatureHttpServerPage::class,
        DaemonFeatureEnvPage::PAGE => DaemonFeatureEnvPage::class,
        DaemonFeatureEnvOrphansPage::PAGE => DaemonFeatureEnvOrphansPage::class,
        DaemonFeatureEnvMismatchPage::PAGE => DaemonFeatureEnvMismatchPage::class,
    ];

    public const array AGENTS = [
        HilosAgentType::HILOS_DAEMON => [
            AgentRegistryKey::WORKER => DaemonFeatureTestPageAgent::class,
            AgentRegistryKey::DAEMON => DaemonFeatureTestPageAgentDaemon::class,
        ],
        HilosAgentType::HILOS_DAEMON_NODE => [
            AgentRegistryKey::WORKER => DaemonNodeAgent::class,
            AgentRegistryKey::DAEMON => DaemonNodeAgentDaemon::class,
        ],
        HilosAgentType::HILOS_DAEMON_COLLECTOR => [
            AgentRegistryKey::WORKER => DaemonCollectorAgent::class,
            AgentRegistryKey::DAEMON => DaemonCollectorAgentDaemon::class,
        ],
    ];

    public const array TABLES = [
        HilosDaemonCronTable::TABLE => HilosDaemonCronTable::class,
        HilosDaemonWorkersTable::TABLE => HilosDaemonWorkersTable::class,
        HilosDaemonEnvKeysTable::TABLE => HilosDaemonEnvKeysTable::class,
        HilosDaemonEnvOrphansTable::TABLE => HilosDaemonEnvOrphansTable::class,
        HilosDaemonAgentsTable::TABLE => HilosDaemonAgentsTable::class,
        HilosDaemonEnvDivergedTable::TABLE => HilosDaemonEnvDivergedTable::class,
        HilosDaemonEnvSourcesDifferTable::TABLE => HilosDaemonEnvSourcesDifferTable::class,
        HilosDaemonHttpRoutesTable::TABLE => HilosDaemonHttpRoutesTable::class,
        HilosDaemonPlacementTable::TABLE => HilosDaemonPlacementTable::class,
    ];

    public const array PAGE_TABLES = [
        DaemonFeatureCronPage::PAGE => [HilosDaemonCronTable::TABLE => []],
        DaemonFeatureWorkersPage::PAGE => [HilosDaemonWorkersTable::TABLE => []],
        DaemonFeatureEnvPage::PAGE => [
            HilosDaemonEnvKeysTable::TABLE => [],
        ],
        DaemonFeatureEnvOrphansPage::PAGE => [
            HilosDaemonEnvOrphansTable::TABLE => [],
        ],
        DaemonFeatureAgentsPage::PAGE => [HilosDaemonAgentsTable::TABLE => []],
        DaemonFeatureEnvMismatchPage::PAGE => [
            HilosDaemonEnvDivergedTable::TABLE => [],
            HilosDaemonEnvSourcesDifferTable::TABLE => [],
        ],
        DaemonFeatureHttpServerPage::PAGE => [HilosDaemonHttpRoutesTable::TABLE => []],
        DaemonFeaturePlacementPage::PAGE => [HilosDaemonPlacementTable::TABLE => []],
    ];

    /** @return HilosDbContext Unused database context required by the facade contract */
    protected static function createDb(): HilosDbContext
    {
        return new DaemonFeatureTestDbContext();
    }
}

final class DaemonFeatureTestDbContext extends HilosDbContext
{
    /** No DB collections are needed for this activation test. */
    public function configure(): void
    {
    }
}

final class DaemonFeatureTestPageAgent extends AbstractHilosDaemonAgent
{
}

final class DaemonFeatureTestPageAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_DAEMON;

    /** @return bool False: the page agent shares a regular worker */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }
}

final class DaemonFeaturePage extends AbstractHilosDaemonPage
{
}

final class DaemonFeaturePlacementPage extends AbstractHilosDaemonPlacementPage
{
}

final class DaemonFeatureNodePage extends AbstractHilosDaemonNodeOverviewPage
{
}

final class DaemonFeatureWorkersPage extends AbstractHilosDaemonWorkersPage
{
}

final class DaemonFeatureAgentsPage extends AbstractHilosDaemonAgentsPage
{
}

final class DaemonFeatureCronPage extends AbstractHilosDaemonCronPage
{
}

final class DaemonFeatureWebsocketsPage extends AbstractHilosDaemonWebsocketsPage
{
}

final class DaemonFeatureHttpServerPage extends AbstractHilosDaemonHttpServerPage
{
}

final class DaemonFeatureEnvPage extends AbstractHilosDaemonEnvPage
{
}

final class DaemonFeatureEnvOrphansPage extends AbstractHilosDaemonEnvOrphansPage
{
}

final class DaemonFeatureEnvMismatchPage extends AbstractHilosDaemonEnvMismatchPage
{
}

final class DaemonFeatureMissingPageHilos extends DaemonFeatureCompleteHilos
{
    public const array PAGES = [
        DaemonFeaturePage::PAGE => DaemonFeaturePage::class,
        DaemonFeaturePlacementPage::PAGE => DaemonFeaturePlacementPage::class,
        DaemonFeatureNodePage::PAGE => DaemonFeatureNodePage::class,
        DaemonFeatureWorkersPage::PAGE => DaemonFeatureWorkersPage::class,
        DaemonFeatureAgentsPage::PAGE => DaemonFeatureAgentsPage::class,
        DaemonFeatureCronPage::PAGE => DaemonFeatureCronPage::class,
        DaemonFeatureHttpServerPage::PAGE => DaemonFeatureHttpServerPage::class,
        DaemonFeatureEnvPage::PAGE => DaemonFeatureEnvPage::class,
        DaemonFeatureEnvMismatchPage::PAGE => DaemonFeatureEnvMismatchPage::class,
    ];
}

final class DaemonFeatureMissingCronTableHilos extends DaemonFeatureCompleteHilos
{
    public const array TABLES = [
        HilosDaemonWorkersTable::TABLE => HilosDaemonWorkersTable::class,
        HilosDaemonAgentsTable::TABLE => HilosDaemonAgentsTable::class,
    ];
}

final class DaemonFeatureMissingCronBindingHilos extends DaemonFeatureCompleteHilos
{
    public const array PAGE_TABLES = [
        DaemonFeatureWorkersPage::PAGE => [HilosDaemonWorkersTable::TABLE => []],
        DaemonFeatureAgentsPage::PAGE => [HilosDaemonAgentsTable::TABLE => []],
    ];
}

final class DaemonFeatureMissingWorkersTableHilos extends DaemonFeatureCompleteHilos
{
    public const array TABLES = [
        HilosDaemonCronTable::TABLE => HilosDaemonCronTable::class,
        HilosDaemonAgentsTable::TABLE => HilosDaemonAgentsTable::class,
    ];
}

final class DaemonFeatureMissingWorkersBindingHilos extends DaemonFeatureCompleteHilos
{
    public const array PAGE_TABLES = [
        DaemonFeatureCronPage::PAGE => [HilosDaemonCronTable::TABLE => []],
        DaemonFeatureAgentsPage::PAGE => [HilosDaemonAgentsTable::TABLE => []],
    ];
}

final class DaemonFeatureMissingOrphansTableHilos extends DaemonFeatureCompleteHilos
{
    public const array TABLES = [
        HilosDaemonCronTable::TABLE => HilosDaemonCronTable::class,
        HilosDaemonWorkersTable::TABLE => HilosDaemonWorkersTable::class,
        HilosDaemonEnvKeysTable::TABLE => HilosDaemonEnvKeysTable::class,
        HilosDaemonAgentsTable::TABLE => HilosDaemonAgentsTable::class,
    ];
}

final class DaemonFeatureMissingEnvKeysBindingHilos extends DaemonFeatureCompleteHilos
{
    public const array PAGE_TABLES = [
        DaemonFeatureCronPage::PAGE => [HilosDaemonCronTable::TABLE => []],
        DaemonFeatureWorkersPage::PAGE => [HilosDaemonWorkersTable::TABLE => []],
        DaemonFeatureEnvPage::PAGE => [HilosDaemonEnvOrphansTable::TABLE => []],
        DaemonFeatureAgentsPage::PAGE => [HilosDaemonAgentsTable::TABLE => []],
    ];
}

final class DaemonFeatureMissingAgentsTableHilos extends DaemonFeatureCompleteHilos
{
    public const array TABLES = [
        HilosDaemonCronTable::TABLE => HilosDaemonCronTable::class,
        HilosDaemonWorkersTable::TABLE => HilosDaemonWorkersTable::class,
    ];
}

final class DaemonFeatureMissingAgentsBindingHilos extends DaemonFeatureCompleteHilos
{
    public const array PAGE_TABLES = [
        DaemonFeatureCronPage::PAGE => [HilosDaemonCronTable::TABLE => []],
        DaemonFeatureWorkersPage::PAGE => [HilosDaemonWorkersTable::TABLE => []],
    ];
}

final class DaemonFeatureMissingSourcesDifferTableHilos extends DaemonFeatureCompleteHilos
{
    public const array TABLES = [
        HilosDaemonCronTable::TABLE => HilosDaemonCronTable::class,
        HilosDaemonWorkersTable::TABLE => HilosDaemonWorkersTable::class,
        HilosDaemonEnvKeysTable::TABLE => HilosDaemonEnvKeysTable::class,
        HilosDaemonEnvOrphansTable::TABLE => HilosDaemonEnvOrphansTable::class,
        HilosDaemonAgentsTable::TABLE => HilosDaemonAgentsTable::class,
        HilosDaemonEnvDivergedTable::TABLE => HilosDaemonEnvDivergedTable::class,
        HilosDaemonHttpRoutesTable::TABLE => HilosDaemonHttpRoutesTable::class,
        HilosDaemonPlacementTable::TABLE => HilosDaemonPlacementTable::class,
    ];
}

final class DaemonFeatureMissingMismatchBindingHilos extends DaemonFeatureCompleteHilos
{
    public const array PAGE_TABLES = [
        DaemonFeatureCronPage::PAGE => [HilosDaemonCronTable::TABLE => []],
        DaemonFeatureWorkersPage::PAGE => [HilosDaemonWorkersTable::TABLE => []],
        DaemonFeatureEnvPage::PAGE => [
            HilosDaemonEnvKeysTable::TABLE => [],
            HilosDaemonEnvOrphansTable::TABLE => [],
        ],
        DaemonFeatureAgentsPage::PAGE => [HilosDaemonAgentsTable::TABLE => []],
        DaemonFeatureEnvMismatchPage::PAGE => [HilosDaemonEnvDivergedTable::TABLE => []],
        DaemonFeatureHttpServerPage::PAGE => [HilosDaemonHttpRoutesTable::TABLE => []],
        DaemonFeaturePlacementPage::PAGE => [HilosDaemonPlacementTable::TABLE => []],
    ];
}

final class DaemonFeatureMissingHttpRoutesTableHilos extends DaemonFeatureCompleteHilos
{
    public const array TABLES = [
        HilosDaemonCronTable::TABLE => HilosDaemonCronTable::class,
        HilosDaemonWorkersTable::TABLE => HilosDaemonWorkersTable::class,
        HilosDaemonEnvKeysTable::TABLE => HilosDaemonEnvKeysTable::class,
        HilosDaemonEnvOrphansTable::TABLE => HilosDaemonEnvOrphansTable::class,
        HilosDaemonAgentsTable::TABLE => HilosDaemonAgentsTable::class,
        HilosDaemonPlacementTable::TABLE => HilosDaemonPlacementTable::class,
    ];
}

final class DaemonFeatureMissingHttpServerBindingHilos extends DaemonFeatureCompleteHilos
{
    public const array PAGE_TABLES = [
        DaemonFeatureCronPage::PAGE => [HilosDaemonCronTable::TABLE => []],
        DaemonFeatureWorkersPage::PAGE => [HilosDaemonWorkersTable::TABLE => []],
        DaemonFeatureEnvPage::PAGE => [
            HilosDaemonEnvKeysTable::TABLE => [],
            HilosDaemonEnvOrphansTable::TABLE => [],
        ],
        DaemonFeatureAgentsPage::PAGE => [HilosDaemonAgentsTable::TABLE => []],
        DaemonFeaturePlacementPage::PAGE => [HilosDaemonPlacementTable::TABLE => []],
    ];
}

final class DaemonFeatureMissingPlacementTableHilos extends DaemonFeatureCompleteHilos
{
    public const array TABLES = [
        HilosDaemonCronTable::TABLE => HilosDaemonCronTable::class,
        HilosDaemonWorkersTable::TABLE => HilosDaemonWorkersTable::class,
        HilosDaemonEnvKeysTable::TABLE => HilosDaemonEnvKeysTable::class,
        HilosDaemonEnvOrphansTable::TABLE => HilosDaemonEnvOrphansTable::class,
        HilosDaemonAgentsTable::TABLE => HilosDaemonAgentsTable::class,
    ];
}

final class DaemonFeatureMissingPlacementBindingHilos extends DaemonFeatureCompleteHilos
{
    public const array PAGE_TABLES = [
        DaemonFeatureCronPage::PAGE => [HilosDaemonCronTable::TABLE => []],
        DaemonFeatureWorkersPage::PAGE => [HilosDaemonWorkersTable::TABLE => []],
        DaemonFeatureEnvPage::PAGE => [
            HilosDaemonEnvKeysTable::TABLE => [],
            HilosDaemonEnvOrphansTable::TABLE => [],
        ],
        DaemonFeatureAgentsPage::PAGE => [HilosDaemonAgentsTable::TABLE => []],
    ];
}

final class DaemonFeatureMissingEnvPageHilos extends DaemonFeatureCompleteHilos
{
    public const array PAGES = [
        DaemonFeaturePage::PAGE => DaemonFeaturePage::class,
        DaemonFeaturePlacementPage::PAGE => DaemonFeaturePlacementPage::class,
        DaemonFeatureNodePage::PAGE => DaemonFeatureNodePage::class,
        DaemonFeatureWorkersPage::PAGE => DaemonFeatureWorkersPage::class,
        DaemonFeatureAgentsPage::PAGE => DaemonFeatureAgentsPage::class,
        DaemonFeatureCronPage::PAGE => DaemonFeatureCronPage::class,
        DaemonFeatureWebsocketsPage::PAGE => DaemonFeatureWebsocketsPage::class,
        DaemonFeatureHttpServerPage::PAGE => DaemonFeatureHttpServerPage::class,
        DaemonFeatureEnvPage::PAGE => DaemonFeatureEnvPage::class,
    ];
}

final class DaemonFeatureMissingAgentHilos extends DaemonFeatureCompleteHilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_DAEMON => DaemonFeatureCompleteHilos::AGENTS[HilosAgentType::HILOS_DAEMON],
        HilosAgentType::HILOS_DAEMON_NODE => DaemonFeatureCompleteHilos::AGENTS[HilosAgentType::HILOS_DAEMON_NODE],
    ];
}

final class DaemonFeatureMissingProxyHilos extends DaemonFeatureCompleteHilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_DAEMON => DaemonFeatureCompleteHilos::AGENTS[HilosAgentType::HILOS_DAEMON],
        HilosAgentType::HILOS_DAEMON_NODE => [AgentRegistryKey::WORKER => DaemonNodeAgent::class],
        HilosAgentType::HILOS_DAEMON_COLLECTOR => DaemonFeatureCompleteHilos::AGENTS[HilosAgentType::HILOS_DAEMON_COLLECTOR],
    ];
}

final class DaemonFeatureUndeclaredHilos extends DaemonFeatureCompleteHilos
{
    protected const array FEATURES = [];
}

final class DaemonFeatureMissingNodePageHilos extends DaemonFeatureCompleteHilos
{
    public const array PAGES = [
        DaemonFeaturePage::PAGE => DaemonFeaturePage::class,
        DaemonFeaturePlacementPage::PAGE => DaemonFeaturePlacementPage::class,
        DaemonFeatureWorkersPage::PAGE => DaemonFeatureWorkersPage::class,
        DaemonFeatureAgentsPage::PAGE => DaemonFeatureAgentsPage::class,
        DaemonFeatureCronPage::PAGE => DaemonFeatureCronPage::class,
        DaemonFeatureWebsocketsPage::PAGE => DaemonFeatureWebsocketsPage::class,
        DaemonFeatureHttpServerPage::PAGE => DaemonFeatureHttpServerPage::class,
        DaemonFeatureEnvPage::PAGE => DaemonFeatureEnvPage::class,
        DaemonFeatureEnvMismatchPage::PAGE => DaemonFeatureEnvMismatchPage::class,
    ];
}
