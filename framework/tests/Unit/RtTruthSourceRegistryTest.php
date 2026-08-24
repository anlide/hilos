<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\TruthSourceOperation;
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
        ExecutionContext::setCurrentAgentId(null);
        RtTruthSourceRegistry::unregisterAgent(self::AGENT_A);
        RtTruthSourceRegistry::unregisterAgent(self::AGENT_B);
        RtTruthSourceRegistry::unregisterDaemon(self::COLLECTION);

        parent::tearDown();
    }

    public function testDaemonSourceCanWriteWithoutCurrentAgent(): void
    {
        RtTruthSourceRegistry::registerDaemon(self::COLLECTION);
        ExecutionContext::setCurrentAgentId(null);

        RtTruthSourceRegistry::checkCanWrite(self::COLLECTION);
        RtTruthSourceRegistry::checkCanWriteState(self::COLLECTION, '1', TruthSourceOperation::Add);
        RtTruthSourceRegistry::checkCanWriteState(self::COLLECTION, '2', TruthSourceOperation::Update);

        $this->assertTrue(true);
    }

    public function testUnregisterDaemonRevokesAgentLessWrite(): void
    {
        RtTruthSourceRegistry::registerDaemon(self::COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(self::COLLECTION);
        ExecutionContext::setCurrentAgentId(null);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        RtTruthSourceRegistry::checkCanWriteState(self::COLLECTION, '1', TruthSourceOperation::Update);
    }

    public function testCurrentAgentCanWriteOnlyRegisteredRtStateKey(): void
    {
        RtTruthSourceRegistry::register(self::COLLECTION, ['1'], self::AGENT_A);
        RtTruthSourceRegistry::register(self::COLLECTION, ['2'], self::AGENT_B);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        RtTruthSourceRegistry::checkCanWriteState(self::COLLECTION, '1', TruthSourceOperation::Update);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        RtTruthSourceRegistry::checkCanWriteState(self::COLLECTION, '2', TruthSourceOperation::Update);
    }

    public function testKeyedRtSourceCannotPerformCollectionWideWrite(): void
    {
        RtTruthSourceRegistry::register(self::COLLECTION, ['1'], self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        RtTruthSourceRegistry::checkCanWrite(self::COLLECTION);
    }

    public function testCollectionWideRtSourceCanWriteAnyStateKey(): void
    {
        RtTruthSourceRegistry::register(self::COLLECTION, true, self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        RtTruthSourceRegistry::checkCanWrite(self::COLLECTION);
        RtTruthSourceRegistry::checkCanWriteState(self::COLLECTION, '1', TruthSourceOperation::Add);
        RtTruthSourceRegistry::checkCanWriteState(self::COLLECTION, '2', TruthSourceOperation::Remove);

        $this->assertTrue(true);
    }

    public function testUnregisterCurrentAgentClearsRtContext(): void
    {
        RtTruthSourceRegistry::register(self::COLLECTION, ['1'], self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        RtTruthSourceRegistry::unregisterAgent(self::AGENT_A);

        $this->assertNull(ExecutionContext::currentAgentId());
    }

    public function testAddRemoveSourceMayBringARowAndTakeItAway(): void
    {
        RtTruthSourceRegistry::register(
            self::COLLECTION,
            true,
            self::AGENT_A,
            [TruthSourceOperation::Add, TruthSourceOperation::Remove],
        );
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        RtTruthSourceRegistry::checkCanWriteState(self::COLLECTION, '1', TruthSourceOperation::Add);
        RtTruthSourceRegistry::checkCanWriteState(self::COLLECTION, '1', TruthSourceOperation::Remove);

        $this->assertTrue(true);
    }

    public function testAddRemoveSourceMayNotEditWhatIsAlreadyWritten(): void
    {
        RtTruthSourceRegistry::register(
            self::COLLECTION,
            true,
            self::AGENT_A,
            [TruthSourceOperation::Add, TruthSourceOperation::Remove],
        );
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        RtTruthSourceRegistry::checkCanWriteState(self::COLLECTION, '1', TruthSourceOperation::Update);
    }

    public function testRefusalNamesTheOperationAndWhatIsAllowed(): void
    {
        RtTruthSourceRegistry::register(
            self::COLLECTION,
            true,
            self::AGENT_A,
            [TruthSourceOperation::Add, TruthSourceOperation::Remove],
        );
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        $this->expectExceptionMessage(
            "Write operation not allowed: agent '" . self::AGENT_A . "' is a truth source for runtime collection "
            . "'" . self::COLLECTION . "' with operations [add, remove] and may not update state '7'."
        );
        RtTruthSourceRegistry::checkCanWriteState(self::COLLECTION, '7', TruthSourceOperation::Update);
    }

    public function testAgentLessRefusalNamesTheOperationWithoutNamingAnAgent(): void
    {
        RtTruthSourceRegistry::register(
            self::COLLECTION,
            true,
            RtTruthSourceRegistry::DAEMON_SOURCE_ID,
            [TruthSourceOperation::Add],
        );
        ExecutionContext::setCurrentAgentId(null);

        $this->expectExceptionMessage(
            "Write operation not allowed: the truth source for runtime collection '" . self::COLLECTION
            . "' has operations [add] and may not remove state '7'."
        );
        RtTruthSourceRegistry::checkCanWriteState(self::COLLECTION, '7', TruthSourceOperation::Remove);
    }

    public function testSourceWithoutTheRowIsRefusedBeforeTheOperationIsWeighed(): void
    {
        RtTruthSourceRegistry::register(self::COLLECTION, ['1'], self::AGENT_A, [TruthSourceOperation::Add]);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        $this->expectExceptionMessage(
            "Write operation not allowed: agent '" . self::AGENT_A . "' is not a truth source for "
            . "runtime collection '" . self::COLLECTION . "' state '2'."
        );
        RtTruthSourceRegistry::checkCanWriteState(self::COLLECTION, '2', TruthSourceOperation::Add);
    }
}
