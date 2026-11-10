# Testing — Agent Guide

Quick reference for running tests across the repo. All test commands go
through Docker (`docker compose ... run --build --rm chat-cli-test ...`) and are
wrapped in `composer` scripts — **do not invoke `phpunit` / `vendor/bin/phpunit`
directly from the host**, especially on Windows (vendor binaries and MySQL
live inside the test container).

A test script or graph step builds the images it runs itself, from the plain
Docker cache, before it starts: after a Dockerfile edit nothing needs rebuilding
by hand before the tests. A failed build fails the step with a non-zero exit
code, so tests never run on the old image.

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
| `composer run test:framework:up` | Build the backup receiver image from the cache, start `mysql-framework-test`, `mysql-framework-remote-test`, `mysql-framework-catalog-test`, and `sshd-framework-test`, and wait until they are healthy. |
| `composer run test:framework:databases` | Recreate the integration-piece and derived change-log schemas on both MariaDB services. Needs `up`. |
| `composer run test:framework:piece -- <K>` | Run integration piece K on its own database: the classes whose crc32 of the short name lands on K, as defined in `scripts/framework-pieces.php`. Needs `up` and `databases`. |
| `composer run test:framework:unit` | Run framework unit tests (`framework/tests/Unit`). |
| `composer run test:framework:integration` | Run framework integration tests (`framework/tests/Integration`). Requires DB. |
| `composer run test:framework:phpunit` | Run both PHPUnit suites. |
| `composer run test:framework:all` | Run the framework backend steps of the graph, tag `framework-php`: the four integration pieces run sequentially, each on its own database, beside the independent `framework-unit`; the stand comes up before them and goes down after a green finish. |
| `composer run test:framework:down[-volumes]` | Stop (and optionally wipe volumes). |

Framework integration uses two real MariaDB services on the stand network:
`mysql-framework-test` and `mysql-framework-remote-test`. The remote service has
its own host and credentials, no published host port, and the same configured
physical database names on a separate server. `test:framework:databases`
recreates the same piece schemas and derived `-change-log` schemas on both
services, using the current primary piece names, then grants each service's app
user access to them. The remote service supports real cross-server ORM routing
tests; the built-in change log stays on the primary server's derived schema and
uses row connection 0. The public composer target names are unchanged, and the
framework unit runner has no database-service dependency.

`mysql-framework-catalog-test` is a third MariaDB service, owned by
`FullRestoreTriggerIntegrationTest`. Its global TRIGGER grant observes every
schema on that server, so a separate schema on either shared service cannot
isolate its prepare/recheck boundary from concurrent pieces. The fixture uses
`HILOS_TEST_CATALOG_DB_HOST`, creates and drops its own schemas and accounts,
and opens its root control connection on `information_schema`; it needs no
piece schemas or application account from `test:framework:databases`. The
service inherits the remote service's image, tmpfs, healthcheck and network,
with no published port. Other framework integration classes keep using the
primary and remote services. All four integration pieces share the
`framework-integration-catalog` group and run sequentially: server-wide metadata
observers on a shared service can see DDL from other piece schemas. Framework
unit tests and other stands remain independent and may run in parallel.
`MomentStorageSchemaIntegrationTest`
uses the existing remote service for its own disposable pair of named schemas,
with a root control connection to `information_schema`; it creates and removes
that pair on every outcome. This keeps its live DDL away from the primary
service's server-wide metadata observers and introduces no new test service or
environment variable.

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

