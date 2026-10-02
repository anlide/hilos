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
 *            is mutual exclusion only: no member waits for another to go green,
 *            and a red member does not skip its group-mates. Of the members ready
 *            to go the shortest starts first (`scripts/launch-order.php`).
 *   tags     selectors: `run-test-suite.php frontend` runs everything tagged
 *            `frontend` plus whatever those steps depend on.
 *   stand    the id of a record in `scripts/test-stands.php`, from which the run's
 *            snapshot takes the directory to run docker from, the compose file, and
 *            what narrows that file to this stand's own containers. A step without one
 *            has no stand at all and is not asked about.
 *   downsStand  whether this step takes its stand down the moment it ends, at any
 *            outcome. Separate from `stand` because twelve steps drive one and
 *            deliberately leave it standing.
 *   seconds  the last measured duration (HIL-1227, 2026-09-29; the e2e of chat and
 *            binance-btc-tracker re-measured by HIL-1221 the same day, after the
 *            protected-mode spec moved between them, again by HIL-1220, after
 *            the backup specs did, and again by HIL-1219, after the settings,
 *            people and admin specs did; polls and online-testing e2e re-measured
 *            by HIL-1226 in isolated green test:e2e-full cycles on 2026-10-02
 *            after the operations half moved, and the e2e of tasks and
 *            ecommerce-shop by HIL-1225 the same day, the same way), read off
 *            GREEN runs on nova-de,
 *            where a step beside its neighbours takes what it
 *            takes alone (chat-e2e 19m47s alone against 19m21s–21m30s beside two,
 *            28.09). A scheduling HINT only, and a narrow one
 *            (`scripts/launch-order.php`): of the steps ready to go, the group
 *            whose longest member is longest goes first, its shortest step
 *            ahead of the rest, and nothing here looks at the work waiting
 *            behind a step. A stale number costs wall clock, never correctness.
 *
 * WHAT BOUNDS A FULL RUN, and why neither the order nor the lanes are the lever.
 * Measured 2026-09-29 (HIL-1227) on nova-de, run 0659, the first green run at
 * seven lanes:
 *
 *   22m19s  the floor, at ANY lane count. chat-php, chat-e2e and chat-check share
 *           `group => 'chat'` — one stand — so no two of them ever overlap:
 *           106 + 1227 + 6 is 1339s that has to be laid end to end.
 *   22m20s  what the run took. The chat chain started with the run and ended it;
 *           every other step was done by 7m08s, after which chat-e2e ran alone.
 *    6m25s  what the sum of every step (2698s) would allow at seven lanes if
 *           nothing were serialized. It sits far BELOW the floor, which is the
 *           whole point: lanes wider than the graph do not shorten the run. Three
 *           lanes took 22m00s–24m32s on the same day, the difference being
 *           chat-e2e's own length and nothing the lanes did.
 *
 * The next lever is an instance of the stand as a parameter, so that chat-e2e can
 * be cut by area across stands of its own (hilos-ops/proposals, P-452) — not the
 * order of the steps and not the lane count.
 *
 * WHO MAY RUN BESIDE WHOM. Any cluster fleet — `binance-btc-tracker-cluster`,
 * `ecommerce-shop-cluster` and `online-testing-cluster`; `cluster` raises none since its
 * matrix left for them — may run beside any e2e step. Neither an edge nor the order keeps
 * them apart, and a fleet leaves with its own step (`downsStand`), which is hygiene
 * rather than separation. Decided by the owner on 2026-09-29 on 43 full runs on
 * nova-de (27–29.09), where cluster overlapped chat-e2e for 1.5–6 minutes and
 * every one was green; run 0659 added three more e2e suites beside them. A red
 * step beside a fleet is read like any other: the neighbours in the step's
 * SNAPSHOT.txt, then a re-run alone. Keeping the fleets apart from EACH OTHER,
 * should that be needed, belongs to the leaves that add them. The first two need
 * nothing of the kind: in run 0684 on nova-de (2026-09-30, HIL-1215) `cluster` and
 * `binance-btc-tracker-cluster` started in the same second, overlapped for 1m45s,
 * and both were green. Nor do the three fleets of the demos: in run 0747 on nova-de
 * (2026-10-02, HIL-1216) `binance-btc-tracker-cluster`, `ecommerce-shop-cluster` and
 * `online-testing-cluster` started in the same second, ran all three together for 1m21s,
 * and all were green.
 */

