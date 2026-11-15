<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Daemon;

use Hilos\Core\Daemon\RtCopyConfirmation;
use PHPUnit\Framework\TestCase;

/**
 * The life of a master's copy of a collection it reads: awaited from the announcement that adds it
 * until every node the announcement reached answered it, or left; and the workers that took the
 * collection up meanwhile, handed over once it is confirmed (HIL-1817).
 */
final class RtCopyConfirmationTest extends TestCase
{
    private const string ROWS = 'rows';

    private const string OTHER = 'other';

    private const string NODE_B = 'node-b';

    private const string NODE_C = 'node-c';

    public function testOffTheMeshEveryWorkerIsAdmittedAtOnce(): void
    {
        $confirmation = new RtCopyConfirmation();

        $this->assertTrue($confirmation->admits(self::ROWS, 1, 1));
        $this->assertSame([], $confirmation->takeWaitingWorkers(self::ROWS));
    }

    public function testAWorkerTakingUpACollectionNotYetAnnouncedWaitsForItsConfirmation(): void
    {
        $confirmation = new RtCopyConfirmation();
        $confirmation->awaitPeers(true);

        $this->assertFalse($confirmation->admits(self::ROWS, 1, 1));
        $announcement = $confirmation->announce([self::ROWS], [self::ROWS]);
        $this->assertTrue($confirmation->isAwaited(self::ROWS), 'Awaited before the announcement is addressed');
        $this->assertSame([], $confirmation->addressed($announcement, [self::NODE_B, self::NODE_C]));

        $this->assertSame([], $confirmation->noteAnswered(self::NODE_B, $announcement));
        $this->assertSame([self::ROWS], $confirmation->noteAnswered(self::NODE_C, $announcement));
        $this->assertSame([1 => 1], $confirmation->takeWaitingWorkers(self::ROWS), 'By worker, with the report it waited with');
        $this->assertTrue($confirmation->admits(self::ROWS, 2, 1), 'A confirmed copy admits the next worker at once');
    }

    public function testAnAnnouncementThatReachedNobodyConfirmsAtOnce(): void
    {
        $confirmation = new RtCopyConfirmation();
        $confirmation->awaitPeers(true);
        $announcement = $confirmation->announce([self::ROWS], [self::ROWS]);

        $this->assertSame([self::ROWS], $confirmation->addressed($announcement, []));
        $this->assertSame([], $confirmation->takeFirstConfirmed([self::ROWS]), 'Nobody was asked, so nobody stayed silent');
    }

    public function testAnAnswerToAnEarlierAnnouncementStrikesNobody(): void
    {
        $confirmation = new RtCopyConfirmation();
        $confirmation->awaitPeers(true);
        $first = $confirmation->addressed($confirmation->announce([self::OTHER], []), [self::NODE_B]);
        $second = $confirmation->announce([self::OTHER, self::ROWS], [self::ROWS]);
        $confirmation->addressed($second, [self::NODE_B]);

        $this->assertSame([], $first);
        $this->assertSame([], $confirmation->noteAnswered(self::NODE_B, $second - 1));
        $this->assertSame([self::ROWS], $confirmation->noteAnswered(self::NODE_B, $second));
    }

    public function testACollectionNoLongerReadForgetsItsWaitAndItsWorkers(): void
    {
        $confirmation = new RtCopyConfirmation();
        $confirmation->awaitPeers(true);
        $confirmation->addressed($confirmation->announce([self::ROWS], [self::ROWS]), [self::NODE_B]);
        $confirmation->admits(self::ROWS, 1, 1);

        $confirmation->announce([], []);

        $this->assertFalse($confirmation->isAwaited(self::ROWS));
        $this->assertFalse($confirmation->isRead(self::ROWS));
        $this->assertSame([], $confirmation->takeWaitingWorkers(self::ROWS));
    }

