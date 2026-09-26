<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Fs;

use Hilos\Auth\PasswordPolicy;
use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Fs\Exception\LfsPointerException;
use Hilos\Fs\FrameworkData;
use Hilos\Fs\FsPath;
use PHPUnit\Framework\TestCase;

/**
 * The shipped data must be materialized, attributed, and kept out of ordinary Git blobs.
 */
final class FrameworkDataTest extends TestCase
{
    public function testFrameworkDataIsMaterialized(): void
    {
        FrameworkData::assertMaterialized();

        $this->assertFileExists(FrameworkData::path('common-passwords.txt'));
    }

    public function testTheWholeDataDirectoryUsesGitLfs(): void
    {
        $this->assertContains(
            'framework/data/** filter=lfs diff=lfs merge=lfs -text',
            file(dirname(__DIR__, 4) . '/.gitattributes', FILE_IGNORE_NEW_LINES),
        );
    }

    public function testAPointerInANestedDirectoryNamesTheFileAndRemedy(): void
    {
        $directory = sys_get_temp_dir() . '/hilos-data-' . uniqid('', true);
        FsPath::ensureDirectory($directory . '/nested');
        $path = $directory . '/nested/pointer.txt';
        FsPath::write($path, "version https://git-lfs.github.com/spec/v1\noid sha256:" . str_repeat('0', 64) . "\nsize 42\n");

        try {
            $this->expectException(LfsPointerException::class);
            $this->expectExceptionMessage(
                "{$path} is a Git LFS pointer, not the file: install git-lfs and run `git lfs pull`"
                . ' (a package archive must include its Git LFS objects)',
            );
            FrameworkData::assertMaterialized($directory);
        } finally {
            FsPath::delete($path);
            FsPath::removeDirectory($directory . '/nested');
            FsPath::removeDirectory($directory);
        }
    }

    public function testShortAndEmptyFilesAreMaterialized(): void
    {
        $directory = sys_get_temp_dir() . '/hilos-data-' . uniqid('', true);
        FsPath::ensureDirectory($directory);
        FsPath::write($directory . '/empty.txt', '');
        FsPath::write($directory . '/short.txt', 'version');

        try {
            FrameworkData::assertMaterialized($directory);
            $this->assertSame('version', FsPath::read($directory . '/short.txt'));
        } finally {
            FsPath::delete($directory . '/empty.txt');
            FsPath::delete($directory . '/short.txt');
            FsPath::removeDirectory($directory);
        }
    }

    public function testMissingDataDirectoryIsRefused(): void
    {
        $this->expectException(DirectoryNotFoundException::class);

        FrameworkData::assertMaterialized(sys_get_temp_dir() . '/hilos-data-absent-' . uniqid('', true));
    }

    public function testMissingFileIsRefused(): void
    {
        $this->expectException(FileNotFoundException::class);

        FrameworkData::path('no-such-file.txt');
    }

    public function testCommonPasswordsMatchThePinnedBuild(): void
    {
        $previous = null;
        $count = 0;
        foreach (FsPath::readLines(FrameworkData::path('common-passwords.txt')) as $line) {
            $password = rtrim($line, "\r\n");
            $this->assertNotSame('', $password);
            $this->assertSame($password . "\n", $line);
            $this->assertSame(mb_strtolower($password), $password);
            $this->assertGreaterThanOrEqual(PasswordPolicy::MIN_LENGTH, strlen($password));
            if ($previous !== null) {
                $this->assertLessThan(0, strcmp($previous, $password), 'Passwords must be sorted and unique');
            }
            $previous = $password;
            $count++;
        }

        $this->assertSame(46528, $count, 'Rebuilding the pinned source must explicitly update this count');
    }

    public function testHeavyFrameworkFilesBelongInTheDataDirectory(): void
    {
        $oversized = [];
        $pending = [dirname(__DIR__, 3)];
        while ($pending !== []) {
            $directory = array_pop($pending);
            foreach (FsPath::entries($directory) as $name) {
                $path = $directory . '/' . $name;
                if (is_dir($path)) {
                    if ($path !== dirname(__DIR__, 3) . '/data' && !in_array($name, ['node_modules', 'dist', '.angular', 'vendor'], true)) {
                        $pending[] = $path;
                    }
                } elseif (FsPath::size($path) > 1048576) {
                    $oversized[] = $path;
                }
            }
        }

        $this->assertSame([], $oversized, 'Move heavy files to framework/data (Git LFS): ' . implode(', ', $oversized));
    }
}
