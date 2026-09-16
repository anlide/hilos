<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * Unit tests for ownership declared on the class instead of claimed inside onStart().
 */
final class DeclaredDbOwnershipTest extends TestCase
{
    public function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(DeclaredDbOwnershipTestAgent::AGENT_TYPE);
        // A claim is a reader interest too, so it is given back here beside the claim itself.
        SourceInterestRegistry::readsWhatItMounts();
        SourceInterestRegistry::releaseConsumer(
            SourceConsumer::agent(DeclaredDbOwnershipTestAgent::AGENT_TYPE),
        );

        parent::tearDown();
    }

    /**
     * The declaration reaches the registry with the operations it named, and the owner may read
     * what it owns at once - the claim is its own reader interest, and one that may add is ready,
     * exactly as the helper it replaces made it. A claim that may not add waits instead, which is
     * {@see BorrowedClaimReadinessTest}'s case.
     */
    public function testADeclaredCollectionIsClaimedWithItsOperationsAndReadableAtOnce(): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();

        OwnershipDeclaration::claimDb(DeclaredDbOwnershipTestAgent::class, DeclaredDbOwnershipTestAgent::AGENT_TYPE);
        ExecutionContext::setCurrentAgentId(DeclaredDbOwnershipTestAgent::AGENT_TYPE);
        TruthSourceRegistry::checkCanWriteItem(
            DeclaredDbOwnershipTestAgent::COLLECTION,
            '1',
            TruthSourceOperation::Update,
        );

        $this->assertTrue(SourceInterestRegistry::isReady(
            SourceChange::KIND_DB,
            DeclaredDbOwnershipTestAgent::COLLECTION,
        ));
        $this->assertSame(
            [TruthSourceOperation::Add, TruthSourceOperation::Update],
            OwnershipDeclaration::dbCollectionsOf(DeclaredDbOwnershipTestAgent::class)
                [DeclaredDbOwnershipTestAgent::COLLECTION]->asList(),
        );
    }

    /**
     * Ownership merges up where reading replaces: the subclass keeps the collection only its
     * parent named, and the collection both named carries the union of the two operation sets.
     *
     * The union is the half that matters. A parent's methods run on the instance of its subclass
     * and were written for a parent's rights, so a subclass narrowing a claim would take a right
     * away from code that is not its own.
     */
    public function testASubclassKeepsWhatItsParentDeclaredAndWidensWhatBothDeclared(): void
    {
        $owned = OwnershipDeclaration::dbCollectionsOf(DeclaredDbOwnershipTestChild::class);

        $this->assertSame(
            [TruthSourceOperation::Add, TruthSourceOperation::Remove],
            $owned[DeclaredDbOwnershipTestParent::PARENT_COLLECTION]->asList(),
        );
        $this->assertSame(
            [TruthSourceOperation::Update],
            $owned[DeclaredDbOwnershipTestChild::CHILD_COLLECTION]->asList(),
        );
        // By membership and not by order: the union is a set, and which of the two declarations
        // the walk met first is not part of what is being promised here.
        $shared = $owned[DeclaredDbOwnershipTestParent::SHARED_COLLECTION];
        $this->assertTrue($shared->allows(TruthSourceOperation::Add));
        $this->assertTrue($shared->allows(TruthSourceOperation::Update));
        $this->assertFalse($shared->allows(TruthSourceOperation::Remove));
    }

    /**
     * A record left to the kind of the agent is answered by the class that is STARTING, however
     * far up the chain the record was written.
     *
     * That is what the call from a parent's onStart() already did on the instance of its subclass,
     * and it is why the expansion happens at every step before the union rather than after it: an
     * unexpanded empty list folded into a neighbour's would lose the kind without a word.
     */
    public function testTheKindOfTheStartingClassAnswersARecordItsParentLeftOpen(): void
    {
        $owned = OwnershipDeclaration::dbCollectionsOf(DeclaredDbOwnershipTestNarrowChild::class);

        $this->assertSame(
            [TruthSourceOperation::Add, TruthSourceOperation::Remove],
            $owned[DeclaredDbOwnershipTestOpenParent::OPEN_COLLECTION]->asList(),
        );
    }

    /**
     * The promise the declaration makes about its own contents: something that is not an operation
     * is refused by the type, at the class that wrote it, and not by a check at some later write.
     */
    public function testAValueThatIsNotAnOperationIsRefusedByTheType(): void
    {
        $this->expectException(TypeError::class);

        OwnershipDeclaration::dbCollectionsOf(DeclaredDbOwnershipTestBadAgent::class);
    }
}

