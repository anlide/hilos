<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\TruthSource\RtReplicaOriginMap;
use PHPUnit\Framework\TestCase;

/**
 * What the daemon master remembers about where its replicas came from (HIL-711).
 *
 * The one thing a node cannot otherwise answer once a link drops: which of the rows it holds
 * have just stopped being kept up to date. After the apply a replica is a row of the collection
 * like any other, and only the frame knew its origin — so it is written down while the frame is
 * still there, and read back by node when the link to that node closes.
 *
 * Per row and not per collection, because ownership by keys (HIL-589) puts several remote
 * owners in one collection at once. A map that answered by collection would freeze a whole
 * fleet's rows when one member of it went away.
 */
final class RtReplicaOriginMapTest extends TestCase
{
    /** @var string Collection the cases replicate into */
    private const string COLLECTION = 'workerStatuses';

    /** @var string A second collection, for the cases about what one node wrote across two */
    private const string OTHER_COLLECTION = 'jobs';

    /** @var string Node most rows here arrive from */
    private const string NODE = 'node-b';

    /** @var string A second node writing rows of the same collection */
    private const string OTHER_NODE = 'node-c';

    public function testARowIsRememberedAgainstTheNodeThatWroteIt(): void
    {
        $map = new RtReplicaOriginMap();

        $map->note(self::NODE, self::COLLECTION, ['row-1']);

        $this->assertSame(self::NODE, $map->nodeOfRow(self::COLLECTION, 'row-1'));
    }

    /**
     * A row nobody replicated is a row this node wrote itself, and absence is how that is said:
     * no link can stop a local row being current, so it never needs an entry here.
     */
    public function testARowNobodyReplicatedBelongsToNoNode(): void
    {
        $map = new RtReplicaOriginMap();

        $this->assertNull($map->nodeOfRow(self::COLLECTION, 'row-1'));
    }

    /**
     * The fleet arrangement (HIL-589): one collection, several remote owners, each writing the
     * rows it claimed. Asked about one of them, the map answers with its rows alone.
     */
    public function testRowsOfANodeAreItsOwnAndNotItsNeighboursInTheSameCollection(): void
    {
        $map = new RtReplicaOriginMap();
        $map->note(self::NODE, self::COLLECTION, ['row-1', 'row-2']);
        $map->note(self::OTHER_NODE, self::COLLECTION, ['row-3']);

        $this->assertSame([self::COLLECTION => ['row-1', 'row-2']], $map->rowsOfNode(self::NODE));
    }

    /**
     * A node writes into as many collections as it owns something in, and losing it loses all of
     * them at once — so the answer is keyed by collection rather than flattened into row ids.
     */
    public function testRowsOfANodeSpanEveryCollectionItWroteIn(): void
    {
        $map = new RtReplicaOriginMap();
        $map->note(self::NODE, self::COLLECTION, ['row-1']);
        $map->note(self::NODE, self::OTHER_COLLECTION, ['row-9']);

        $this->assertSame(
            [self::COLLECTION => ['row-1'], self::OTHER_COLLECTION => ['row-9']],
            $map->rowsOfNode(self::NODE),
        );
    }

    /**
     * Ownership of a row can move between nodes — a fleet member takes over what another one
     * dropped — and the newest frame is what says who keeps it up to date now.
     */
    public function testARowArrivingFromAnotherNodeMovesToIt(): void
    {
        $map = new RtReplicaOriginMap();
        $map->note(self::NODE, self::COLLECTION, ['row-1']);

        $map->note(self::OTHER_NODE, self::COLLECTION, ['row-1']);

        $this->assertSame(self::OTHER_NODE, $map->nodeOfRow(self::COLLECTION, 'row-1'));
        $this->assertSame([], $map->rowsOfNode(self::NODE));
    }

    /**
     * A row its owner deleted is gone from here too, and what was known about it goes with it.
     * Kept, it would freeze on the next dropped link a row that no longer exists.
     */
    public function testAForgottenRowIsNoLongerAnyNodes(): void
    {
        $map = new RtReplicaOriginMap();
        $map->note(self::NODE, self::COLLECTION, ['row-1', 'row-2']);

        $map->forget(self::COLLECTION, 'row-1');

        $this->assertNull($map->nodeOfRow(self::COLLECTION, 'row-1'));
        $this->assertSame([self::COLLECTION => ['row-2']], $map->rowsOfNode(self::NODE));
    }

