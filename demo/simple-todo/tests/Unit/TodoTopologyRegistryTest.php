<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Tests\Unit;

use Demo\SimpleTodo\Agents\Hilos\DemoHilosAgent;
use Demo\SimpleTodo\Agents\TodoAgent;
use Demo\SimpleTodo\Browser\Table\UserDetailBrowserTable;
use Demo\SimpleTodo\Constants\AgentType;
use Demo\SimpleTodo\Constants\PageConstants;
use Demo\SimpleTodo\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\SimpleTodo\Core\Agent\Daemon\TodoAgentDaemon;
use Demo\SimpleTodo\Hilos;
use Demo\SimpleTodo\Pages\Hilos\DashboardPage;
use Demo\SimpleTodo\Pages\Hilos\SettingsPage;
use Demo\SimpleTodo\Pages\Hilos\Users\UserPage;
use Demo\SimpleTodo\Pages\Hilos\Users\UsersPage;
use Demo\SimpleTodo\Pages\MainPage;
use Demo\SimpleTodo\Runtime\View\Context\TodoRtContext;
use Demo\SimpleTodo\Tables\HilosUser\HilosUsersTable;
use Demo\SimpleTodo\Tables\TodoTableContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\CLI\CliManager;
use Hilos\Tables\Settings\HilosSettingsTable;
use PHPUnit\Framework\TestCase;

/**
 * Guards the project-level simple-todo topology registry.
 */
final class TodoTopologyRegistryTest extends TestCase
{
    public function testComputedPageRoutesCoverEveryRegisteredPage(): void
    {
        $this->assertSame(array_keys(Hilos::PAGES), array_keys(Hilos::getPageRoutes()));
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

    public function testMainPageIsOwnedByTheTodoAgent(): void
    {
        $this->assertSame(MainPage::class, Hilos::PAGES[PageConstants::MAIN]);
        $this->assertSame(AgentType::TODO, MainPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(TodoAgent::class, AgentRegistry::workerClass(Hilos::AGENTS[AgentType::TODO]));
        $this->assertSame(TodoAgentDaemon::class, AgentRegistry::daemonClass(Hilos::AGENTS[AgentType::TODO]));
        $this->assertFalse(AgentRegistry::requiresIndex(Hilos::AGENTS[AgentType::TODO]));
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

    public function testTodoAppOwnSurfaceStaysTransportOnly(): void
    {
        // The todo application's OWN surface stays transport-only: its main page
        // and worker push no server-driven data. The activated Hilos admin
        // features (settings, users) own their actions/signals/browser tables —
        // asserted separately below.
        $this->assertSame([], Hilos::GROUPS);
        $this->assertSame([], MainPage::ACTIONS);
        $this->assertSame([], MainPage::SIGNALS);
        $this->assertSame([], TodoAgent::AGENT_SIGNALS);
        $this->assertSame([], Hilos::getAgentSignalRoutes());
    }

    public function testSettingsAndUsersAdminFeaturesAreActivated(): void
    {
        // Both framework admin features are activated: settings (configure-only)
        // and hilos-users (the users list page + the user-detail page with its
        // rename action and a filtered detail browser table).
        $this->assertSame([
            TodoTableContext::settings => HilosSettingsTable::class,
            TodoTableContext::hilosUsers => HilosUsersTable::class,
        ], Hilos::TABLES);

        $this->assertSame(
            [UserDetailBrowserTable::TABLE => UserDetailBrowserTable::class],
            Hilos::BROWSER_TABLES,
        );

        $this->assertSame(
            [SettingsPage::PAGE, UsersPage::PAGE, UserPage::PAGE],
            array_keys(Hilos::PAGE_TABLES),
        );
        $this->assertSame([TodoTableContext::hilosUsers => []], Hilos::PAGE_TABLES[UsersPage::PAGE]);

        $this->assertSame(
            UserPage::PAGE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_USER_UPDATE],
        );
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
            TodoRtContext::class,
        );

        $this->addToAssertionCount(1);
    }
}
