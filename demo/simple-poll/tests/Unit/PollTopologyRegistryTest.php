<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Tests\Unit;

use Demo\SimplePoll\Agents\Hilos\DemoHilosAgent;
use Demo\SimplePoll\Agents\PollAgent;
use Demo\SimplePoll\Browser\Table\UserDetailBrowserTable;
use Demo\SimplePoll\Constants\AgentType;
use Demo\SimplePoll\Constants\PageConstants;
use Demo\SimplePoll\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\SimplePoll\Core\Agent\Daemon\PollAgentDaemon;
use Demo\SimplePoll\Hilos;
use Demo\SimplePoll\Groups\Hilos\NotificationsGroup;
use Demo\SimplePoll\Pages\Hilos\DashboardPage;
use Demo\SimplePoll\Pages\Hilos\SettingsPage;
use Demo\SimplePoll\Pages\Hilos\Users\UserPage;
use Demo\SimplePoll\Pages\Hilos\Users\UsersPage;
use Demo\SimplePoll\Pages\MainPage;
use Demo\SimplePoll\Runtime\View\Context\PollRtContext;
use Demo\SimplePoll\Tables\HilosUser\HilosUsersTable;
use Demo\SimplePoll\Tables\PollTableContext;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\CLI\CliManager;
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

    /**
     * No poll page is served by the agent of one entity instance yet: this leaf gave the
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

    public function testPollAppOwnSurfaceStaysTransportOnly(): void
    {
        // The poll application's OWN surface stays transport-only: its main page
        // and worker push no server-driven data. The activated Hilos admin
        // features (settings, users) own their actions/signals/browser tables —
        // asserted separately below.
        //
        // The one frame the worker is addressed by is not its surface but the seam the
        // sessions moved behind (HIL-710): the library says what a session became, and this
        // agent is what turns that into a connection row and an identity on the wire.
        // The one group is not the application's own surface either: it is the framework's
        // notification channel, activated by the same feature that mounts the bell (HIL-721).
        $this->assertSame(
            [NotificationsGroup::GROUP => NotificationsGroup::class],
            Hilos::GROUPS,
        );
        $this->assertSame([], MainPage::ACTIONS);
        $this->assertSame([], MainPage::SIGNALS);
        $this->assertSame(
            [HilosSignalConstants::HILOS_SESSION_STATE => SessionStateSignalData::class],
            PollAgent::AGENT_SIGNALS,
        );
        $this->assertSame(
            [
                HilosSignalConstants::HILOS_SESSION_STATE => AgentType::POLL,
                // The other half of the seam, and the seven endings the users library hands
                // over: this demo has no sign-in, so nothing sends them - but the library it
                // registers is what would be addressed if anything did.
                HilosSignalConstants::HILOS_AUTH_SESSION_GRANT => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_LANDED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_RECOVERY_GRANTED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_PASSWORD_CHANGED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_ABANDONED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_WAIT_MOVED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_RECOVERY_WAIT_MOVED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_SESSION_REBIND => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            ],
            Hilos::getAgentSignalRoutes(),
        );
    }

    public function testSettingsAndUsersAdminFeaturesAreActivated(): void
    {
        // Both framework admin features are activated: settings (configure-only)
        // and hilos-users (the users list page + the user-detail page with its
        // rename action and a filtered detail browser table).
        $this->assertSame([
            PollTableContext::settings => HilosSettingsTable::class,
            PollTableContext::hilosUsers => HilosUsersTable::class,
        ], Hilos::TABLES);

        $this->assertSame(
            [UserDetailBrowserTable::TABLE => UserDetailBrowserTable::class],
            Hilos::BROWSER_TABLES,
        );

        $this->assertSame(
            [SettingsPage::PAGE, UsersPage::PAGE, UserPage::PAGE],
            array_keys(Hilos::PAGE_TABLES),
        );
        $this->assertSame([PollTableContext::hilosUsers => []], Hilos::PAGE_TABLES[UsersPage::PAGE]);

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
            PollRtContext::class,
        );

        $this->addToAssertionCount(1);
    }
}
