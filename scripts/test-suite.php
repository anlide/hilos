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
 *            with whoever shared the box. A scheduling HINT only, and a narrow
 *            one: of the steps ready to go, the one with the longest OWN duration
 *            starts first, and nothing here looks at the work waiting behind a
 *            step. A stale number costs wall clock, never correctness.
 *
 * WHAT BOUNDS A FULL RUN, and why the order is not the lever it looks like.
 * Measured 2026-09-05 (HIL-854) on the durations below, two lanes:
 *
 *   13m26s  the floor, at ANY lane count. chat-check, chat-php and chat-e2e share
 *           `group => 'chat'`, so no two of them ever overlap: 19 + 169 + 618 is
 *           806s that has to be laid end to end. Three lanes finish no sooner.
 *   12m52s  what the sum of every step (1543s) would allow at two lanes if nothing
 *           were serialized. It sits BELOW the floor above, which is the whole
 *           point: this run is bound by the chat group, not by the lane count.
 *   14m51s  what the current order costs on these numbers; the green run of
 *           2026-08-27 measured 14m28s. Ordering by the critical path behind each
 *           step — the obvious fix, and the one HIL-854 was raised to make — buys
 *           22 seconds of that. Counting each step's group load as well reaches
 *           the floor and buys 85. Both were declined, for the reason below.
 *
 * The order is also the only thing keeping `cluster` and `chat-e2e` apart, and that
 * is an accident of these numbers rather than something the graph enforces: today
 * cluster runs 0..178s and chat-e2e starts at 254s. Every faster order sends the
 * frontend chain first and puts chat-e2e beside the live five-daemon cluster fleet
 * — 115s of overlap ordering by critical path, 53s counting group load — and that
 * neighbour once cost chat-e2e 16m10s against 9m36s plus fourteen failures that
 * were nothing but the neighbour (HIL-752). Re-measuring the numbers below can lose
 * the separation with nothing saying so, so check it here rather than trusting that
 * a run which used to be green stays that way.
 */

/** Demos carrying a tests/e2e suite, with their measured per-step durations. */
$demos = [
    'chat' => ['check' => 19, 'php' => 169, 'e2e' => 618],
    'tasks' => ['check' => 14, 'php' => 15, 'e2e' => 104],
    'polls' => ['check' => 34, 'php' => 15, 'e2e' => 117],
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
