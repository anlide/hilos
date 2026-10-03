<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Fs;

use Hilos\Fs\ClusterDirectoryMarker;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Fs\FsDirectory;
use Hilos\Fs\FsPath;
use PHPUnit\Framework\TestCase;

/**
 * A directory's own entries leave the cluster directory marker out (HIL-1242).
 *
 * The marker is the framework's file, not the owner's: listing, emptying and measuring the
 * directory go through one list that does not name it, so no sweep removes it and no size counts it.
 */
final class FsDirectoryEntriesTest extends TestCase
{
    /** @var string Contents of the marker file the cases leave in the directory */
    private const string MARKER_CONTENTS = '{"version":1}';

    private string $path = '';

    private FsDirectory $directory;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-directory-entries-' . uniqid('', true);
        $context = new class extends FsContext {
            /** Registers nothing: the case builds its one directory itself. */
            public function configure(): void
            {
            }
        };
        $this->directory = new FsDirectory($context, 'published', $this->path, DirectoryScope::CLUSTER);
        FsPath::ensureDirectory($this->path);
        FsPath::write(ClusterDirectoryMarker::pathIn($this->path), self::MARKER_CONTENTS);
        FsPath::write($this->path . DIRECTORY_SEPARATOR . 'a.bin', 'aaaa');
        FsPath::write($this->path . DIRECTORY_SEPARATOR . 'b.bin', 'bb');
    }

    protected function tearDown(): void
    {
        foreach (FsPath::entries($this->path) as $entry) {
            FsPath::delete($this->path . DIRECTORY_SEPARATOR . $entry);
        }
        FsPath::removeDirectory($this->path);

        parent::tearDown();
    }

    public function testTheEntriesLeaveTheMarkerOut(): void
    {
        $entries = $this->directory->entries();
        sort($entries);

        $this->assertSame(['a.bin', 'b.bin'], $entries);
    }

    public function testDeletingAllLeavesTheMarkerAndRemovesTheRest(): void
    {
        $this->directory->deleteAll();

        $this->assertSame([ClusterDirectoryMarker::FILE_NAME], FsPath::entries($this->path));
        $this->assertSame(self::MARKER_CONTENTS, FsPath::read(ClusterDirectoryMarker::pathIn($this->path)));
    }

    public function testTheSizeDoesNotCountTheMarker(): void
    {
        $this->assertSame(6, $this->directory->size());
    }
}
