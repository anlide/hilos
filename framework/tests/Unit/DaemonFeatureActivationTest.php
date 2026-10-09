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
use Hilos\Pages\Daemon\AbstractHilosDaemonEnvPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonHttpServerPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonWebsocketsPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonWorkersPage;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tables\Daemon\HilosDaemonWorkersTable;
use PHPUnit\Framework\TestCase;

/** Pins the real Daemon feature definition and its startup refusal contract. */
final class DaemonFeatureActivationTest extends TestCase
{
    public function testDefinitionRequiresExactlyEightPagesAndThreeAgentsWithoutLogs(): void
    {
        $definition = (new FeatureRegistry())->definition(HilosFeature::DAEMON);

        self::assertInstanceOf(DaemonFeature::class, $definition);
        self::assertSame([
            AbstractHilosDaemonPage::class,
            AbstractHilosDaemonWorkersPage::class,
            AbstractHilosDaemonAgentsPage::class,
            AbstractHilosDaemonCronPage::class,
            AbstractHilosDaemonWebsocketsPage::class,
            AbstractHilosDaemonHttpServerPage::class,
            AbstractHilosDaemonEnvPage::class,
            AbstractHilosDaemonEnvMismatchPage::class,
        ], $definition->requirements()->requiredPages);
        self::assertSame([
            HilosAgentType::HILOS_DAEMON,
            HilosAgentType::HILOS_DAEMON_NODE,
            HilosAgentType::HILOS_DAEMON_COLLECTOR,
        ], $definition->requirements()->requiredAgents);
        self::assertSame([HilosDaemonCronTable::class, HilosDaemonWorkersTable::class], $definition->requirements()->requiredTables);
        self::assertSame([
            AbstractHilosDaemonCronPage::class => HilosDaemonCronTable::class,
            AbstractHilosDaemonWorkersPage::class => HilosDaemonWorkersTable::class,
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

    public function testMissingWorkersPageBindingRefusesStartup(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('HilosFeature::DAEMON is declared but PAGE_TABLES binds no page extending '
            . AbstractHilosDaemonWorkersPage::class . ' to ' . HilosDaemonWorkersTable::class);

        DaemonFeatureMissingWorkersBindingHilos::validateFeatureActivation();
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
        DaemonFeatureWorkersPage::PAGE => DaemonFeatureWorkersPage::class,
        DaemonFeatureAgentsPage::PAGE => DaemonFeatureAgentsPage::class,
        DaemonFeatureCronPage::PAGE => DaemonFeatureCronPage::class,
        DaemonFeatureWebsocketsPage::PAGE => DaemonFeatureWebsocketsPage::class,
        DaemonFeatureHttpServerPage::PAGE => DaemonFeatureHttpServerPage::class,
        DaemonFeatureEnvPage::PAGE => DaemonFeatureEnvPage::class,
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
    ];

    public const array PAGE_TABLES = [
        DaemonFeatureCronPage::PAGE => [HilosDaemonCronTable::TABLE => []],
        DaemonFeatureWorkersPage::PAGE => [HilosDaemonWorkersTable::TABLE => []],
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

final class DaemonFeatureEnvMismatchPage extends AbstractHilosDaemonEnvMismatchPage
{
}

final class DaemonFeatureMissingPageHilos extends DaemonFeatureCompleteHilos
{
    public const array PAGES = [
        DaemonFeaturePage::PAGE => DaemonFeaturePage::class,
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
    public const array TABLES = [HilosDaemonWorkersTable::TABLE => HilosDaemonWorkersTable::class];
}

final class DaemonFeatureMissingCronBindingHilos extends DaemonFeatureCompleteHilos
{
    public const array PAGE_TABLES = [DaemonFeatureWorkersPage::PAGE => [HilosDaemonWorkersTable::TABLE => []]];
}

final class DaemonFeatureMissingWorkersTableHilos extends DaemonFeatureCompleteHilos
{
    public const array TABLES = [HilosDaemonCronTable::TABLE => HilosDaemonCronTable::class];
}

final class DaemonFeatureMissingWorkersBindingHilos extends DaemonFeatureCompleteHilos
{
    public const array PAGE_TABLES = [DaemonFeatureCronPage::PAGE => [HilosDaemonCronTable::TABLE => []]];
}

final class DaemonFeatureMissingEnvPageHilos extends DaemonFeatureCompleteHilos
{
    public const array PAGES = [
        DaemonFeaturePage::PAGE => DaemonFeaturePage::class,
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
