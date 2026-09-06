<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\Exception\ClaimedRowKeysMissingException;
use Hilos\Core\TruthSource\Exception\ClaimWidthConflictException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ownership declared narrowly: the collection on the class, the rows on the instance.
 */
final class DeclaredRowOwnershipTest extends TestCase
{
    public function tearDown(): void
    {
        foreach (
            [
                DeclaredRowOwnershipTestRtAgent::AGENT_TYPE,
                DeclaredRowOwnershipTestDbAgent::AGENT_TYPE,
                DeclaredRowOwnershipTestSilentAgent::AGENT_TYPE,
                DeclaredRowOwnershipTestConflictAgent::AGENT_TYPE,
            ] as $agentType
        ) {
            $agentId = $agentType . ':7';
            RtTruthSourceRegistry::unregisterAgent($agentId);
            TruthSourceRegistry::unregisterAgent($agentId);
            // A claim is a reader interest too, so it is given back here beside the claim itself.
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
        }
        SourceInterestRegistry::readsWhatItMounts();

        parent::tearDown();
    }

    /**
     * The narrow runtime claim reaches the registry over the rows the INSTANCE named, and over no
     * other row - which is the whole difference between this width and the collection-wide one.
     */
    public function testANarrowClaimCarriesTheRowsOfTheInstanceAndNoOthers(): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();
        $agent = new DeclaredRowOwnershipTestRtAgent('7');

        OwnershipDeclaration::claimRtRows($agent);

        $this->assertTrue(RtTruthSourceRegistry::isTruthSource(
            DeclaredRowOwnershipTestRtAgent::COLLECTION,
            ['7'],
        ));
        $this->assertFalse(RtTruthSourceRegistry::isTruthSource(
            DeclaredRowOwnershipTestRtAgent::COLLECTION,
            ['8'],
        ));
        $this->assertTrue(RtTruthSourceRegistry::allowsOperation(
            DeclaredRowOwnershipTestRtAgent::COLLECTION,
            $agent->getId(),
            TruthSourceOperation::Update,
        ));
        $this->assertTrue(SourceInterestRegistry::isReady(
            SourceChange::KIND_RT,
            DeclaredRowOwnershipTestRtAgent::COLLECTION,
        ));
    }

    /**
     * The width is readable off the CLASS, with nothing running: the narrow map names the
     * collection and the whole-collection map does not.
     *
     * That is what the split into two maps buys, and the only thing the validator with no instance
     * to ask will ever have.
     */
    public function testTheWidthOfTheClaimIsReadableOffTheClassAlone(): void
    {
        $this->assertSame(
            [DeclaredRowOwnershipTestRtAgent::COLLECTION],
            array_keys(OwnershipDeclaration::rtRowCollectionsOf(DeclaredRowOwnershipTestRtAgent::class)),
        );
        $this->assertSame([], OwnershipDeclaration::rtCollectionsOf(DeclaredRowOwnershipTestRtAgent::class));
    }

    /**
     * A seam that names no row stops the start instead of registering a claim over nothing.
     *
     * A width of no rows is already taken: it is the right to create. Registered quietly, this
     * would leave the collection with no holder of its rows and say so only at the first foreign
     * write, an hour later and somewhere else.
     */
    public function testASilentSeamRefusesTheClaimAndRegistersNothing(): void
    {
        $agent = new DeclaredRowOwnershipTestSilentAgent('7');

        $this->expectException(ClaimedRowKeysMissingException::class);

        try {
            OwnershipDeclaration::claimRtRows($agent);
        } finally {
            $this->assertFalse(RtTruthSourceRegistry::hasTruthSource(
                DeclaredRowOwnershipTestSilentAgent::COLLECTION,
            ));
        }
    }

    /**
     * One collection named by both maps of a half is refused rather than resolved.
     *
     * This is the one mistake nothing else here would catch: the registry keeps one grant per
     * (collection, agent) pair and a repeated registration replaces it, so without the refusal one
     * of the two claims would silently eat the other, and which one won would be a matter of the
     * order the two calls happen to stand in.
     */
    public function testACollectionNamedByBothWidthsRefusesTheClaim(): void
    {
        $agent = new DeclaredRowOwnershipTestConflictAgent('7');

        $this->expectException(ClaimWidthConflictException::class);

        try {
            OwnershipDeclaration::claimRtRows($agent);
        } finally {
            $this->assertFalse(RtTruthSourceRegistry::hasTruthSource(
                DeclaredRowOwnershipTestConflictAgent::COLLECTION,
            ));
        }
    }

    /**
     * The database half works by the same walk and files its claim in its own registry.
     *
     * There is no live caller of it yet, which is why it is asserted here: the two halves share
     * every line but the constant they read and the registry they reach, and a closure handed the
     * wrong one would put a database claim in the runtime registry without a word.
     */
    public function testTheDatabaseHalfClaimsItsOwnRowsInItsOwnRegistry(): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();
        $agent = new DeclaredRowOwnershipTestDbAgent('7');

        OwnershipDeclaration::claimDbRows($agent);

        $this->assertTrue(TruthSourceRegistry::isTruthSource(
            DeclaredRowOwnershipTestDbAgent::COLLECTION,
            ['7'],
        ));
        $this->assertFalse(TruthSourceRegistry::isTruthSource(
            DeclaredRowOwnershipTestDbAgent::COLLECTION,
            ['8'],
        ));
        $this->assertFalse(RtTruthSourceRegistry::hasTruthSource(DeclaredRowOwnershipTestDbAgent::COLLECTION));
        $this->assertTrue(SourceInterestRegistry::isReady(
            SourceChange::KIND_DB,
            DeclaredRowOwnershipTestDbAgent::COLLECTION,
        ));
    }
}

