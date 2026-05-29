<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Utils\Helpers\FileSystemHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FileSystemHelper filesystem utilities.
 */
final class FileSystemHelperTest extends TestCase
{
    public function testScandirOrFalseReturnsEntriesForExistingDirectory(): void
    {
        $directory = $this->createTempDirectory();
        $sampleFile = $directory . DIRECTORY_SEPARATOR . 'sample.txt';
        file_put_contents($sampleFile, 'x');

        try {
            $entries = FileSystemHelper::scandirOrFalse($directory);

            $this->assertIsArray($entries);
            $this->assertContains('.', $entries);
            $this->assertContains('..', $entries);
            $this->assertContains('sample.txt', $entries);
        } finally {
            unlink($sampleFile);
            rmdir($directory);
        }
    }

    public function testScandirOrFalseReturnsFalseForMissingDirectory(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-fs-missing-' . uniqid('', true);

        $this->assertFalse(FileSystemHelper::scandirOrFalse($path));
    }

    public function testScandirOrFalseReturnsFalseForFilePath(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'hilos-fs-file-');
        $this->assertNotFalse($file);

        try {
            $this->assertFalse(FileSystemHelper::scandirOrFalse($file));
        } finally {
            unlink($file);
        }
    }

    public function testNormalizeBasenameLowercasesTrimmedBasename(): void
    {
        $this->assertSame('photo.jpg', FileSystemHelper::normalizeBasename('  Photo.JPG  '));
    }

    public function testNormalizeBasenameUsesBasenameFromPath(): void
    {
        $this->assertSame('photo.jpg', FileSystemHelper::normalizeBasename('uploads/subdir/Photo.JPG'));
    }

    public function testNormalizeBasenameStripsNullBytesBeforeBasename(): void
    {
        $this->assertSame('evil.txt', FileSystemHelper::normalizeBasename("safe\0/evil.txt"));
    }

    private function createTempDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-fs-test-' . uniqid('', true);
        $this->assertTrue(mkdir($directory));

        return $directory;
    }
}