    /**
     * Cancellations of two collections, of two nodes, before the announcement behind their requests is
     * taken await that one announcement; it is taken once, and only its answer ends each wait - a
     * snapshot published meanwhile ends none.
     */
    public function testCancellationsBeforeTheAnnouncementBehindTheirRequestsAwaitThatOneAnnouncement(): void
    {
        $confirmation = new RtCopyConfirmation();
        $confirmation->awaitPeers(true);
        $first = $confirmation->announce([self::ROWS, self::OTHER], [self::ROWS, self::OTHER]);
        $confirmation->addressed($first, [self::NODE_B, self::NODE_C]);

        $this->assertSame($first + 1, $confirmation->reopen(self::ROWS, self::NODE_B));
        $this->assertSame($first + 1, $confirmation->reopen(self::OTHER, self::NODE_C));
        $this->assertSame($first + 1, $confirmation->reannounce($first + 1));
        $this->assertNull($confirmation->reannounce($first + 1), 'The second cancellation makes no second announcement');
        $this->assertSame([self::ROWS, self::OTHER], $confirmation->readCollections(), 'Announced with the same list');

        $confirmation->notePublished(self::ROWS, self::NODE_B);
        $this->assertSame([], $confirmation->noteAnswered(self::NODE_B, $first));
        $this->assertSame([], $confirmation->noteAnswered(self::NODE_C, $first));
        $this->assertSame([self::ROWS], $confirmation->noteAnswered(self::NODE_B, $first + 1));
        $this->assertSame([self::OTHER], $confirmation->noteAnswered(self::NODE_C, $first + 1));
    }

    /**
     * A copy's first confirmation names it for its holders, a snapshot published or not; a confirmation
     * after a reopened wait is not a first one. A published snapshot is remembered until the
     * collection is added anew.
     */
    public function testOnlyTheFirstConfirmationAsksTheHolders(): void
    {
        $confirmation = new RtCopyConfirmation();
        $confirmation->awaitPeers(true);
        $first = $confirmation->announce([self::ROWS], [self::ROWS]);
        $confirmation->addressed($first, [self::NODE_B]);
        $confirmation->notePublished(self::ROWS, self::NODE_B);

        $this->assertSame([self::ROWS], $confirmation->takeFirstConfirmed($confirmation->noteAnswered(self::NODE_B, $first)));
        $reopened = $confirmation->reopen(self::ROWS, self::NODE_B);
        $confirmation->reannounce($reopened);
        $this->assertSame([], $confirmation->takeFirstConfirmed($confirmation->noteAnswered(self::NODE_B, $reopened)));
        $this->assertTrue($confirmation->wasHandedOver(self::ROWS, self::NODE_B));

        $confirmation->announce([], []);
        $confirmation->announce([self::ROWS], [self::ROWS]);
        $this->assertFalse($confirmation->wasHandedOver(self::ROWS, self::NODE_B), 'Added anew, the collection waits for a snapshot again');
    }

    /**
     * A published snapshot speaks for the node it came from alone: another node of the same collection,
     * whose snapshot never came, did not speak for its rows; and a snapshot of a collection not read here
     * is remembered for nobody.
     */
    public function testAPublishedSnapshotIsRememberedForTheNodeItCameFrom(): void
    {
        $confirmation = new RtCopyConfirmation();
        $confirmation->awaitPeers(true);
        $confirmation->announce([self::ROWS], [self::ROWS]);

        $confirmation->notePublished(self::ROWS, self::NODE_B);
        $confirmation->notePublished(self::OTHER, self::NODE_B);

        $this->assertTrue($confirmation->wasHandedOver(self::ROWS, self::NODE_B));
        $this->assertFalse($confirmation->wasHandedOver(self::ROWS, self::NODE_C), 'No snapshot came from that node');
        $this->assertFalse($confirmation->wasHandedOver(self::OTHER, self::NODE_B), 'The collection is not read here');
    }

    /**
     * An announcement taken in the ordinary way after a cancellation goes out behind its request as
     * well, so the reopened wait takes no announcement of its own; one not read here reopens nothing.
     */
    public function testAnAnnouncementTakenAfterTheCancellationServesItsWait(): void
    {
        $confirmation = new RtCopyConfirmation();
        $confirmation->awaitPeers(true);
        $first = $confirmation->announce([self::ROWS], [self::ROWS]);
        $confirmation->addressed($first, [self::NODE_B]);

        $reopened = $confirmation->reopen(self::ROWS, self::NODE_B);
        $confirmation->announce([self::ROWS, self::OTHER], [self::OTHER]);

        $this->assertNull($confirmation->reannounce($reopened));
        $this->assertNull($confirmation->reopen('unread', self::NODE_B));
        $this->assertSame([self::ROWS], $confirmation->noteAnswered(self::NODE_B, $reopened));
    }
}
