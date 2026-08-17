<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\ArchiveMigrationMarker;
use Hilos\Backup\Exception\RestoreFailedException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the migration level a schema archive declares in its dump text.
 *
 * The fixtures are shaped the way mysqldump writes a `migration` block, because that shape is
 * the reader's whole premise - in particular the inherited `COMMENT=` clause a dump taken after
 * a restore carries inside `CREATE TABLE`, which is the one thing that must never be read as
 * the level.
 */
final class ArchiveMigrationMarkerTest extends TestCase
{
    public function testTheStampedStatementIsReadBackAsTheLevelItStamped(): void
    {
        $this->assertSame(7, ArchiveMigrationMarker::parse(ArchiveMigrationMarker::statement(7)));
    }

    public function testTheInheritedCommentOfAPreviousLevelIsNotTheLevel(): void
    {
        // A backup taken after a restore: mysqldump re-emits the comment the restore left on the
        // table, so the block says 3 while the database is actually at 9. Only the appended
        // statement counts - the block is an echo of where this schema came from.
        $level = ArchiveMigrationMarker::parse(
            "DROP TABLE IF EXISTS `migration`;\n"
            . "CREATE TABLE `migration` (\n"
            . "  `index` int(10) unsigned NOT NULL,\n"
            . "  `failed` tinyint(1) NOT NULL DEFAULT '1',\n"
            . "  PRIMARY KEY (`index`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='hilos-migration-index=3';\n"
            . ArchiveMigrationMarker::statement(9),
        );

        $this->assertSame(9, $level);
    }

    public function testADumpWithoutAMarkerDeclaresNoLevel(): void
    {
        // An archive written before the marker existed: the restore has to tell this from a
        // level, because "no level" is what it refuses on and 0 is a level it accepts.
        $level = ArchiveMigrationMarker::parse(
            "CREATE TABLE `migration` (\n"
            . "  `index` int(10) unsigned NOT NULL,\n"
            . "  PRIMARY KEY (`index`)\n"
            . ") ENGINE=InnoDB;\n",
        );

        $this->assertNull($level);
    }

    public function testAnUnrelatedTableCommentIsNotAMarker(): void
    {
        $level = ArchiveMigrationMarker::parse(
            "ALTER TABLE `migration` COMMENT='written by hand';\n",
        );

        $this->assertNull($level);
    }

    public function testAMarkerOnAnotherTableIsNotAMarker(): void
    {
        // The level belongs to the `migration` table of this connection; the same comment on a
        // neighbouring table is somebody else's string, not a second opinion about the level.
        $level = ArchiveMigrationMarker::parse(
            "ALTER TABLE `chat_message` COMMENT='" . ArchiveMigrationMarker::COMMENT_PREFIX . "4';\n",
        );

        $this->assertNull($level);
    }

    public function testLevelZeroIsALevelAndNotAMissingMarker(): void
    {
        // A database that never migrated is stamped too: "not recorded" and "recorded as 0" are
        // the distinction the whole refusal stands on.
        $this->assertSame(0, ArchiveMigrationMarker::parse(ArchiveMigrationMarker::statement(0)));
    }

    public function testTheLastMarkerWins(): void
    {
        // Belt and braces for the restore-then-backup cycle: whatever else a dump accumulated,
        // the level is the one the create path appended last.
        $sql = ArchiveMigrationMarker::statement(2) . ArchiveMigrationMarker::statement(5);

        $this->assertSame(5, ArchiveMigrationMarker::parse($sql));
    }

    public function testTheStreamedFileReadSeesWhatTheTextParseSees(): void
    {
        // Production only ever calls read(), which walks the file a line at a time rather than
        // holding it; every other case here goes through parse(), so without this one the
        // streaming loop would be uncovered.
        $sql = "CREATE TABLE `migration` (\n"
            . "  `index` int(10) unsigned NOT NULL,\n"
            . "  PRIMARY KEY (`index`)\n"
            . ") ENGINE=InnoDB;\n"
            . ArchiveMigrationMarker::statement(11);
        $path = (string)tempnam(sys_get_temp_dir(), 'hilos-marker-');
        file_put_contents($path, $sql);

        try {
            $streamed = ArchiveMigrationMarker::read($path);
        } finally {
            unlink($path);
        }

        $this->assertSame(11, $streamed);
        $this->assertSame(ArchiveMigrationMarker::parse($sql), $streamed);
    }

    public function testAMissingDumpFileIsARefusal(): void
    {
        $this->expectException(RestoreFailedException::class);

        ArchiveMigrationMarker::read(sys_get_temp_dir() . '/hilos-no-such-dump-' . getmypid() . '.sql');
    }
}
