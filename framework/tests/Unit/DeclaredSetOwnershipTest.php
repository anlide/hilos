<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\Exception\ClaimedSetKeyMissingException;
use Hilos\Core\TruthSource\Exception\ClaimWidthConflictException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ownership declared by a set: the collection on the class, the set key on the instance.
 */
final class DeclaredSetOwnershipTest extends TestCase
{
    public function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        foreach (
            [
                DeclaredSetOwnershipTestAgent::AGENT_TYPE . ':42',
                DeclaredSetOwnershipTestSilentAgent::AGENT_TYPE . ':42',
                DeclaredSetOwnershipTestWholeConflictAgent::AGENT_TYPE . ':42',
                DeclaredSetOwnershipTestRowsConflictAgent::AGENT_TYPE . ':42',
                DeclaredSetOwnershipTestHeirAgent::AGENT_TYPE . ':42',
                DeclaredSetOwnershipTestBorrowerAgent::AGENT_TYPE . ':42',
            ] as $agentId
        ) {
            RtTruthSourceRegistry::unregisterAgent($agentId);
            TruthSourceRegistry::unregisterAgent($agentId);
            // A claim is a reader interest too, so it is given back here beside the claim itself.
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
        }
        SourceInterestRegistry::readsWhatItMounts();

        parent::tearDown();
    }

    /**
     * The set claim reaches the registry under the key the INSTANCE named: a row of that set is
     * the agent's to write, a row of another set is refused by the truth source.
     */
    public function testASetClaimCarriesTheSetOfTheInstance(): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();
        $agent = new DeclaredSetOwnershipTestAgent('42');

        OwnershipDeclaration::claimDbSet($agent);
        ExecutionContext::setCurrentAgentId($agent->getId());

        TruthSourceRegistry::checkCanWriteItem(
            DeclaredSetOwnershipTestAgent::COLLECTION,
            '1',
            static fn(): array => ['42'],
            TruthSourceOperation::Update,
        );
        $this->assertTrue(SourceInterestRegistry::isReady(SourceChange::KIND_DB, DeclaredSetOwnershipTestAgent::COLLECTION));
        $this->assertFalse(RtTruthSourceRegistry::hasTruthSource(DeclaredSetOwnershipTestAgent::COLLECTION));

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("it holds set '42'");
        TruthSourceRegistry::checkCanWriteItem(
            DeclaredSetOwnershipTestAgent::COLLECTION,
            '2',
            static fn(): array => ['7'],
            TruthSourceOperation::Update,
        );
    }

    /**
     * The width is readable off the CLASS, with nothing running: the map of sets names the
     * collection, and neither of the other two maps of the half does.
     */
    public function testTheWidthOfTheClaimIsReadableOffTheClassAlone(): void
    {
        $this->assertSame(
            [DeclaredSetOwnershipTestAgent::COLLECTION],
            array_keys(OwnershipDeclaration::dbSetCollectionsOf(DeclaredSetOwnershipTestAgent::class)),
        );
        $this->assertSame([], OwnershipDeclaration::dbCollectionsOf(DeclaredSetOwnershipTestAgent::class));
        $this->assertSame([], OwnershipDeclaration::dbRowCollectionsOf(DeclaredSetOwnershipTestAgent::class));
    }

    /**
     * A seam that names no set key stops the start instead of registering a claim over nobody's set.
     */
    public function testASilentSeamRefusesTheClaimAndRegistersNothing(): void
    {
        $agent = new DeclaredSetOwnershipTestSilentAgent('42');

        $this->expectException(ClaimedSetKeyMissingException::class);

        try {
            OwnershipDeclaration::claimDbSet($agent);
        } finally {
            $this->assertFalse(TruthSourceRegistry::hasTruthSource(DeclaredSetOwnershipTestSilentAgent::COLLECTION));
        }
    }

    /**
     * One collection declared both whole and by a set is refused before either claim is laid, and
     * the refusal names the two maps it stands in.
     */
    public function testACollectionNamedWholeAndByASetRefusesTheClaim(): void
    {
        $agent = new DeclaredSetOwnershipTestWholeConflictAgent('42');

        $this->expectException(ClaimWidthConflictException::class);
        $this->expectExceptionMessage(
            "'" . DeclaredSetOwnershipTestWholeConflictAgent::COLLECTION . "' in OWNS_DB and OWNS_DB_SET",
        );

        try {
            OwnershipDeclaration::claimDbSet($agent);
        } finally {
            $this->assertFalse(TruthSourceRegistry::hasTruthSource(DeclaredSetOwnershipTestWholeConflictAgent::COLLECTION));
        }
    }

    /**
     * The narrow width reads the map of sets too: a collection declared by rows and by a set is
     * refused by the call that lays the rows, not left to whichever of the two calls runs last.
     */
    public function testTheRowsWidthSeesTheMapOfSets(): void
    {
        $agent = new DeclaredSetOwnershipTestRowsConflictAgent('42');

        $this->expectException(ClaimWidthConflictException::class);
        $this->expectExceptionMessage(
            "'" . DeclaredSetOwnershipTestRowsConflictAgent::COLLECTION . "' in OWNS_DB_ROWS and OWNS_DB_SET",
        );

        try {
            OwnershipDeclaration::claimDbRows($agent);
        } finally {
            $this->assertFalse(TruthSourceRegistry::hasTruthSource(DeclaredSetOwnershipTestRowsConflictAgent::COLLECTION));
        }
    }

    /**
     * A parent declaring a collection by a set and a subclass declaring it whole contradict each
     * other exactly as one class naming it twice does: the maps are read folded.
     */
    public function testAParentBySetAndAnHeirWholeRefuseTheClaim(): void
    {
        $agent = new DeclaredSetOwnershipTestHeirAgent('42');

        $this->expectException(ClaimWidthConflictException::class);
        $this->expectExceptionMessage("in OWNS_DB and OWNS_DB_SET");

        OwnershipDeclaration::claimDbSet($agent);
    }

    /**
     * A set claim that may not add is borrowed: its state is declared and waited for rather than
     * ready at once. One carrying the kind of an ordinary agent may add, and borrows nothing.
     */
    public function testASetClaimWithoutAddIsBorrowedAndWaitsForItsState(): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();
        $agent = new DeclaredSetOwnershipTestBorrowerAgent('42');

        $this->assertSame(
            [DeclaredSetOwnershipTestBorrowerAgent::COLLECTION],
            OwnershipDeclaration::borrowedDbCollectionsOf(DeclaredSetOwnershipTestBorrowerAgent::class),
        );
        $this->assertSame([], OwnershipDeclaration::borrowedDbCollectionsOf(DeclaredSetOwnershipTestAgent::class));

        OwnershipDeclaration::claimDbSet($agent);

        $this->assertTrue(SourceInterestRegistry::isDeclared(
            SourceChange::KIND_DB,
            DeclaredSetOwnershipTestBorrowerAgent::COLLECTION,
        ));
        $this->assertFalse(SourceInterestRegistry::isReady(
            SourceChange::KIND_DB,
            DeclaredSetOwnershipTestBorrowerAgent::COLLECTION,
        ));
    }
}

