# Testing — Agent Guide

Quick reference for running tests across the repo. All test commands go
through Docker (`docker compose ... run --rm chat-cli-test ...`) and are
wrapped in `composer` scripts — **do not invoke `phpunit` / `vendor/bin/phpunit`
directly from the host**, especially on Windows (vendor binaries and MySQL
live inside the test container).

---

## Environment split — use the test env, not the developer's sandbox

The local environment is the **developer's** running sandbox: the `daemon-start`
/ `daemon-stop` / `daemon-restart` / `daemon-monitor` scripts
(`docker-compose.local.yml`) and the local database are theirs, and local
migrations apply automatically on a local daemon restart. An AI agent works the
**test** environment instead — the `test:*` composer scripts below, against the
test database — and does not touch the local daemon or run local migrations
(`db:migration:up/down` against local is the developer's). This keeps an agent
out of a running local sandbox.

---

## Framework backend (`framework/`)

Composer scripts live in the repo-root `composer.json`:

| Script | What it does |
|---|---|
| `composer run test:framework:install-deps` | Install PHPUnit & framework dev deps inside the test container. |
| `composer run test:framework:up` | Start `mysql-framework-test` and the backup receiver, and wait until both are healthy. |
| `composer run test:framework:databases` | Recreate the databases of the integration pieces, `hilos-framework-test-1`…`4`, as root inside the database container. Needs `up`. |
| `composer run test:framework:piece -- <K>` | Run integration piece K on its own database: the classes whose crc32 of the short name lands on K, as defined in `scripts/framework-pieces.php`. Needs `up` and `databases`. |
| `composer run test:framework:unit` | Run framework unit tests (`framework/tests/Unit`). |
| `composer run test:framework:integration` | Run framework integration tests (`framework/tests/Integration`). Requires DB. |
| `composer run test:framework:phpunit` | Run both PHPUnit suites. |
| `composer run test:framework:all` | Run the framework backend steps of the graph, tag `framework-php`: `framework-unit` beside the integration pieces, each on its own database; the stand comes up before them and goes down after a green finish. |
| `composer run test:framework:down[-volumes]` | Stop (and optionally wipe volumes). |

---

## demo/chat backend (`demo/chat/`)

Composer scripts live in `demo/chat/composer.json`. Run from `demo/chat/`:

| Script | What it does |
|---|---|
| `composer run test:up` | Start `mysql-test`. |
| `composer run test:db-wait` | Wait until the test DB is reachable. |
| `composer run test:db-reset` | Drop + recreate the test schema (`cli.php test:db:reset`). |
| `composer run test:unit` | Run unit tests (`tests/Unit`). No DB needed. |
| `composer run test:integration` | Run integration tests (`tests/Integration`). Requires DB. |
| `composer run test:phpunit` | Run both PHPUnit suites. |
| `composer run test:all` | `db-reset` → `phpunit` → `down`. Runs every available test type for demo/chat. |
| `composer run test:down[-volumes]` | Stop (and optionally wipe volumes). |

**Typical local loops:**

- Pure unit test iteration: `composer run test:unit` (fast, no DB).
- Integration iteration: `composer run test:up && composer run test:db-reset && composer run test:integration`
  (first run and again after a `test:down`: the database lives in memory;
  subsequent iterations can skip `db-reset` only if the test mutates neither
  schema nor data — a data-mutating test needs a reset before each rerun).
- Full pass before a PR: `composer run test:all` (PHPUnit suites).

---

## Code-style guard and its baseline

`composer run test:framework:unit` also runs the machine-checkable code-style
rules over `framework/backend`, `framework/tests`, `demo/*/backend`, and
`demo/*/tests`. A failure names the file, the line, the rule id, and the document
that owns the rule.

Existing debt lives in `framework/tests/CodeStyle/baseline.txt`, anchored to a
file and a count, and every record names the leaf that will remove it. The
baseline can only shrink: a fresh hit fails, and so does a record that has
nothing left to cover. After paying debt off, regenerate it with
`CODESTYLE_BASELINE_UPDATE=1` — the run rewrites the file and then fails on
purpose so the diff gets reviewed. Regeneration goes downwards only: it lowers
counts and drops records, while raising a count or adding a record stays a
person's hand-written decision. Full command and the `--user` caveat are in
[code-style/automated-checks.md](code-style/automated-checks.md).

---

## An issue fails the run

Every `phpunit.xml` in the repository — `framework/tests` and each demo's
`tests` — sets `failOnDeprecation`, `failOnNotice` and `failOnWarning` to
`true`. A deprecated call, a notice or a warning is therefore a red run, not a
line in a summary nobody reads. PHPUnit prints the details itself: each
`failOn*` raises the matching `displayDetailsOn*`, so the output names the
file, the line and the test that triggered the issue.

`failOnAllIssues` is deliberately **not** used: it also turns on
`failOnSkipped`, `failOnIncomplete`, `failOnRisky` and
`failOnEmptyTestSuite`, and the tree carries 15 deliberate skips that each have
their own reason. `failOnPhpunitDeprecation` is not used either — PHPUnit's own
deprecations arrive with a version bump, not with a change someone made, so the
line would go red on a defect nobody introduced.

**The way out is per test, never per config.** A test that must live with an
issue carries `#[IgnoreDeprecations]` (class or method) for a deprecation, or
`#[WithoutErrorHandler]` (method) for a warning or a notice, with a comment
saying why. PHPUnit 11 has no narrower attribute for warnings and notices.
Removing one of the three attributes from a configuration is **not** a way out
— it is undoing the gate, and it takes a person's decision.

`PhpunitIssueGateTest` in the framework unit suite keeps the gate on: it reads
all eight configurations — the framework's and one per demo — and requires the
three attributes. The next demo is added to the list inside that test — a demo
born without its gate fails there instead of running quiet.

*Why the gate exists.* The warning that a walk over a mutating collection was
skipping a row was printed on every run and counted by nobody, and it stayed in
the tree until it cost a wrong session (HIL-673).

---

## Selective testing — what to run for which change

Match the test set to what changed; do not run everything for every edit. The
heavy suites (full e2e, two-window) cost a Docker stack per demo and minutes of
wall-clock, so they are a **deliberate, infrequent** run — never an inner loop.

| What changed | Run | How often |
|---|---|---|
| PHP backend logic (framework or a demo) | the affected side's PHPUnit — `test:framework:phpunit`, or a demo's `test:phpunit` | every change |
| A project topology registry — `Hilos::PAGES` / `GROUPS` / `AGENTS` / `TABLES` / `ACTIONS` / `SIGNALS` / `AGENT_SIGNALS` | that demo's `test:unit` — the `*TopologyRegistryTest` snapshot guard stays red until the new page / agent / action / signal is added to it | every registry change |
| FE core / SDK or a view (`@hilos/*`, TS) | `test:framework:frontend` (check + vitest + lint + format) | every change |
| An Angular view's template | `test:framework:frontend` — plain `tsc` does not read templates, so the `@hilos/angular` check runs a second pass, `ngc --noEmit` over the `tsconfig.build.json` the ng-packagr build uses; a template error — and an Angular extended template diagnostic (`NG8xxx`), which `tsconfig.build.json` raises to an error — is red here, not only in `test:framework:frontend:build` | every Angular template change |
| Wire / signal / subscription **contract** (backend + FE together) | the above **plus** one affected demo's `test:e2e-full` — the cross-boundary path only e2e exercises | when the contract moves |
| Cluster behavior — consensus, membership, placement, the peer link and its TLS, what a write on one node does on another (`framework/backend/Cluster/`, the cluster seams of `DaemonManager` and `WorkerServer`, the harness in `framework/docker/cluster/`) | the scenarios that cover it, on the stand that carries them, pointed: `composer -d demo/<demo> run test:cluster:scenarios -- <numbers>` — a fresh stand every time; that stand's whole matrix before the merge. Which stand carries which scenario: section "The cluster stands — three demos, three shapes" below | when cluster code moves |
| An e2e spec or a selector | that demo's full cycle, pointed: `composer run test:e2e-full -- <spec file>` or `-- --grep "<title>"` — the same clean stand as the full run, so a repeat is a fresh verdict | while editing the spec |
| Cross-connection behavior — subscription, viewport, pending/Apply, presence | the **two-window** e2e across the affected demos (and a full pass) | rarely — see below |
| Accessibility — ARIA roles/names, keyboard, focus, screen-reader semantics | the **a11y** e2e (`a11y.spec.ts`) across the affected demos (and a full pass) | rarely — see below |

**A pointed cycle is the full cycle.** Every link of `test:e2e-full` but `@test:e2e`
carries Composer's `@no_additional_args`, so what follows `--` reaches only
`npx playwright test`: teardown, database reset and daemon boot run exactly as they
do unpointed. A filter that matches no test is red (`No tests found`), not green.
It needs Composer ≥ 2.8.0 — an older one hands the marker to `test:down` as an
argument, and the first link is red on an unknown stand. Bringing the stack up once
and repeating `test:e2e` against it is not a verdict: the database is dirty between
runs. `E2eFullPointingTest` in the framework unit suite keeps the markers on.

**The rare, full run** is `composer run test:frontend:all` — the `frontend` slice
of the step graph below (FE install + build + check / vitest / lint, then every
demo's `test:check` and `test:e2e-full`). Run it when:

- a change touches the subscription / viewport / pending / cross-connection path,
  where a single tab cannot reveal the bug; or
- a change touches accessibility — ARIA, keyboard operability, focus, or
  screen-reader semantics; or
- before collapsing or merging the branch, as the final gate.

Its npm installs and SDK builds are **idempotent and self-skipping**: every
`npm ci` / `npm install` in the test targets goes through
`npm-install-if-stale.mjs` and every SDK build through `prebuild-sdk.mjs`, so a
second run on an unchanged tree performs neither, and says on stdout which it
skipped and why. Nothing is skipped by looking at a diff, and no test or check is
ever skipped — only work whose output is already on disk and provably current
(see [frontend/build-and-docker.md](frontend/build-and-docker.md)). If a run
looks suspiciously cheap, the guards' own log lines are the first place to check.

It is **not** part of the inner loop. The two-window coverage lives in chat's
bots and profile specs (Vue; and so do binance-btc-tracker's `settings.spec.ts`
and `users.spec.ts`) and the `users.spec.ts` of ecommerce-shop (React) and
online-testing (Angular) — one representative path per view layer. The **a11y** coverage is the same kind of
separate, rarely-run category — an `a11y.spec.ts` per demo asserting the
accessibility tree over the live socket: table accessible names and `aria-sort`,
keyboard sort operability, the skip link and `aria-current`, the document title
and page-change announcement, one top-level heading per page, and presence
exposed as text. Run it in the full pass or pointed (`test:e2e-full -- a11y.spec`)
while editing a11y; a green inner loop (check + vitest + pointed phpunit) does not
require re-running them. The normative AA requirements those specs guard are in
[frontend/accessibility.md](frontend/accessibility.md).

A data-mutating e2e is **re-run through the full cycle, pointed or not** — the cycle
resets for you; see [Re-running tests and state between runs](#re-running-tests-and-state-between-runs).

### What no run proves

What only a person sees on a real device or at a real provider is listed in
[manual-checks.md](manual-checks.md). A change touching one of those surfaces
names the affected numbered items in its hand-over for acceptance; the person
records the result there. A green run does not stand in for those checks.

### A cross-process defect is an e2e defect first

A defect where **two processes see different state** — the master appends an
agent's log and a worker asks for its size; the daemon and the CLI; two nodes of
a cluster — is proved on the stand, where both processes are real rather than
impersonated. That is the default, and it is already how the log tail is tested:
`demo/binance-btc-tracker/tests/e2e/helpers/logs.ts` asks the daemon to append
lines over its own command channel, the log-store agent writes them, the master
files them, and the front end reads them through a worker
(`demo/binance-btc-tracker/tests/e2e/tests/logs.spec.ts`). The helper's docblock
(`demo/binance-btc-tracker/tests/e2e/helpers/logs.ts:14`) says why it does not append to the
file itself: "Appending to the file from here instead would prove only that a
file grew."

Leave that step only by **naming what e2e cannot see**. There are exactly three
such reasons, all about observability and none about convenience:

1. the state never leaves the process — a cache inside it, a counter, a
   descriptor;
2. the window in which the defect is visible is shorter than one tick of the
   stand;
3. the missing participant is an **external service** the stand does not reach.

The third reason leads to the second step, not past it: an external service is
**impersonated on the stand**, not worked around with a second process. The stand
gateway (`framework/docker/stand-gateway`) is one container running the
framework's own TLS server on port 18000, and the channel is the path prefix
(`framework/docker/stand-gateway/src/StandGatewayTlsServer.php`, `SmsRoutes.php`,
`TelegramRoutes.php`); whatever it catches lands in Mailpit and is read by the
helpers a spec already uses for a code — `framework/frontend/scripts/standSms.mjs`
and `framework/frontend/scripts/standTelegram.mjs`. That inbox, the house, and the
rule for adding a resident are [stand-services.md](stand-services.md).

Only when neither step applies may a unit test spawn a second process, and then
the reason goes into the test's docblock. What such a test's own tooling erases,
and which sample to copy, is under [Writing new tests](#writing-new-tests).

---

## The full run — one graph, a bounded number of lanes

Everything the project can be tested with is one graph in
[`scripts/test-suite.php`](../../scripts/test-suite.php), executed by
[`scripts/run-test-suite.php`](../../scripts/run-test-suite.php):

```
composer run test:suite                       every step
composer run test:frontend:all                the `frontend` tag, plus its dependencies
composer run test:framework:all                the `framework-php` tag, plus its dependencies
php scripts/run-test-suite.php chat-e2e       one step, plus its dependencies
php scripts/run-test-suite.php --list         the plan, without running it
```

A target is a step id or a tag, and whatever it selects pulls its dependencies in.
Steps run **concurrently up to a global limit**, the longest expected step first —
by its **own** duration, not by the chain waiting behind it. The limit is sized
from the machine ([`scripts/lane-count.php`](../../scripts/lane-count.php)): a
lane per two cores, less one lane left to the machine itself, never more than one
lane per 2 GiB of available memory, and never fewer than one. The box of the line,
at 16 cores, takes 7; eight cores take 3; four cores, or a machine that does not
report its size, take **1**, so a small CI runner degrades to the serial run
instead of thrashing. `HILOS_TEST_LANES` or `--lanes=N` overrides it. What decided
the number is printed in a line of its own — `lanes: 7 (adaptive: 16 cores, 55.2
GiB available)` — by the run before its first step and by `--list` under the head
of the plan.

The lane count is **not a timeout multiplier** (HIL-1227). The runner sets no
timeout variable at all: every suite — Playwright of each demo, the cluster
harness — derives its factor from the host it runs on, load per CPU and available
memory, the same way inside the full run and outside it. `HILOS_E2E_TIMEOUT_SCALE`
and `CLUSTER_E2E_TIMEOUT_SCALE` remain a pin made by hand. What each suite does
with its factor — and what a test longer than its cap owes — is in
[frontend/testing-strategy.md](frontend/testing-strategy.md).

The graph is where the safety lives, and two kinds of constraint carry it:

- **an edge** — the frontend steps of a demo (`<demo>-check`, `<demo>-e2e`) depend
  on `fe-build`, because every demo frontend prebuilds the **same**
  `framework/frontend` workspace. Concurrency is safe only because a current SDK
  makes those prebuilds skip. Do not delete that edge to free up a lane.
  `<demo>-php` is backend-only and waits for nothing.
- **a group** — the steps of one demo share one compose project, and
  `test:e2e-full` starts by taking that project down. Group members never run at the
  same time, but a red one does not skip the others: `<demo>-php` is backend-only
  and keeps its own verdict when `<demo>-check` fails.

**A database per piece.** Framework integration runs as four graph steps against
one database container. `framework-up` recreates their separate databases; each
test class belongs to one piece in full. Shared stand resources, such as the backup
receiver or MariaDB server settings, may therefore be touched by only one class.
The pieces need no edge or group between them.

Neither the order nor the lane count is the lever it looks like. The three steps of
the `chat` demo share a group — one stand — so 106 + 1227 + 6 = 1339s of them can
never overlap: **22m19s is the floor of a full run at any lane count**, measured
2026-09-29 on nova-de at seven lanes (HIL-1227, run 0659). That run took 22m20s,
every other step was done by 7m08s, and seven lanes could otherwise pack the whole
graph into 6m25s. The next lever is an instance of the stand as a parameter, so that
`chat-e2e` can be cut across stands of its own. The numbers are in the head of
[`scripts/test-suite.php`](../../scripts/test-suite.php).

**Any cluster fleet may run beside any e2e step.** No edge keeps them apart and the
order does not either; a fleet leaves with its own step (`downsStand`), which is
hygiene rather than separation. A red step beside a fleet is read like any other —
the neighbours in the step's `SNAPSHOT.txt`, then the re-run alone described below.

There is **no fail-fast**. A red step skips what depends on it, unrelated branches
finish, and the runner exits non-zero if anything was red. Each step writes its own
log under `var/test-suite/`, its output is replayed to stdout between a `START` and
an `END` line once it finishes, and `<log-dir>/rc` carries one `<id> rc=<n>` line
per step — the run stays attributable line by line even though the steps overlap.

A **full run sweeps the evidence of steps the manifest no longer lists** — the log out
of `var/test-suite/`, the snapshot out of `var/test-suite/artifacts/` — and prints an
`=== swept: <N> step(s) no longer in the manifest ===` section when it removed
anything. Re-running one step still touches nothing but its own evidence, exactly as
before, because only a full run can tell "not in the plan" from "not there at all".
The price this pays for: a log left behind by a deleted step answers a grep across the
whole directory and reads as fresh, which on HIL-853 handed a reader the very line the
fix under test had just removed — out of a demo `scripts/test-suite.php` does not
contain at all. The `rc` ledger is never swept.

### A step that only passed on a retry says so

Playwright retries twice in CI, so a flickering test leaves its step `ok` and the
run's exit code zero. That verdict stands, but it is no longer silent: the step's
ledger entry grows a field (`chat-e2e rc=0 unstable=3`), keeping the `<id> rc=<n>`
grammar every reader already parses, and the summary ends with an
`=== unstable: <S> step(s), <T> retried test(s) ===` section naming the specs. A
run with nothing to report prints neither, so a green log is byte for byte what it
always was and the section appearing at all means there is something to look at.
Who owns a named flicker — and why it is not automatically the ticket in hand — is
in [frontend/testing-strategy.md](frontend/testing-strategy.md).

### A red step under concurrency is not a verdict

Re-run it **alone on the same HEAD** — `php scripts/run-test-suite.php <id>
--lanes=1` — before believing it:

- **red again** — real. Treat it as any other failure.
- **green alone** — the step is green. Name the test that went red as possibly
  flaky, with the run it failed in and the run it passed in, and go on. Do not
  repeat the full run, at one lane or at several: a full run at one lane took
  about 55 minutes with no fail-fast and never changed the verdict, and a
  flicker is caught by watching the tests named this way, not by rerunning the
  suite.

What must never happen is a red waved off as "probably the neighbour" without that
re-run: it is exactly how a genuine regression reaches the base wearing the excuse
of concurrency. The check is cheap — one demo block is 1–3 minutes and `chat-e2e`,
the longest step there is, about twenty — and it only happens on red. The other
half of the defense is that Playwright's caps stretch with host load rather than
firing ([frontend/testing-strategy.md](frontend/testing-strategy.md)).

## The cluster stands — three demos, three shapes

The framework's multi-node behavior is proved on the cluster stands of three
empty demos, each with its own shape of Hilos cluster and its own MySQL
topology. All of it runs in docker on one machine: a multi-machine stand does
not exist, and HIL-348 closed without one. The one stand that carried every
scenario on a single shape is retired (HIL-1218).

| Demo | View | Hilos cluster | MySQL | Scenarios |
|---|---|---|---|---|
| binance-btc-tracker | Vue | three masters, two slaves and `x1`, a node of a foreign authority | one server | 1 master-slave mesh, 2 master-master, 5 leader-kill re-election, 7 quorum-loss, 8 split-brain prevention, 10 cross-node browser, 13 rt partition converges (skipped as flaky, P-169), 17 foreign certificate refused, 20 rt set width across nodes, 23 verifier circle on every master, 25 freeze settles on every master, 29 a node with a cluster directory of its own refused on both ends, 30 a ready data export copy outlives the node its agent lived on, 32 a node with another admin view mode refused on both ends, 33 every master takes browsers, 34 a tab is the same on every master under protected mode, 35 rt row deleted while cut off is swept, 36 a slave cut off with its leader stops its work before it runs elsewhere |
| ecommerce-shop | React | one master and two slaves of unequal room, `ram=10` and `ram=4` | a primary and an asynchronous read-only replica; the nodes know only the primary's address, and nothing reads the replica | 3 placement, 4 slave-kill failover, 6 hot-join, 9 daemon-crash self-heal (parked as flaky), 12 rt replication, 14 rt claim refused, 16 recreated node leaves no phantom fleet (parked, P-441/2), 18 capacity is consumed, 19 worker death on a live node, 31 the replica keeps up with the primary |
| online-testing | Angular | three equal masters that host work themselves | a MariaDB Galera of three members behind one HAProxy address, every member written to, reads waiting for the cluster's writes | 11 cross-node db fact, 15 db interest addressing, 21 schema rolled out once by nodes that start together, 22 a node reading another database refused on both ends, 24 a cut-off leader stops its work before it runs elsewhere, 26 the database is one cluster of every member, the application connected to each, 27 a database member dies under load and every node writes on through the others, 28 a member that comes back catches up and is handed connections again |

Why the scenarios fall this way (the owner's word, 2026-09-27): quorum, a
network partition and TLS are proved on the simplest database, so that two
sources of nondeterminism never share one run; placement and failover are
proved where the leader is stable; everything about a shared database is
proved where the database is a real cluster.

The move goes **cluster → e2e → MySQL**. The scenarios move first, every
stand still on one database server — a scenario never changes its database in
the same step as its stand; the e2e specs move next; the MySQL topologies and
the browser on a multi-node stand come last. The one-time schema rollout went
ahead of all of them (HIL-1228): it is needed on one server too. Nodes that
start together also read one marker of their database — the first write is
decided by the insert, like the rollout claim — and a node naming another
marker is refused by every other (HIL-1206).

A stand takes rather than holds. The harness is one and shared,
`framework/docker/cluster/`: it reads the nodes, their addresses, their room
and the set of scenarios from the stand it is run against. The probe agents the
scenarios drive are the framework's (`framework/backend/Cluster/Probe/`): a demo
lists in its `AGENTS` the ones its stand's scenarios need, one row each, keyed by
the probe's type with the record taken from `ClusterProbe::AGENTS`:

```php
HilosAgentType::HILOS_PROBE_FLEET => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_FLEET],
```

A probe starts only on a node that is in a cluster and whose `APP_ENV` is not
production-like, so the same demo on one node, on its own Playwright stand and
in production carries the rows and runs none of them. Which demo takes which:
binance-btc-tracker the fleet and the runtime set probe; ecommerce-shop the
fleet, the claimer and the ballast; online-testing the fleet and the database
probe.

The stand's compose file is the one place its nodes are written, and there is
no second copy: a node is a service with `CLUSTER_ENABLED=true` and a
`CLUSTER_NODE_ID`, and its container, address, role, `ram` and log directory
are read off that service — what the node itself reads. What compose cannot
say stands in a top-level `x-hilos-cluster` block of the same file: `cli`, the
service commands to the nodes go through; `entry`, the one browser-facing service
shared by the masters (optional); `stranger`, the node under a profile
that a scenario raises on its own (optional); `cluster-directory`, the name and
in-container path of a cluster directory of the stand's `$fs`, which scenario 29
gives one node a copy of its own (optional); `scenarios`, the numbers the
stand carries — never their order, which is the harness's. A demo's composer
script calls `python3 ../../framework/docker/cluster/cluster.py <compose file>
scenarios`. A scenario the stand names but cannot carry by its shape — too few
masters or slaves, no stranger, no cluster directory — is refused before the
stand is raised, and so is a stand that leaves out what the harness reads.

A stand whose database has more than one server labels every member
`hilos.database.member: "true"` and exactly one of them `hilos.role: database` —
the one `db-sql` and the step's artifact collector reach. The nodes use one
address: online-testing's proxy over all members, or ecommerce-shop's primary
itself, never a replica. The replica is declared by `MARIADB_MASTER_HOST` and
has no `MYSQL_DATABASE`, `MYSQL_USER` or `MYSQL_PASSWORD` of its own: the primary
replicates the schema and users, and creating them locally stops its SQL thread
at the first `CREATE USER` (error 1396). The harness signs in with the database
service's credentials. Scenario 26 asks every member of a multi-primary;
scenario 31 asks every replica.
The proxy service is labelled `hilos.database.proxy: "true"`; scenario 28 reads
its server states, and a member that is not Synced takes no new connection.

Every server of a fleet's database, whether one server or a member of a cluster,
keeps its data in a host directory under `/dev/shm` and mounts
`framework/docker/mysql/test-stand.cnf`. A new fleet database server does the
same. The harness wipes these directories on `down --volumes`; a member killed
and started again keeps its data.

A demo's cluster stand is `docker/docker-compose.cluster.yml` beside its other
stacks, and a compose project of its own: the demo's e2e steps take their whole
project down when they start, and a fleet inside it would fall in the middle of
its matrix. Its nodes log to `data/logs-cluster/<node>`, and it has a TLS
authority of its own, so two stands on one machine never trust each other. It
has a step of its own in the full run, `<demo>-cluster`, which runs the demo's
`composer run test:cluster:scenarios` — binance-btc-tracker's,
ecommerce-shop's and online-testing's.

A new scenario is written on the stand whose shape it needs. When two shapes
would do, the reasons above decide. A scenario that needs a shape none of the
three has is a question for the owner, not a fourth stand grown on the way:
the three shapes are one decision, and a quiet fourth would rewrite it from
inside a leaf.

Nodes that start together on one database roll the schema out once, under
the rollout claim in the database itself (HIL-1228,
[orm/migrations.md](orm/migrations.md)): one node applies the migrations, the
rest wait for it and find the level already there. A stand therefore has no
one-shot rollout service in front of its nodes any more, and starts them all
at once on an empty database — which is what scenario 21 reads.

The `binance-btc-tracker-cluster-e2e` step drives the cluster browser suite in
`demo/binance-btc-tracker/tests/e2e/cluster/`, after the demo's single-node e2e.
The browser enters through the stand's one nginx entry, where each context's
`hilos_stand_master` cookie selects a master (HIL-1304). By default browsers and
the protected-mode operator sit on a follower master, away from the page holder
on the leader and the policy-placed agents on slaves. The live phase checks
backup, logs, protected mode and one person's tabs across masters. The harness
then stops the slave the backup spec names, waits for placement to move, and
runs the after-loss phase: the archive is out of reach and the log node offline.
`composer -d demo/binance-btc-tracker run test:cluster:e2e` runs that suite alone
on a fresh stand; `-- --grep circle` points it. The scenario and browser steps
share a group because both reset the same compose project (HIL-1232).
Before the browser stand starts, its node log directories are emptied, including
rotation archives, while tracked `.gitkeep` files stay. Save a failed run's logs
before starting another. The step runner copies `data/logs-cluster/<node>/` to
`artifacts/binance-btc-tracker-cluster-e2e/nodes/<node>/` at every verdict, before
teardown. Live logs and this run's archive and staging trees keep their relative
paths. A missing root or unreadable source is listed in `SNAPSHOT.txt` under
`missing`, without changing the test verdict; `data/logs-test` is not a substitute
for these node journals. A direct composer run has no step snapshot collector,
so preserve its journals before starting the next fresh stand.
After node loss the wait checks only placements that
host an agent (`placing` or `started`); a refused record is no running work.

The full run carries three fleets now instead of one. An e2e step may stand
beside any of them in the lane plan: nothing keeps them apart, and each fleet
leaves with its own step (HIL-1227).

The ports and the subnet of every stand are in the registry of
[../new-project/README.md](../new-project/README.md); which demo carries an
e2e spec, and whose a spec is, is in
[frontend/testing-strategy.md](frontend/testing-strategy.md), "Which demo
carries a spec".

---

## Attributing a red snapshot guard

The `*TopologyRegistryTest` snapshots are **shared** across every ticket that
touches a topology registry, so a single red run can carry more than one
ticket's missing entries at once. Before blaming a red snapshot on a foreign or
pre-existing change, read the failure diff **per entry**: check every missing
line against your own change. If any belongs to what you just registered — a
page, an agent, an `ACTIONS` / `SIGNALS` / `AGENT_SIGNALS` line — it is yours to
add, even when the rest of the diff is another ticket's debt. Do not declare
your change clean because the failure is "mostly" someone else's, and do not
route the whole test to a human on that basis. Attribute at the granularity of
the failing line, not the whole test: add your own lines, and reopen the culprit
ticket for the entries that are genuinely foreign.

---

## Re-running tests and state between runs

- A test stand's database lives in its container's tmpfs, with write durability
  reduced by `framework/docker/mysql/test-stand.cnf`. `test:down` removes the
  container and its data, so run `test:db-reset` after the next `test:up`. A
  fleet's database lives in host memory under `/dev/shm`: it survives `down/up`
  and a member's `kill/start`, while `down --volumes` (including the start of
  each scenario matrix) and a host reboot clear it.
- A test that **mutates data** is not idempotent across runs on the same database.
  Reset before re-running it — `composer run test:db-reset` for PHPUnit; for e2e,
  `test:e2e-full`, pointed or not, resets for you, and so does `test:all`. **Do not treat a
  failure on a repeated run *without* a reset as a bug** — reset is the contract;
  re-running against a dirty database is not a supported scenario.
- `test:e2e-full` **tears the stack down before it starts**, so it never inherits a
  daemon from an earlier run. It has to own that itself rather than trust its
  caller: `up -d` is idempotent, so a container left running is *kept* running, and
  a PHP daemon holds the code it loaded at boot. A backend change would then be
  measured against the process that predates it — with the frontend rebuilt around
  it, which reads as a plausible result rather than an obviously stale one. The
  stack is most likely to still be up exactly when it matters: a red run aborts the
  chain before `@test:e2e-down`.
- To test an **irreversible or time-delayed** operation repeatedly (deleting an
  orphan row, an account deleted N days after the request), do **not** engineer
  idempotency into the test. The designed path is a **test-only CLI command** —
  gated to refuse on production — that sets up or tears down the state, not an
  ad-hoc reset hack inside the test.

---

## Writing new tests

- **PHPUnit unit tests** (`tests/Unit/`): pure, no DB, no Hilos runtime.
  Use PHPUnit's `TestCase` directly. See
  `demo/chat/tests/Unit/MessageActionDTOTest.php` and
  `demo/chat/tests/Unit/ActionFailSignalDataTest.php` for reference.
- **PHPUnit integration tests** (`tests/Integration/`): extend
  `IntegrationTestCase` for a prepared test DB and Hilos bootstrap.
- For signal-layer DTOs that cross the worker → daemon IPC boundary,
  always cover the `fromArray(toArray())` roundtrip — a missing or
  broken `fromArray` silently falls back to generic `SignalData` and
  drops any `WebSocketEnvelopeAware` metadata. See
  `ActionSuccessSignalDataTest::testRoundtripPreservesConcreteTypeAndEnvelopeMarker`.
  A **new field on an existing sync DTO** needs this exactly as much as a new DTO does:
  left out of `toArray()`, it arrives as `null`, whatever guard reads it takes its
  safe-default branch from then on, and every suite stays green while the feature the
  field carries is dead.
- A test that reproduces the defect lands **before** the fix, in its own commit. When the
  fix changes a signature, write that test against the signature as it stands and adapt
  the call in the fix commit — what has to survive is the scenario, not the call. **Never
  keep the old signature, add a parallel parameter, or introduce an overload so the test
  text can stay untouched:** the test exists for the code, not the other way round.
- A green new test is not yet a useful one. Ask whether its assertion could still hold
  with the fix removed; if it could, it pins nothing. While developing, the cheapest way
  to find out is to break one line of the fix and watch the test go red. That is a
  debugging trick, not a gate — the verdict on a change still comes from one full run.
- **A second process inside a unit test is the last step of that ladder.** The
  ladder is "A cross-process defect is an e2e defect first" above: e2e first, the
  stand gateway when the missing participant is an external service, a second
  process last. A test that takes the last step says so in its docblock — or in
  the docblock of the helper that spawns the process — in one sentence naming
  what e2e could not see and why the stand gateway did not fit. Without that
  sentence the test reads as chosen for convenience, and the next author copies
  its form without knowing its price: that is how `exec()` travelled into HIL-874
  from `framework/tests/Unit/BackupRestoreCommandTest.php:547` and let a case go
  green on a broken reader.

  The trap the step is written for: **the tool a test uses to watch or arrange
  what happens can itself destroy the state the test checks.** Measured on PHP
  8.4.24 in the `hilos-cli-test` container, five probes per condition, one
  condition at a time (HIL-874). The stat cache is dropped by `exec()`, by
  `popen()`, by `proc_open()` with pipes on any write into a pipe, by `fopen()`,
  and by **any PHPUnit assertion** placed between the other process's write and
  the repeated question about the file. What survives: `proc_open()` **without
  pipes** plus `proc_close()`, and not one assertion inside the measured window —
  "the child appended nothing" is asserted after the window, not inside it.
  `exec()` is not banned as a tool; it is banned as the way to arrange or observe
  state the test then reads through file metadata. Building a fixture with it
  stays legitimate, and the suite does so in three places:
  `framework/tests/Unit/AiToolingInstallerTest.php:234`,
  `framework/tests/Unit/BackupRestoreCommandTest.php:547`,
  `framework/tests/Integration/BackupRestorerIntegrationTest.php:677`.

  A fork is a form of its own. Forking is banned by the `PROCESS-FORK` guard
  (see [process-fork.md](code-style/process-fork.md)) and admitted only by an
  allow-list entry backed by the owner's decision. The child inherits the live
  PHPUnit run, so: an immediate `exit()` at the end of the child's branch; not
  one assertion on the child's path — a red one there does not fail the test, it
  carries the child to the end of the run and prints a second PHPUnit report;
  and the wait in `finally`, through `pcntl_waitpid()`. The suite carries no fork
  today: its only one, the regression of HIL-732, left with HIL-929 once a
  scenario on the stand turned red on the defect every time. History keeps the
  form — `serveTlsResponseInChild()` in
  `framework/tests/Unit/AsyncHttpClientTest.php` as of `c32457783` (`:441`; the
  `exit` at `:494`, the wait at `:155`).

  Two samples, and what picks between them. `framework/tests/Unit/OrphanReaperTest.php`
  (HIL-450) is the sample of **structure**: real children through the
  framework's `Hilos\Core\Process`, stopped in `tearDown()` (`:57-70`), a
  readiness barrier instead of a blind sleep, and its own trap of the same kind
  in the class docblock (`:22-29`). But `Process` opens three pipes by default
  (`framework/backend/Core/Process.php:100-102`, `:122`), so a test that measures
  **file metadata** cannot use it; that form is the bare pipeless `proc_open()`
  of `framework/tests/Unit/Log/LogLineReaderAppendedTest.php:244`
  (`appendFromAnotherProcess()`, docblock `:226-243`). Measuring the live process
  table — `Process`; measuring a file's metadata — bare `proc_open()` without
  pipes.
- **Time-based features** (grace periods, token/session expiry, digests,
  scheduled rounds/settlement): there is no global clock to mock — see
  `cli/commands.md` § "Time-based features: no universal clock". Add a small,
  per-feature test-only CLI (`extends TestOnlyCommand`) that ages the one stored
  timestamp so the scheduled logic fires now; never build a shared time-travel knob.