    /**
     * A node this one holds no replica of has nothing to freeze, and says so with an empty
     * answer rather than with a collection holding no rows.
     */
    public function testANodeThatWroteNothingHereHasNoRows(): void
    {
        $map = new RtReplicaOriginMap();
        $map->note(self::NODE, self::COLLECTION, ['row-1']);
        $map->forget(self::COLLECTION, 'row-1');

        $this->assertSame([], $map->rowsOfNode(self::NODE));
        $this->assertSame([], $map->rowsOfNode(self::OTHER_NODE));
    }

    public function testChangingOriginsKeepsOriginalCollectionAndRowPositions(): void
    {
        $map = new RtReplicaOriginMap();
        $map->note(self::OTHER_NODE, self::COLLECTION, ['first', 'second']);
        $map->note(self::NODE, self::OTHER_COLLECTION, ['last']);
        $map->note(self::NODE, self::COLLECTION, ['second']);
        $map->note(self::NODE, self::COLLECTION, ['first', 'first']);
        $this->assertSame([self::COLLECTION => ['first', 'second'], self::OTHER_COLLECTION => ['last']], $map->rowsOfNode(self::NODE));
        $this->assertSame([], $map->rowsOfNode(self::OTHER_NODE));

        $map->note(self::OTHER_NODE, self::COLLECTION, ['second']);
        $map->note(self::NODE, self::COLLECTION, ['second']);
        $this->assertSame([self::COLLECTION => ['first', 'second'], self::OTHER_COLLECTION => ['last']], $map->rowsOfNode(self::NODE));
        $map->forget(self::COLLECTION, 'first');
        $map->note(self::NODE, self::COLLECTION, ['first']);
        $this->assertSame([self::COLLECTION => ['second', 'first'], self::OTHER_COLLECTION => ['last']], $map->rowsOfNode(self::NODE));
        $map->forget(self::COLLECTION, 'first');
        $map->forget(self::COLLECTION, 'second');
        $map->note(self::NODE, self::COLLECTION, ['new']);
        $this->assertSame([self::OTHER_COLLECTION => ['last'], self::COLLECTION => ['new']], $map->rowsOfNode(self::NODE));
    }

    public function testNumericIdentifiersEmptyReportsAndUnknownDeparturesKeepTheirExactMeaning(): void
    {
        $map = new RtReplicaOriginMap();
        $map->note('3', '07', []);
        $map->note('3', '0', ['07', '0']);
        $map->note('3', '07', ['7']);
        $map->forget('unknown', 'unknown');
        $map->forget('0', '7');
        $this->assertSame(['0' => ['07', '0'], '07' => ['7']], $map->rowsOfNode('3'));
        $this->assertSame('3', $map->nodeOfRow('0', '07'));
        $this->assertNull($map->nodeOfRow('0', '7'));
        $map->forget('0', '07');
        $map->forget('0', '0');
        $map->forget('07', '7');
        $this->assertSame([], $map->rowsOfNode('3'));
        $this->assertNull($map->nodeOfRow('0', '0'));
    }

    public function testOriginAdmissionIsIndependentOfOlderRowPositionsAndNeverReturnsByAba(): void
    {
        $map = new RtReplicaOriginMap();
        $map->note(self::OTHER_NODE, self::COLLECTION, ['older']);
        $map->note(self::NODE, self::COLLECTION, ['original', 'returning', 'recreated']);
        $cutoff = $map->admissionCutoff();
        $map->note(self::NODE, self::COLLECTION, ['original']);
        $this->assertSame($cutoff, $map->admissionCutoff(), 'An ordinary fact from the same source preserves its admission');
        $map->note(self::NODE, self::COLLECTION, ['older']);
        $map->note(self::OTHER_NODE, self::COLLECTION, ['returning']);
        $map->note(self::NODE, self::COLLECTION, ['returning']);
        $map->forget(self::COLLECTION, 'recreated');
        $map->note(self::NODE, self::COLLECTION, ['recreated', 'new']);

        $this->assertSame(self::NODE, $map->nodeOfRow(self::COLLECTION, 'original', $cutoff));
        foreach (['older', 'returning', 'recreated', 'new', 'unknown'] as $id) {
            $this->assertNull($map->nodeOfRow(self::COLLECTION, $id, $cutoff), $id);
        }
        $this->assertSame(
            [self::COLLECTION => ['older', 'original', 'returning', 'recreated', 'new']],
            $map->rowsOfNode(self::NODE),
            'The live reverse index keeps original position order independently of finite admission',
        );
        $this->assertSame(self::NODE, $map->nodeOfRow(self::COLLECTION, 'older'));
        $this->assertGreaterThan($cutoff, $map->admissionCutoff());
    }
}