/** Demos carrying a tests/e2e suite, with their measured per-step durations. */
$demos = [
    'chat' => ['check' => 11, 'php' => 106, 'e2e' => 849],
    'tasks' => ['check' => 9, 'php' => 17, 'e2e' => 108],
    'polls' => ['check' => 15, 'php' => 17, 'e2e' => 142],
    'binance-btc-tracker' => ['check' => 10, 'php' => 15, 'e2e' => 394],
    'ecommerce-shop' => ['check' => 9, 'php' => 15, 'e2e' => 83],
    'online-testing' => ['check' => 15, 'php' => 15, 'e2e' => 111],
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
        'seconds' => 2,
    ],
    [
        'id' => 'framework',
        'command' => 'composer run test:framework:all',
        'cwd' => '.',
        'stand' => 'framework',
        'deps' => ['framework-image'],
        'group' => null,
        'tags' => ['framework', 'backend'],
        'seconds' => 425,
    ],
    [
        'id' => 'fe-install',
        'command' => 'composer run test:framework:frontend:install',
        'cwd' => '.',
        'stand' => 'frontend',
        'deps' => [],
        'group' => null,
        'tags' => ['framework', 'frontend'],
        'seconds' => 1,
    ],
    [
        'id' => 'fe-build',
        'command' => 'composer run test:framework:frontend:build',
        'cwd' => '.',
        'stand' => 'frontend',
        'deps' => ['fe-install'],
        'group' => null,
        'tags' => ['framework', 'frontend'],
        'seconds' => 35,
    ],
    [
        'id' => 'fe-checks',
        'command' => 'composer run test:framework:frontend',
        'cwd' => '.',
        'stand' => 'frontend',
        'deps' => ['fe-install'],
        'group' => null,
        'tags' => ['framework', 'frontend'],
        'seconds' => 87,
    ],
    // demo/cluster's unit suite and nothing more: its scenario matrix has left whole for the
    // stands of the demos - 1, 2, 5, 7, 8, 10, 13, 17, 20, 23 and 25 run on
    // binance-btc-tracker-cluster, 3, 4, 6, 9, 12, 14, 16, 18 and 19 on ecommerce-shop-cluster,
    // 11, 15, 21 and 22 on online-testing-cluster - so the step raises no fleet, only the
    // database and the cli container its units run in, and stays until HIL-1218 takes
    // demo/cluster away. The seconds below are measured in run 0747, beside the three fleets.
    [
        'id' => 'cluster',
        'command' => 'composer run test:cluster:all',
        'cwd' => '.',
        'stand' => 'cluster',
        'deps' => [],
        'group' => null,
        'tags' => ['cluster', 'backend'],
        'seconds' => 8,
        // Takes its stand down with it, at any outcome. This is hygiene: a fleet has no reason
        // to outlive its step, and one that was FORGOTTEN kept eating cores for the rest of the
        // run — on nova-lt that turned chat-e2e into 16m10s against 9m36s with fourteen failures
        // that were only the leak (HIL-752). It is not what lets the step stand beside an e2e:
        // the head of this file says they may overlap. Declared here rather than appended to
        // the composer chain because that chain breaks at the first red, and the runner is the
        // one that holds the outcome.
        'downsStand' => true,
    ],
    // The fleet of binance-btc-tracker (HIL-1215): five nodes of the whole demo on one database,
    // and a stranger scenario 17 raises. It may run beside `cluster` and beside any e2e step - no
    // group and no edge keep it apart from them (the head of this file). Takes its stand down
    // with it, at any outcome, for the reason `cluster` does. The demo's unit suite is not run
    // here but in binance-btc-tracker-php. Scenarios 23 and 25 freeze the masters last (HIL-1125,
    // HIL-1128). The seconds are measured on the step run alone on nova-de (2026-10-01,
    // HIL-1125), with scenarios 13 and 20 parked (P-169, P-456); returning one moves the number.
    [
        'id' => 'binance-btc-tracker-cluster',
        'command' => 'composer run test:cluster:scenarios',
        'cwd' => 'demo/binance-btc-tracker',
        'stand' => 'binance-btc-tracker-cluster',
        'deps' => [],
        'group' => null,
        'tags' => ['cluster', 'backend'],
        'seconds' => 105,
        'downsStand' => true,
    ],
    // The fleet of ecommerce-shop (HIL-1216): one master and two slaves of unequal room, the whole
    // demo on one database. Beside the other fleets and any e2e step, no group and no edge (the
    // head of this file); takes its stand down with it, at any outcome, for the reason `cluster`
    // does. The demo's unit suite runs in ecommerce-shop-php. The seconds are measured in run
    // 0747, beside the other two fleets, with scenarios 16 and 19 parked (P-441/2, P-441/1);
    // returning one moves the number.
    [
        'id' => 'ecommerce-shop-cluster',
        'command' => 'composer run test:cluster:scenarios',
        'cwd' => 'demo/ecommerce-shop',
        'stand' => 'ecommerce-shop-cluster',
        'deps' => [],
        'group' => null,
        'tags' => ['cluster', 'backend'],
        'seconds' => 150,
        'downsStand' => true,
    ],
    // The fleet of online-testing (HIL-1217): three equal masters that carry the work themselves,
    // the whole demo on one database. Beside the other fleets and any e2e step, no group and no
    // edge (the head of this file); takes its stand down with it, at any outcome, for the reason
    // `cluster` does. The demo's unit suite runs in online-testing-php. The seconds are measured
    // on the step run alone on nova-de (2026-10-01, HIL-1217).
    [
        'id' => 'online-testing-cluster',
        'command' => 'composer run test:cluster:scenarios',
        'cwd' => 'demo/online-testing',
        'stand' => 'online-testing-cluster',
        'deps' => [],
        'group' => null,
        'tags' => ['cluster', 'backend'],
        'seconds' => 80,
        'downsStand' => true,
    ],
    // Where every log line of a node lands, proven on the live tasks stand (HIL-1018): five
    // scenarios in sequence over demo/tasks, judged against scripts/log-streams.php. In the
    // tasks group so it never overlaps tasks-check, tasks-php or tasks-e2e over one stand,
    // and on tasks rather than chat because the chat group is the floor of the whole run
    // (1339s end to end) and this step would lift it; the tasks group has the room. Takes its
    // stand down at any outcome, standalone as well as here.
    //
    // The edge to `cluster` is an ORDER, not a need: this step wants nothing the cluster
    // produced (no SDK, no built frontend either). Without the edge the two are the longest
    // steps ready at t=0, so at two lanes the longest-first order put them side by side — a
    // five-node fleet next to a 28-worker daemon being killed and restarted — and cluster's
    // liveness scenario 9 ("s1 seen offline after its daemon died") timed out twice after
    // twelve green runs in a row, then passed alone on the same HEAD (2026-09-17, runs
    // 0350/0351, nova-lt; not re-measured on nova-de). The graph has no other lever to keep
    // two steps apart: a group is a shared stand, and these two share none. The edge costs
    // nothing — the tasks group is not the floor of the run. The price is paid knowingly: a
    // red cluster skips this step, in a run that is red already; and
    // `run-test-suite.php log-streams` runs cluster first, so the step alone is
    // `composer run test:log-streams`.
    [
        'id' => 'log-streams',
        'command' => 'composer run test:log-streams',
        'cwd' => '.',
        'stand' => 'tasks',
        'deps' => ['cluster'],
        'group' => 'tasks',
        'tags' => ['backend', 'demo'],
        'seconds' => 112,
        'downsStand' => true,
    ],
];

foreach ($demos as $demo => $seconds) {
    $steps[] = [
        'id' => $demo . '-check',
        'command' => 'composer run test:check',
        'cwd' => 'demo/' . $demo,
        'stand' => $demo,
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
        'stand' => $demo,
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
        'stand' => $demo,
        'deps' => ['fe-build'],
        'group' => $demo,
        'tags' => ['frontend', 'e2e', 'demo'],
        'seconds' => $seconds['e2e'],
    ];
}

return $steps;