/**
 * An agent holding the set of its own index in one database collection.
 */
class DeclaredSetOwnershipTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_declared_set_ownership';
    public const string COLLECTION = 'unit_declared_set_ownership_rows';

    public const array OWNS_DB_SET = [self::COLLECTION => TruthSourceOperation::BY_KIND];

    /**
     * @param string $agentIndex Set this instance holds
     */
    public function __construct(string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
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
 * An agent declaring a collection by a set and leaving the seam unanswered.
 */
final class DeclaredSetOwnershipTestSilentAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_declared_set_ownership_silent';
    public const string COLLECTION = 'unit_declared_set_ownership_silent_rows';

    public const array OWNS_DB_SET = [self::COLLECTION => TruthSourceOperation::BY_KIND];

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
 * An agent naming one database collection both whole and by a set.
 */
final class DeclaredSetOwnershipTestWholeConflictAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_declared_set_ownership_whole_conflict';
    public const string COLLECTION = 'unit_declared_set_ownership_whole_conflict_rows';

    public const array OWNS_DB = [self::COLLECTION => [TruthSourceOperation::Update]];

    public const array OWNS_DB_SET = [self::COLLECTION => [TruthSourceOperation::Update]];

    /**
     * @param string $agentIndex Set this instance would hold, were the declaration readable
     */
    public function __construct(string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
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

/**
 * An agent naming one database collection both by rows and by a set, each seam answering.
 */
final class DeclaredSetOwnershipTestRowsConflictAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_declared_set_ownership_rows_conflict';
    public const string COLLECTION = 'unit_declared_set_ownership_rows_conflict_rows';

    public const array OWNS_DB_ROWS = [self::COLLECTION => [TruthSourceOperation::Update]];

    public const array OWNS_DB_SET = [self::COLLECTION => [TruthSourceOperation::Update]];

    /**
     * @param string $agentIndex Row and set this instance would hold, were the declaration readable
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
     * @return string The one set this instance answers for
     */
    public function ownedDbSetKey(string $collection): string
    {
        return (string)$this->agentIndex;
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

/**
 * A subclass of the set holder declaring the same collection whole.
 */
final class DeclaredSetOwnershipTestHeirAgent extends DeclaredSetOwnershipTestAgent
{
    public const string AGENT_TYPE = 'unit_declared_set_ownership_heir';

    public const array OWNS_DB = [self::COLLECTION => [TruthSourceOperation::Update]];
}

/**
 * An agent holding a set it may edit and remove from but not add to.
 */
final class DeclaredSetOwnershipTestBorrowerAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_declared_set_ownership_borrower';
    public const string COLLECTION = 'unit_declared_set_ownership_borrowed_rows';

    public const array OWNS_DB_SET = [self::COLLECTION => [TruthSourceOperation::Update, TruthSourceOperation::Remove]];

    /**
     * @param string $agentIndex Set this instance holds
     */
    public function __construct(string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
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
