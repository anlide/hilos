<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Tests\Unit;

use Demo\SimplePoll\Agents\PollAgent;
use Demo\SimplePoll\Constants\AgentType;
use Demo\SimplePoll\Constants\PageConstants;
use Demo\SimplePoll\Core\Agent\Daemon\PollAgentDaemon;
use Demo\SimplePoll\Hilos;
use Demo\SimplePoll\Pages\MainPage;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
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
        $this->assertSame([PageConstants::MAIN => MainPage::class], Hilos::PAGES);
        $this->assertSame(AgentType::POLL, MainPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(PollAgent::class, AgentRegistry::workerClass(Hilos::AGENTS[AgentType::POLL]));
        $this->assertSame(PollAgentDaemon::class, AgentRegistry::daemonClass(Hilos::AGENTS[AgentType::POLL]));
        $this->assertFalse(AgentRegistry::requiresIndex(Hilos::AGENTS[AgentType::POLL]));
    }

    public function testTransportOnlyContractDeclaresNoActionsSignalsOrGroups(): void
    {
        $this->assertSame([], Hilos::GROUPS);
        $this->assertSame([], Hilos::TABLES);
        $this->assertSame([], Hilos::BROWSER_TABLES);
        $this->assertSame([], Hilos::PAGE_TABLES);
        $this->assertSame([], Hilos::getPageActionRoutes());
        $this->assertSame([], Hilos::getPageSignalRoutes());
        $this->assertSame([], Hilos::getAgentSignalRoutes());
        $this->assertSame([], MainPage::ACTIONS);
        $this->assertSame([], MainPage::SIGNALS);
        $this->assertSame([], PollAgent::AGENT_SIGNALS);
    }
}
