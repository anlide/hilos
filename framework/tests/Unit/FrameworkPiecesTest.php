<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/framework-pieces.php';

/** The same piece definitions drive the graph, database creation, and PHPUnit filters. */
final class FrameworkPiecesTest extends TestCase
{
    /** A class must keep its piece and every piece number must be in range. */
    public function testAssignsAStablePiece(): void
    {
        $first = frameworkPieceOf('BackupShipperIntegrationTest');

        $this->assertGreaterThanOrEqual(1, $first);
        $this->assertLessThanOrEqual(FRAMEWORK_INTEGRATION_PIECES, $first);
        $this->assertSame($first, frameworkPieceOf('BackupShipperIntegrationTest'));
    }

    /** Every real integration class lands once and no piece is empty. */
    public function testPartitionsTheWholeIntegrationDirectory(): void
    {
        $root = dirname(__DIR__, 3);
        $expected = array_map(
            static fn(string $file): string => basename($file, '.php'),
            glob($root . '/' . FRAMEWORK_INTEGRATION_DIR . '/*Test.php') ?: [],
        );
        sort($expected);
        $actual = [];
        for ($piece = 1; $piece <= FRAMEWORK_INTEGRATION_PIECES; $piece++) {
            $classes = frameworkPieceClasses($root, $piece);
            $this->assertNotSame([], $classes, 'piece ' . $piece . ' is empty');
            $this->assertSame($classes, array_values(array_unique($classes)));
            $actual = [...$actual, ...$classes];
        }
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    /** The class boundary excludes names that merely contain the selected name. */
    public function testFilterMatchesOnlyTheNamedClass(): void
    {
        $filter = frameworkPieceFilter(['FooTest']);

        $this->assertSame(1, preg_match($filter, 'Hilos\\Tests\\Integration\\FooTest::testBar with data set #0'));
        $this->assertSame(0, preg_match($filter, 'Hilos\\Tests\\Integration\\BarFooTest::testBar'));
        $this->assertSame(0, preg_match($filter, 'Hilos\\Tests\\Integration\\FooTestTwo::testBar'));
    }

    /** Database and graph names are derived from the same piece number. */
    public function testNamesTheDatabaseAndStep(): void
    {
        $this->assertSame('hilos-framework-test-2', frameworkPieceDatabase(2));
        $this->assertSame('framework-integration-2', frameworkPieceStepId(2));
    }

    /** The graph runs every piece after setup and keeps teardown behind all outcomes. */
    public function testManifestContainsAllPiecesAndTheirDependencies(): void
    {
        $steps = array_column(require __DIR__ . '/../../../scripts/test-suite.php', null, 'id');
        $pieceIds = [];

        $this->assertArrayNotHasKey('framework', $steps);
        for ($piece = 1; $piece <= FRAMEWORK_INTEGRATION_PIECES; $piece++) {
            $id = frameworkPieceStepId($piece);
            $pieceIds[] = $id;
            $this->assertArrayHasKey($id, $steps);
            $this->assertSame(frameworkPieceDatabase($piece), $steps[$id]['database']);
            $this->assertSame(['framework-up'], $steps[$id]['deps']);
        }

        $actualPieceIds = array_filter(array_keys($steps), static fn(string $id): bool => str_starts_with($id, 'framework-integration-'));
        $this->assertSame($pieceIds, array_values($actualPieceIds));
        $this->assertSame(['framework-unit', ...$pieceIds], $steps['framework-down']['deps']);
    }
}
