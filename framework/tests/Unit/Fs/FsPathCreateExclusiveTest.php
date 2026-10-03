<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Fs;

use Hilos\Fs\Exception\FileWriteException;
use Hilos\Fs\FsPath;
use PHPUnit\Framework\TestCase;

/**
 * The first write of a file decided by the filesystem itself (HIL-1242).
 *
 * The caller that creates the file is told so; the caller that finds it there is told that, and
 * the file it found is not touched. A file that cannot be created at all is an error, not "already
 * there".
 */
final class FsPathCreateExclusiveTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-create-exclusive-' . uniqid('', true);
        FsPath::ensureDirectory($this->directory);
    }

    protected function tearDown(): void
    {
        FsPath::delete($this->path());
        FsPath::removeDirectory($this->directory);

        parent::tearDown();
    }

    public function testTheCallerThatCreatesTheFileIsToldSoAndTheFileCarriesThePayload(): void
    {
        $this->assertTrue(FsPath::createExclusive($this->path(), 'first'));

        $this->assertSame('first', FsPath::read($this->path()));
    }

    public function testTheCallerThatFindsTheFileIsToldSoAndTheFileIsLeftAsItWas(): void
    {
        FsPath::createExclusive($this->path(), 'first');

        $this->assertFalse(FsPath::createExclusive($this->path(), 'second'));

        $this->assertSame('first', FsPath::read($this->path()));
    }

    public function testAFileThatCannotBeCreatedIsAnError(): void
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . 'missing' . DIRECTORY_SEPARATOR . 'file';

        $this->expectException(FileWriteException::class);
        $this->expectExceptionMessage("Cannot create file: {$path}");

        FsPath::createExclusive($path, 'first');
    }

    /**
     * @return string Absolute path of the file every case creates
     */
    private function path(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'file';
    }
}
