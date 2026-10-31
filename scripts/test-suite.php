<?php

declare(strict_types=1);

require_once __DIR__ . '/framework-pieces.php';

/**
 * The full test run, as a graph instead of a script.
 *
 * `scripts/run-test-suite.php` executes this graph for both composer and an
 * external pipeline, including on a fresh checkout with no pipeline beside it.
 * Adding a demo is an entry in this list, not an edit to anyone's control flow.
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
 *   database  the database inside the stand this step works in, when it is not
 *            the one the stand's database container names as MYSQL_DATABASE;
 *            the step's snapshot reads this one.
 *   seconds  a rough duration, written by hand off green runs on the test box
 *            (the demo numbers last on 2026-10-16) and not kept current: specs
 *            land every day and nobody refreshes it. A scheduling HINT only, and
 *            a narrow one (`scripts/launch-order.php`): of the steps ready to go,
 *            the group whose longest member is longest goes first, its shortest
 *            step ahead of the rest, and nothing here looks at the work waiting
 *            behind a step. A stale number costs wall clock, never correctness.
 *            It is no measure of load: the demo a new spec file is born in is
 *            chosen from the durations of the latest green full run
 *            (docs/agents/frontend/testing-strategy.md, "Which demo carries a
 *            spec").
 *
 * WHAT BOUNDS A FULL RUN, and why neither the order nor the lanes are the lever.
 * Measured 2026-09-29 (HIL-1227) on the test box, run 0659, the first green run
 * at seven lanes:
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
 * be cut by area across stands of its own — not the order of the steps and not
 * the lane count.
 *
 * THE FRAMEWORK SUITE IN PIECES (HIL-1328). The unit suite runs beside four
 * integration pieces, each using its own database in one MariaDB container. A
 * class belongs to one piece by the crc32 of its short name, as declared in
 * scripts/framework-pieces.php. On the test box at seven lanes, run 0789
 * (2026-10-04), framework-deps through the last integration piece took 7m38s,
 * then down took 2s. Before the split, the `framework` step took 10m14s in green
 * run 0788 on the parent base. Archived id `framework` meant both suites in one
 * process; rc=1 there meant either unit or integration failed.
 *
 * WHO MAY RUN BESIDE WHOM. Any cluster fleet — `binance-btc-tracker-cluster`,
 * `ecommerce-shop-cluster` and `online-testing-cluster` — may run beside any
 * e2e step. Neither an edge nor the order keeps them apart, and a fleet leaves
 * with its own step (`downsStand`), which is hygiene rather than separation.
 * Decided by the owner on 2026-09-29 on 43 full runs on the test box (27–29.09),
 * where cluster overlapped chat-e2e for 1.5–6 minutes and every one was green;
 * run 0659 added three more e2e suites beside them. A red step beside a fleet
 * is read like any other: the full verdict stays red; neighbors in the step's
 * SNAPSHOT.txt and narrow re-runs supply diagnostic evidence. Keeping the fleets
 * apart from EACH OTHER, should that be needed, belongs to the leaves that add
 * them. The three fleets need nothing of the kind: in run 0747 on the test box
 * (2026-10-02, HIL-1216),
 * `binance-btc-tracker-cluster`, `ecommerce-shop-cluster` and
 * `online-testing-cluster` started in the same second, ran all three together
 * for 1m21s, and all were green. Log-streams may run beside any fleet too: in
 * 23 full runs on the test box (2026-10-02/03, runs 0748–0773) it overlapped
 * ecommerce-shop-cluster, which carries scenario 9, for 113–136s, and every run
 * was green (HIL-1218). Two steps of the same cluster stand share a group: each
 * takes that compose project down before it runs (HIL-1232).
 */

/**
 * Demos carrying a tests/e2e suite, with rough per-step durations for the launch order
 * (`seconds` above) — not the load that places a new spec.
 *
 * A demo with a `build` duration builds its frontend in a step of its own, `<demo>-build`,
 * and its e2e step serves that build instead of making one (`test:e2e-prebuilt`). Only
 * binance-btc-tracker needs it: its cluster browser serves the same frontend/dist beside
 * the demo's e2e, and a build empties dist before it writes it (HOTFIX 2026-10-25).
 */
