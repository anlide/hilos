<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Ship\BackupShipTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for parsing the one destination an installation ships to.
 *
 * Every malformed case here has to end in null rather than an exception: the destination is read
 * on a tick of the monopoly backup agent, so a throw would take the schedule and the restore down
 * over a typo in a deployment value.
 */
final class BackupShipTargetTest extends TestCase
{
    public function testSshUrlCarriesLoginHostPortAndPath(): void
    {
        $target = BackupShipTarget::parse('ssh://backup@receiver.example:2222/srv/backups');

        $this->assertNotNull($target);
        $this->assertSame(BackupShipTarget::SCHEME_SSH, $target->scheme);
        $this->assertSame('backup', $target->user);
        $this->assertSame('receiver.example', $target->host);
        $this->assertSame(2222, $target->port);
        $this->assertSame('/srv/backups', $target->path);
    }

    public function testSshUrlWithoutAPortFallsBackToTheDefaultOne(): void
    {
        $target = BackupShipTarget::parse('ssh://backup@receiver.example/srv/backups');

        $this->assertNotNull($target);
        $this->assertSame(BackupShipTarget::DEFAULT_SSH_PORT, $target->port);
    }

    public function testFileUrlCarriesOnlyThePath(): void
    {
        $target = BackupShipTarget::parse('file:///mnt/nas/backups');

        $this->assertNotNull($target);
        $this->assertSame(BackupShipTarget::SCHEME_FILE, $target->scheme);
        $this->assertSame('', $target->user);
        $this->assertSame('', $target->host);
        $this->assertSame(0, $target->port);
        $this->assertSame('/mnt/nas/backups', $target->path);
    }

    public function testATrailingSlashIsTrimmedSoOneDestinationHasOneSpelling(): void
    {
        // The drivers append '/<scope>/' themselves; keeping the slash here would double it.
        $this->assertSame('/srv/backups', BackupShipTarget::parse('file:///srv/backups/')?->path);
        $this->assertSame(
            '/srv/backups',
            BackupShipTarget::parse('ssh://backup@receiver.example/srv/backups/')?->path,
        );
    }

    public function testSurroundingWhitespaceIsIgnored(): void
    {
        // Deployment values arrive from .env files and env vars, where a stray space is invisible.
        $this->assertNotNull(BackupShipTarget::parse("  file:///srv/backups\n"));
    }

    #[DataProvider('malformedUrls')]
    public function testAMalformedOrEmptyUrlTurnsShippingOff(string $url, string $why): void
    {
        $this->assertNull(BackupShipTarget::parse($url), $why);
    }

    /**
     * @return array<string, array{string, string}> Destination URL and why it names nothing shippable
     */
    public static function malformedUrls(): array
    {
        return [
            'empty' => ['', 'the documented "shipping off" value'],
            'whitespace only' => ['   ', 'an env value that looks set but is not'],
            'unknown scheme' => ['s3://bucket/prefix', 'no driver serves it in this leaf'],
            'no scheme' => ['receiver.example:/srv/backups', 'nothing says how to reach it'],
            'ssh without a login' => [
                'ssh://receiver.example/srv/backups',
                'rsync would fall back to the daemon account, which is not the one the receiver knows',
            ],
            'ssh without a host' => ['ssh://backup@/srv/backups', 'there is nobody to reach'],
            'ssh without a path' => ['ssh://backup@receiver.example', 'no destination directory'],
            'file without a path' => ['file://', 'no destination directory'],
            'relative path' => [
                'file://srv/backups',
                'a relative destination resolves against whatever directory the transfer started in',
            ],
            'root only' => ['file:///', 'the whole filesystem is not a backup destination'],
        ];
    }
}
