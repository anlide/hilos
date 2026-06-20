<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Tests\Unit;

use Demo\SimplePoll\Agents\Hilos\DemoHilosAgent;
use Demo\SimplePoll\Agents\PollAgent;
use Demo\SimplePoll\Constants\AgentType;
use Demo\SimplePoll\Constants\PageConstants;
use Demo\SimplePoll\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\SimplePoll\Core\Agent\Daemon\PollAgentDaemon;
use Demo\SimplePoll\Hilos;
use Demo\SimplePoll\Pages\Hilos\DashboardPage;
use Demo\SimplePoll\Pages\Hilos\SettingsPage;
use Demo\SimplePoll\Pages\MainPage;
use Demo\SimplePoll\Tables\PollTableContext;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Tables\Settings\HilosSettingsTable;
use PHPUnit\Framework\TestCase;

/**
 * Guards the project-level simple-poll topology registry.
 */
final class PollTopologyRegistryTest extends TestCase
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

    public function testMainPageIsOwnedByThePollAgent(): void
    {
        $this->assertSame(MainPage::class, Hilos::PAGES[PageConstants::MAIN]);
        $this->assertSame(AgentType::POLL, MainPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(PollAgent::class, AgentRegistry::workerClass(Hilos::AGENTS[AgentType::POLL]));
        $this->assertSame(PollAgentDaemon::class, AgentRegistry::daemonClass(Hilos::AGENTS[AgentType::POLL]));
        $this->assertFalse(AgentRegistry::requiresIndex(Hilos::AGENTS[AgentType::POLL]));
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

    public function testPollAppSurfaceDeclaresNoActionsSignalsOrGroupsYet(): void
    {
        // The poll application itself is still transport-only: its own pages and
        // agent push no server-driven data. The one server-driven surface — the
        // activated framework settings admin feature — is asserted separately
        // below. These stay empty until the project opts into a feature needing
        // them; transport-only is a starting state, not a permanent contract.
        $this->assertSame([], Hilos::GROUPS);
        $this->assertSame([], Hilos::BROWSER_TABLES);
        $this->assertSame([], Hilos::getPageSignalRoutes());
        $this->assertSame([], Hilos::getAgentSignalRoutes());
        $this->assertSame([], MainPage::ACTIONS);
        $this->assertSame([], MainPage::SIGNALS);
        $this->assertSame([], PollAgent::AGENT_SIGNALS);
    }

    public function testSettingsIsTheOnlyActivatedAdminFeature(): void
    {
        // The framework settings feature is activated configure-only: the project
        // registers the framework table, binds it to its settings page, and
        // inherits the add/update/delete action routes from the framework page
        // base. Nothing else is wired — settings is the demo's single
        // server-driven feature.
        $this->assertSame([PollTableContext::settings => HilosSettingsTable::class], Hilos::TABLES);
        $this->assertSame([SettingsPage::PAGE => [PollTableContext::settings => []]], Hilos::PAGE_TABLES);
        $this->assertSame(
            array_fill_keys(array_keys(SettingsPage::ACTIONS), SettingsPage::PAGE),
            Hilos::getPageActionRoutes(),
        );
    }
}
