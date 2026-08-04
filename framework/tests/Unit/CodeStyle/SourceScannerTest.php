<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Tests\CodeStyle\SourceScanner;
use PHPUnit\Framework\TestCase;

/**
 * Pins what the scanner leaves out. A directory dropped because of its name would
 * take real sources with it the day a suite reuses that name, and nothing would
 * say so — the guard would simply report less.
 */
final class SourceScannerTest extends TestCase
{
    public function testDirectoryNamedAfterAToolDirectoryIsStillScanned(): void
    {
        $this->assertContains('Good/data/StillScanned.php', $this->scannedPaths());
    }

    public function testOnlyThePinnedPathIsLeftOut(): void
    {
        $scanned = $this->scannedPaths(['Good/data']);

        $this->assertNotContains('Good/data/StillScanned.php', $scanned);
        $this->assertContains('Good/PhpDocClean.php', $scanned);
    }

    public function testMissingRootIsSkippedSilently(): void
    {
        $scanner = new SourceScanner(dirname(__DIR__, 2) . '/CodeStyle/NoSuchRoot');

        $this->assertSame([], iterator_to_array($scanner->files()));
    }

    /**
     * @param array<int, string> $excludedPaths Paths relative to the fixture root to leave out
     * @return array<int, string> Scanned files, relative to the fixture root
     */
    private function scannedPaths(array $excludedPaths = []): array
    {
        $scanner = new SourceScanner(dirname(__DIR__, 2) . '/CodeStyle/Fixtures', $excludedPaths);
        $paths = [];
        foreach ($scanner->files() as $file) {
            $paths[] = $scanner->relativePath($file);
        }

        return $paths;
    }
}
