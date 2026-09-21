<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupHistoryScanner;
use Hilos\Backup\Ship\BackupShipTarget;
use Hilos\Backup\Ship\LocalBackupShipper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the local-directory driver.
 *
 * Same rsync as the ssh driver without the remote half, which is what lets a mounted network
 * share be a destination without a driver of its own.
 */
final class LocalBackupShipperTest extends TestCase
{
    public function testPushCarriesNoTransportArgument(): void
    {
        $command = $this->shipper()->pushCommand('/var/backups/full/20260816-full-01.tar.gz', 'full');

        $this->assertSame('rsync', $command->binary);
        $this->assertSame([
            '-a',
            '--partial-dir=.tmp-ship-partial',
            '/var/backups/full/20260816-full-01.tar.gz',
            '/mnt/nas/backups/full/',
        ], $command->args);
        $this->assertNotContains('-e', $command->args);
    }

    public function testMirrorOnlyDeletesAndSendsTheDirectoryItself(): void
    {
        $base = '2026-09-20_14-30-05-prod-full';
        $command = $this->shipper()->mirrorCommand('/var/backups/schema-only', 'schema-only', [$base]);

        $this->assertSame([
            '-r',
            '--delete',
            '--existing',
            '--ignore-existing',
            '--include=/' . $base . BackupHistoryScanner::ARCHIVE_EXTENSION,
            '--include=/' . $base . BackupHistoryScanner::SIDECAR_EXTENSION,
            '--exclude=*',
            '/var/backups/schema-only/',
            '/mnt/nas/backups/schema-only/',
        ], $command->args);
    }

    public function testMirrorLeavesUnpublishedArtifactsAtHomeAndKeepsNothingPartial(): void
    {
        // One --exclude=* after the includes is the protection that used to be two private
        // excludes: a live backup's work directory never matches an include, and neither does
        // the resume directory a concurrent push still uses.
        $args = $this->shipper()->mirrorCommand('/var/backups/full', 'full', ['owed'])->args;

        $this->assertContains('--exclude=*', $args);
        $this->assertNotContains('--partial', $args);
        $this->assertNotContains('--partial-dir=' . LocalBackupShipper::PARTIAL_DIR, $args);
        $this->assertNotContains('--exclude=' . LocalBackupShipper::PARTIAL_DIR, $args);
    }

    public function testMirrorWritesNothingAtAll(): void
    {
        // It is the deletion half and only that: the push steps do the copying and the index
        // repeats them until they land. A pass that also re-stated the directory would overwrite
        // ciphertext with the plaintext of the same name, which rsync's quick check cannot tell
        // apart - same name, same mtime, and a size that need not differ enough to notice.
        $args = $this->shipper()->mirrorCommand('/var/backups/full', 'full', ['owed'])->args;

        $this->assertContains('--existing', $args);
        $this->assertContains('--ignore-existing', $args);
        // `-a` is about the attributes of what is copied, and nothing is copied any more.
        $this->assertNotContains('-a', $args);
    }

    public function testEachScopeGetsItsOwnDirectoryOnTheReceiver(): void
    {
        // The destination mirrors the local layout, so a restore from the copy finds the archive
        // where the scanner already looks for it.
        $shipper = $this->shipper();

        $this->assertContains('/mnt/nas/backups/full/', $shipper->pushCommand('/a.tar.gz', 'full')->args);
        $this->assertContains(
            '/mnt/nas/backups/schema-seed/',
            $shipper->pushCommand('/a.tar.gz', 'schema-seed')->args,
        );
    }

    /**
     * @return LocalBackupShipper Driver over a local destination directory
     */
    private function shipper(): LocalBackupShipper
    {
        $target = BackupShipTarget::parse('file:///mnt/nas/backups');
        $this->assertNotNull($target);

        return new LocalBackupShipper($target);
    }
}