$demos = [
    'chat' => ['check' => 11, 'php' => 79, 'e2e' => 600],
    'tasks' => ['check' => 9, 'php' => 17, 'e2e' => 642],
    'polls' => ['check' => 15, 'php' => 17, 'e2e' => 792],
    'binance-btc-tracker' => ['check' => 10, 'php' => 15, 'build' => 13, 'e2e' => 755],
    'ecommerce-shop' => ['check' => 9, 'php' => 15, 'e2e' => 102],
    'online-testing' => ['check' => 15, 'php' => 15, 'e2e' => 111],
];

$steps = [
    // HIL-1795: 5.714s through the container gateway on 2026-10-24; no stand is raised.
    [
        'id' => 'cluster-unit',
        'command' => 'composer run test:cluster:unit',
        'cwd' => '.',
        'deps' => [],
        'group' => null,
        'tags' => ['cluster', 'backend'],
        'seconds' => 6,
    ],
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
    // Install dependencies before raising the stand: both composer commands use the
    // same Compose project, whose network the first command creates. Unit failures
    // must not hide integration verdicts, so both branches depend on deps instead
    // of making unit a gate. Down waits for unit too, because it removes the network
    // the unit container uses. A red piece skips down and leaves the stand for triage.
    [
        'id' => 'framework-deps',
        'command' => 'composer run test:framework:install-deps',
        'cwd' => '.',
        'stand' => 'framework',
        'deps' => ['framework-image'],
        'group' => null,
        'tags' => ['framework', 'backend', 'framework-php'],
        'seconds' => 1,
    ],
    [
        'id' => 'framework-unit',
        'command' => 'composer run test:framework:unit -- --do-not-cache-result',
        'cwd' => '.',
        'stand' => 'framework',
        'deps' => ['framework-deps'],
        'group' => null,
        'tags' => ['framework', 'backend', 'framework-php'],
        'seconds' => 22,
    ],
    [
        'id' => 'framework-up',
        'command' => 'composer run test:framework:up && composer run test:framework:databases',
        'cwd' => '.',
        'stand' => 'framework',
        'deps' => ['framework-deps'],
        'group' => null,
        'tags' => ['framework', 'backend', 'framework-php'],
        'seconds' => 7,
    ],
];

$frameworkPieceIds = [];
// HIL-1327, the group alone on the test box, databases in memory, 2026-10-04.
$frameworkPieceSeconds = [1 => 10, 2 => 17, 3 => 16, 4 => 33];
for ($piece = 1; $piece <= FRAMEWORK_INTEGRATION_PIECES; $piece++) {
    $frameworkPieceIds[] = frameworkPieceStepId($piece);
    $steps[] = [
        'id' => frameworkPieceStepId($piece),
        'command' => 'composer run test:framework:piece -- ' . $piece,
        'cwd' => '.',
        'stand' => 'framework',
        'database' => frameworkPieceDatabase($piece),
        'deps' => ['framework-up'],
        'group' => null,
        'tags' => ['framework', 'backend', 'framework-php'],
        'seconds' => $frameworkPieceSeconds[$piece],
    ];
}
$steps[] = [
    'id' => 'framework-down',
    'command' => 'composer run test:framework:down',
    'cwd' => '.',
    'stand' => 'framework',
    'deps' => ['framework-unit', ...$frameworkPieceIds],
    'group' => null,
    'tags' => ['framework', 'backend', 'framework-php'],
    'seconds' => 2,
];

