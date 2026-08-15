<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupPhase;
use Hilos\Backup\BackupProgressMarker;
use Hilos\Backup\RestorePhase;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the phase line protocol carried on the child's stdout.
 *
 * The reader is handed whatever one tick of the pipe produced, so every case here is about a chunk
 * that does not line up with the lines inside it: several announcements at once, an announcement
 * cut in half, and the child's own output mixed in between. The rule the whole channel rests on is
 * that anything unrecognized is dropped rather than guessed at.
 */
final class BackupProgressMarkerTest extends TestCase
{
    public function testAWholeLineIsRecognized(): void
    {
        $read = BackupProgressMarker::read(BackupProgressMarker::statement(BackupPhase::DUMPING->value));

        $this->assertSame([BackupPhase::DUMPING->value], $read->phases);
        $this->assertSame('', $read->tail);
    }

    public function testTwoAnnouncementsInOneChunkArriveInOrder(): void
    {
        $chunk = BackupProgressMarker::statement(RestorePhase::EXTRACTING->value)
            . BackupProgressMarker::statement(RestorePhase::IMPORTING->value);

        $read = BackupProgressMarker::read($chunk);

        $this->assertSame([RestorePhase::EXTRACTING->value, RestorePhase::IMPORTING->value], $read->phases);
    }

    public function testAnUnfinishedLineWaitsInTheTailAndIsReadOnTheNextChunk(): void
    {
        $whole = BackupProgressMarker::statement(BackupPhase::ARCHIVING->value);
        $cut = 5;

        $first = BackupProgressMarker::read(substr($whole, 0, $cut));
        $this->assertSame([], $first->phases);
        $this->assertSame(substr($whole, 0, $cut), $first->tail);

        $second = BackupProgressMarker::read($first->tail . substr($whole, $cut));
        $this->assertSame([BackupPhase::ARCHIVING->value], $second->phases);
        $this->assertSame('', $second->tail);
    }

    public function testTheChildsOwnOutputIsIgnored(): void
    {
        $chunk = "Backup created: /var/backups/full-2026-08-15.tar.gz\n"
            . BackupProgressMarker::statement(BackupPhase::PUBLISHING->value)
            . "done\n";

        $read = BackupProgressMarker::read($chunk);

        $this->assertSame([BackupPhase::PUBLISHING->value], $read->phases);
        $this->assertSame('', $read->tail);
    }

    public function testAnUnknownPhaseTokenIsDropped(): void
    {
        $read = BackupProgressMarker::read(BackupProgressMarker::statement('teleporting'));

        $this->assertSame([], $read->phases);
    }

    public function testACarriageReturnBeforeTheLineBreakDoesNotHideThePhase(): void
    {
        $read = BackupProgressMarker::read(BackupProgressMarker::PREFIX . ' ' . RestorePhase::MIGRATING->value . "\r\n");

        $this->assertSame([RestorePhase::MIGRATING->value], $read->phases);
    }
}
