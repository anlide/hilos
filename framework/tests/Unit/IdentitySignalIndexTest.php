<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Daemon\IdentitySignalEntry;
use Hilos\Core\Daemon\IdentitySignalIndex;
use PHPUnit\Framework\TestCase;

/** Parked-to-ready transitions retain their original finite cancellation membership. */
final class IdentitySignalIndexTest extends TestCase
{
    public function testAnOlderPageCleanupCannotClearANewerConnectionInvalidation(): void
    {
        $settled = [];
        $index = new IdentitySignalIndex(static function (IdentitySignalEntry $entry) use (&$settled): void {
            $settled[] = [$entry->id, $entry->releaseId];
        });
        $index->add(1, 'socket', 'p');
        $index->release(1, 1);
        $original = $index->releasedEntry(1);
        self::assertNotNull($original);
        $older = $index->cancelConnection('socket', 'p');
        self::assertTrue($index->isCanceled($original));
        $index->add(2, 'socket', 'p');
        $middle = $index->get(2);
        self::assertFalse($index->isCanceled($middle));
        $newer = $index->cancelConnection('socket');
        $index->add(3, 'socket', 'q');
        $replacement = $index->get(3);
        self::assertFalse($index->isCanceled($replacement));
        iterator_to_array($older);
        self::assertNull($index->releasedEntry(1));
        self::assertTrue($index->isCanceled($middle));
        $index->release(2, 2);
        self::assertNull($index->get(2), 'A ready transition must settle an already canceled original');
        iterator_to_array($newer);
        self::assertSame([[1, 1], [2, 2]], $settled);
        self::assertFalse($index->isCanceled($replacement));
        self::assertSame([3], iterator_to_array($index->parkedCandidates(), false));
        $index->release(3, 3);
        self::assertSame($replacement, $index->releasedEntry(3));
        self::assertSame([], iterator_to_array($index->parkedCandidates(), false));
        $index->settle(3);
        self::assertSame([[1, 1], [2, 2], [3, 3]], $settled);
    }

    public function testAnEmptyCancellationCannotCaptureAFutureSamePageWait(): void
    {
        $settled = [];
        $index = new IdentitySignalIndex(static function (IdentitySignalEntry $entry) use (&$settled): void {
            $settled[] = $entry->id;
        });
        $cleanup = $index->cancelConnection('socket', 'p');
        $index->add(1, 'socket', 'p');
        iterator_to_array($cleanup);
        self::assertSame([], $settled);
        self::assertFalse($index->isCanceled($index->get(1)));
        self::assertSame([1], iterator_to_array($index->parkedCandidates(), false));
    }
}
