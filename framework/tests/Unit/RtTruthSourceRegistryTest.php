<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for runtime truth-source ownership checks.
 */
final class RtTruthSourceRegistryTest extends TestCase
{
    private const string COLLECTION = 'unit_rt_truth_source';
    private const string AGENT_A = 'unit_agent:a';
    private const string AGENT_B = 'unit_agent:b';

    public function tearDown(): void
    {
        RtTruthSourceRegistry::setCurrentAgentId(null);
        RtTruthSourceRegistry::unregisterAgent(self::AGENT_A);
        RtTruthSourceRegistry::unregisterAgent(self::AGENT_B);

        parent::tearDown();
    }

    public function testCurrentAgentCanWriteOnlyRegisteredRtStateKey(): void
    {
        RtTruthSourceRegistry::register(self::COLLECTION, ['1'], self::AGENT_A);
        RtTruthSourceRegistry::register(self::COLLECTION, ['2'], self::AGENT_B);
        RtTruthSourceRegistry::setCurrentAgentId(self::AGENT_A);

        RtTruthSourceRegistry::checkCanWriteState(self::COLLECTION, '1');

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        RtTruthSourceRegistry::checkCanWriteState(self::COLLECTION, '2');
    }

    public function testKeyedRtSourceCannotPerformCollectionWideWrite(): void
    {
        RtTruthSourceRegistry::register(self::COLLECTION, ['1'], self::AGENT_A);
        RtTruthSourceRegistry::setCurrentAgentId(self::AGENT_A);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        RtTruthSourceRegistry::checkCanWrite(self::COLLECTION);
    }

    public function testCollectionWideRtSourceCanWriteAnyStateKey(): void
    {
        RtTruthSourceRegistry::register(self::COLLECTION, true, self::AGENT_A);
        RtTruthSourceRegistry::setCurrentAgentId(self::AGENT_A);

        RtTruthSourceRegistry::checkCanWrite(self::COLLECTION);
        RtTruthSourceRegistry::checkCanWriteState(self::COLLECTION, '1');
        RtTruthSourceRegistry::checkCanWriteState(self::COLLECTION, '2');

        $this->assertTrue(true);
    }

    public function testUnregisterCurrentAgentClearsRtContext(): void
    {
        RtTruthSourceRegistry::register(self::COLLECTION, ['1'], self::AGENT_A);
        RtTruthSourceRegistry::setCurrentAgentId(self::AGENT_A);

        RtTruthSourceRegistry::unregisterAgent(self::AGENT_A);

        $this->assertNull(RtTruthSourceRegistry::getCurrentAgentId());
    }
}