/**
 * An agent holding the row of its own index in one runtime collection.
 */
final class DeclaredRowOwnershipTestRtAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_declared_row_ownership_rt';
    public const string COLLECTION = 'unit_declared_row_ownership_rt_rows';

    public const array OWNS_RT_ROWS = [self::COLLECTION => [TruthSourceOperation::Update]];

    /**
     * @param string $agentIndex Row this instance holds
     */
    public function __construct(string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }

    /**
     * @param string $collection Collection the resolver is asking about
     * @return list<string> The one row this instance answers for
     */
    public function ownedRtRowKeys(string $collection): array
    {
        return [(string)$this->agentIndex];
    }

    /**
     * Claims nothing here: the declaration above and the seam beside it are the claim.
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
 * An agent holding the row of its own index in one database collection.
 */
final class DeclaredRowOwnershipTestDbAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_declared_row_ownership_db';
    public const string COLLECTION = 'unit_declared_row_ownership_db_rows';

    public const array OWNS_DB_ROWS = [self::COLLECTION => [TruthSourceOperation::Update]];

    /**
     * @param string $agentIndex Row this instance holds
     */
    public function __construct(string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }

    /**
     * @param string $collection Collection the resolver is asking about
     * @return list<string> The one row this instance answers for
     */
    public function ownedDbRowKeys(string $collection): array
    {
        return [(string)$this->agentIndex];
    }

    /**
     * Claims nothing here: the declaration above and the seam beside it are the claim.
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
 * An agent declaring a collection narrowly and leaving the seam unanswered.
 */
final class DeclaredRowOwnershipTestSilentAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_declared_row_ownership_silent';
    public const string COLLECTION = 'unit_declared_row_ownership_silent_rows';

    public const array OWNS_RT_ROWS = [self::COLLECTION => [TruthSourceOperation::Update]];

    /**
     * @param string $agentIndex Index this instance carries, which it never turns into a claim
     */
    public function __construct(string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }

    /**
     * Claims nothing here: the declaration above is refused for want of a seam.
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
 * An agent naming one collection in both maps of the runtime half.
 */
final class DeclaredRowOwnershipTestConflictAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_declared_row_ownership_conflict';
    public const string COLLECTION = 'unit_declared_row_ownership_conflict_rows';

    public const array OWNS_RT = [self::COLLECTION => [TruthSourceOperation::Update]];

    public const array OWNS_RT_ROWS = [self::COLLECTION => [TruthSourceOperation::Update]];

    /**
     * @param string $agentIndex Row this instance would hold, were the declaration readable
     */
    public function __construct(string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }

    /**
     * @param string $collection Collection the resolver is asking about
     * @return list<string> The one row this instance answers for
     */
    public function ownedRtRowKeys(string $collection): array
    {
        return [(string)$this->agentIndex];
    }

    /**
     * Claims nothing here: the two declarations above contradict each other and are refused.
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