/**
 * An agent owning one collection, and owning it narrowly.
 */
final class DeclaredDbOwnershipTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_declared_db_ownership';
    public const string COLLECTION = 'unit_declared_db_ownership_db';

    public const array OWNS_DB = [self::COLLECTION => [TruthSourceOperation::Add, TruthSourceOperation::Update]];

    /**
     * Claims nothing here: the declaration above is the claim.
     */
    public function onStart(): void
    {
    }

    /**
     * Holds nothing across a stop.
     */
    public function onStop(): void
    {
    }
}

/**
 * A base owning two collections, one of which its subclass names again.
 */
class DeclaredDbOwnershipTestParent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_declared_db_ownership_parent';
    public const string PARENT_COLLECTION = 'unit_declared_db_ownership_parent_db';
    public const string SHARED_COLLECTION = 'unit_declared_db_ownership_shared_db';

    public const array OWNS_DB = [
        self::PARENT_COLLECTION => [TruthSourceOperation::Add, TruthSourceOperation::Remove],
        self::SHARED_COLLECTION => [TruthSourceOperation::Add],
    ];

    /**
     * Claims nothing here: the declaration above is the claim.
     */
    public function onStart(): void
    {
    }

    /**
     * Holds nothing across a stop.
     */
    public function onStop(): void
    {
    }
}

/**
 * A subclass adding one collection of its own and widening the one it shares.
 */
final class DeclaredDbOwnershipTestChild extends DeclaredDbOwnershipTestParent
{
    public const string AGENT_TYPE = 'unit_declared_db_ownership_child';
    public const string CHILD_COLLECTION = 'unit_declared_db_ownership_child_db';

    public const array OWNS_DB = [
        self::CHILD_COLLECTION => [TruthSourceOperation::Update],
        parent::SHARED_COLLECTION => [TruthSourceOperation::Update],
    ];
}

/**
 * A base leaving the operations of its one collection to the kind of the agent.
 */
class DeclaredDbOwnershipTestOpenParent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_declared_db_ownership_open';
    public const string OPEN_COLLECTION = 'unit_declared_db_ownership_open_db';

    public const array OWNS_DB = [self::OPEN_COLLECTION => TruthSourceOperation::BY_KIND];

    /**
     * Claims nothing here: the declaration above is the claim.
     */
    public function onStart(): void
    {
    }

    /**
     * Holds nothing across a stop.
     */
    public function onStop(): void
    {
    }
}

/**
 * A subclass whose kind adds and removes and never edits, inheriting the record left open.
 */
final class DeclaredDbOwnershipTestNarrowChild extends DeclaredDbOwnershipTestOpenParent
{
    public const string AGENT_TYPE = 'unit_declared_db_ownership_narrow';

    /**
     * @return TruthSourceOperations Adding and removing, never updating
     */
    public static function defaultTruthSourceOperations(): TruthSourceOperations
    {
        return TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Remove);
    }
}

/**
 * An agent whose map holds something that is not an operation.
 */
final class DeclaredDbOwnershipTestBadAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_declared_db_ownership_bad';
    public const string COLLECTION = 'unit_declared_db_ownership_bad_db';

    public const array OWNS_DB = [self::COLLECTION => ['update']];

    /**
     * Claims nothing here: the declaration above is what the test reads.
     */
    public function onStart(): void
    {
    }

    /**
     * Holds nothing across a stop.
     */
    public function onStop(): void
    {
    }
}
