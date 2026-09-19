<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
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
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::AGENT_A);
        TruthSourceRegistry::unregisterAgent(self::AGENT_B);

        parent::tearDown();
    }

    public function testCurrentAgentCanWriteOnlyRegisteredDbItemKey(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::listed('1'), self::AGENT_A);
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::listed('2'), self::AGENT_B);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '1', TruthSourceOperation::Update);

        $this->expectException(WriteNotAllowedException::class);
        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '2', TruthSourceOperation::Update);
    }

    public function testKeyedDbSourceCannotPerformCollectionWideWrite(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::listed('1'), self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        $this->expectException(WriteNotAllowedException::class);
        TruthSourceRegistry::checkCanWrite(self::COLLECTION, TruthSourceOperation::Update);
    }

    public function testCollectionWideDbSourceCanWriteAnyItemKey(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::all(), self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        TruthSourceRegistry::checkCanWrite(self::COLLECTION, TruthSourceOperation::Update);
        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '1', TruthSourceOperation::Update);
        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '2', TruthSourceOperation::Remove);

        $this->assertTrue(true);
    }

    public function testCreateSourceCanCreateButNotWrite(): void
    {
        TruthSourceRegistry::registerCreate(self::COLLECTION, self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        TruthSourceRegistry::checkCanCreate(self::COLLECTION);

        $this->expectException(WriteNotAllowedException::class);
        TruthSourceRegistry::checkCanWrite(self::COLLECTION, TruthSourceOperation::Update);
    }

    public function testCurrentAgentCannotUseOtherAgentCreateSource(): void
    {
        TruthSourceRegistry::registerCreate(self::COLLECTION, self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_B);

        $this->expectException(CreateNotAllowedException::class);
        TruthSourceRegistry::checkCanCreate(self::COLLECTION);
    }

    public function testUnregisterCurrentAgentClearsDbContext(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::listed('1'), self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        TruthSourceRegistry::unregisterAgent(self::AGENT_A);

        $this->assertNull(ExecutionContext::currentAgentId());
    }

    public function testCreateSourceOwnsNoRowsAndIsNotReadAsOne(): void
    {
        TruthSourceRegistry::registerCreate(self::COLLECTION, self::AGENT_A);

        $this->assertNull(TruthSourceRegistry::getTruthSourceKeys(self::COLLECTION));
    }

    public function testCreateSourceCannotWriteTheRowItMinted(): void
    {
        TruthSourceRegistry::registerCreate(self::COLLECTION, self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        $this->expectException(WriteNotAllowedException::class);
        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '1', TruthSourceOperation::Update);
    }

    public function testKeyedSourceCannotMintANewRecord(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::listed('1'), self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        $this->expectException(CreateNotAllowedException::class);
        TruthSourceRegistry::checkCanCreate(self::COLLECTION);
    }

    public function testUnregisterCreateLeavesTheRestOfAGrantStanding(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::all(), self::AGENT_A);
        TruthSourceRegistry::unregisterCreate(self::COLLECTION, self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '1', TruthSourceOperation::Update);

        $this->expectException(CreateNotAllowedException::class);
        TruthSourceRegistry::checkCanCreate(self::COLLECTION);
    }

    /**
     * The create right used to live in a store of its own, so an agent could claim it and a
     * write right in either order and hold both. Folding them onto one axis must not turn the
     * second call into a revocation of the first - a right lost that quietly would surface as
     * a refused write far from the registration that dropped it.
     */
    public function testClaimingCreateOnTopOfAWriteRightKeepsBoth(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::listed('1'), self::AGENT_A);
        TruthSourceRegistry::registerCreate(self::COLLECTION, self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '1', TruthSourceOperation::Update);

        $this->assertSame(['1'], TruthSourceRegistry::getTruthSourceKeys(self::COLLECTION)?->listedKeys());
    }

    public function testClaimingCreateAddsTheOperationToAGrantThatLackedIt(): void
    {
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::all(),
            self::AGENT_A,
            TruthSourceOperations::of(TruthSourceOperation::Update),
        );
        TruthSourceRegistry::registerCreate(self::COLLECTION, self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        TruthSourceRegistry::checkCanCreate(self::COLLECTION);
        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '1', TruthSourceOperation::Update);

        $this->assertTrue(TruthSourceRegistry::getTruthSourceKeys(self::COLLECTION)?->coversEveryKey());
    }

    public function testDbRefusalNamesTheOperationAndWhatIsAllowed(): void
    {
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::all(),
            self::AGENT_A,
            TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Remove),
        );
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        $this->expectExceptionMessage(
            "Write operation not allowed: agent '" . self::AGENT_A . "' is a truth source for table "
            . "'" . self::COLLECTION . "' with operations [add, remove] and may not update item '7'."
        );
        TruthSourceRegistry::checkCanWriteItem(self::COLLECTION, '7', TruthSourceOperation::Update);
    }

    public function testCollectionWideAgentWithoutRemoveCannotDropRowsAcrossTheTable(): void
    {
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::all(),
            self::AGENT_A,
            TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Update),
        );
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        TruthSourceRegistry::checkCanWrite(self::COLLECTION, TruthSourceOperation::Update);

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage(
            "with operations [add, update] and may not remove rows across the whole table"
        );
        TruthSourceRegistry::checkCanWrite(self::COLLECTION, TruthSourceOperation::Remove);
    }

    public function testCollectionWideAgentWithoutUpdateCannotEditRowsAcrossTheTable(): void
    {
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::all(),
            self::AGENT_A,
            TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Remove),
        );
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        TruthSourceRegistry::checkCanWrite(self::COLLECTION, TruthSourceOperation::Remove);

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("may not update rows across the whole table");
        TruthSourceRegistry::checkCanWrite(self::COLLECTION, TruthSourceOperation::Update);
    }

    public function testAgentlessCollectionWideWriteIsJudgedByTheUnionOfCollectionWideGrants(): void
    {
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::all(),
            self::AGENT_A,
            TruthSourceOperations::of(TruthSourceOperation::Add),
        );
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::all(),
            self::AGENT_B,
            TruthSourceOperations::of(TruthSourceOperation::Remove),
        );

        TruthSourceRegistry::checkCanWrite(self::COLLECTION, TruthSourceOperation::Remove);

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage(
            "the collection-wide truth source for table '" . self::COLLECTION . "' has operations [add, remove] "
            . "and may not update rows across the whole table."
        );
        TruthSourceRegistry::checkCanWrite(self::COLLECTION, TruthSourceOperation::Update);
    }

    public function testCollectionWideWriteJudgesWidthBeforeTheOperation(): void
    {
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::listed('1'), self::AGENT_A);
        ExecutionContext::setCurrentAgentId(self::AGENT_A);

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("is not a collection-wide truth source");
        TruthSourceRegistry::checkCanWrite(self::COLLECTION, TruthSourceOperation::Remove);
    }
}