$steps = array_merge($steps, [
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
        // READS fe-build's OUTPUT — NOT an ordering preference. The initial bundle
        // budget suite resolves the real @hilos/angular package, whose exports live
        // only in the APF manifest ng-packagr writes to angular/dist, and ng-packagr
        // deletes that directory before it rebuilds. Beside a running fe-build the
        // suite found no manifest ("missing generated Angular APF exports") in runs
        // 1340 and 1342; alone it read a dist left by an older build.
        'deps' => ['fe-install', 'fe-build'],
        'group' => null,
        'tags' => ['framework', 'frontend'],
        'seconds' => 87,
    ],
    // The fleet of binance-btc-tracker (HIL-1215): five nodes of the whole demo on one
    // database, and a stranger scenario 17 raises. It may run beside the other fleets and any
    // e2e step - no edge keeps them apart (the head of this file). It shares a group only with
    // its own browser step: both reset the same compose project (HIL-1232). Takes its stand
    // down at any outcome: a fleet has no reason to outlive its step, and one forgotten on
    // the old box kept eating cores for the rest of the run, turning chat-e2e into 16m10s against
    // 9m36s with fourteen failures that were only the leak (HIL-752). This is hygiene, not what
    // lets the step run beside an e2e (the head of this file). It is declared here instead of
    // appended to the composer chain because that chain breaks at the first red, while the
    // runner holds the outcome. The demo's unit suite runs in binance-btc-tracker-php.
    // Scenarios 23 and 25 freeze the masters last (HIL-1125, HIL-1128). Scenarios 29 and 30
    // recreate and kill a slave (HIL-1243); scenario 32 recreates one twice (HIL-1274). The
    // seconds are measured on the test box, 2026-10-17 (HIL-1505): full fresh stand, cached
    // images; 363.153 s rounded to 363, 18 passed, 3 existing skips (13, 8, 36), no retries.
    [
        'id' => 'binance-btc-tracker-cluster',
        'command' => 'composer run test:cluster:scenarios',
        'cwd' => 'demo/binance-btc-tracker',
        'stand' => 'binance-btc-tracker-cluster',
        'deps' => [],
        'group' => 'binance-btc-tracker-cluster',
        'tags' => ['cluster', 'backend'],
        'seconds' => 363,
        'downsStand' => true,
    ],
    // A browser on the cluster stand through its entry (HIL-1232, HIL-1304).
    // Runs beside the demo's e2e, not after it (HOTFIX 2026-10-25): behind the
    // 27-minute e2e it was the last six minutes of every full run, alone on the box.
    // The two still share three things, and each has its own keeper. frontend/dist,
    // which the entry serves: binance-btc-tracker-build makes it, and in this graph
    // nothing else writes it. tests/e2e/node_modules: binance-btc-tracker-check
    // installs it, after which every install-if-stale skips. The Playwright output
    // folders: the demo's main run writes test-results/main and playwright-report/main,
    // so neither its start nor its report clears the cluster-* and quarantine/* folders.
    // The group keeps the two cluster steps from taking the same compose project down
    // beneath each other.
    //
    // Both timeout factors are pinned at 2 here, the owner's decision of the same day:
    // beside four e2e steps the box is loaded when this step reads its factor and may
    // be more loaded later. A pin silences the load term and keeps the memory floor
    // (docs/agents/testing.md, "The full run — one graph").
    [
        'id' => 'binance-btc-tracker-cluster-e2e',
        'command' => 'HILOS_E2E_TIMEOUT_SCALE=2 CLUSTER_E2E_TIMEOUT_SCALE=2 composer run test:cluster:e2e',
        'cwd' => 'demo/binance-btc-tracker',
        'stand' => 'binance-btc-tracker-cluster',
        'deps' => ['binance-btc-tracker-build', 'binance-btc-tracker-check'],
        'group' => 'binance-btc-tracker-cluster',
        'tags' => ['cluster', 'e2e'],
        // On the test box, 2026-10-25 (HOTFIX): full run 1590 at seven lanes, beside four demo
        // e2e steps, 8m04s; daemon phase switched off (F-023), 13 live + 2 after-loss
        // passed, quarantine tail 10 live with F-011 failed, no retries or unstable tests.
        'seconds' => 484,
        'downsStand' => true,
    ],
    // The fleet of ecommerce-shop (HIL-1216): one master and two slaves of unequal room, the
    // whole demo on one MariaDB server with primary and derived journal (HIL-1453). Beside the
    // other fleets and any e2e step, no group and no edge (the head of this file); takes its
    // stand down with it, at any outcome, for the reason binance-btc-tracker-cluster does. The
    // demo's unit suite runs in ecommerce-shop-php. Seconds retain the historical estimate:
    // ten fresh primary/replica matrices on the test box (2026-10-05, HIL-1229), median 131 s,
    // max 136 s, no retries, scenarios 9 and 16 parked; not remeasured for the single server.
    [
        'id' => 'ecommerce-shop-cluster',
        'command' => 'composer run test:cluster:scenarios',
        'cwd' => 'demo/ecommerce-shop',
        'stand' => 'ecommerce-shop-cluster',
        'deps' => [],
        'group' => null,
        'tags' => ['cluster', 'backend'],
        'seconds' => 131,
        'downsStand' => true,
    ],
    // The fleet of online-testing (HIL-1217): three equal masters that carry the work themselves,
    // the whole demo on one MariaDB server with primary and derived journal (HIL-1453). Beside
    // the other fleets and unrelated e2e steps, sharing a group with its browser step; takes its
    // stand down with it, at any outcome, for the reason binance-btc-tracker-cluster does.
    // The demo's unit suite runs in online-testing-php. Seconds retain the historical median of
    // ten fresh Galera stands with member death and return (HIL-1231), not a single-server timing.
    [
        'id' => 'online-testing-cluster',
        'command' => 'composer run test:cluster:scenarios',
        'cwd' => 'demo/online-testing',
        'stand' => 'online-testing-cluster',
        'deps' => [],
        'group' => 'online-testing-cluster',
        'tags' => ['cluster', 'backend'],
        'seconds' => 165,
        'downsStand' => true,
    ],
    // Analytics across all three masters (HIL-1423), live only. The single-node e2e
    // finishes using the shared dist, node_modules and reports before this starts;
    // the cluster group keeps the scenario matrix from resetting the browser stand.
    [
        'id' => 'online-testing-cluster-e2e',
        'command' => 'composer run test:cluster:e2e',
        'cwd' => 'demo/online-testing',
        'stand' => 'online-testing-cluster',
        'deps' => ['online-testing-e2e'],
        'group' => 'online-testing-cluster',
        'tags' => ['cluster', 'e2e'],
        // 2026-10-17, HIL-1423: 84s for the fresh-stand command with cached images,
        // one worker, timeout scale 1; all three pairs passed without retries.
        'seconds' => 84,
        'downsStand' => true,
    ],
    // Where every log line of a node lands, proven on the live tasks stand (HIL-1018): five
    // scenarios in sequence over demo/tasks, judged against scripts/log-streams.php. In the
    // tasks group so it never overlaps tasks-check, tasks-php or tasks-e2e over one stand,
    // and on tasks rather than chat because the chat group is the floor of the whole run
    // (1339s end to end) and this step would lift it; the tasks group has the room. Takes its
    // stand down at any outcome, standalone as well as here.
    //
    // No ordering edge: the old cluster step held back log-streams after scenario 9 ("s1 seen
    // offline after its daemon died") timed out beside it twice on the old box (2026-09-17, runs
    // 0350/0351). On the test box, ecommerce-shop-cluster carries that scenario and overlapped
    // log-streams for 113–136s in 23 full runs (2026-10-02/03, runs 0748–0773), all green.
    // Like any fleet it may run beside this step (the head of this file); a red beside a fleet
    // is read by its SNAPSHOT.txt and a re-run alone.
    [
        'id' => 'log-streams',
        'command' => 'composer run test:log-streams',
        'cwd' => '.',
        'stand' => 'tasks',
        'deps' => [],
        'group' => 'tasks',
        'tags' => ['backend', 'demo'],
        'seconds' => 112,
        'downsStand' => true,
    ],
]);

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
    $ownBuild = isset($seconds['build']);
    if ($ownBuild) {
        // The demo's frontend build, made once for its e2e and its cluster browser; in
        // the demo's group, because `test:e2e-full` next door takes the project down.
        $steps[] = [
            'id' => $demo . '-build',
            'command' => 'composer run test:e2e-build',
            'cwd' => 'demo/' . $demo,
            'stand' => $demo,
            'deps' => ['fe-build'],
            'group' => $demo,
            'tags' => ['frontend', 'e2e', 'demo'],
            'seconds' => $seconds['build'],
        ];
    }
    $steps[] = [
        'id' => $demo . '-e2e',
        'command' => $ownBuild ? 'composer run test:e2e-prebuilt' : 'composer run test:e2e-full',
        'cwd' => 'demo/' . $demo,
        'stand' => $demo,
        'deps' => [$ownBuild ? $demo . '-build' : 'fe-build'],
        'group' => $demo,
        'tags' => ['frontend', 'e2e', 'demo'],
        'seconds' => $seconds['e2e'],
        // Every part of either chain but `playwright test` is marked
        // `@no_additional_args`, so the tests a re-run names reach Playwright alone.
        'takesTests' => true,
    ];
}

return $steps;
