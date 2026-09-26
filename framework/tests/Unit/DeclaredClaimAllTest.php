<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\Exception\ClaimWidthConflictException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the one call that lays every claim an agent declares, the way a node starts it.
 */
final class DeclaredClaimAllTest extends TestCase
{
    public function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        foreach (
            [
                DeclaredClaimAllTestEveryMapAgent::AGENT_TYPE,
                DeclaredClaimAllTestDbOnlyAgent::AGENT_TYPE,
                DeclaredClaimAllTestConflictAgent::AGENT_TYPE,
            ] as $agentType
        ) {
            $agentId = $agentType . ':7';
            RtTruthSourceRegistry::unregisterAgent($agentId);
            TruthSourceRegistry::unregisterAgent($agentId);
            // A claim is a reader interest too, so it is given back here beside the claim itself.
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
        }

        parent::tearDown();
    }

    /**
     * An agent that declares every map holds six grants after one call: two over whole
     * collections, two over the rows its instance named and no others, and in each half one over
     * the set its instance named and no other.
     */
    public function testOneCallLaysEveryMap(): void
    {
        $agent = new DeclaredClaimAllTestEveryMapAgent('7');

        OwnershipDeclaration::claimAll($agent);

        $this->assertTrue(TruthSourceRegistry::isTruthSource(DeclaredClaimAllTestEveryMapAgent::DB_COLLECTION, ['8']));
        $this->assertTrue(TruthSourceRegistry::isTruthSource(DeclaredClaimAllTestEveryMapAgent::DB_ROWS_COLLECTION, ['7']));
        $this->assertFalse(TruthSourceRegistry::isTruthSource(DeclaredClaimAllTestEveryMapAgent::DB_ROWS_COLLECTION, ['8']));
        $this->assertEqualsCanonicalizing(
            [
                DeclaredClaimAllTestEveryMapAgent::DB_COLLECTION,
                DeclaredClaimAllTestEveryMapAgent::DB_ROWS_COLLECTION,
                DeclaredClaimAllTestEveryMapAgent::DB_SET_COLLECTION,
            ],
            SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::agent($agent->getId()), SourceChange::KIND_DB),
        );
        $this->assertEqualsCanonicalizing(
            [
                DeclaredClaimAllTestEveryMapAgent::RT_COLLECTION,
                DeclaredClaimAllTestEveryMapAgent::RT_ROWS_COLLECTION,
                DeclaredClaimAllTestEveryMapAgent::RT_SET_COLLECTION,
            ],
            RtTruthSourceRegistry::collectionsOf($agent->getId()),
        );
        $this->assertEqualsCanonicalizing(
            [
                DeclaredClaimAllTestEveryMapAgent::RT_COLLECTION,
                DeclaredClaimAllTestEveryMapAgent::RT_ROWS_COLLECTION,
                DeclaredClaimAllTestEveryMapAgent::RT_SET_COLLECTION,
            ],
            SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::agent($agent->getId()), SourceChange::KIND_RT),
        );
        $this->assertSame(
            [
                DeclaredClaimAllTestEveryMapAgent::RT_ROWS_COLLECTION => ['7'],
                DeclaredClaimAllTestEveryMapAgent::RT_SET_COLLECTION => [],
            ],
            RtTruthSourceRegistry::keysByCollectionOf($agent->getId()),
        );

        ExecutionContext::setCurrentAgentId($agent->getId());
        RtTruthSourceRegistry::checkCanWriteState(
            DeclaredClaimAllTestEveryMapAgent::RT_SET_COLLECTION,
            '1',
            ['7'],
            TruthSourceOperation::Update,
        );
        try {
            RtTruthSourceRegistry::checkCanWriteState(
                DeclaredClaimAllTestEveryMapAgent::RT_SET_COLLECTION,
                '1',
                ['8'],
                TruthSourceOperation::Update,
            );
            $this->fail('Expected a runtime row of another set to be refused');
        } catch (RtTruthSourceWriteNotAllowedException $e) {
            $this->assertStringContainsString("it holds set '7'", $e->getMessage());
        }
        TruthSourceRegistry::checkCanWriteItem(
            DeclaredClaimAllTestEveryMapAgent::DB_SET_COLLECTION,
            '1',
            static fn(): array => ['7'],
            TruthSourceOperation::Update,
        );
        $this->expectException(WriteNotAllowedException::class);
        TruthSourceRegistry::checkCanWriteItem(
            DeclaredClaimAllTestEveryMapAgent::DB_SET_COLLECTION,
            '1',
            static fn(): array => ['8'],
            TruthSourceOperation::Update,
        );
    }

    /**
     * An agent that declares one map gets that grant and nothing beside it: the five maps it left
     * empty reach no registry.
     *
     * This is what made it safe to move the harnesses that laid only some of the claims by hand
     * onto the whole beat - a map the class leaves empty grants nothing.
     */
    public function testAnAgentDeclaringOneMapGetsThatGrantAlone(): void
    {
        $agent = new DeclaredClaimAllTestDbOnlyAgent('7');

        OwnershipDeclaration::claimAll($agent);

        $this->assertTrue(TruthSourceRegistry::isTruthSource(DeclaredClaimAllTestDbOnlyAgent::COLLECTION, ['8']));
        $this->assertSame(
            [DeclaredClaimAllTestDbOnlyAgent::COLLECTION],
            SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::agent($agent->getId()), SourceChange::KIND_DB),
        );
        $this->assertSame([], RtTruthSourceRegistry::collectionsOf($agent->getId()));
        $this->assertSame(
            [],
            SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::agent($agent->getId()), SourceChange::KIND_RT),
        );
    }

    /**
     * A collection declared both whole and by rows stops the call with the refusal the narrow half
     * raises on its own, and nothing on the way out swallows it.
     *
     * What stood before the refusal stays: the whole claim is laid first and is not taken back here.
     * Taking it back belongs to the caller, which also holds the reader interest raised before any
     * claim - {@see WorkerManager} gives both back in one catch.
     */
    public function testACollectionNamedByBothWidthsStopsTheCall(): void
    {
        $agent = new DeclaredClaimAllTestConflictAgent('7');

        $this->expectException(ClaimWidthConflictException::class);

        try {
            OwnershipDeclaration::claimAll($agent);
        } finally {
            $this->assertTrue(TruthSourceRegistry::hasTruthSource(DeclaredClaimAllTestConflictAgent::COLLECTION));
        }
    }
}

