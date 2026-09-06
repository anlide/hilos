<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for runtime ownership declared on the class instead of claimed inside onStart().
 */
final class DeclaredRtOwnershipTest extends TestCase
{
    public function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterAgent(DeclaredRtOwnershipTestAgent::AGENT_TYPE);
        RtTruthSourceRegistry::unregisterAgent(DeclaredRtOwnershipTestBothHalvesAgent::AGENT_TYPE);
        TruthSourceRegistry::unregisterAgent(DeclaredRtOwnershipTestBothHalvesAgent::AGENT_TYPE);
        // A claim is a reader interest too, so it is given back here beside the claim itself.
        SourceInterestRegistry::readsWhatItMounts();
        SourceInterestRegistry::releaseConsumer(
            SourceConsumer::agent(DeclaredRtOwnershipTestAgent::AGENT_TYPE),
        );
        SourceInterestRegistry::releaseConsumer(
            SourceConsumer::agent(DeclaredRtOwnershipTestBothHalvesAgent::AGENT_TYPE),
        );

        parent::tearDown();
    }

    /**
     * The declaration reaches the runtime registry with the operations it named, and the owner may
     * read what it owns at once - a writer holds the copy of what it writes, so the interest is
     * ready the moment the claim is laid down.
     */
    public function testADeclaredCollectionIsClaimedWithItsOperationsAndReadableAtOnce(): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();

        OwnershipDeclaration::claimRt(DeclaredRtOwnershipTestAgent::class, DeclaredRtOwnershipTestAgent::AGENT_TYPE);

        $this->assertTrue(RtTruthSourceRegistry::allowsOperation(
            DeclaredRtOwnershipTestAgent::COLLECTION,
            DeclaredRtOwnershipTestAgent::AGENT_TYPE,
            TruthSourceOperation::Update,
        ));
        $this->assertFalse(RtTruthSourceRegistry::allowsOperation(
            DeclaredRtOwnershipTestAgent::COLLECTION,
            DeclaredRtOwnershipTestAgent::AGENT_TYPE,
            TruthSourceOperation::Add,
        ));
        $this->assertTrue(SourceInterestRegistry::isReady(
            SourceChange::KIND_RT,
            DeclaredRtOwnershipTestAgent::COLLECTION,
        ));
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
        $owned = OwnershipDeclaration::rtCollectionsOf(DeclaredRtOwnershipTestChild::class);

        $this->assertSame(
            [TruthSourceOperation::Add, TruthSourceOperation::Remove],
            $owned[DeclaredRtOwnershipTestParent::PARENT_COLLECTION]->asList(),
        );
        $this->assertSame(
            [TruthSourceOperation::Update],
            $owned[DeclaredRtOwnershipTestChild::CHILD_COLLECTION]->asList(),
        );
        // By membership and not by order: the union is a set, and which of the two declarations
        // the walk met first is not part of what is being promised here.
        $shared = $owned[DeclaredRtOwnershipTestParent::SHARED_COLLECTION];
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
        $owned = OwnershipDeclaration::rtCollectionsOf(DeclaredRtOwnershipTestNarrowChild::class);

        $this->assertSame(
            [TruthSourceOperation::Add, TruthSourceOperation::Remove],
            $owned[DeclaredRtOwnershipTestOpenParent::OPEN_COLLECTION]->asList(),
        );
    }

    /**
     * The two halves do not leak into each other: one walk reads two constants, and a closure
     * handed the wrong one would file a runtime claim in the database registry or the other way
     * round.
     *
     * This is the only mistake a shared walk makes possible that nothing else here would catch -
     * every other promise of this file holds just as well when both halves read one constant.
     */
    public function testEachHalfSeesOnlyItsOwnDeclaration(): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();
        $agentId = DeclaredRtOwnershipTestBothHalvesAgent::AGENT_TYPE;

        $this->assertSame(
            [DeclaredRtOwnershipTestBothHalvesAgent::RT_COLLECTION],
            array_keys(OwnershipDeclaration::rtCollectionsOf(DeclaredRtOwnershipTestBothHalvesAgent::class)),
        );
        $this->assertSame(
            [DeclaredRtOwnershipTestBothHalvesAgent::DB_COLLECTION],
            array_keys(OwnershipDeclaration::dbCollectionsOf(DeclaredRtOwnershipTestBothHalvesAgent::class)),
        );

        OwnershipDeclaration::claimRt(DeclaredRtOwnershipTestBothHalvesAgent::class, $agentId);

        $this->assertTrue(RtTruthSourceRegistry::hasTruthSource(
            DeclaredRtOwnershipTestBothHalvesAgent::RT_COLLECTION,
        ));
        $this->assertFalse(TruthSourceRegistry::hasTruthSource(
            DeclaredRtOwnershipTestBothHalvesAgent::DB_COLLECTION,
        ));
    }
}

/**
 * An agent owning one runtime collection, and owning it narrowly.
 */
final class DeclaredRtOwnershipTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_declared_rt_ownership';
    public const string COLLECTION = 'unit_declared_rt_ownership_rt';

    public const array OWNS_RT = [self::COLLECTION => [TruthSourceOperation::Update]];

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
 * A base owning two runtime collections, one of which its subclass names again.
 */
class DeclaredRtOwnershipTestParent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_declared_rt_ownership_parent';
    public const string PARENT_COLLECTION = 'unit_declared_rt_ownership_parent_rt';
    public const string SHARED_COLLECTION = 'unit_declared_rt_ownership_shared_rt';

    public const array OWNS_RT = [
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
 * A subclass adding one runtime collection of its own and widening the one it shares.
 */
final class DeclaredRtOwnershipTestChild extends DeclaredRtOwnershipTestParent
{
    public const string AGENT_TYPE = 'unit_declared_rt_ownership_child';
    public const string CHILD_COLLECTION = 'unit_declared_rt_ownership_child_rt';

    public const array OWNS_RT = [
        self::CHILD_COLLECTION => [TruthSourceOperation::Update],
        parent::SHARED_COLLECTION => [TruthSourceOperation::Update],
    ];
}

/**
 * A base leaving the operations of its one runtime collection to the kind of the agent.
 */
class DeclaredRtOwnershipTestOpenParent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_declared_rt_ownership_open';
    public const string OPEN_COLLECTION = 'unit_declared_rt_ownership_open_rt';

    public const array OWNS_RT = [self::OPEN_COLLECTION => TruthSourceOperation::BY_KIND];

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
final class DeclaredRtOwnershipTestNarrowChild extends DeclaredRtOwnershipTestOpenParent
{
    public const string AGENT_TYPE = 'unit_declared_rt_ownership_narrow';

    /**
     * @return TruthSourceOperations Adding and removing, never updating
     */
    public static function defaultTruthSourceOperations(): TruthSourceOperations
    {
        return TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Remove);
    }
}

/**
 * An agent owning one collection of each half, and a different one on each side.
 */
final class DeclaredRtOwnershipTestBothHalvesAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_declared_rt_ownership_both';
    public const string DB_COLLECTION = 'unit_declared_rt_ownership_db_half';
    public const string RT_COLLECTION = 'unit_declared_rt_ownership_rt_half';

    public const array OWNS_DB = [self::DB_COLLECTION => [TruthSourceOperation::Update]];

    public const array OWNS_RT = [self::RT_COLLECTION => [TruthSourceOperation::Add]];

    /**
     * Claims nothing here: the declarations above are the claims.
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
