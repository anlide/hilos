<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Fs;

use Hilos\Fs\Exception\FileWriteException;
use Hilos\Fs\FsPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * Observe advisory locks with a second descriptor, without spawning another process.
 */
final class FsPathWithLockTest extends TestCase
{
    private string $directory;
    private string $lockPath;

    /**
     * Prepare a lock file whose existing contents must survive every acquisition.
     */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-with-lock-' . uniqid('', true);
        $this->lockPath = $this->directory . DIRECTORY_SEPARATOR . '.hilos-framework.lock';
        FsPath::ensureDirectory($this->directory);
        FsPath::write($this->lockPath, 'lock-marker');
    }

    /**
     * Remove the retained lock file and its temporary directory.
     */
    protected function tearDown(): void
    {
        FsPath::delete($this->lockPath);
        FsPath::removeDirectory($this->directory);

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{bool}> Shared and exclusive acquisition modes
     */
    public static function lockModes(): iterable
    {
        yield 'shared' => [false];
        yield 'exclusive' => [true];
    }

    /**
     * @param bool $exclusive Whether the callback holds an exclusive lock
     */
    #[DataProvider('lockModes')]
    public function testHoldsTheRequestedLockAndReleasesItAfterReturning(bool $exclusive): void
    {
        $observer = fopen($this->lockPath, 'c');
        $this->assertIsResource($observer);
        $openStreams = count(get_resources('stream'));
        $value = new stdClass();

        try {
            $returned = FsPath::withLock($this->lockPath, $exclusive, function () use ($observer, $exclusive, $openStreams, $value): stdClass {
                $this->assertSame(0, func_num_args());
                $this->assertSame($openStreams + 1, count(get_resources('stream')));
                $this->assertFalse(flock($observer, LOCK_EX | LOCK_NB));
                $this->assertSame(!$exclusive, flock($observer, LOCK_SH | LOCK_NB));
                if (!$exclusive) {
                    $this->assertTrue(flock($observer, LOCK_UN));
                }

                return $value;
            });

            $this->assertSame($value, $returned);
            $this->assertSame($openStreams, count(get_resources('stream')));
            $this->assertTrue(flock($observer, LOCK_EX | LOCK_NB));
            $this->assertSame('lock-marker', FsPath::read($this->lockPath));
        } finally {
            fclose($observer);
        }
    }

    /**
     * @param bool $exclusive Whether the throwing callback holds an exclusive lock
     */
    #[DataProvider('lockModes')]
    public function testCallbackFailureReleasesTheLockAndClosesTheHandle(bool $exclusive): void
    {
        $observer = fopen($this->lockPath, 'c');
        $this->assertIsResource($observer);
        $openStreams = count(get_resources('stream'));
        $failure = new RuntimeException('callback failed');

        try {
            try {
                FsPath::withLock($this->lockPath, $exclusive, function () use ($observer, $failure): never {
                    $this->assertFalse(flock($observer, LOCK_EX | LOCK_NB));

                    throw $failure;
                });
                $this->fail('The callback exception must pass through.');
            } catch (RuntimeException $caught) {
                $this->assertSame($failure, $caught);
            }

            $this->assertSame($openStreams, count(get_resources('stream')));
            $this->assertTrue(flock($observer, LOCK_EX | LOCK_NB));
            $this->assertSame('lock-marker', FsPath::read($this->lockPath));
        } finally {
            fclose($observer);
        }
    }

    /**
     * @param bool $exclusive Whether to create the file for an exclusive lock
     */
    #[DataProvider('lockModes')]
    public function testCreatesAnAbsentLockFileAndRetainsItForTheNextCaller(bool $exclusive): void
    {
        FsPath::delete($this->lockPath);
        $calls = 0;

        $returned = FsPath::withLock($this->lockPath, $exclusive, static function () use (&$calls): int {
            return ++$calls;
        });

        $this->assertSame(1, $returned);
        $this->assertSame('', FsPath::read($this->lockPath));
        $inode = fileinode($this->lockPath);
        $this->assertNull(FsPath::withLock($this->lockPath, $exclusive, static fn (): null => null));
        $this->assertSame($inode, fileinode($this->lockPath));
    }

    /**
     * @param bool $exclusive Whether opening was requested for an exclusive lock
     */
    #[DataProvider('lockModes')]
    public function testAMissingParentFailsWithoutCallingTheCallback(bool $exclusive): void
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . 'missing' . DIRECTORY_SEPARATOR . 'lock';
        $called = false;
        $openStreams = count(get_resources('stream'));

        try {
            FsPath::withLock($path, $exclusive, static function () use (&$called): void {
                $called = true;
            });
            $this->fail('An unopenable lock file must throw.');
        } catch (FileWriteException $failure) {
            $this->assertSame("Cannot open lock file: {$path}", $failure->getMessage());
            $this->assertFalse($called);
            $this->assertSame($openStreams, count(get_resources('stream')));
        }
    }

    /**
     * A directory at the lock path cannot act as a lock file.
     */
    public function testAnExistingDirectoryFailsToOpen(): void
    {
        $this->expectException(FileWriteException::class);
        $this->expectExceptionMessage("Cannot open lock file: {$this->directory}");

        FsPath::withLock($this->directory, true, function (): void {
            $this->fail('An invalid lock file must not call the callback.');
        });
    }

    /**
     * @param bool $exclusive Whether the unsupported stream was asked for an exclusive lock
     */
    #[DataProvider('lockModes')]
    public function testAcquisitionFailureClosesTheHandleWithoutCallingTheCallback(bool $exclusive): void
    {
        // The memory stream opens normally but does not support advisory flock.
        $path = 'php://memory';
        $called = false;
        $openStreams = count(get_resources('stream'));

        try {
            FsPath::withLock($path, $exclusive, static function () use (&$called): void {
                $called = true;
            });
            $this->fail('An unsupported lock must throw.');
        } catch (FileWriteException $failure) {
            $this->assertSame("Cannot lock file: {$path}", $failure->getMessage());
            $this->assertFalse($called);
            $this->assertSame($openStreams, count(get_resources('stream')));
        }
    }
}
