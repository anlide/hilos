<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\Peer\DTO\PeerRtClaimEntry;
use Hilos\TruthSource\RtClusterClaimRegistry;
use PHPUnit\Framework\TestCase;

/**
 * How the leader tells two owners of one runtime collection apart from a legitimate arrangement
 * (HIL-696).
 *
 * A node can see that IT owns a collection and cannot see that another node owns it too, so the
 * declarations are reported to the leader and only there do two of them meet. What is pinned
 * here is the narrowness of the verdict: both axes of the right decide it, and everything the
 * two neighbouring tickets made legal has to stay legal. A guard that fired on a co-owner short
 * of an operation (HIL-688) or on two agents owning different rows (HIL-589) would stop agents
 * that are working exactly as declared.
 *
 * The other half is what the map REMEMBERS. A claim that lost is not a holding, and the case
 * that proves why is the holder's second report: the holder reports again every time anything
 * it owns moves, and a loser recorded as an incumbent would take the right off the very node it
 * lost to.
 */
final class RtClusterClaimRegistryTest extends TestCase
{
    /** @var string Node whose claims the leader hears first */
    private const string HOLDER_NODE = 'node-a';

    /** @var string Node that claims afterwards */
    private const string LATE_NODE = 'node-b';

    /** @var string Collection the cases claim */
    private const string COLLECTION = 'unitClusterClaimRows';

    /** @var string Collection nobody in these cases contests */
    private const string OTHER_COLLECTION = 'unitClusterClaimOther';

    public function testTwoWholeClaimsOnTwoNodesAreTheSplit(): void
    {
        $registry = new RtClusterClaimRegistry();
        $registry->fold(self::HOLDER_NODE, [self::claim('library')]);

        $refusals = $registry->fold(self::LATE_NODE, [self::claim('twin')]);

        $this->assertCount(1, $refusals, 'Two whole rights over one collection are the defect');
        $this->assertSame(self::COLLECTION, $refusals[0]->collectionKey);
        $this->assertSame(self::HOLDER_NODE, $refusals[0]->holderNodeId, 'The node heard first keeps the right');
        $this->assertSame('library', $refusals[0]->holderAgentId);
        $this->assertSame('twin', $refusals[0]->agentId, 'The verdict names the agent that lost');
        $this->assertSame([], $refusals[0]->stateIds, 'No rows named means the whole collection');
    }

    public function testTheVerdictCarriesTheIdentityAPlacementFrameIsAddressedWith(): void
    {
        $registry = new RtClusterClaimRegistry();
        $registry->fold(self::HOLDER_NODE, [self::claim('library')]);

        $refusals = $registry->fold(self::LATE_NODE, [
            new PeerRtClaimEntry('render:9', 'render', '9', [self::COLLECTION]),
        ]);

        $this->assertSame('render', $refusals[0]->agentType);
        $this->assertSame('9', $refusals[0]->agentIndex);
    }

    public function testACoOwnerShortOfAnOperationIsNotASplit(): void
    {
        $registry = new RtClusterClaimRegistry();
        $registry->fold(self::HOLDER_NODE, [self::claim('library')]);

        $refusals = $registry->fold(self::LATE_NODE, [
            new PeerRtClaimEntry('editor', 'editor', null, [self::COLLECTION], [self::COLLECTION]),
        ]);

        $this->assertSame([], $refusals, 'A claim short of an operation leaves the rest to a neighbour (HIL-688)');
    }

    public function testAWholeClaimAgainstAPartialHolderIsNotASplitEither(): void
    {
        $registry = new RtClusterClaimRegistry();
        $registry->fold(self::HOLDER_NODE, [
            new PeerRtClaimEntry('editor', 'editor', null, [self::COLLECTION], [self::COLLECTION]),
        ]);

        $refusals = $registry->fold(self::LATE_NODE, [self::claim('library')]);

        $this->assertSame([], $refusals, 'Only two WHOLE rights collide; a partial holder has no ground to refuse');
    }

    public function testTwoAgentsOwningDifferentRowsAreTwoOwnersOfDifferentEntities(): void
    {
        $registry = new RtClusterClaimRegistry();
        $registry->fold(self::HOLDER_NODE, [self::rowClaim('member:1', ['1'])]);

        $refusals = $registry->fold(self::LATE_NODE, [self::rowClaim('member:2', ['2'])]);

        $this->assertSame([], $refusals, 'Different rows are different entities, not a split (HIL-589)');
    }

    public function testTwoAgentsSharingOneRowAreJudgedOverThatRowAlone(): void
    {
        $registry = new RtClusterClaimRegistry();
        $registry->fold(self::HOLDER_NODE, [self::rowClaim('member:1', ['1', '2', '3'])]);

        $refusals = $registry->fold(self::LATE_NODE, [self::rowClaim('member:2', ['3', '4'])]);

        $this->assertCount(1, $refusals);
        $this->assertSame(['3'], $refusals[0]->stateIds, 'The verdict names the shared row, not the collection');
    }

