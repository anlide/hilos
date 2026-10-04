<?php

declare(strict_types=1);

/** The integration suite runs in this many independent database pieces. */
const FRAMEWORK_INTEGRATION_PIECES = 4;

/** Integration test files relative to the repository root. */
const FRAMEWORK_INTEGRATION_DIR = 'framework/tests/Integration';

/** Assign a whole integration test class to one stable piece. */
function frameworkPieceOf(string $class): int
{
    return crc32($class) % FRAMEWORK_INTEGRATION_PIECES + 1;
}

/**
 * The short class names belonging to a piece, in a stable order.
 *
 * @return list<string>
 */
function frameworkPieceClasses(string $root, int $piece): array
{
    $classes = [];
    foreach (glob($root . '/' . FRAMEWORK_INTEGRATION_DIR . '/*Test.php') ?: [] as $file) {
        $class = basename($file, '.php');
        if (frameworkPieceOf($class) === $piece) {
            $classes[] = $class;
        }
    }
    sort($classes);

    return $classes;
}

/** The database owned by one integration piece. */
function frameworkPieceDatabase(int $piece): string
{
    return 'hilos-framework-test-' . $piece;
}

/** The graph id reported for one integration piece. */
function frameworkPieceStepId(int $piece): string
{
    return 'framework-integration-' . $piece;
}

/**
 * Match only methods of the named classes, even when another class has the name as a suffix or prefix.
 *
 * @param list<string> $classes
 */
function frameworkPieceFilter(array $classes): string
{
    return '/\\\\(?:' . implode('|', array_map(static fn(string $class): string => preg_quote($class, '/'), $classes)) . ')::/';
}
