<?php

declare(strict_types=1);

/**
 * The full test run, as a graph instead of a script.
 *
 * `scripts/run-test-suite.php` executes this; the two together replace the fixed
 * sequence that composer, GitHub Actions and hilos-ops/verify-run.sh each used to
 * spell out for themselves. Adding a demo is an entry in this list, not an edit to
 * anyone's control flow.
 *
 * This file returns a list of steps. A step is:
 *   id       the name the run reports it under. These ids are load-bearing: the
 *            ops playbook, every archived verify log and every rc file already
 *            speak them, and attribution of past red depends on them not moving.
 *   command  a shell line, run through `/bin/sh -c` from `cwd`.
 *   cwd      relative to the repository root.
 *   deps     ids that must have finished GREEN before this one may start. A
 *            failed dependency skips this step rather than failing it.
 *   group    steps sharing a group never run at the same time. Unlike `deps` this
 *            is mutual exclusion only: it does not order them, and a red member
 *            does not skip its group-mates.
 *   tags     selectors: `run-test-suite.php frontend` runs everything tagged
 *            `frontend` plus whatever those steps depend on.
 *   seconds  the last measured duration (HIL-547, 2026-08-09). A scheduling HINT
 *            only — the longest ready step starts first, so that chat-e2e does not
 *            become the tail. A stale number costs wall clock, never correctness.
 */

/** Demos carrying a tests/e2e suite, with their measured per-step durations. */
$demos = [
    'chat' => ['check' => 17, 'php' => 32, 'e2e' => 233],
    'simple-todo' => ['check' => 12, 'php' => 10, 'e2e' => 53],
    'simple-poll' => ['check' => 14, 'php' => 10, 'e2e' => 57],
];

$steps = [
    // Rebuilding cli-test up front, because `docker compose run` only builds an
    // image when there is NONE: an edited Dockerfile.test-cli otherwise rides the
    // old image silently (HIL-274 added default-mysql-client to it, and without
    // the rebuild the integration suite failed with `mysql: not found`). With an
    // unchanged Dockerfile this is a cache hit measured in seconds.
    [
        'id' => 'framework-image',
        'command' => 'docker compose -f framework/docker/docker-compose.yml build hilos-cli-test',
        'cwd' => '.',
        'deps' => [],
        'group' => null,
        'tags' => ['framework', 'backend'],
        'seconds' => 1,
    ],
    [
        'id' => 'framework',
        'command' => 'composer run test:framework:all',
        'cwd' => '.',
        'deps' => ['framework-image'],
        'group' => null,
        'tags' => ['framework', 'backend'],
        'seconds' => 19,
    ],
    [
        'id' => 'fe-install',
        'command' => 'composer run test:framework:frontend:install',
        'cwd' => '.',
        'deps' => [],
        'group' => null,
        'tags' => ['framework', 'frontend'],
        'seconds' => 3,
    ],
    [
        'id' => 'fe-build',
        'command' => 'composer run test:framework:frontend:build',
        'cwd' => '.',
        'deps' => ['fe-install'],
        'group' => null,
        'tags' => ['framework', 'frontend'],
        'seconds' => 54,
    ],
    [
        'id' => 'fe-checks',
        'command' => 'composer run test:framework:frontend',
        'cwd' => '.',
        'deps' => ['fe-install'],
        'group' => null,
        'tags' => ['framework', 'frontend'],
        'seconds' => 93,
    ],
    // The best free neighbour in the graph: the cluster suite is wall-clock-bound
    // rather than CPU-bound — it polls convergence once a second and waits out the
    // grace windows it exists to verify — so it costs a lane and almost no cores.
    [
        'id' => 'cluster',
        'command' => 'composer run test:cluster:all',
        'cwd' => '.',
        'deps' => [],
        'group' => null,
        'tags' => ['cluster', 'backend'],
        'seconds' => 127,
    ],
];

foreach ($demos as $demo => $seconds) {
    $steps[] = [
        'id' => $demo . '-check',
        'command' => 'composer run test:check',
        'cwd' => 'demo/' . $demo,
        // SHARED SDK WORKSPACE INVARIANT — NOT an ordering preference, do not
        // delete it to "free up a lane". Every demo's frontend resolves @hilos/*
        // to framework/frontend, and its prebuild hook runs prebuild-sdk.mjs and
        // npm-install-if-stale.mjs against that ONE workspace and its ONE
        // node_modules. Two stale demos at once means two npm installs and two
        // core builds writing the same tree. What makes concurrency safe is that
        // fe-install and fe-build leave the SDK current, after which every demo
        // prebuild prints "current — skipped" and writes nothing.
        'deps' => ['fe-build'],
        'group' => $demo,
        'tags' => ['frontend', 'demo'],
        'seconds' => $seconds['check'],
    ];
    $steps[] = [
        'id' => $demo . '-php',
        'command' => 'composer run test:db-reset && composer run test:phpunit && composer run test:down',
        'cwd' => 'demo/' . $demo,
        // Backend only: no SDK, no built frontend, so it depends on nothing. It is
        // held apart from its demo's other steps by `group`, not by an edge —
        // `test:e2e-full` starts with a `docker compose down` of the whole project,
        // which would pull the database out from under a phpunit run next door.
        'deps' => [],
        'group' => $demo,
        'tags' => ['backend', 'demo'],
        'seconds' => $seconds['php'],
    ];
    $steps[] = [
        'id' => $demo . '-e2e',
        'command' => 'composer run test:e2e-full',
        'cwd' => 'demo/' . $demo,
        'deps' => ['fe-build'],
        'group' => $demo,
        'tags' => ['frontend', 'e2e', 'demo'],
        'seconds' => $seconds['e2e'],
    ];
}

return $steps;
