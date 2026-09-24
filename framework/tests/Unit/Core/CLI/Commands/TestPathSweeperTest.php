<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Core\CLI\Commands\TestPathSweeper;
use PHPUnit\Framework\TestCase;

/**
 * Tests the sweep the test-only reset commands empty a directory with.
 *
 * The tally is worth a test because a caller reports it to an operator, but the rule this file
 * really exists for is the other one: the sweep must not take the file that makes an otherwise
 * empty directory exist in git. That rule was learnt the plain way - the first run of the backup
 * reset deleted `demo/chat/data/backup/.gitignore`, which is the only thing git knows about that
 * directory, so a fresh checkout would have come without it.
 */
final class TestPathSweeperTest extends TestCase
{
    /** @var string Directory this test builds and takes down again */
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hilos-sweeper-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/archive/batch-1', 0o777, true);
    }

    protected function tearDown(): void
    {
        new TestPathSweeper()->emptyDirectory($this->root);
        self::removeWhatTheSweepKeeps($this->root);
    }

    public function testItEmptiesTheDirectoryAndKeepsTheDirectoryItself(): void
    {
        file_put_contents($this->root . '/archive/batch-1/daemon.log', 'x');
        file_put_contents($this->root . '/daemon.log', 'x');

        $sweeper = new TestPathSweeper();
        $sweeper->emptyDirectory($this->root);

        $this->assertSame([], $sweeper->failed());
        $this->assertSame(4, $sweeper->removed(), 'the two files, the batch and the archive');
        $this->assertDirectoryExists($this->root);
        $this->assertFileDoesNotExist($this->root . '/daemon.log');
    }

    public function testItNeverTakesTheFileThatMakesTheDirectoryExistInGit(): void
    {
        file_put_contents($this->root . '/.gitignore', '*');
        file_put_contents($this->root . '/archive/.gitkeep', '');
        file_put_contents($this->root . '/archive/batch-1/daemon.log', 'x');

        new TestPathSweeper()->emptyDirectory($this->root);

        $this->assertFileExists($this->root . '/.gitignore');
        $this->assertFileExists($this->root . '/archive/.gitkeep');
        $this->assertDirectoryDoesNotExist($this->root . '/archive/batch-1');
    }

    public function testAGlobSweepSpareThemToo(): void
    {
        file_put_contents($this->root . '/.gitkeep', '');
        file_put_contents($this->root . '/daemon.log', 'x');

        $sweeper = new TestPathSweeper();
        $sweeper->removeMatching($this->root . '/*');

        $this->assertFileExists($this->root . '/.gitkeep');
        $this->assertFileDoesNotExist($this->root . '/daemon.log');
        $this->assertSame([], $sweeper->failed());
    }

    /**
     * Takes down what the sweep is written to leave behind, so the box keeps no temp trees.
     *
     * The sweep spares the markers on purpose and the directories holding them with it, so a
     * teardown that only swept would leave its own root standing after every case.
     *
     * @param string $path Directory to take down, with everything under it
     */
    private static function removeWhatTheSweepKeeps(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($child) ? self::removeWhatTheSweepKeeps($child) : unlink($child);
        }

        rmdir($path);
    }

    public function testAPathTheDeleteLeavesInPlaceIsReportedAsFailedNotRemoved(): void
    {
        // A dangling symlink is what is_file() denies: the seam's delete leaves it without a word,
        // and what counts as removed is a path that is gone after the call.
        $link = $this->root . '/dangling';
        symlink($this->root . '/never-made', $link);

        try {
            $sweeper = new TestPathSweeper();
            $sweeper->emptyDirectory($this->root);

            $this->assertSame([$link], $sweeper->failed());
            $this->assertSame(2, $sweeper->removed(), 'the batch and the archive');
            $this->assertTrue(is_link($link));
        } finally {
            unlink($link);
        }
    }

    public function testADirectoryThatIsNotThereIsNothingToDo(): void
    {
        $sweeper = new TestPathSweeper();
        $sweeper->emptyDirectory($this->root . '/never-made');

        $this->assertSame(0, $sweeper->removed());
        $this->assertSame([], $sweeper->failed());
    }
}