Moment storage has a separate source guard and historical registry. The source
check is `MomentStorageGuardTest`, run by `composer run test:framework:unit`;
it scans the available framework and demo backend roots and reconciles exact
findings against
`framework/tests/CodeStyle/moment-storage-baseline.json`. That versioned JSON
registry is independent of `baseline.txt`; `CODESTYLE_BASELINE_UPDATE=1` does
not update it. Its exact tuple, ownership, coverage and payoff rules are in
[Moment Storage Checks](code-style/automated-checks.md#moment-storage-checks).

Live schema checks are an opt-in integration door, separate from the unit
source scan. `composer run test:framework:integration` includes
`MomentStorageSchemaIntegrationTest`; run it after
`composer run test:framework:up` and `composer run test:framework:databases` as
in the framework setup above. The existing demo suites — chat, polls, tasks,
binance-btc-tracker, online-testing, ecommerce-shop and preferans — run their
live moment audit through `EntitySchemaConsistencyTest` with that demo's usual
`composer run test:integration` command from its root after the documented
`test:up` and `test:db-reset` setup. These runs cover the physical axes
`moment_column_missing`, `moment_type` and `moment_auto_write`; the framework
unit source rule reports `MOMENT-WRITE`. A source-only green result does not
replace the live schema pass.

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
| Cluster behavior — consensus, membership, placement, the peer link and its TLS, what a write on one node does on another (`framework/backend/Cluster/`, the cluster seams of `DaemonManager` and `WorkerServer`, the harness in `framework/docker/cluster/`) | the scenarios that cover it, on the stand that carries them, pointed: `composer -d demo/<demo> run test:cluster:scenarios -- <numbers>` — a fresh stand every time; that stand's whole matrix before the merge. Which stand carries which scenario: section "The cluster stands — six demos, six shapes" below | when cluster code moves |
| An e2e spec or a selector | that demo's full cycle, pointed: `composer run test:e2e-full -- <spec file>` or `-- --grep "<title>"` — the same clean stand as the full run, so a repeat is a fresh verdict | while editing the spec |
| Cross-connection behavior — subscription, viewport, pending/Apply, presence | the **two-window** e2e across the affected demos (and a full pass) | rarely — see below |
| Accessibility — ARIA roles/names, keyboard, focus, screen-reader semantics | the **a11y** e2e (`a11y.spec.ts`) across the affected demos (and a full pass) | rarely — see below |

**A pointed cycle is the full cycle.** Every link of `test:e2e-full` but `@test:e2e`
carries Composer's `@no_additional_args`, so what follows `--` reaches only
the shared Playwright entry: teardown, database reset and daemon boot run
exactly as they do unpointed. A filter that matches no test is red (`No tests found`), not green.
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
and `users.spec.ts`), the `users.spec.ts` of ecommerce-shop (React), polls's
`profile.spec.ts` (Angular — edits in another tab of the same session) and
online-testing's `users.spec.ts` (Angular) — representative paths per view layer. The **a11y** coverage is the same kind of
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
php scripts/run-test-suite.php chat-e2e --lanes=1 --tests=tests/bots.spec.ts:71
                                              named tests of one e2e step, alone
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
and `CLUSTER_E2E_TIMEOUT_SCALE` remain a pin made by hand, with one exception in
the graph: `binance-btc-tracker-cluster-e2e` pins both at 2 in its command, because
it runs beside the demo e2e steps and reads its factor on a loaded box (the
owner's decision, 2026-10-25). The pin silences the load term, so a test that
hangs there costs twice its cap. What each suite does
with its factor — and what a test longer than its cap owes — is in
[frontend/testing-strategy.md](frontend/testing-strategy.md).

The graph is where the safety lives, and two kinds of constraint carry it:

- **an edge** — the frontend steps of a demo (`<demo>-check`, `<demo>-e2e`) depend
  on `fe-build`, because every demo frontend prebuilds the **same**
  `framework/frontend` workspace. Concurrency is safe only because a current SDK
  makes those prebuilds skip. Do not delete that edge to free up a lane.
  `<demo>-php` is backend-only and waits for nothing. A demo with a `build`
  duration in `$demos` — binance-btc-tracker alone — builds its frontend in
  `<demo>-build` (`test:e2e-build`); its `<demo>-e2e` depends on that step and runs
  `test:e2e-prebuilt`, the full chain without the build, so nothing else in the
  graph writes `frontend/dist` while its cluster browser serves it.
- **a group** — the steps of one demo share one compose project, and
  `test:e2e-full` starts by taking that project down. Group members never run at the
  same time, but a red one does not skip the others: `<demo>-php` is backend-only
  and keeps its own verdict when `<demo>-check` fails.

**A database per piece.** Framework integration runs as four graph steps against
the primary and remote database services. `framework-up` starts both, and
`framework:databases` recreates each piece's separate databases on both servers;
each test class belongs to one piece in full. Separate schemas do not isolate
server-wide catalog observers from another piece's DDL. All four pieces therefore
share `group => 'framework-integration-catalog'` and run sequentially. Each still
depends only on `framework-up`, so a failed piece does not skip another piece.
Framework unit tests and other stands may run in parallel with this group, and
teardown still waits for unit and all four pieces to finish green. Shared stand
resources, such as the backup receiver or MariaDB server settings, may be touched
by only one class.

Neither the order nor the lane count is the lever it looks like. The three steps of
the `chat` demo share a group — one stand. On 2026-09-29, their measured
106 + 1227 + 6 = 1339s could not overlap: **22m19s was the floor of that graph
at any lane count** (HIL-1227, run 0659, the test box, seven lanes). That run
took 22m20s, every other step was done by 7m08s, and seven lanes could otherwise
pack that graph into 6m25s. These are historical measurements, not a current
baseline or a promised duration after the
[ordinary carrier expansion](#ordinary-demo-carriers).
The numbers are in the head of
[`scripts/test-suite.php`](../../scripts/test-suite.php).

**Stand instances.** Several copies of every stand can stand on one host at once, one
per working tree. An instance is a number from 1 to 9:
`eval "$(php scripts/stand-instance-env.php <n>)"` exports its environment, and
everything run in that shell — the runner, a demo's composer script, a teardown —
lands on that instance and leaves the others alone. The instance appends `-s<n>` to
every compose project, container, explicitly named volume and network and `/dev/shm`
directory, moves each stand's `10.x.0.0/16` network (and every fixed address on it) to
an octet of its own, and leaves host ports to docker. Without those variables a stand
is exactly what its compose file says: the default instance. Service keys are not
renamed — they are the host names inside a stand's own network. A new compose file
that pins a name, a subnet, an address or a host path takes `${HILOS_STAND_SUFFIX:-}`
or its stand's `HILOS_STAND_NET_*` octet the same way; the table of octets lives in
[`scripts/stand-instance.php`](../../scripts/stand-instance.php). The table is
written in blocks: the octet an instance occupies does not shift, and a new stand
opens its own block rather than extending an old one.
`framework/tests/Unit/ClusterStandIsolationGuardTest.php` holds every cluster stand
to this, and a stack with a `docker/docker-compose.cluster.yml` needs its row in
`scripts/test-stands.php`.

**CPU weight of a stand.** Every stand service reads `cpu_shares` from
`HILOS_STAND_CPU_SHARES`; unset, it is `0`, which docker reads as its own default.
A check run beside a full run on another instance lowers it, so the full run keeps
the processor when both want it. A new stand service takes the same line.

**Log rotation of a stand.** Every service that runs a daemon sets
`LOG_ROTATION_CRON=0 3 29 2 *`, in `docker/cluster/common.env` on a cluster stand,
so a test stand never rotates its logs on its own at night. A scenario that needs a
rotation asks for one: see
[Time](frontend/testing-strategy.md#time--a-command-brings-the-moment-to-now).
`framework/tests/Unit/StandLogRotationGuardTest.php` holds every `demo/` row of
`scripts/test-stands.php` to this, so a new demo takes the same line.

**Any cluster fleet may run beside any e2e step.** No edge keeps them apart and the
order does not either; a fleet leaves with its own step (`downsStand`), which is
hygiene rather than separation. A red step beside a fleet keeps the full run red.
The neighbors in its `SNAPSHOT.txt` and host load are diagnostic evidence, as are
the narrow re-runs described below.

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

### A test that only passed on a retry makes the step red

**The full run owns the verdict.** A test that passes only on a retry (↻) makes
its step `FAILED`, even if the underlying command exits zero. The runner reads
`hilos-unstable` from the completed step's own log before releasing dependencies:
`effectiveRc = processRc != 0 ? processRc : (unstable.count > 0 ? 1 : 0)`.
A native nonzero exit code is preserved. END, artifacts, the ledger and SUMMARY
all use this one result; failed dependencies skip their descendants while
independent branches finish. A failure or a skipped step makes the run nonzero.

The ledger keeps its grammar: `chat-e2e rc=1 unstable=3` for a successful command
with three retried tests. A clean step stays `<id> rc=0`, and a skipped step has
`SKIPPED` without `rc=`. The summary retains
`=== unstable: <S> step(s), <T> retried test(s) ===` with the reported names.
A clean run adds neither an unstable field nor an unstable section.

Playwright reports `hilos-unstable: <N> (<spec:line>, ...)`. A cluster scenario
that succeeds after a timeout retry reports exactly one
`hilos-unstable: 1 (cluster/scenario-46)` line, using its scenario number,
regardless of the number of attempts. A first-attempt success or a scenario
skipped by `FLAKY_SKIP` prints none. Exhausted retries and hard errors retain
the first failure. Automatic retry counts and timeout scaling are unchanged.

Names and history help attribute the cause; they do not turn a red verdict green.
See [frontend/testing-strategy.md](frontend/testing-strategy.md#a-retried-test-makes-the-step-red-and-its-cause-must-be-traced).

### Playwright quarantine

The currently active ordinary demos' `test:e2e` composer commands and
e2e package `test` scripts use
`framework/frontend/scripts/playwright-run.mjs`. It collects the original
Playwright selection, partitions static `@quarantine:F-NNN` tags from main,
and runs main before one quarantine attempt per selected project, even after a
main failure. Main keeps its native rc, configured retries and `hilos-unstable`
evidence; quarantine never emits `hilos-unstable`. An empty main set succeeds
without starting a browser or a cluster controller. An empty original selection
still fails. Editor `test:ui` and raw Playwright remain diagnostic tools.
The complete composer cycle finishes both sets before its existing teardown.

Declare the tag on the affected test or parameterized variant, as described in
[the frontend strategy](frontend/testing-strategy.md#quarantine--a-static-tag-and-one-attempt-after-main).
Quarantine does not repair the test or close its F record.

Each selected tagged identity emits exactly one `hilos-quarantine: <JSON>` line,
with `flake`, `status`, `file`, `line`, `project`, `titlePath` and `reason`.
The file is relative to the config root; the positive line is a reading address.
History identifies an observation by F record, project, file and title path,
without the line. A real browser pass counts as `passed` only after its host
checks succeed. Browser `failed` / `timedOut` count as failed; `skipped`,
`interrupted` and `notRun` count as not run. A missing result is completed from
the collected plan as `notRun` with a reason. A host failure after a browser
pass demotes it to `failed`; preparation failure before the browser is `notRun`.
The host defers publication until cleanup, so no later correction line is needed.

The append-only ledger adds `quarantine=<N> quarantine_failed=<F>
quarantine_not_run=<R>` only to a step with observations. SUMMARY adds
`=== quarantine: <S> step(s), <T> test(s), <F> failed, <R> not run ===`
and the JSON observations. Neither changes rc, step outcome, dependencies or
the main unstable verdict. With zero quarantine observations the ledger and
summary stay byte-for-byte unchanged.

Quarantine runs with one worker, one attempt, no retries and retained failure
traces. Its outputs live under `test-results/quarantine/<project>` and
`playwright-report/quarantine/<project>`, separate from main and other projects;
the ordinary recursive artifact collector keeps both. Each cluster invocation
uses a private `data/cluster-quarantine/<uuid>` plan/result directory across the
existing repository bind mount. Only its own files are removed.

`composer run test:framework:frontend:quarantine` runs the real, browserless
Playwright CLI integration of the shared entry in the frontend container.
`test:framework:frontend` includes it after unit tests, before lint/format. It
checks collection, real reporter sidecars, native filtering and the main rc;
unit tests inject the executor rather than launching a second runner. The
cluster host's regression tests are included in `test:cluster:unit`.

### Narrow runs collect evidence

Use narrow runs while implementing and debugging. For an e2e step, name its red
or unstable tests with `--tests=` and use `--repeat=N` to gather N observations
of each selected test in one clean cycle. On `total-refac`, the diagnostic call is:

```bash
php scripts/run-test-suite.php <id> --lanes=1 --tests=tests/a.spec.ts:71 --repeat=5
```

`N` is a decimal integer from 1 through PHP's maximum integer, without a sign,
fraction or exponent. An explicit `--repeat`, including `--repeat=1`, requires
nonempty `--tests=` and exactly one existing step id with `takesTests=true`;
a tag, multiple targets or a cluster scenario step is refused before the graph
starts. Without the option, existing full and pointed runs behave as before.
The selected command receives escaped selectors and `--repeat-each=N` after
Composer's `--`; dependencies and stand preparation run once. Automatic retries
remain in effect for each observation. An explicit repeat selects the native
diagnostic path, including tagged tests: it emits ordinary unstable evidence,
not quarantine observations or quarantine statistics for the full cycle. No
fail-fast is added: all N observations are collected even with red tests,
unless the run itself is interrupted.

A non-e2e step can be investigated with
`php scripts/run-test-suite.php <id> --lanes=1`. These narrow loops help the
executor diagnose a defect; they do not replace Verify. Five green observations
on the same HEAD do not erase a red full run. Concurrency, host load and an older
flake explain a failure's origin without changing its verdict. Report a foreign
cause without repairing it inside the current ticket. A diagnostic green result
does not authorize removing a quarantine tag or closing its F record.

## Ordinary demo carriers

HIL-1984 approves twelve **ordinary demos**, four per view framework. This
count excludes the framework stands and the existing cluster fleets. Active
ordinary demos are registered in `framework-projects.json` and the test runner;
the remaining planned rows are activation targets, not working applications
merely because a directory or README exists.

| View | Ordinary demo | Current state / activation owner |
|---|---|---|
| Vue | `chat` | Active |
| Vue | `binance-btc-tracker` | Active |
| Vue | `pmd` | Planned (not in the code yet — HIL-2016) |
| Vue | `roblox-game-server` | Planned (not in the code yet — HIL-2017) |
| React | `tasks` | Active |
| React | `ecommerce-shop` | Active |
| React | `solar-system-model` | Planned (not in the code yet — HIL-2018) |
| React | `file-gallery-manager` | Planned (not in the code yet — HIL-2019) |
| Angular | `polls` | Active |
| Angular | `online-testing` | Active |
| Angular | `preferans` | Active |
| Angular | `concert-tickets` | Planned (not in the code yet — HIL-2021) |

The planned rows' markers cover each activation's complete obligation: an
application shell with an empty home page, AUTH/sign-in, working Analytics in
the administrative section, ordinary local/test stands, the command interface
below, and registration in the existing runner and stand registry. Each new
demo proves its own guest opening, sign-in and real Analytics data; a menu
entry alone does not prove Analytics works. Administrative placement keeps the
normal admin view mode and its masking rules. The exact framework areas each
activation takes, their current paths and the whole-area transfer rule live in
[Which demo carries a spec](frontend/testing-strategy.md#which-demo-carries-a-spec--the-server-check-travels-the-product-stays-home).

PMD uses a service database in this phase (not in the code yet — HIL-2016).
Solar permits guest access to its public Model and authenticated access to its
administration and Analytics; a sign-in control in the public header is
optional (not in the code yet — HIL-2018). The Model product itself is outside
this activation phase.

Today `pmd`, `roblox-game-server`, `solar-system-model` and
`file-gallery-manager` have `composer.json` stubs without scripts;
`demo/concert-tickets` does not exist. [`framework-projects.json`](../../framework-projects.json)
lists the active projects; [`scripts/test-suite.php`](../../scripts/test-suite.php)
and [`scripts/test-stands.php`](../../scripts/test-stands.php) register their
ordinary test suites and stands alongside the existing cluster and framework
stands. An activation updates these facts and documents its actual commands
when its code lands, clearing its markers in that same commit under
[the rule-authoring contract](rule-authoring.md#a-rule-written-ahead-of-its-code).

### Run and clean up an active ordinary stand

From the repository root, the existing Chat interface is:

```bash
composer -d demo/chat run test:e2e-full
composer -d demo/chat run test:e2e-full -- table-geometry.spec.ts
composer -d demo/chat run test:e2e-full -- --grep "<title>"
composer -d demo/chat run test:down
```

The middle two lines are alternative pointed runs; replace `<title>` with the
test title. `test:e2e-full` runs teardown → base infrastructure up → frontend
build → e2e install → e2e check → e2e up → tests → teardown. Every link except
`@test:e2e` retains `@no_additional_args`, so a selector reaches only the test
entry. `test:e2e-up` prepares the database and starts the application and nginx.
`test:up` alone starts the base infrastructure, not the whole browser stand.
Use the [clean rerun rule](#re-running-tests-and-state-between-runs).

Composer stops its chain on a failed link, so the final teardown is not a
guarantee after failure. Preserve the diagnostic evidence, then run
`test:down` on that same instance after either outcome. Repository-wide cleanup
is the existing `composer run test:stands:down`, backed by
[`scripts/down-stands.php`](../../scripts/down-stands.php); it selects the
registered stands of the current instance, including any raised by hand.

For each remaining planned demo, `test:e2e-full`, its pointed form, `test:down`
and `test:e2e-time-zone` are the **required activation interface**, owned by
that demo's marker in the table above. These commands are not yet available in
those planned projects. Each activation supplies the same argument isolation
and shared Playwright entry; the existing
[quarantine](#playwright-quarantine) and
[browser time-zone](frontend/testing-strategy.md#explicit-browser-time-zones)
rules apply without a second set of rules here.

### Runner, isolation and the comparison budget

Keep the existing `composer run test:suite` runner and
`composer run test:frontend:all` selection. Add each activated demo through the
manifest and stand registry, under its activation owner above; do not add a
second runner. Preserve the `fe-build` dependency, the per-demo group, step
snapshots and the instance's teardown. A step declaring `downsStand` takes its
stand down after its snapshot on every outcome; ordinary failed stands may
remain for diagnosis and still owe the explicit cleanup above.

The suffix, network/address, host-port and CPU-share isolation described under
[the full run](#the-full-run--one-graph-a-bounded-number-of-lanes) still applies.
The address map in
[`scripts/stand-instance.php`](../../scripts/stand-instance.php) is two blocks.
The first holds its ten assigned stands. The second holds six reserved ordinary
demo addresses, `HILOS_STAND_NET_PMD` through
`HILOS_STAND_NET_CONCERT_TICKETS`, on default octets 214 to 219; Preferans uses
one of these addresses, while the other planned carriers retain theirs. The
test compose of each activation reads its own
variable, and its ports and local subnet are reserved in the registry
([../new-project/README.md](../new-project/README.md)).
Room for the cluster stands of HIL-1985 is left but not introduced.

**Seven lanes is the agreed comparison condition for HIL-1984 on the test
box**, not a replacement for the adaptive lane-count formula. Do not increase
the lane or instance count to manufacture a gain. HIL-2023 owns the comparable
full-run measurement and residue checks after activation and transfer
(not in the code yet — HIL-2023). Record the run and measurement boundary,
keeping main and quarantine observations distinct; the historical durations
above promise no new speedup.

### Ordinary and cluster phases

HIL-1984 ends at the twelve ordinary demos: the base shell, sign-in and
Analytics, whole-area transfers, runner registration, isolation, cleanup and
measurement. `roblox-game-server`, `file-gallery-manager` and `concert-tickets`
are ordinary carriers in this phase. Their additional Hilos clusters,
specialized MariaDB topologies, journals across the database topology,
fault/recovery proofs and cluster-browser transfers belong to HIL-1985. The
phase-two matrix is in [The cluster stands](#the-cluster-stands--six-demos-six-shapes).
The three existing stands retain their proofs except for cluster-browser areas
that move within their view framework under the
[approved target](frontend/testing-strategy.md#approved-cluster-browser-target--hil-1985).

The remaining ordinary carrier activations do not build their products. PMD
without a service database or with managed external databases, CLI/MCP/3D/MCP
Apps, and Software Factory are outside this phase.
Solar's Model product belongs to HIL-193/HIL-132; Software Factory waits for
the Tasks product. Analytics here means activating the existing section, not
the future Visits/cubes/rebuild work of HIL-1402.

## The cluster stands — six demos, six shapes

The six cluster carriers are separate from the twelve ordinary-demo target:
three phase-one stands work today, and three phase-two stands are approved
targets under the owner's decision of 2026-11-04 (HIL-1985). The marked rows
below name the leaves that will build them; they are not working stands yet.
See the [ordinary/cluster phase boundary](#ordinary-and-cluster-phases) for
HIL-1984 and HIL-1985; it does not retire or unpark a scenario below.

The phase-one stands — binance-btc-tracker, ecommerce-shop and online-testing —
each use one ordinary MariaDB 11.4 server, holding the primary database and its
derived `<primary>-change-log` journal (the owner's decision, 2026-10-14,
HIL-1453). Every carrier uses MariaDB. `pmd`, `solar-system-model` and `preferans`
receive no cluster stands of their own.
All of it runs in docker on one machine: a multi-machine stand does not exist,
and HIL-348 closed without one. These stands test process and network failures
on one host, not physical-server or data-center failures. A single HAProxy or
entry provides no high-availability promise of its own. The one stand that
carried every scenario on a single shape is retired (HIL-1218).

| Demo | View | Hilos cluster | MariaDB | Scenarios |
|---|---|---|---|---|
| binance-btc-tracker | Vue | three masters, two slaves and `x1`, a node of a foreign authority | one server, primary + derived journal | 1 master-slave mesh, 2 master-master, 5 leader-kill re-election, 7 quorum-loss, 8 split-brain prevention, 10 cross-node browser, 13 rt partition converges (skipped as flaky, P-169), 17 foreign certificate refused, 20 rt set width across nodes, 23 verifier circle on every master, 25 freeze settles on every master, 29 a node with a cluster directory of its own refused on both ends, 30 a ready data export copy outlives the node its agent lived on, 32 a node with another admin view mode refused on both ends, 33 every master takes browsers, 34 a tab is the same on every master under protected mode, 35 rt row deleted while cut off is swept, 36 a slave cut off with its leader stops its work before it runs elsewhere, 38 manual maintenance from any node, 41 i18n catalog taken once and skipped after holder restart, 42 a node with another default language refused on both ends, 44 replicated waiting fleet survives leader death and quorum loss, then starts when slots return without another ask, 45 upload pool across nodes: reservations, worker receipts, crossing waits and peer generations, 46 browser liveness across masters, 47 a node with another project time zone refused on both ends |
| ecommerce-shop | React | one master and two slaves of unequal room, `ram=10` and `ram=4`, each with `slots=64` | one server, primary + derived journal | 3 placement, 4 slave-kill failover, 6 hot-join, 9 daemon-crash self-heal, 12 rt replication, 14 rt claim refused, 16 recreated node leaves no phantom fleet (parked, P-441/2), 18 capacity is consumed, 19 worker death on a live node, 37 costless agents consume slots, 39 person agents across nodes, 43 waiting ballast retries after a slave returns without another ask (cleanup checked with `composer -d demo/ecommerce-shop run test:cluster:scenarios -- 43 37 18`) |
| online-testing | Angular | three equal masters that host work themselves | one server, primary + derived journal | 11 cross-node db fact, 15 db interest addressing, 21 schema rolled out once by nodes that start together, 22 a node reading another database refused on both ends, 24 a cut-off leader stops its work before it runs elsewhere, 40 journal triggers installed once and unchanged on ordinary restart, 48 daemon hang self-heal |
| roblox-game-server | Vue | three masters and one slave: `m1` without capacity — it votes and hosts no placed work; `m2`, `m3` workers with `ram=10`, `slots=64`; slave `s1` worker with `ram=4`, `slots=64` (not in the code yet — HIL-2029) | Galera of two data nodes and the `garbd` arbitrator, which votes and stores no data, behind HAProxy; the application uses both data nodes (not in the code yet — HIL-2029) | role and placement proofs below (not in the code yet — HIL-2036); Galera fault proofs below (not in the code yet — HIL-2040) |
| file-gallery-manager | React | two masters without capacity and three slave workers with `ram=10` / `6` / `4`, each with `slots=64` (not in the code yet — HIL-2032) | primary and one read-only replica behind one address that always leads to the primary, no automatic promotion (not in the code yet — HIL-2032) | 31 (not in the code yet — HIL-2032) |
| concert-tickets | Angular | five equal master workers with `ram=10`, `slots=64`, no slaves (not in the code yet — HIL-2033) | Galera of three data nodes behind HAProxy; the application uses all three (not in the code yet — HIL-2033) | 26/27/28 (not in the code yet — HIL-2041) |

`ram` means abstract units of declared placement capacity, not gigabytes of RAM
or a container memory limit.

**What each new shape must keep — continue or stop safely**

| Carrier | Layer | Fault or condition | Expected outcome | Proof owner |
|---|---|---|---|---|
| roblox-game-server | Hilos | `m1` alive without capacity | It votes and never hosts placed work; work runs on `m2`/`m3`/`s1`. | (not in the code yet — HIL-2036) |
| roblox-game-server | Hilos | A working master or the slave is lost | Its work moves to the remaining capacity, never two executors at once; no rebalance is owed on return. | (not in the code yet — HIL-2036) |
| roblox-game-server | DB | `garbd` is lost | The two data nodes keep quorum and writes continue. | (not in the code yet — HIL-2040) |
| roblox-game-server | DB | One data node is lost, `garbd` alive | Survivor and arbitrator keep quorum and writes continue; the returned node takes connections only when Synced. | (not in the code yet — HIL-2040) |
| roblox-game-server | DB | Data node + `garbd` are cut off from the other data node | The majority continues; the minority takes no operations. | (not in the code yet — HIL-2040) |
| file-gallery-manager | Hilos | Either of the two masters is lost | Quorum 2 of 2 is gone: leadership and placed work stop safely, never two executors; the three slaves add no votes; the returned pair elects one leader and work resumes. | (not in the code yet — HIL-2037) |
| file-gallery-manager | Hilos | One slave is lost, masters healthy | Its work moves to the remaining capacity. | (not in the code yet — HIL-2037) |
| file-gallery-manager | DB | Replica is lost | The primary is unaffected. | (not in the code yet — HIL-2042) |
| file-gallery-manager | DB | Primary is lost | The database is unavailable until the primary is safely restored; no promotion and no read from the replica. | (not in the code yet — HIL-2042) |
| concert-tickets | Hilos | Two of five masters are lost | Quorum 3 of 5 holds and work continues. | (not in the code yet — HIL-2038) |
| concert-tickets | Hilos | Three masters are lost | Work stops. | (not in the code yet — HIL-2038) |
| concert-tickets | Hilos | 3:2 partition, with the old leader and its working agents in the minority | The majority continues; the minority leader stops its work; one executor after recovery. | (not in the code yet — HIL-2038) |
| concert-tickets | DB | One of three data nodes is lost and returned | Writes continue through the survivors without restarting Hilos; the returned member catches up and takes new connections only when Synced. | (not in the code yet — HIL-2041) |

Acceptance proves Hilos and database failures separately, keeping the other
layer healthy (not in the code yet — HIL-2043). New fleets enter after ordinary
activations HIL-2017/HIL-2019/HIL-2021 and phase-one acceptance HIL-2023, with
isolation in every instance extending HIL-2015: their octets are the third block of
`scripts/stand-instance.php`, the variables `HILOS_STAND_NET_ROBLOX_GAME_SERVER_CLUSTER`,
`HILOS_STAND_NET_FILE_GALLERY_MANAGER_CLUSTER` and
`HILOS_STAND_NET_CONCERT_TICKETS_CLUSTER` with default octets 225, 227 and 229, and the
cluster runner forwards them.
Do not add lanes or instances: the full run accepts all six carriers and
measures their resource budget on the existing lanes
(not in the code yet — HIL-2043).

Every carrier owes [the database guarantees](app-topology.md#database-guarantees-hil-1206)
`ONE_LOGICAL_DATABASE` and `READ_AFTER_WRITE` for both primary and
`<primary>-change-log`. Roblox sets `wsrep_sync_wait=1` (the READ bit) in every
application session, reconnects included, with `wsrep_dirty_reads` off
(not in the code yet — HIL-2029). Concert-tickets uses that same session setup
(not in the code yet — HIL-2033). Hilos does not read the replica; losing
acknowledged writes by promoting a lagging replica is not an accepted outcome.

Scenario **45** on binance-btc-tracker proves the
[upload raw-byte pool](architecture/uploads.md#the-raw-byte-pool) on real
nodes (`scenario_45_upload_pool_across_nodes` in
[`scenarios.py`](../../framework/docker/cluster/scenarios.py)). Its actual
shape guard is `Need(masters=2, slaves=2)`: the WORKER fleet probes run on
both slaves of this carrier. From the repository root, use the pointed run
while changing that path and the carrier's whole matrix before merge:

```bash
composer -d demo/binance-btc-tracker run test:cluster:scenarios -- 45
composer -d demo/binance-btc-tracker run test:cluster:scenarios
```

The test-only `test:cluster:upload-pool` driver uses `inspect`, `limit` and
`enqueue`, behind the ordinary CLI and daemon production guards. Its oracle
has exactly four fields: `limitBytes`, `usedBytes`, `waitingUploads` and
`pendingPeerReservations`: the installed node limit, charged raw bytes,
unfinished credit demands plus driver metadata, and incoming/outgoing reserve waits.
`pendingPeerReservations` does not count every received worker receipt.
The driver's zero limit suspends grants for the proof; the production setting
remains positive. These live counters do not prove RSS or payload contents.

With 131,072-byte node pools and 65,536-byte portions, the scenario proves
reservation before data, local plus remote debt under one cap, overlapping
crossing demands and fresh control roundtrips while capacity waits. It captures
an exact worker incarnation before STOP, checks its stopped state, and restores
that incarnation with CONT in `finally`. Real worker death, peer EOF and a new
peer generation prove final cleanup without releasing accepted receiver debt
early. The original stand is restored and checked before the matrix proceeds.
Actual browser credit/binary bytes, files larger than the pool and exact
downloaded contents are covered separately by chat's
[`attachment.spec.ts`](../../demo/chat/tests/e2e/tests/attachment.spec.ts),
under `the shared browser upload pool`.

Scenarios **43/44** prove waiting-placement recovery (HIL-446). Ecommerce-shop's
43 returns a slave to waiting ballast; binance-btc-tracker's 44 first gives both
slaves 16 slots, then fills them with fleet indices 2000..2032 until one waits
`Unplaced` with a null host and exact slot refusals. Every surviving master must
hold a complete snapshot with that record before the leader is killed, naming
the same leader/term and at least the observed revision. A new leader's snapshot
must name its new term after rebuild, preserve the queue, and remain available
when that master loses quorum and leadership. Returning the masters must recover
the queue again; restoring the first slave's 64 slots then starts the unique
target and advances its live RT row without another ask. All live placements
count against the ceiling. The supervisor only declares 0..9, so it cannot mask
lost extra intent. Revisions are compared only within one leader/term. These
are delivered memory replicas, with no durable or quorum-commit guarantee.
Both scenarios clean up by restarting their whole original stand without
deleting volumes, restoring the ordinary fleet and removing extra demand and
environment overrides before the next scenario. Scenario 44 runs after 5/7/8:

```bash
composer -d demo/binance-btc-tracker run test:cluster:scenarios -- 5 7 8 44
```

Scenarios **41/42** on binance-btc-tracker prove the i18n startup and default
language agreement (HIL-1505); its compose gives every node
`HILOS_DEFAULT_LANGUAGE=en`. Scenario 41 runs first in that stand's matrix,
before any restart or kill can rotate the original startup logs. It requires
one `hilos_i18n_library` startup and one started POLICY placement, one
`I18n catalog reflow committed: <fingerprint>` INFO on its holder, and the same
64-character lowercase hex fingerprint in `hilos_i18n_reflow`. The database has
51 countries, 51 English base country names, and only the `en` language and
countryless locale. Killing and restarting the holder produces a new library
startup and one started placement, with the catalog snapshot unchanged and no
new reflow commit line.

Scenario 42 temporarily recreates a slave with `HILOS_DEFAULT_LANGUAGE=ru`.
It requires the exact `ru` versus `en` refusal on both ends, the node offline
in every survivor's view, the singleton placed outside it, and no `ru` language
row. Its `finally` restores the original env, full mesh and singleton; scenario
41 also restores the holder, mesh and singleton in `finally`. Both scenarios
belong only to binance-btc-tracker. From the repository root, the pointed run
and that carrier's full matrix are:

```bash
composer -d demo/binance-btc-tracker run test:cluster:scenarios -- 41 42
composer -d demo/binance-btc-tracker run test:cluster:scenarios
```

Scenario **47** on binance-btc-tracker recreates a node with another
`HILOS_PROJECT_TIME_ZONE`. It requires the exact project-time-zone refusal on
both ends and the node offline in the remaining cluster. The scenario restores
the original environment and mesh in `finally`. From the repository root, run:

```bash
composer -d demo/binance-btc-tracker run test:cluster:scenarios -- 47
```

Scenario **46** on binance-btc-tracker proves
[browser socket liveness](architecture/event-loop.md#browser-socket-liveness) across
masters (HIL-1566; `scenario_46_browser_liveness_across_masters` in
[`scenarios.py`](../../framework/docker/cluster/scenarios.py)). A raw socket is held
through the entry on a master that does not lead (the `liveness-hold` command of
[`cluster.py`](../../framework/docker/cluster/cluster.py)), the owner of `connections`
is the singleton on the leader, and the row is read on the third master. The whole
stand, the socket's master included, runs the framework's 40/50/120/600 s, and no
moment is waited out: the raw socket publishes the session token its master set on the
101, and the scenario brings the doubt, the close and the check of the copy to now on
that master with `test:connection:liveness` (HIL-1860). The scenario requires that a
socket whose doubt is brought to now is doubted in the observer's row, and cleared once it
answers; that a transition made while the owner's worker is stopped is missed — the
transition and the check brought to now after it go to the stopped worker, and the
observer's row stays as it was (for a missed `false` the check is brought twice, because
the tick of the first may itself clear the doubt and skip its check); that, after the
worker is killed and the leader starts the owner again, the socket's master's own check
repairs the row within `CONVERGE_TIMEOUT`, the check being brought to now again on every
turn of the wait, for a missed `true` and a missed `false` alike; that the master closes a socket whose close is brought
to now and drops one that floods pings and reads no pong past the 8 MiB cap, the cap's log
line being in that master's log and no other's; and that another socket of the same
master, and every node, live on. How long each span is, the scenario does not measure:
the unit test
[`WebSocketLivenessTest.php`](../../framework/tests/Unit/Socket/WebSocket/WebSocketLivenessTest.php)
holds the production values. The scenario also resumes a stopped worker in `finally`. It
needs three masters and the entry, and runs before the freeze pair 23/25:

```bash
composer -d demo/binance-btc-tracker run test:cluster:scenarios -- 46
```

The single-node half is `presence-liveness.spec.ts` in each demo's `tests/e2e/tests/`
(Vue, React, Angular). Each demo's `docker/docker-compose.test.yml` sets none of the
`WEBSOCKET_*` liveness spans, so the master runs the production values of the catalog,
and the spec waits none of them out: `bringLivenessMomentToNow()` from
[`rawWebSocket.ts`](../../framework/frontend/e2e/rawWebSocket.ts) sends
`test:connection:liveness` with one browser's session token
([Time-based features](cli/commands.md#time-based-features-no-universal-clock)) and
requires the master to answer that exactly one socket took the moment. Raw sockets are
needed because no browser keeps silent: Chromium answers a ping in its network service
whatever its page is doing. The spec therefore opens raw sockets with the cookies of a
browser context (`openRawWebSocket()`) that can keep their pong back. The command takes
every socket of the browser it names, so the person signs in on two browsers, the first by
`createTestUser` and the second by `bindTestUser`; each reads its session token on a fresh
document, closes its tab and leaves one raw socket, so the token is the cookie that socket
presents and the one the master finds it by. An administrator watches the person
on the people list and the card: online while one socket answers, probably offline while
every socket is doubted, offline when none is left. The spec keeps no clock of its own and
no `test.slow()`; each step brings one browser's doubt or close to now, waits for what the
master did to its socket, the ping it sent into the silence or the close, and then for
what the observer was told.

Scenario **48** on online-testing proves
[container watchdog hang recovery](architecture/daemon-lifecycle.md)
for a live follower master (`scenario_48_daemon_hang_selfheal` in
[`scenarios.py`](../../framework/docker/cluster/scenarios.py)). Its `Need(masters=3)`
selects a confirmed follower while the leader and surviving majority remain healthy. The
scenario observes the first accepted heartbeat and rechecks the follower and majority before
the fault. It pins the watchdog PID 1, its start tick, the daemon PID and its start tick, and
the container identity, and requires the same watchdog process to remain alive. It freezes
the daemon with SIGSTOP, uses the harness's `watchdog-expire-heartbeat` request to make its
active 60-second silence deadline due, and checks TERM; it then uses
`watchdog-expire-stop` to make the active five-second stop grace due and checks SIGKILL plus
the watchdog's observation of the old process's actual exit. No command-port request is sent
to the stopped master. Only after a new master is alive in the same container does the
scenario inspect the victim again and require every node's fresh roster to agree that it
rejoined.

Those two harness requests send SIGUSR1 and SIGUSR2 to the addressed watchdog; they make only
the active hang deadlines due now and do not wait out the production 60 seconds or five-second
grace. The watchdog admits them only in a non-production environment, and the harness verifies
the target watchdog PID before signaling it. On this stand the normal 20-second
`DAEMON_MIN_RESTART_INTERVAL` backoff remains real. Polling for TERM, KILL, observed exit, the
replacement process and fresh roster is bounded by the existing `CRASH_RECOVERY_TIMEOUT`.
Its `finally` path captures evidence and ordinarily recreates the victim node so the next
scenario starts with the stand restored.

Measured on the test box on 2026-11-08, scenario 48 took 36.288 seconds when pointed and
38.505 seconds in the matrix, including initial readiness, evidence collection and cleanup.
The recovered-node inspection portion took 26.867 seconds pointed and 27.632 seconds in the
matrix. These are the scenario's observed added times; the 76-second pointed invocation and
128-second complete matrix are command totals, not scenario cost. The timing is an
observation, not a promise for a future graph. The separate 24-hour no-browser observation
has not been completed and is not established by these scenario runs. From the repository
root, run the pointed scenario and, before merge, the online-testing matrix:

```bash
composer -d demo/online-testing run test:cluster:scenarios -- 48
composer -d demo/online-testing run test:cluster:scenarios
```

The Hilos shapes keep their original purpose: quorum, network partitions and
TLS on binance-btc-tracker; placement and failover with a stable leader on
ecommerce-shop; shared database facts and simultaneous schema rollout on
online-testing. Roblox adds roles with and without capacity and a Galera
arbitrator (not in the code yet — HIL-2029). Gallery adds the even quorum of
two masters that must stop safely, and primary/replica
(not in the code yet — HIL-2032). Tickets adds a quorum of five masters and
three-node Galera (not in the code yet — HIL-2033). The owner's decision of
2026-11-04 (HIL-1985) chooses different shapes for meaningful tests.
The former primary/replica and Galera database topologies were replaced by
one MariaDB on binance-btc-tracker, ecommerce-shop and online-testing by the
owner's decision of 2026-10-14.
Scenarios **26/27/28/31 have no current carrier**: their implementations,
registry entries and shape guards stay in the harness for future stands.
They are not run or counted as passed by any current matrix.
Scenario 31 belongs on file-gallery-manager (not in the code yet — HIL-2032).
Scenarios 26/27/28 return on concert-tickets (not in the code yet — HIL-2041);
their component model and preconditions count votes separately from data
members. Their two-data-node + `garbd` form
belongs on roblox-game-server (not in the code yet — HIL-2040).

The one-time schema rollout is needed on one server too (HIL-1228). Nodes that
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

The stand's resolved Compose model is the one place its nodes are written, and
there is no second copy: a node is a service with `CLUSTER_ENABLED=true` and a
`CLUSTER_NODE_ID`, and its container, address, role, `ram` and log directory
are read off that service — what the node itself reads. What compose cannot
say stands in a top-level `x-hilos-cluster` block of the same file: `cli`, the
service commands to the nodes go through; `entry`, the one browser-facing service
shared by the masters (optional); `stranger`, the node under a profile
that a scenario raises on its own (optional); `cluster-directory`, the name and
in-container path of a cluster directory of the stand's `$fs`, which scenario 29
gives one node a copy of its own (optional); `scenarios`, the numbers the
stand carries — never their order, which is the harness's; `e2e`, the optional
browser declaration with `runner` (a service under profile `e2e`, requiring
`entry`) and `capabilities` (an optional list, empty when absent). Its only
capability names are `daemon` (leader loss; at least three masters and one
slave), `delivery` (live fault control; at least three masters), and
`env-mismatch` (a foreign-environment fixture; at least one slave). The host
checks every declared capability against the stand's shape before starting
containers, regardless of browser filters. After collecting the native plan
and before filtering projects or starting a phase, it refuses any planned
daemon, delivery or env-mismatch test whose capability is undeclared.
Delivery control serves every live phase on a declaring stand because its
spec loads before native execution filters; collection receives its directory
on every stand without starting a controller. The env-mismatch fixture follows
the native plan separately for main and quarantine.

Scripts that call the harness enter through `framework/docker/cluster/run` with that demo's
compose file. A scenario the stand names but cannot carry by its shape — too
few masters or slaves, no stranger, no cluster directory — is refused before
the stand is raised, and so is a stand that leaves out what the harness reads.

### Cluster Compose model

Each demo's root `docker/docker-compose.cluster.yml` keeps the project name and
`x-hilos-cluster`. One `include` path-list merges `cluster/local.yml` with
`cluster/tooling.yml`. Local services extend the canonical `database.yml`,
`node.yml` and, where present, `entry.yml` in that directory. Those canonical
services do not become extra nodes in the aggregate. Compose 5.6.1, pinned in
the harness, resolves this model directly, including `!reset` and `!override`.

The local aggregate removes standalone port publications with `ports: !reset []`.
Every local node replaces `env_file` with the tracked demo `.env.example`,
`common.env` and its fixed `nodes/<id>.env`, in that order. A global
`HILOS_CLUSTER_NODE_ENV` therefore cannot replace local node identities. Bind
paths and build contexts resolve from `docker/cluster`; mounts merge by their
container target, retaining per-node logs and the stand's export storage.
Binance shares one named export volume among its nodes and entry. Online-testing
shares its host `data/data_export` and serves Angular's `frontend/dist` at
`/usr/share/nginx/html`. Ecommerce-shop has no entry, cluster browser suite or
additional export volume. Each cluster stand gives Status a separate named
volume, suffixed with `${HILOS_STAND_SUFFIX:-}` and shared by all its nodes and
the entry where one exists.

The CLI carrier is default-on. It uses `APP_ENV=test`, explicit cluster database
credentials and command port, and mounts tracked `tests/.env.example` read-only
at `/app/tests/.env` (selectable through `HILOS_CLUSTER_TEST_ENV_FILE`). Its
`/app/tests` is tmpfs: Docker would otherwise create a missing nested file-mount
target on the host project bind. This carrier runs backend commands; the test
suites keep their existing separate services. Nodes use `APP_ENV=dev` and
tracked defaults. Cluster startup neither copies nor overwrites host `.env`
files. Only Mailpit and Playwright need the `e2e` profile; Binance's foreign
node uses `stranger`.

From a demo directory, one direct command starts its ordinary local aggregate:

```bash
docker compose -f docker/docker-compose.cluster.yml up -d --build --force-recreate
```

The canonical components can also be resolved and started separately with
Compose on their own server. Each demo's `docker/cluster/standalone.env.example`
and README show `--env-file`, a distinct project per component, published
database/peer/WebSocket/HTTP ports and, where present, entry ports.
`--env-file` supplies Compose interpolation; `HILOS_CLUSTER_NODE_ENV` selects
the separate runtime file loaded after `common.env`. The operator supplies
routable database, advertise and seed endpoints, matching mutual-TLS files,
trusted proxy ranges, built dependencies/frontend and any shared export storage
visible to every node and entry. Entry upstreams are complete `host:port` pairs
in `HILOS_CLUSTER_WS_M1/M2/M3` and `HILOS_CLUSTER_HTTP_M1/M2/M3`.
The nginx entrypoints substitute only those endpoints and
`FRONTEND_HTML_UPSTREAM`, preserving nginx's own variables and noncluster
templates. Standalone config has been checked; deployment across real hosts has
not been tested. The harness remains a local single-host runner and provisions
no remote machines or shared storage.

### Cluster harness runner

From a demo directory, run
`../../framework/docker/cluster/run docker/docker-compose.cluster.yml <command> [args]`.
For example, `up`, `status`, `inspect m1` and `down` operate the selected stand.
The existing `composer run test:cluster:*` names remain convenient entry points.
From the repository root, `composer run test:cluster:unit` runs the harness's
stdlib Python unit tests in the same disposable runtime. The `unit` command
runs before loading the compose stand, checking its registry or dispatching a
stand action; it starts no cluster and makes no SQL or network calls in its tests.
The independent `cluster-unit` graph step includes this check in the full run.

`composer run test:cluster:down` keeps using the existing
`scripts/down-stands.php`; calling the shell runner's `down` is a standalone
path. Use `down --volumes` only when intentionally deleting the stand's
database data.

`up` uses the single root Compose command above. It builds and recreates default
services with current code; it does not build every optional profile. The first
optional `e2e` or `stranger` startup builds its own services. `restart` performs
ordinary `down` followed by `up`. Ordinary `up`, `down` and `restart` retain both
databases and protected-mode stores, including a saved manual maintenance window.
They do not clear logs or open a freeze. The demo aliases include
`test:cluster:restart`, `test:cluster:inspect -- m1`,
`test:cluster:logs -- m1` (follow; Ctrl-C stops it) and
`test:cluster:client -- m1 test:cluster:inspect`.

Every new `scenarios` or `e2e` invocation, pointed or unpointed, checks its
prerequisites before one `down --volumes` and a fresh `up`. There is no further
global database reset between scenarios, retries or browser phases, and no
`--keep-db` mode. This test boundary deliberately discards accumulated fixtures;
it is separate from the ordinary lifecycle that preserves them.

Before fleet/browser readiness, test startup inspects every master's protected
mode and opens a recognized saved operation through its recorded initiator:
`maintenance:disable` for `manual_maintenance` owned by `hilos_index`,
`test:protected-mode:open` for that owner's test freeze,
`protected-mode:open` for `restore` owned by `hilos_backup`, and
`test:backup:freeze-open` for `cluster-settle` owned by `hilos_backup` (the
scenario 25 test driver). It waits for all
masters to become inactive within one bounded deadline. An unknown identity,
malformed store/reply, refused command or timeout fails startup. No freeze file
is deleted to make the test pass. For ordinary operation, use
`test:cluster:client -- <node> protected-mode:inspect` to inspect the saved
identity and the corresponding owner's command to open it.

The shell launcher needs ordinary Linux shell utilities, the Docker CLI and an
accessible local Unix Docker socket. It requires Linux and a selected local
Unix endpoint: `DOCKER_CONTEXT` and `DOCKER_HOST` are honored, while a remote
TCP/SSH endpoint or an absent or inaccessible socket is refused with exit 2
before the stand is affected. The supported target is a local Linux Docker
Engine; Docker Desktop, rootless, SSH and multi-machine setups are outside this
runner's supported contract. It needs no host Python, PHP, Composer or Compose
plugin, and it does not need root, privileged mode or host networking.

Every invocation builds `framework/docker/Dockerfile.cluster-harness` with
Docker's normal cache and without `--pull`, then runs that invocation's exact
image ID in a disposable `--rm --init` container. The first build happens
automatically; unchanged inputs use cached layers on later invocations. The
image pins Python 3.12.16 on Alpine 3.24.3, Docker CLI 29.9.2, Compose 5.6.1
and Buildx 0.38.1. It reads the current Python sources from the repository mount.
The launcher preserves arguments, standard streams, exit codes and signals;
build output goes to stderr, leaving `inspect` stdout as JSON. The container
has temporary `HOME` and `DOCKER_CONFIG`, and Python bytecode is not written
into the repository.

The repository is mounted read/write at its physical absolute path, and the
container working directory is the physical demo directory. It mounts the
selected Docker socket and runs as the caller's UID:GID with the socket's
numeric GID added. Host `/dev/shm` is mounted read-only so a separate Docker
helper can find existing database bind directories before cleanup. New files
belong to the caller; owners and permissions of existing journals are left
intact. When defined, the launcher passes the existing
`CLUSTER_E2E_TIMEOUT_SCALE`, `CLUSTER_E2E_RETRIES` and
`HILOS_E2E_TIMEOUT_SCALE` variables, plus the existing
`HILOS_STAND_SUFFIX`, `HILOS_STAND_CPU_SHARES`,
`HILOS_STAND_NET_BINANCE_BTC_TRACKER_CLUSTER`,
`HILOS_STAND_NET_ECOMMERCE_SHOP_CLUSTER`,
`HILOS_STAND_NET_ONLINE_TESTING_CLUSTER`,
`HILOS_STAND_NET_ROBLOX_GAME_SERVER_CLUSTER`,
`HILOS_STAND_NET_FILE_GALLERY_MANAGER_CLUSTER` and
`HILOS_STAND_NET_CONCERT_TICKETS_CLUSTER` variables. Its explicit deployment
allowlist also carries the `HILOS_CLUSTER_*` runtime-file/storage selections,
bind address, published ports and six entry upstream endpoints defined in the
standalone examples. It preserves absent versus empty values and does not
forward arbitrary host environment variables. A mistaken direct call to `cluster.py`
under Python earlier than 3.10 exits before imports with a shell-runner hint;
Python 3.10+ is not a host prerequisite.

The harness retains multi-server database capabilities for the planned carriers
above. Such a stand labels every member `hilos.database.member: "true"` and exactly one
`hilos.role: database` — the one `db-sql` and the artifact collector reach.
The nodes use one address leading to the writable database. A replica is
declared by `MARIADB_MASTER_HOST` and has no `MYSQL_DATABASE`, `MYSQL_USER` or
`MYSQL_PASSWORD` of its own: its schema and users arrive from the primary.
The harness uses the database service's application credentials. Scenario 26
asks every member of a multi-primary; scenario 31 asks every replica. A proxy
is labeled `hilos.database.proxy: "true"`; scenario 28 reads its server states.
A Galera arbitrator (`garbd`) votes and stores no data: it is declared apart
from the data members, labeled `hilos.database.voter: "true"`, never labeled
`hilos.database.member` and never asked SQL. Scenario 26 asks for the READ bit of
`wsrep_sync_wait`, not a value of at least 1 (not in the code yet — HIL-2027).
binance-btc-tracker, ecommerce-shop and online-testing declare no database
member or proxy label; each labels its single MariaDB `hilos.role: database`.

Every server of a fleet's database, whether one server or a member of a cluster,
keeps its data in a host directory under `/dev/shm` and mounts
`framework/docker/mysql/test-stand.cnf`. A new fleet database server does the
same. The harness wipes these directories on `down --volumes`; a member killed
and started again keeps its data, as does ordinary `down/up` or `restart`.
This is host-memory persistence only: a host reboot clears `/dev/shm`.
Stand suffixes keep those directories separate, alongside namespaced projects,
containers, networks and named volumes. The binance-btc-tracker, ecommerce-shop
and online-testing stands each mount their existing
`mysql/init-change-log.sh` at `/docker-entrypoint-initdb.d/init-change-log.sh`
and `mysql/provision-change-log.sh` at `/usr/local/bin/provision-change-log.sh`.
Fresh initialization creates and grants the derived journal before Hilos
starts; an existing ordinary MariaDB directory is provisioned with the root
script before the new Hilos code starts, without DROP or reset. Repeating that
script and restarting on the same directory preserve primary and journal data.
For Galera and primary/replica, journal preparation runs once through the
replicating path, as specified in [On A Cluster](architecture/change-log.md#on-a-cluster).

Before changing an older compose, stop it through its old file without
`--volumes`, so removed replica/proxy/member containers do not become orphans.
Online-testing uses the new `/dev/shm/hilos-online-testing-cluster-mysql-data`;
its old `hilos-online-testing-cluster-galera{1,2,3}-data` directories remain
untouched and must not be mounted as ordinary MariaDB data. No automatic
transfer from Galera is provided. Ecommerce's former replica data stays too.
Local/test volumes are outside this transition. The per-demo README gives the
exact provisioning commands.

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
six has is a question for the owner, not a seventh stand grown on the way:
the six shapes are one decision of the owner, 2026-11-04 (HIL-1985). A scenario
that needs a shape not built yet lands with its carrier or after it.

Nodes that start together on one database roll the schema out once, under
the rollout claim in the database itself (HIL-1228,
[orm/migrations.md](orm/migrations.md)): one node applies the migrations, the
rest wait for it and find the level already there. A stand therefore has no
one-shot rollout service in front of its nodes any more, and starts them all
at once on an empty database — which is what scenario 21 reads.

The `binance-btc-tracker-cluster-e2e` step drives the cluster browser suite in
`demo/binance-btc-tracker/tests/e2e/cluster/`, beside the demo's single-node e2e
rather than after it (HOTFIX 2026-10-25): it waits for `binance-btc-tracker-build`,
whose `frontend/dist` its entry serves, and `binance-btc-tracker-check`, which
installs `tests/e2e/node_modules`. The demo's main Playwright run keeps its output
in `test-results/main` and `playwright-report/main`, so it clears neither the
`cluster-*` folders nor `quarantine/*`. The browser enters through the stand's one nginx entry, where each context's
`hilos_stand_master` cookie selects a master (HIL-1304). By default browsers and
the protected-mode operator sit on a follower master, away from the page holder
on the leader and the policy-placed agents on slaves. The live phase checks
backup, logs, protected mode, one person's tabs, theme delivery, Change Log and
i18n across masters. `cluster/live/change-log.spec.ts` first observes a known
leader Workers roster and its started monopolistic `hilos_change_log` worker
before opening Change Log. If the first roster was unknown, it makes one fresh
Workers navigation after the known node report, then applies the full roster
checks; this independent read does not warm the journal. It checks the person's
`started` `hilos_user:<id>` owner on a slave before and after the block, then
pins the receipt and `rowKey`. Fresh socket, filter and receipt reads through
every master must return that receipt and its single entry with exact actor,
action, writer, the complete When/Source header, and `block` values `0` → `1`;
the leader and term stay unchanged. The shared
`expectFreshChangeLogReceiptWriter` helper reopens the pinned receipt within the
normal assertion budget until the actual writer appears, and lets refusals or
errors fail. Attached evidence includes bootstrap, placement and term, the
pinned receipt and master reads (HIL-1621).

The cold acceptance run passed on the test box on 2026-10-26 with no retry.
F-016 remains quarantined: its tag, 2026-10-18 date and original failure
observation stay in the spec, and this pass does not settle the earlier flake.
Point the scenario with `-- --grep 'cluster change log:'` (HIL-1465).
This pointed run stays in the live phase, with no fault request.
The theme spec checks settings on all three masters, choices and reset between
independent account sessions, new tabs, and stale Dark reconnect without adoption;
`-- --grep 'cluster theme'` runs its three tests.

`cluster/live/i18n.spec.ts` adds five live tests (HIL-1505):

- language `nativeName` edits in both directions;
- country `currencySymbol` edited from the list and delivered to the other card;
- locale off/on delivered between masters;
- language base and locale-correction names created and then edited across masters;
- country base and locale-correction names edited in both directions.

Each test uses real forms in two independent contexts fixed to the two follower
masters, with `signUpAdmin` in both. CDP checks their WebSocket upgrades against
those masters. After the subscriptions are ready, `watchI18nLivePages` checks
delivery on the existing documents and sockets, with no observer reload,
navigation or Apply and no new WebSocket on either page; `expectI18nName` checks
the saved names in place without Apply. The leader and term stay unchanged.
Cleanup removes the language, locale and names fixtures; the country edit
restores its original currency symbol, currency code, default locale and card
text. From the repository root, the pointed live run is:

```bash
composer -d demo/binance-btc-tracker run test:cluster:e2e -- --grep i18n
```

The pointed i18n run stays in the live phase, with no fault request. In the full
browser run, the harness then stops the slave the backup spec names, waits for
placement to move, and runs the after-loss phase: the archive is out of reach
and the log node offline.
`composer -d demo/binance-btc-tracker run test:cluster:e2e` runs that suite
alone on a fresh stand; `-- --grep circle` points the existing live/after-loss
phases. The harness collects a static plan before destructive host actions.
Every selected main project finishes before any quarantine project starts.
A nonempty main `daemon` project retains its original place before live and
strict recovery checks; with the entire daemon scenario tagged, main daemon is
empty and starts no controller or readiness wait. A main failure may skip its
dependent after-loss phase, while the quarantine tail still runs and preserves
main's native rc.

The tail runs selected quarantine after-loss tests first, only with a valid
original fault request and a stopped slave. Without that state they are
`notRun`, never substituted with live. Before quarantine live, the host starts
the stopped slave, waits for all members to converge and reads leader/follower
and term again. It opens a recognized saved freeze through the existing test
bootstrap and refreshes roles before live and daemon. Quarantine daemon runs
last. There is no database reset or stand teardown between main and the tail.
`-- --daemon` selects daemon only, including forwarded Playwright filters; a
pointed live run does not add daemon. Each selected quarantine project runs
at most once.

A phase listed in `FLAKY_SKIP` of `framework/docker/cluster/browser.py` is
switched off whole, the host-side twin of a static `test.fixme`: the host prints
`cluster e2e: phase <project> SKIP (<reason>)` and prepares it in neither main
nor quarantine. `-- --daemon` with daemon switched off prints
`browser suite skipped: phase daemon` and succeeds without collecting.

The daemon scenario checks foreign Workers/Agents/Cron/HTTP/Env, a remote UI
restart proved by fresh node journals, mismatch privacy, then loss of the
original leader. Two original follower contexts retain scheme, placement,
mismatch and dashboard tabs; the source that remains follower proves addressed
`page_resubscribe`, ordinary subscribe and live updates on its original
sockets/documents. Both LEADER rows (`hilos_index`, `hilos_daemon`) move; the
dead column remains unknown. The destructive spec has no retries.

The host alone changes the remote environment; it kills the leader only after
the browser's run-id-bound readiness request. On every exit it joins its
controller, restores the master, clears that environment override, waits for
all five members to converge, checks every restart for startup refusal and
removes only that run's control files. Live delivery control likewise stops
and joins before restoring its captured faults and checking controller errors.
A main cleanup failure stays red, and any later main phase requires restored
roles. For quarantined daemon, ordinary phases have already finished: a host
failure becomes one final failed/not-run observation, without changing main rc.
The original failure reason is retained alongside any recovery failure.
Inspect before/after, fresh restart journal tails, Cron and mismatch page-response/table-window
frames and socket/document recovery proof are Playwright attachments under
main's `test-results/cluster-daemon`, or `test-results/quarantine/daemon` for
the tagged scenario; the harness prints restoration/convergence too.
The scenario and browser
steps share a group because both reset the same compose project (HIL-1232).
Before the browser stand starts, a fresh CLI image clears its stopped nodes'
log directories, including rotation archives, while retaining `.gitkeep` and
every `protected-mode.state.json*` store, including unfinished `.tmp` files.
Ordinary `up` does not run this cleanup. Save a failed run's logs before
starting another. The step runner copies `data/logs-cluster/<node>/` to
`artifacts/binance-btc-tracker-cluster-e2e/nodes/<node>/` at every verdict,
before teardown. Live logs and this run's archive and staging trees keep their
relative paths. A missing root or unreadable source is listed in `SNAPSHOT.txt`
under `missing`, without changing the test verdict; `data/logs-test` is not a
substitute for these node journals. A direct composer run has no step snapshot
collector, so preserve its journals before starting the next fresh stand. After
node loss the wait checks only placements that host an agent (`placing` or
`started`); a refused record is no running work.

The `online-testing-cluster-e2e` step proves Analytics and Moderation on the
existing three-master stand (HIL-1423, HIL-1639). After
`composer -d demo/online-testing run test:e2e-build`,
`composer -d demo/online-testing run test:cluster:e2e` runs three sequential
source/viewer pairs, each master followed by the next in the harness's list.
The entry serves Angular's `dist/index.csr.html`; Mailpit and the browser runner
belong to the `e2e` profile. This stand has only a live phase: no fault request,
no after-loss phase and no slaves. Online-testing declares no `e2e.capabilities`;
binance-btc-tracker declares all three: `daemon`, `delivery` and `env-mismatch`.

Each pair registers a person and an administrator in separate contexts, proves
every actual WebSocket upgrade's master through CDP, waits for both Analytics
snapshots, and checks navigation, session isolation, action order, sorting and
absence of action bodies in the DOM and Analytics frames. Before and after the
browser work, the live reader is on the harness leader, the writer matches its
single started POLICY placement, and every member has one journal agent.
Fresh node logs prove the LEADER/NODE agents, accounting for stops, worker death
and daemon restarts; inspect's POLICY placements are not their registry.
The leader and term must stay unchanged throughout each pair.

The same step runs `cluster/live/moderation.spec.ts` in three master rotations.
Each rotation puts a person (S), the rule-writing administrator (A) and the
observing administrator (O) on distinct masters, and checks every actual socket
upgrade against its assigned master. A saves a Profile name rule with a
`modelKey`; O sees the exact rule without reload. S changes their own name
without supplying a key and sees it published, with exactly one model request
using the key from A's rule. O deletes the rule; A receives a pending removal,
uses the standard Apply action, and then sees the row disappear without reload.
Before and after the browser work, the spec checks both POLICY singleton
placements and the current journal agents on all nodes. The leader and term
remain unchanged. The removal is not expected to disappear from A's view before
A applies it.

The graph runs this step after `online-testing-e2e`, and both it and
`online-testing-cluster` share the `online-testing-cluster` group. A directed graph
run is `composer run test:suite -- online-testing-cluster-e2e --lanes=1` from
the repository root; it includes the step's dependencies. The collector keeps
`artifacts/online-testing-cluster-e2e/nodes/<node>/`, including archive and staging
paths, plus Playwright evidence and `SNAPSHOT.txt`, before teardown, under the same
missing-source rules as binance. It never substitutes `data/logs-test` for these
journals. Save direct-run logs before another fresh stand empties them.

The full run carries the phase-one fleets binance-btc-tracker, ecommerce-shop
and online-testing; the planned carriers above enter under the same rule.
An e2e step may stand beside any fleet in the lane plan: nothing keeps them
apart, and each fleet leaves with its own step (HIL-1227).

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

## Forgetting loaded ORM state

Use this operation in tests that need the next ORM read to start from cold
memory without changing database rows.

Outside tests, agents must neither use nor propose this operation for approval. Use outside tests only when the owner explicitly asks for it.

This rule covers `test:orm:forget`, the direct `forgetLoadedDataForTest()` API,
and indirect use of the same operation through a helper or another command.
An explicit owner request does not authorize changing `APP_ENV` to gain
admission or bypassing the environment gate. Ordinary restore and `reHydrate()`
remain governed by their own contracts; this restriction is on the test-only
forget operation.

Rollback-scoped integration cases request a cold ORM reset after rollback by
implementing `Hilos\Testing\BreaksOrmState`; see [Rollback-scoped integration tests](testing/integration-tests.md#cold-orm-reset-after-rollback).

**Choose the process whose memory the test needs to forget.** In the current
PHP test process, use the mounted context:

```php
Hilos::$db->forgetLoadedDataForTest();
```

For one already-running process, invoke the project's `cli.php` in its test
container, over the configured command channel:

```bash
php cli.php test:orm:forget daemon
php cli.php test:orm:forget worker <workerId>
```

`daemon` addresses that node's master and accepts no worker id. `worker`
requires one nonzero signed integer: positive for a regular worker, negative
for a monopolistic worker. Extra arguments, other process names, and zero are
refused. There are no new options; `--help` follows the usual CLI rules.
The command is database-free in the CLI process: it opens no SQL connection
there. It forgets only the addressed live process, not the calling test's PHP
memory, other workers, or peer nodes.

**Success means completed work.** The master replies to a `daemon` request
only after its local reset completes. For `worker`, it sends
`WorkerTestOrmForgetDTO` and waits for `WorkerTestOrmForgottenDTO`: a successful
ACK must carry the matching correlation id, `ok=true`, and `error=null`, and
come from the original worker connection. A missing or malformed ACK is not
success; a worker refusal, disconnect, or timeout fails the request. Foreign,
duplicate, and late ACKs cannot complete it. The wait is discarded when the
caller leaves, and a replacement worker is not retried automatically. Success
prints `{"process":"daemon","workerId":null}` or the corresponding worker
payload with its signed id; refusals use the ordinary error reply and nonzero
exit code.

**Admission is checked before any collection changes.**
`NonProductionGate::admitted()` must accept the environment: production,
staging, missing, unreadable, and unknown environments are refused. The CLI,
command socket, answering process, and local API retain their gates. A live
target without a DB context is refused. `Database::hasOpenTransactions()`
checks every connection index without connecting, executing SQL, or changing
the active index; any open transaction, including a failed level awaiting
rollback, refuses the reset. The local API raises `OrmForgetRefusedException`
for environment or transaction refusal. A direct call to
`Objects::forgetLoadedDataForTest()` has the same two guards. Finish the
transaction with its normal commit or rollback before resetting; the operation
does neither and does not discard rollback journals, after-commit work, or
handler state.

**The reset is cold and local.** It drops rows from every mounted Objects
collection and clears every mounted DB View item cache, without SQL, loading
an unmounted collection, or replacing `Hilos::$db`. The context, collections,
actions, mounted declarations, generation baseline, connection settings, and
SQL result handles stay in place. A DB collection with the `NONE` strategy,
or one already loaded whole (including through `preloadAll()`), keeps its
promise to load the whole table on its next key/count/first/last/walk read,
even across repeated resets before that read. Other `KEY`, `BATCH`, and
`FULL_ON_ACCESS` collections keep their normal loading strategies.

Obtain Entity, Object, and DbItem references and detached selections again
after the reset: previously handed-out values are not rebound or refreshed.
The operation sends no sync or SourceChange announcements and resets no RT or
analytics state. It is not a complete test teardown or a transaction harness.

Do not substitute `reHydrateDbBackedCollections()` or `Objects::reHydrate()`
for this cold reset: rehydration immediately reloads a collection that was
already loaded whole, executing SQL during the operation. Forgetting leaves
it cold until the next read. Ordinary restore/rehydration and `clearInMemory()`
keep their existing behavior.

---

## Writing new tests

- **PHPUnit unit tests** (`tests/Unit/`): pure, no DB, no Hilos runtime.
  Use PHPUnit's `TestCase` directly. See
  `demo/chat/tests/Unit/MessageActionDTOTest.php` for reference.
- **Rollback-scoped PHPUnit integration tests** (`tests/Integration/`): opt in
  with `#[IntegrationTest]` on a concrete class and follow
  [Rollback-scoped integration tests](testing/integration-tests.md). Existing
  unmarked classes and their bases keep their current lifecycle.
- For signal-layer DTOs that cross the worker → daemon IPC boundary,
  always cover the `fromArray(toArray())` roundtrip — a missing or
  broken `fromArray` silently falls back to generic `SignalData` and
  drops any `WebSocketEnvelopeAware` metadata. See
  `framework/tests/Unit/PageActionSuccessSignalDataTest.php::testRoundtripPreservesSuccessMarkerAndRequestId`.
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
  scheduled rounds/settlement, cron rules, delivery windows, polling and
  reporting intervals, heartbeats and staleness, cooldowns, rotations by age,
  delayed answers): there is no global clock to mock — see `cli/commands.md`
  § "Time-based features: no universal clock". Add a small, per-feature
  test-only CLI (`extends TestOnlyCommand`) that brings the one moment to now
  and stamps it where its reader reads it; never build a shared time-travel
  knob. For a setup without clock waiting or a short temporary e2e real wait,
  follow the canonical
  [Time rules](frontend/testing-strategy.md#time--a-command-brings-the-moment-to-now).
  Real-clock waits outside that exception, every such PHP-test wait regardless
  of duration, and shortening a stand's production timer through env or a
  compose overlay require the owner's approval in the leaf's contract
  ([approval gate](../../agents.md#contract-approval-gate-hard-stop)).
