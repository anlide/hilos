<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Tests\Unit;

use Demo\BinanceBtcTracker\Hilos;
use Demo\BinanceBtcTracker\Tests\Fixtures\Status\StatusHilos;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\TopologyAgentFactory;
use PHPUnit\Framework\TestCase;

/** The fault binding changes only the e2e catalog and uses an existing isolated process. */
final class StatusFaultFixtureTest extends TestCase
{
    public function testProductionCatalogAndTopologyRemainIntact(): void
    {
        $production = iterator_to_array(Hilos::statusComponentsOf(Hilos::class));
        $fixture = iterator_to_array(Hilos::statusComponentsOf(StatusHilos::class));

        $this->assertSame(['service'], array_keys($production));
        $this->assertSame(HilosAgentType::HILOS_INDEX, $production['service']->agentType);
        $this->assertSame(['settings'], array_keys($fixture));
        $this->assertSame(HilosAgentType::HILOS_SETTINGS_LIBRARY, $fixture['settings']->agentType);
        $this->assertSame(Hilos::AGENTS, StatusHilos::AGENTS);
        $this->assertSame(Hilos::PAGES, StatusHilos::PAGES);
        $this->assertSame(Hilos::TABLES, StatusHilos::TABLES);
        $this->assertTrue(TopologyAgentFactory::createDaemon(Hilos::class, $fixture['settings']->agentType, null)->requiresMonopolisticProcess());
    }
}
