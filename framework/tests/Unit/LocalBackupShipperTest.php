<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupCreator;
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
        $command = $this->shipper()->mirrorCommand('/var/backups/schema-only', 'schema-only');

        $this->assertSame([
            '-r',
            '--delete',
            '--existing',
            '--ignore-existing',
            '--exclude=.tmp-*',
            '--exclude=.tmp-ship-partial',
            '/var/backups/schema-only/',
            '/mnt/nas/backups/schema-only/',
        ], $command->args);
    }

    public function testMirrorLeavesUnpublishedArtifactsAtHomeAndKeepsNothingPartial(): void
    {
        // The same two rules as the ssh driver, and for the same reasons: a mirror running beside
        // a live backup must not carry its work directory, and must not leave a half-written file
        // where the receiver can read it.
        $args = $this->shipper()->mirrorCommand('/var/backups/full', 'full')->args;

        $this->assertContains('--exclude=' . BackupCreator::TEMP_PREFIX . '*', $args);
        $this->assertNotContains('--partial', $args);
        $this->assertNotContains('--partial-dir=' . LocalBackupShipper::PARTIAL_DIR, $args);
    }

    public function testMirrorWritesNothingAtAll(): void
    {
        // It is the deletion half and only that: the push steps do the copying and the index
        // repeats them until they land. A pass that also re-stated the directory would overwrite
        // ciphertext with the plaintext of the same name, which rsync's quick check cannot tell
        // apart - same name, same mtime, and a size that need not differ enough to notice.
        $args = $this->shipper()->mirrorCommand('/var/backups/full', 'full')->args;

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
