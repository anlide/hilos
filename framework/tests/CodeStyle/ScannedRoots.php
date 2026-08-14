<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle;

/**
 * The roots the code-style guard reads, each with the kind that decides its rules.
 *
 * A root is here when it holds PHP the repository runs, or PHP that decides a run:
 * the framework and the demos are the first, `scripts/` is the second — it carries
 * the test runner every Verify run is judged by, and host-side installers, all of
 * which execute for real and are held to production rules for that reason.
 *
 * A demo contributes its two source directories rather than its whole tree, because
 * `demo/<name>/data/` is a sibling of them and holds root-owned MariaDB files the
 * walk must never descend into. A new demo arrives through the glob, so there is no
 * activation step to forget, and a missing root stays no configuration error at all:
 * the framework also ships without the demos, and {@see SourceScanner} skips a path
 * that is not there in silence.
 *
 * One PHP file of the repository is deliberately outside every root: the type stub
 * `framework/Stubs/event.php`, which exists for an IDE and is never executed nor
 * loaded. Left unsaid, its absence would be indistinguishable from an oversight —
 * exactly what `scripts/` was until this class was written.
 */
final class ScannedRoots
{
    /**
     * @param string $repositoryRoot Absolute path of the repository root
     * @return array<string, RootKind> Kind of every scanned root, keyed by its path from the repository root
     */
    public static function all(string $repositoryRoot): array
    {
        $roots = [
            'framework/backend' => RootKind::Production,
            'framework/tests' => RootKind::Suite,
            'scripts' => RootKind::Production,
        ];

        foreach (glob($repositoryRoot . '/demo/*', GLOB_ONLYDIR) ?: [] as $demo) {
            $roots['demo/' . basename($demo) . '/backend'] = RootKind::Production;
            $roots['demo/' . basename($demo) . '/tests'] = RootKind::Suite;
        }

        return $roots;
    }
}
