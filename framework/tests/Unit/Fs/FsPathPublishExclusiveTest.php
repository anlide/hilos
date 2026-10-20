<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Fs;

use Hilos\Fs\Exception\FileAlreadyExistsException;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\FsPath;
use PHPUnit\Framework\TestCase;

/**
 * Exclusive publication exposes complete bytes and leaves a competing target untouched.
 */
final class FsPathPublishExclusiveTest extends TestCase
{
    private string $directory;
    private string $tmpPath;
    private string $finalPath;

    /**
     * Prepare a finished file on the target's filesystem.
     */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-publish-exclusive-' . uniqid('', true);
        $this->tmpPath = $this->directory . DIRECTORY_SEPARATOR . 'file.tmp';
        $this->finalPath = $this->directory . DIRECTORY_SEPARATOR . 'file';
        FsPath::ensureDirectory($this->directory);
        FsPath::write($this->tmpPath, "finished\0payload\n");
    }

    /**
     * Remove the files and target entries each test can leave behind.
     */
    protected function tearDown(): void
    {
        if (is_link($this->finalPath)) {
            unlink($this->finalPath);
        } elseif (is_dir($this->finalPath)) {
            FsPath::removeDirectory($this->finalPath);
        } else {
            FsPath::delete($this->finalPath);
        }
        FsPath::delete($this->tmpPath);
        FsPath::removeDirectory($this->directory);

        parent::tearDown();
    }

    /**
     * The final entry carries the whole payload and the prepared file's mode.
     */
    public function testPublishesTheFinishedFileAndRemovesTheTempName(): void
    {
        FsPath::chmod($this->tmpPath, 0640);

        FsPath::publishExclusive($this->tmpPath, $this->finalPath);

        $this->assertFileDoesNotExist($this->tmpPath);
        $this->assertSame("finished\0payload\n", FsPath::read($this->finalPath));
        $this->assertSame(0640, fileperms($this->finalPath) & 0777);
    }

    /**
     * A later publisher keeps its temp and cannot replace the first publisher's bytes.
     */
    public function testACompetingPublicationPreservesBothPayloads(): void
    {
        FsPath::publishExclusive($this->tmpPath, $this->finalPath);
        FsPath::write($this->tmpPath, 'competitor');

        try {
            FsPath::publishExclusive($this->tmpPath, $this->finalPath);
            $this->fail('An occupied target must refuse publication.');
        } catch (FileAlreadyExistsException $failure) {
            $this->assertSame("File already exists: {$this->finalPath}", $failure->getMessage());
            $this->assertSame("finished\0payload\n", FsPath::read($this->finalPath));
            $this->assertSame('competitor', FsPath::read($this->tmpPath));
        }
    }

    /**
     * An existing empty file is a conflict too.
     */
    public function testAnEmptyTargetIsPreserved(): void
    {
        FsPath::write($this->finalPath, '');

        try {
            FsPath::publishExclusive($this->tmpPath, $this->finalPath);
            $this->fail('An empty target must refuse publication.');
        } catch (FileAlreadyExistsException) {
            $this->assertSame('', FsPath::read($this->finalPath));
            $this->assertSame("finished\0payload\n", FsPath::read($this->tmpPath));
        }
    }

    /**
     * A directory occupying the target is never replaced.
     */
    public function testAnExistingDirectoryIsAConflict(): void
    {
        FsPath::ensureDirectory($this->finalPath);

        try {
            FsPath::publishExclusive($this->tmpPath, $this->finalPath);
            $this->fail('A directory target must refuse publication.');
        } catch (FileAlreadyExistsException) {
            $this->assertDirectoryExists($this->finalPath);
            $this->assertSame("finished\0payload\n", FsPath::read($this->tmpPath));
        }
    }

    /**
     * A dangling symlink occupies a name even though file_exists() answers false.
     */
    public function testADanglingSymlinkIsAConflict(): void
    {
        $missingPath = $this->directory . DIRECTORY_SEPARATOR . 'missing';
        $this->assertTrue(symlink($missingPath, $this->finalPath));

        try {
            FsPath::publishExclusive($this->tmpPath, $this->finalPath);
            $this->fail('A symlink target must refuse publication.');
        } catch (FileAlreadyExistsException) {
            $this->assertTrue(is_link($this->finalPath));
            $this->assertSame($missingPath, readlink($this->finalPath));
            $this->assertFileDoesNotExist($missingPath);
            $this->assertSame("finished\0payload\n", FsPath::read($this->tmpPath));
        }
    }

    /**
     * Publication failure retains the prepared bytes for caller cleanup or retry.
     */
    public function testAMissingTargetDirectoryRaisesFileMoveAndKeepsTheTemp(): void
    {
        $target = $this->directory . DIRECTORY_SEPARATOR . 'missing' . DIRECTORY_SEPARATOR . 'file';

        try {
            FsPath::publishExclusive($this->tmpPath, $target);
            $this->fail('A missing target directory must refuse publication.');
        } catch (FileMoveException $failure) {
            $this->assertSame("Cannot publish {$this->tmpPath} to {$target}", $failure->getMessage());
            $this->assertFileDoesNotExist($target);
            $this->assertSame("finished\0payload\n", FsPath::read($this->tmpPath));
        }
    }

    /**
     * A missing temp is a publication failure, never an empty target file.
     */
    public function testAMissingTempRaisesFileMoveWithoutCreatingTheTarget(): void
    {
        FsPath::delete($this->tmpPath);

        try {
            FsPath::publishExclusive($this->tmpPath, $this->finalPath);
            $this->fail('A missing temp must refuse publication.');
        } catch (FileMoveException) {
            $this->assertFileDoesNotExist($this->finalPath);
        }
    }

    /**
     * Passing the same name twice cannot delete the prepared file.
     */
    public function testIdenticalPathsAreAConflictAndKeepTheFile(): void
    {
        try {
            FsPath::publishExclusive($this->tmpPath, $this->tmpPath);
            $this->fail('The temp already occupies its own path.');
        } catch (FileAlreadyExistsException) {
            $this->assertSame("finished\0payload\n", FsPath::read($this->tmpPath));
        }
    }

    /**
     * The existing publication API keeps its overwrite behavior.
     */
    public function testOrdinaryPublishStillOverwritesTheTarget(): void
    {
        FsPath::write($this->finalPath, 'old');

        FsPath::publish($this->tmpPath, $this->finalPath);

        $this->assertFileDoesNotExist($this->tmpPath);
        $this->assertSame("finished\0payload\n", FsPath::read($this->finalPath));
    }
}
