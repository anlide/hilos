<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Runtime\RtStaleness;
use PHPUnit\Framework\TestCase;

/**
 * What this process holds a frozen copy of, and since when (HIL-711).
 *
 * A replica whose owner became unreachable is still served — an empty answer is no truer than a
 * stale one — so what changes is that the row now answers "is my source still reachable". The
 * store is where that answer lives, in the master and in every worker alike, and it is the only
 * way to ask: the mark is kept BESIDE the rows, since a housekeeping field inside one would
 * travel into the browser's projection and into every snapshot diff.
 */
final class RtStalenessTest extends TestCase
{
    /** @var string Collection the cases freeze rows of */
    private const string COLLECTION = 'workerStatuses';

    /** @var string A second collection, for the cases about what the whole process holds */
    private const string OTHER_COLLECTION = 'jobs';

    /** @var float Microtime a link is lost at */
    private const float EARLIER = 1000.0;

    /** @var float Microtime a second link is lost at, later */
    private const float LATER = 2000.0;

    protected function tearDown(): void
    {
        RtStaleness::reset();

        parent::tearDown();
    }

    public function testAnUnmarkedRowIsFresh(): void
    {
        $this->assertNull(RtStaleness::staleSince(self::COLLECTION, 'row-1'));
    }

    public function testAMarkedRowAnswersWithTheMomentItFroze(): void
    {
        RtStaleness::mark(self::COLLECTION, ['row-1'], self::EARLIER);

        $this->assertSame(self::EARLIER, RtStaleness::staleSince(self::COLLECTION, 'row-1'));
    }

    /**
     * The mark is per row because one collection has several remote owners (HIL-589), so
     * freezing one owner's rows must leave its neighbours' rows alone.
     */
    public function testMarkingSomeRowsLeavesTheOthersFresh(): void
    {
        RtStaleness::mark(self::COLLECTION, ['row-1'], self::EARLIER);

        $this->assertNull(RtStaleness::staleSince(self::COLLECTION, 'row-2'));
    }

    /**
     * Asked about the collection, the answer is the OLDEST moment among its frozen rows: what a
     * reader of a collection wants to know is how out of date the worst of it may be.
     */
    public function testACollectionAnswersWithTheEarliestOfItsFrozenRows(): void
    {
        RtStaleness::mark(self::COLLECTION, ['row-1'], self::LATER);
        RtStaleness::mark(self::COLLECTION, ['row-2'], self::EARLIER);

        $this->assertSame(self::EARLIER, RtStaleness::staleSince(self::COLLECTION));
    }

    public function testACollectionWithNothingFrozenIsFresh(): void
    {
        $this->assertNull(RtStaleness::staleSince(self::COLLECTION));
    }

    /**
     * A second link dropping cannot make a row younger: the reader stopped hearing about it when
     * the FIRST of its sources went away, and that is the age it has been out of date for.
     */
    public function testARowAlreadyFrozenKeepsTheMomentItFirstFroze(): void
    {
        RtStaleness::mark(self::COLLECTION, ['row-1'], self::EARLIER);

        RtStaleness::mark(self::COLLECTION, ['row-1'], self::LATER);

        $this->assertSame(self::EARLIER, RtStaleness::staleSince(self::COLLECTION, 'row-1'));
    }

    public function testAClearedRowIsFreshAgain(): void
    {
        RtStaleness::mark(self::COLLECTION, ['row-1', 'row-2'], self::EARLIER);

        RtStaleness::clear(self::COLLECTION, ['row-1']);

        $this->assertNull(RtStaleness::staleSince(self::COLLECTION, 'row-1'));
        $this->assertSame(self::EARLIER, RtStaleness::staleSince(self::COLLECTION, 'row-2'));
    }

    public function testTheFrozenRowsOfACollectionAreNamedWithTheirMoments(): void
    {
        RtStaleness::mark(self::COLLECTION, ['row-1'], self::EARLIER);
        RtStaleness::mark(self::COLLECTION, ['row-2'], self::LATER);

        $this->assertSame(
            ['row-1' => self::EARLIER, 'row-2' => self::LATER],
            RtStaleness::staleRows(self::COLLECTION),
        );
    }

    public function testACollectionWithNothingFrozenNamesNoRows(): void
    {
        $this->assertSame([], RtStaleness::staleRows(self::COLLECTION));
    }

    /**
     * What a page is answered with is built over every collection it reads, so the store has to
     * be able to name them all at once rather than one lookup at a time.
     */
    public function testEveryFrozenCollectionIsNamedWithItsEarliestMoment(): void
    {
        RtStaleness::mark(self::COLLECTION, ['row-1'], self::LATER);
        RtStaleness::mark(self::COLLECTION, ['row-2'], self::EARLIER);
        RtStaleness::mark(self::OTHER_COLLECTION, ['row-9'], self::LATER);

        $this->assertSame(
            [self::COLLECTION => self::EARLIER, self::OTHER_COLLECTION => self::LATER],
            RtStaleness::staleCollections(),
        );
    }

    /**
     * A collection whose last frozen row was cleared drops out of the answer entirely, rather
     * than staying in it as an empty entry that reads as "something here is frozen".
     */
    public function testACollectionLeavesTheAnswerWhenItsLastFrozenRowIsCleared(): void
    {
        RtStaleness::mark(self::COLLECTION, ['row-1'], self::EARLIER);

        RtStaleness::clear(self::COLLECTION, ['row-1']);

        $this->assertSame([], RtStaleness::staleCollections());
    }
}
