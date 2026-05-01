<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for database truth-source ownership checks.
 */
final class TruthSourceRegistryTest extends TestCase
{
    private const string COLLECTION = 'unit_db_truth_source';
    private const string AGENT_A = 'unit_agent:a';
    private const string AGENT_B = 'unit_agent:b';

    public function tearDown(): void
    {
        TruthSourceRegistry::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::AGENT_A);
        TruthSourceRegistry::unregisterAgent(self::AGENT_B);

        parent::tearDown();
    }

    public function testCurrentAgentCanWriteOnlyRegisteredDbItemKey(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, ['1'], self::AGENT_A);
        TruthSourceRegistry::register(self::COLLECTION, ['2'], self::AGENT_B);
        TruthSourceRegistry::setCurrentAgentId(self::AGENT_A);

        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '1');

        $this->expectException(WriteNotAllowedException::class);
        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '2');
    }

    public function testKeyedDbSourceCannotPerformCollectionWideWrite(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, ['1'], self::AGENT_A);
        TruthSourceRegistry::setCurrentAgentId(self::AGENT_A);

        $this->expectException(WriteNotAllowedException::class);
        TruthSourceRegistry::checkCanWrite(self::COLLECTION);
    }

    public function testCollectionWideDbSourceCanWriteAnyItemKey(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, true, self::AGENT_A);
        TruthSourceRegistry::setCurrentAgentId(self::AGENT_A);

        TruthSourceRegistry::checkCanWrite(self::COLLECTION);
        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '1');
        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '2');

        $this->assertTrue(true);
    }

    public function testCreateSourceCanCreateButNotWrite(): void
    {
        TruthSourceRegistry::registerCreate(self::COLLECTION, self::AGENT_A);
        TruthSourceRegistry::setCurrentAgentId(self::AGENT_A);

        TruthSourceRegistry::checkCanCreate(self::COLLECTION);

        $this->expectException(WriteNotAllowedException::class);
        TruthSourceRegistry::checkCanWrite(self::COLLECTION);
    }

    public function testCurrentAgentCannotUseOtherAgentCreateSource(): void
    {
        TruthSourceRegistry::registerCreate(self::COLLECTION, self::AGENT_A);
        TruthSourceRegistry::setCurrentAgentId(self::AGENT_B);

        $this->expectException(CreateNotAllowedException::class);
        TruthSourceRegistry::checkCanCreate(self::COLLECTION);
    }

    public function testUnregisterCurrentAgentClearsDbContext(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, ['1'], self::AGENT_A);
        TruthSourceRegistry::setCurrentAgentId(self::AGENT_A);

        TruthSourceRegistry::unregisterAgent(self::AGENT_A);

        $this->assertNull(TruthSourceRegistry::getCurrentAgentId());
    }
}