/**
 * An agent declaring every map, each over a collection of its own.
 */
final class DeclaredClaimAllTestEveryMapAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_declared_claim_all_every_map';
    public const string DB_COLLECTION = 'unit_declared_claim_all_db';
    public const string DB_ROWS_COLLECTION = 'unit_declared_claim_all_db_rows';
    public const string RT_COLLECTION = 'unit_declared_claim_all_rt';
    public const string RT_ROWS_COLLECTION = 'unit_declared_claim_all_rt_rows';
    public const string DB_SET_COLLECTION = 'unit_declared_claim_all_db_set';
    public const string RT_SET_COLLECTION = 'unit_declared_claim_all_rt_set';

    public const array OWNS_DB = [self::DB_COLLECTION => [TruthSourceOperation::Update]];
    public const array OWNS_DB_ROWS = [self::DB_ROWS_COLLECTION => [TruthSourceOperation::Update]];
    public const array OWNS_RT = [self::RT_COLLECTION => [TruthSourceOperation::Update]];
    public const array OWNS_RT_ROWS = [self::RT_ROWS_COLLECTION => [TruthSourceOperation::Update]];
    public const array OWNS_DB_SET = [self::DB_SET_COLLECTION => [TruthSourceOperation::Update]];
    public const array OWNS_RT_SET = [self::RT_SET_COLLECTION => [TruthSourceOperation::Update]];

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
     * @param string $collection Collection the resolver is asking about
     * @return list<string> The one row this instance answers for
     */
    public function ownedRtRowKeys(string $collection): array
    {
        return [(string)$this->agentIndex];
    }

    /**
     * @param string $collection Collection the resolver is asking about
     * @return string The one set this instance answers for
     */
    public function ownedDbSetKey(string $collection): string
    {
        return (string)$this->agentIndex;
    }

    /**
     * @param string $collection Collection the resolver is asking about
     * @return string The one set this instance answers for
     */
    public function ownedRtSetKey(string $collection): string
    {
        return (string)$this->agentIndex;
    }

    /**
     * Claims nothing here: the declarations above and the seams beside them are the claim.
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
 * An agent declaring whole database collections and nothing else.
 */
final class DeclaredClaimAllTestDbOnlyAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_declared_claim_all_db_only';
    public const string COLLECTION = 'unit_declared_claim_all_db_only';

    public const array OWNS_DB = [self::COLLECTION => [TruthSourceOperation::Update]];

    /**
     * @param string $agentIndex Index this instance runs under
     */
    public function __construct(string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }

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
 * An agent declaring one database collection both whole and by rows.
 */
final class DeclaredClaimAllTestConflictAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_declared_claim_all_conflict';
    public const string COLLECTION = 'unit_declared_claim_all_conflict';

    public const array OWNS_DB = [self::COLLECTION => [TruthSourceOperation::Update]];
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
     * Claims nothing here: the declarations above are the claim.
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
