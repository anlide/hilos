<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupCreator;
use Hilos\Backup\Ship\BackupShipTarget;
use Hilos\Backup\Ship\SshBackupShipper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the rsync-over-ssh driver.
 *
 * Asserted argument by argument on purpose: the driver builds commands and never runs them, so
 * this is the only place a wrong flag can be caught without a receiver, and the arguments are the
 * whole of what the driver does.
 */
final class SshBackupShipperTest extends TestCase
{
    public function testPushSpellsOutEveryArgumentTheReceiverWillSee(): void
    {
        $command = $this->shipper()->pushCommand('/var/backups/full/20260816-full-01.tar.gz', 'full');

        $this->assertSame('rsync', $command->binary);
        $this->assertSame([
            '-a',
            '--partial-dir=.tmp-ship-partial',
            '-e',
            'ssh -i /etc/hilos/ship.key -o UserKnownHostsFile=/etc/hilos/known_hosts '
            . '-o StrictHostKeyChecking=yes -p 2222',
            '/var/backups/full/20260816-full-01.tar.gz',
            'backup@receiver.example:/srv/backups/full/',
        ], $command->args);
    }

    public function testMirrorOnlyDeletesAndSendsTheDirectoryItself(): void
    {
        $command = $this->shipper()->mirrorCommand('/var/backups/full', 'full');

        $this->assertSame('rsync', $command->binary);
        $this->assertSame([
            '-r',
            '--delete',
            '--existing',
            '--ignore-existing',
            '--exclude=.tmp-*',
            '--exclude=.tmp-ship-partial',
            '-e',
            'ssh -i /etc/hilos/ship.key -o UserKnownHostsFile=/etc/hilos/known_hosts '
            . '-o StrictHostKeyChecking=yes -p 2222',
            // The trailing slash is what makes rsync mirror the CONTENTS of the directory into the
            // destination rather than nest a second 'full/' inside it.
            '/var/backups/full/',
            'backup@receiver.example:/srv/backups/full/',
        ], $command->args);
    }

    public function testAMirrorLeavesTheStoresUnpublishedArtifactsAtHome(): void
    {
        // A scope directory is not quiet while a backup is being taken, and shipping has its own
        // process slot: without this the pass would send a raw uncompressed dump across the link.
        $this->assertContains(
            '--exclude=' . BackupCreator::TEMP_PREFIX . '*',
            $this->shipper()->mirrorCommand('/var/backups/full', 'full')->args,
        );
    }

    public function testAMirrorKeepsNothingPartialWhereTheReceiverCanSeeIt(): void
    {
        // A mirror walks names, so '.json' crosses before '.tar.gz': kept partial data would put a
        // complete sidecar beside a truncated archive. A push may resume; a mirror may not.
        $mirror = $this->shipper()->mirrorCommand('/var/backups/full', 'full')->args;
        $push = $this->shipper()->pushCommand('/var/backups/full/a.tar.gz', 'full')->args;

        $this->assertNotContains('--partial-dir=' . SshBackupShipper::PARTIAL_DIR, $mirror);
        $this->assertContains('--partial-dir=' . SshBackupShipper::PARTIAL_DIR, $push);
        // A resume that parks the fragment under the REAL name is what the staging directory
        // replaces: a push now overwrites a copy whose sidecar is already there, so an interrupted
        // one would leave a truncated archive wearing a complete passport.
        $this->assertNotContains('--partial', $push);
    }

    public function testAMirrorWritesNothingAtAll(): void
    {
        // The deletion half and only that. Re-stating the directory would also be wrong rather
        // than merely redundant now: a receiver holding ciphertext of one recipient set and a
        // local archive in the clear share a name, and rsync's quick check looks at nothing that
        // tells them apart.
        $args = $this->shipper()->mirrorCommand('/var/backups/full', 'full')->args;

        $this->assertContains('--existing', $args);
        $this->assertContains('--ignore-existing', $args);
        // `-a` is about the attributes of what is copied, and nothing is copied any more.
        $this->assertNotContains('-a', $args);
    }

    public function testAnUnconfiguredKeyLeavesTheIdentityOutRatherThanPassingAnEmptyOne(): void
    {
        // The factory allows an empty key on purpose (an agent-forwarded or default identity), and
        // rsync splits this value on whitespace - an empty `-i` would swallow the `-o` behind it.
        $target = BackupShipTarget::parse('ssh://backup@receiver.example/srv/backups');
        $this->assertNotNull($target);

        $transport = new SshBackupShipper($target, '', '/etc/hilos/known_hosts')
            ->pushCommand('/var/backups/full/a.tar.gz', 'full')
            ->args[3];

        $this->assertSame(
            'ssh -o UserKnownHostsFile=/etc/hilos/known_hosts -o StrictHostKeyChecking=yes -p 22',
            $transport,
        );
    }

    public function testASourceDirectoryAlreadyEndingInASlashIsNotDoubled(): void
    {
        $command = $this->shipper()->mirrorCommand('/var/backups/full/', 'full');

        $this->assertContains('/var/backups/full/', $command->args);
        $this->assertNotContains('/var/backups/full//', $command->args);
    }

    public function testHostKeyCheckingIsPinnedOnAndNotNegotiable(): void
    {
        // The payload is a dump of the whole database: an unverified receiver is not a lesser
        // evil than no copy at all, so no branch of this driver may relax the check - not even
        // the one where the copy that leaves is ciphertext.
        $transport = $this->shipper()->pushCommand('/var/backups/full/a.tar.gz', 'full')->args[3];

        $this->assertStringContainsString('-o StrictHostKeyChecking=yes', $transport);
        $this->assertStringContainsString('-o UserKnownHostsFile=/etc/hilos/known_hosts', $transport);
    }

    public function testTheDefaultPortIsSpelledOutRatherThanLeftToSshsOwnConfiguration(): void
    {
        $target = BackupShipTarget::parse('ssh://backup@receiver.example/srv/backups');
        $this->assertNotNull($target);

        $transport = new SshBackupShipper($target, '/k', '/kh')
            ->pushCommand('/var/backups/full/a.tar.gz', 'full')
            ->args[3];

        $this->assertStringContainsString('-p 22', $transport);
    }

    /**
     * @return SshBackupShipper Driver over a fully spelled-out destination
     */
    private function shipper(): SshBackupShipper
    {
        $target = BackupShipTarget::parse('ssh://backup@receiver.example:2222/srv/backups');
        $this->assertNotNull($target);

        return new SshBackupShipper($target, '/etc/hilos/ship.key', '/etc/hilos/known_hosts');
    }
}
