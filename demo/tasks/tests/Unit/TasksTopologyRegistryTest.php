<?php

declare(strict_types=1);

namespace Demo\Tasks\Tests\Unit;

use Demo\Tasks\Agents\Hilos\DemoHilosAgent;
use Demo\Tasks\Agents\TasksAgent;
use Demo\Tasks\Browser\Table\UserDetailBrowserTable;
use Demo\Tasks\Constants\AgentType;
use Demo\Tasks\Constants\PageConstants;
use Demo\Tasks\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\TasksAgentDaemon;
use Demo\Tasks\Hilos;
use Demo\Tasks\Pages\Hilos\DashboardPage;
use Demo\Tasks\Pages\Hilos\SettingsPage;
use Demo\Tasks\Pages\Hilos\Users\UserPage;
use Demo\Tasks\Pages\Hilos\Users\UsersPage;
use Demo\Tasks\Pages\MainPage;
use Demo\Tasks\Runtime\View\Context\TasksRtContext;
use Demo\Tasks\Tables\HilosUser\HilosUsersTable;
use Demo\Tasks\Tables\TasksTableContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\CLI\CliManager;
use Hilos\Tables\Settings\HilosSettingsTable;
use PHPUnit\Framework\TestCase;

/**
 * Guards the project-level tasks topology registry.
 */
final class TasksTopologyRegistryTest extends TestCase
{
    public function testComputedPageRoutesCoverEveryRegisteredPage(): void
    {
        $this->assertSame(array_keys(Hilos::PAGES), array_keys(Hilos::getPageRoutes()));
    }

    /**
     * No tasks page is served by the agent of one entity instance yet: this leaf gave the
     * mechanism, and which pages move onto it belongs to the epic (HIL-627).
     */
    public function testNoPageIsServedByTheAgentOfOneInstance(): void
    {
        $this->assertSame([], Hilos::getPageAgentIndexRoutes());
    }

    public function testPageRegistryKeysMatchPageClassConstants(): void
    {
        foreach (Hilos::PAGES as $page => $pageClass) {
            $this->assertSame($page, $pageClass::PAGE);
        }
    }

    public function testAgentRegistryEntriesAreConcreteAndConsistent(): void
    {
        foreach (Hilos::AGENTS as $agentType => $registryEntry) {
            $workerClass = AgentRegistry::workerClass($registryEntry);
            $daemonClass = AgentRegistry::daemonClass($registryEntry);

            $this->assertIsString($workerClass);
            $this->assertIsString($daemonClass);
            $this->assertTrue(class_exists($workerClass), "{$workerClass} must be a concrete worker class");
            $this->assertTrue(class_exists($daemonClass), "{$daemonClass} must be a concrete daemon class");
            $this->assertTrue(is_subclass_of($daemonClass, AbstractAgentDaemon::class));
            $this->assertSame($agentType, $workerClass::AGENT_TYPE);
        }
    }

    public function testPageSubscriptionOwnersAreDeclaredByPageClasses(): void
    {
        $pageRoutes = Hilos::getPageRoutes();

        foreach (Hilos::PAGES as $page => $pageClass) {
            $this->assertSame($pageClass::SUBSCRIPTION_AGENT_TYPE, $pageRoutes[$page]);
            $this->assertNotSame('', $pageClass::SUBSCRIPTION_AGENT_TYPE, "{$page} must declare a subscription owner");
        }
    }

    public function testMainPageIsOwnedByTheTasksAgent(): void
    {
        $this->assertSame(MainPage::class, Hilos::PAGES[PageConstants::MAIN]);
        $this->assertSame(AgentType::TASKS, MainPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(TasksAgent::class, AgentRegistry::workerClass(Hilos::AGENTS[AgentType::TASKS]));
        $this->assertSame(TasksAgentDaemon::class, AgentRegistry::daemonClass(Hilos::AGENTS[AgentType::TASKS]));
        $this->assertFalse(AgentRegistry::requiresIndex(Hilos::AGENTS[AgentType::TASKS]));
    }

    public function testHilosDashboardIsOwnedByTheIndexAgent(): void
    {
        $this->assertSame(DashboardPage::class, Hilos::PAGES[DashboardPage::PAGE]);
        $this->assertSame(AgentType::HILOS_INDEX, DashboardPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(DemoHilosAgent::class, AgentRegistry::workerClass(Hilos::AGENTS[AgentType::HILOS_INDEX]));
        $this->assertSame(
            DemoHilosAgentDaemon::class,
            AgentRegistry::daemonClass(Hilos::AGENTS[AgentType::HILOS_INDEX]),
        );
        $this->assertFalse(AgentRegistry::requiresIndex(Hilos::AGENTS[AgentType::HILOS_INDEX]));
    }

    public function testTasksAppOwnSurfaceStaysTransportOnly(): void
    {
        // The tasks application's OWN surface stays transport-only: its main page
        // and worker push no server-driven data. The activated Hilos admin
        // features (settings, users) own their actions/signals/browser tables —
        // asserted separately below.
        $this->assertSame([], Hilos::GROUPS);
        $this->assertSame([], MainPage::ACTIONS);
        $this->assertSame([], MainPage::SIGNALS);
        $this->assertSame([], TasksAgent::AGENT_SIGNALS);
        $this->assertSame([], Hilos::getAgentSignalRoutes());
    }

    public function testSettingsAndUsersAdminFeaturesAreActivated(): void
    {
        // Both framework admin features are activated: settings (configure-only)
        // and hilos-users (the users list page + the user-detail page with its
        // rename action and a filtered detail browser table).
        $this->assertSame([
            TasksTableContext::settings => HilosSettingsTable::class,
            TasksTableContext::hilosUsers => HilosUsersTable::class,
        ], Hilos::TABLES);

        $this->assertSame(
            [UserDetailBrowserTable::TABLE => UserDetailBrowserTable::class],
            Hilos::BROWSER_TABLES,
        );

        $this->assertSame(
            [SettingsPage::PAGE, UsersPage::PAGE, UserPage::PAGE],
            array_keys(Hilos::PAGE_TABLES),
        );
        $this->assertSame([TasksTableContext::hilosUsers => []], Hilos::PAGE_TABLES[UsersPage::PAGE]);

        $this->assertSame(
            UserPage::PAGE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_USER_UPDATE],
        );
    }

    public function testProjectTopologyPassesStartupValidation(): void
    {
        // The first check init() runs, and the one every project boots under. It judges
        // this project's real registry, unlike the framework's TopologyValidatorTest, which
        // only runs it against invented fixture facades.
        Hilos::validateTopology();

        $this->addToAssertionCount(1);
    }

    public function testDeclaredFeaturesAreFullyActivated(): void
    {
        // The startup activation check this project boots under: every declared feature has
        // its pages, agents, tables, bindings and catalogs, and nothing framework-owned is
        // registered without the declaration that switches it on.
        Hilos::validateFeatureActivation();

        $this->addToAssertionCount(1);
    }

    public function testDeclaredFeaturesHaveWhatStartupCannotCheck(): void
    {
        // The other half of the activation check, the half no starting process can make: the SQL
        // tables a declared feature reads live in migrations applied as a separate step, and the
        // presence source behind the users list is a runtime collection, not a constant.
        Hilos::validateDeferredFeatureRequirements(
            __DIR__ . '/../../backend/Database/Migration/Schema',
            CliManager::class,
            TasksRtContext::class,
        );

        $this->addToAssertionCount(1);
    }
}
