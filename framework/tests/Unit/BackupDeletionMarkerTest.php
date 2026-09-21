<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupDeletionMarker;
use Hilos\Backup\BackupHistoryScanner;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the empty on-disk debt that a local delete leaves for the receiver.
 *
 * The name is the whole content: writing records a base, owed() reads those names back,
 * and files the scanner already counts (archives and sidecars) are not debts.
 */
final class BackupDeletionMarkerTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-deletion-marker-' . uniqid('', true);
        if (!mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            $this->fail("Could not create fixture directory: {$this->dir}");
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '{,.}*', GLOB_BRACE) ?: [] as $entry) {
            if (is_file($entry)) {
                unlink($entry);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    public function testTheMarkerNameIsTheBasePlusTheExtensionAndOwedReadsItBack(): void
    {
        $base = '2026-09-20_14-30-05-prod-full';
        $path = BackupDeletionMarker::path($this->dir, $base);

        $this->assertSame($this->dir . '/' . $base . BackupDeletionMarker::EXTENSION, $path);
        $this->assertTrue(BackupDeletionMarker::write($this->dir, $base));
        $this->assertFileExists($path);
        $this->assertSame('', file_get_contents($path));
        $this->assertSame([$base], BackupDeletionMarker::owed($this->dir));
    }

    public function testOwedIgnoresArchivesAndSidecarsInTheSameDirectory(): void
    {
        $base = 'kept-pair';
        file_put_contents($this->dir . '/' . $base . BackupHistoryScanner::ARCHIVE_EXTENSION, 'x');
        file_put_contents($this->dir . '/' . $base . BackupHistoryScanner::SIDECAR_EXTENSION, '{}');
        file_put_contents($this->dir . '/noise.txt', 'no');
        BackupDeletionMarker::write($this->dir, 'owed-one');

        $this->assertSame(['owed-one'], BackupDeletionMarker::owed($this->dir));
    }

    public function testClearRemovesOnlyTheNamedMarkersAndIsSilentWhenOneIsAlreadyGone(): void
    {
        BackupDeletionMarker::write($this->dir, 'keep');
        BackupDeletionMarker::write($this->dir, 'drop');

        BackupDeletionMarker::clear($this->dir, ['drop', 'already-gone']);

        $this->assertSame(['keep'], BackupDeletionMarker::owed($this->dir));
        $this->assertFileExists(BackupDeletionMarker::path($this->dir, 'keep'));
        $this->assertFileDoesNotExist(BackupDeletionMarker::path($this->dir, 'drop'));
    }

    public function testWriteReturnsFalseWhenTheFileCannotBeCreated(): void
    {
        $this->assertFalse(BackupDeletionMarker::write($this->dir . '/no-such-dir', 'x'));
    }
}
