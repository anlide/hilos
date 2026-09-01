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
 *   seconds  the last measured duration (HIL-733, 2026-08-27), read off a GREEN
 *            single-lane run so it is the step's own cost rather than an overlap
 *            with whoever shared the box. A scheduling HINT only — the longest
 *            ready step starts first, so that chat-e2e does not become the tail.
 *            A stale number costs wall clock, never correctness.
 */

/** Demos carrying a tests/e2e suite, with their measured per-step durations. */
$demos = [
    'chat' => ['check' => 19, 'php' => 169, 'e2e' => 618],
    'tasks' => ['check' => 14, 'php' => 15, 'e2e' => 104],
    'simple-poll' => ['check' => 34, 'php' => 15, 'e2e' => 117],
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
        'seconds' => 96,
    ],
    [
        'id' => 'fe-install',
        'command' => 'composer run test:framework:frontend:install',
        'cwd' => '.',
        'deps' => [],
        'group' => null,
        'tags' => ['framework', 'frontend'],
        'seconds' => 2,
    ],
    [
        'id' => 'fe-build',
        'command' => 'composer run test:framework:frontend:build',
        'cwd' => '.',
        'deps' => ['fe-install'],
        'group' => null,
        'tags' => ['framework', 'frontend'],
        'seconds' => 61,
    ],
    [
        'id' => 'fe-checks',
        'command' => 'composer run test:framework:frontend',
        'cwd' => '.',
        'deps' => ['fe-install'],
        'group' => null,
        'tags' => ['framework', 'frontend'],
        'seconds' => 100,
    ],
    // Not the free neighbour this comment used to promise. The suite holds five node
    // daemons, a mysql, a cli container and a fleet of ten agents, so it costs real
    // cores as well as a lane — and it still runs green beside a neighbour: measured
    // 2026-08-27, green next to chat-php at two lanes. The red this step produced for
    // three weeks was a cluster defect (HIL-746 roster liveness, HIL-747 hand-over
    // scope), not a busy box, so do not reach for lanes when it goes red again. The
    // 178s below is measured with scenario 13 parked (P-169); returning it moves the
    // number.
    [
        'id' => 'cluster',
        'command' => 'composer run test:cluster:all',
        'cwd' => '.',
        'deps' => [],
        'group' => null,
        'tags' => ['cluster', 'backend'],
        'seconds' => 178,
        // The one step that takes its stand down with it, at any outcome. Its fleet of five node
        // daemons keeps eating cores for the rest of the run otherwise, which is what turned
        // chat-e2e into 16m10s against 9m36s and produced fourteen failures that were only the
        // neighbour (HIL-752). Declared here rather than appended to the composer chain because
        // that chain breaks at the first red, and the runner is the one that holds the outcome.
        'downAfter' => 'cluster',
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