    public function testAWholeClaimMeetingARowOwnerIsJudgedOverThatOwnersRows(): void
    {
        $registry = new RtClusterClaimRegistry();
        $registry->fold(self::HOLDER_NODE, [self::rowClaim('member:1', ['7'])]);

        $refusals = $registry->fold(self::LATE_NODE, [self::claim('library')]);

        $this->assertCount(1, $refusals, 'Claiming the collection reaches over the rows somebody else writes');
        $this->assertSame(['7'], $refusals[0]->stateIds);
    }

    public function testTwoOwnersInsideOneNodeAreNotThisGuardsBusiness(): void
    {
        $registry = new RtClusterClaimRegistry();

        $refusals = $registry->fold(self::HOLDER_NODE, [self::claim('library'), self::claim('twin')]);

        $this->assertSame([], $refusals, 'A duplicate inside one node is HIL-685, and unreadable from here');
    }

    public function testTheClaimThatLostDoesNotDispossessTheHolderLater(): void
    {
        $registry = new RtClusterClaimRegistry();
        $registry->fold(self::HOLDER_NODE, [self::claim('library')]);
        $registry->fold(self::LATE_NODE, [self::claim('twin')]);

        // What the holder does next, every time anything it owns moves.
        $refusals = $registry->fold(self::HOLDER_NODE, [self::claim('library')]);

        $this->assertSame([], $refusals, 'The loser is not recorded, so it cannot become the incumbent');
    }

    public function testOnlyTheContestedCollectionIsTakenOffTheLoser(): void
    {
        $registry = new RtClusterClaimRegistry();
        $registry->fold(self::HOLDER_NODE, [self::claim('library')]);
        $registry->fold(self::LATE_NODE, [
            new PeerRtClaimEntry('twin', 'twin', null, [self::COLLECTION, self::OTHER_COLLECTION]),
        ]);

        $refusals = $registry->fold(self::HOLDER_NODE, [
            new PeerRtClaimEntry('library', 'library', null, [self::COLLECTION, self::OTHER_COLLECTION]),
        ]);

        $this->assertCount(1, $refusals, 'The loser keeps the collections nobody contested');
        $this->assertSame(self::OTHER_COLLECTION, $refusals[0]->collectionKey);
        $this->assertSame(self::LATE_NODE, $refusals[0]->holderNodeId);
    }

    public function testANodeThatLeftTakesItsClaimsWithIt(): void
    {
        $registry = new RtClusterClaimRegistry();
        $registry->fold(self::HOLDER_NODE, [self::claim('library')]);

        $registry->forget(self::HOLDER_NODE);

        $this->assertSame(
            [],
            $registry->fold(self::LATE_NODE, [self::claim('twin')]),
            'A right held by a node that is gone would refuse the host failover just chose',
        );
    }

    public function testAFreshLeaderStartsFromNothing(): void
    {
        $registry = new RtClusterClaimRegistry();
        $registry->fold(self::HOLDER_NODE, [self::claim('library')]);

        $registry->clear();

        $this->assertSame(
            [],
            $registry->fold(self::LATE_NODE, [self::claim('twin')]),
            'The map is soft state: a new term is judged by the reports of that term',
        );
    }

    public function testAReportThatOwnsNothingReleasesWhatTheNodeHeld(): void
    {
        $registry = new RtClusterClaimRegistry();
        $registry->fold(self::HOLDER_NODE, [self::claim('library')]);

        // The report a node makes once the agent holding the right has stopped.
        $registry->fold(self::HOLDER_NODE, []);

        $this->assertSame(
            [],
            $registry->fold(self::LATE_NODE, [self::claim('twin')]),
            'A released right is free for the next claimant',
        );
    }

    /**
     * Builds a claim over the whole contested collection.
     *
     * @param string $agentId Agent holding the claim
     * @return PeerRtClaimEntry Claim over every operation and every row
     */
    private static function claim(string $agentId): PeerRtClaimEntry
    {
        return new PeerRtClaimEntry($agentId, $agentId, null, [self::COLLECTION]);
    }

    /**
     * Builds a claim over named rows of the contested collection.
     *
     * @param string $agentId Agent holding the claim
     * @param list<string> $stateIds Rows it claims
     * @return PeerRtClaimEntry Claim over every operation, over those rows alone
     */
    private static function rowClaim(string $agentId, array $stateIds): PeerRtClaimEntry
    {
        return new PeerRtClaimEntry(
            $agentId,
            'member',
            substr($agentId, strrpos($agentId, ':') + 1),
            [self::COLLECTION],
            [],
            [self::COLLECTION => $stateIds],
        );
    }
}
