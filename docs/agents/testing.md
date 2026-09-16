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
| `composer run test:framework:up` | Start `mysql-framework-test`. |
| `composer run test:framework:unit` | Run framework unit tests (`framework/tests/Unit`). |
| `composer run test:framework:integration` | Run framework integration tests (`framework/tests/Integration`). Requires DB. |
| `composer run test:framework:phpunit` | Run both PHPUnit suites. |
| `composer run test:framework:all` | `install-deps` → `up` → `phpunit` → `down`. Runs every available test type for the framework. |
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
- Integration iteration: `composer run test:up && composer run test:db-reset && composer run test:integration` (first run only; subsequent iterations can skip `db-reset` only if the test mutates neither schema nor data — a data-mutating test needs a reset before each rerun).
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
all five configurations and requires the three attributes. A sixth demo is
added to the list inside that test — a demo born without its gate fails there
instead of running quiet.

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
| An Angular view's template | `test:framework:frontend` — plain `tsc` does not read templates, so the `@hilos/angular` check runs a second pass, `ngc --noEmit` over the `tsconfig.build.json` the ng-packagr build uses; a template error is red here, not only in `test:framework:frontend:build` | every Angular template change |
| Wire / signal / subscription **contract** (backend + FE together) | the above **plus** one affected demo's `test:e2e-full` — the cross-boundary path only e2e exercises | when the contract moves |
| An e2e spec or a selector | that demo's e2e, pointed: `test:e2e-up` once, then `test:e2e -- <grep>` | while editing the spec |
| Cross-connection behavior — subscription, viewport, pending/Apply, presence | the **two-window** e2e across the affected demos (and a full pass) | rarely — see below |
| Accessibility — ARIA roles/names, keyboard, focus, screen-reader semantics | the **a11y** e2e (`a11y.spec.ts`) across the affected demos (and a full pass) | rarely — see below |

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
`moderator.spec.ts` (Vue; settings / bots / profile also carry two-tab tests) and
the `users.spec.ts` of tasks (React) and polls (Angular) — one
representative path per view layer. The **a11y** coverage is the same kind of
separate, rarely-run category — an `a11y.spec.ts` per demo asserting the
accessibility tree over the live socket: table accessible names and `aria-sort`,
keyboard sort operability, the skip link and `aria-current`, the document title
and page-change announcement, one top-level heading per page, and presence
exposed as text. Run it in the full pass or pointed (`test:e2e -- a11y.spec`)
while editing a11y; a green inner loop (check + vitest + pointed phpunit) does not
require re-running them. The normative AA requirements those specs guard are in
[frontend/accessibility.md](frontend/accessibility.md).

Always **reset before re-running a data-mutating e2e** (`test:e2e-up` does it); see
the next section.

### A cross-process defect is an e2e defect first

A defect where **two processes see different state** — the master appends an
agent's log and a worker asks for its size; the daemon and the CLI; two nodes of
a cluster — is proved on the stand, where both processes are real rather than
impersonated. That is the default, and it is already how the log tail is tested:
`demo/chat/tests/e2e/helpers/logs.ts` asks the daemon to append lines over its
own command channel, the log-store agent writes them, the master files them, and
the front end reads them through a worker
(`demo/chat/tests/e2e/tests/logs.spec.ts`). The helper's docblock
(`demo/chat/tests/e2e/helpers/logs.ts:14`) says why it does not append to the
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
gateway (`framework/docker/stand-gateway`) is one container running PHP's
built-in server on port 18000, and the channel is the path prefix
(`framework/docker/stand-gateway/src/Router.php`, `SmsRoutes.php`,
`TelegramRoutes.php`); whatever it catches lands in Mailpit and is read by the
helpers a spec already uses for a code — `demo/chat/tests/e2e/helpers/sms.ts`
and `demo/chat/tests/e2e/helpers/telegram.ts`. That inbox, the house, and the
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
php scripts/run-test-suite.php chat-e2e       one step, plus its dependencies
php scripts/run-test-suite.php --list         the plan, without running it
```

A target is a step id or a tag, and whatever it selects pulls its dependencies in.
Steps run **concurrently up to a global limit**, the longest expected step first —
by its **own** duration, not by the chain waiting behind it. The
limit defaults to 2 on a machine with at least 8 cores and about 4 GB available and
to **1 everywhere else**, so a small CI runner degrades to the old serial run
instead of thrashing; `HILOS_TEST_LANES` or `--lanes=N` overrides it.

That lane count leaves the runner as a **timeout multiplier** as well: before the
first step it exports `HILOS_E2E_TIMEOUT_SCALE` and `CLUSTER_E2E_TIMEOUT_SCALE`,
so a two-lane run gives every suite twice its usual patience instead of each
suite guessing from a load average it sampled for itself. A value already in the
environment is kept as it is. What each suite then does with the number — and why
memory can still raise it — is in
[frontend/testing-strategy.md](frontend/testing-strategy.md).

The graph is where the safety lives, and two kinds of constraint carry it:

- **an edge** — the frontend steps of a demo (`<demo>-check`, `<demo>-e2e`) depend
  on `fe-build`, because all three demo frontends prebuild the **same**
  `framework/frontend` workspace. Concurrency is safe only because a current SDK
  makes those prebuilds skip. Do not delete that edge to free up a lane.
  `<demo>-php` is backend-only and waits for nothing.
- **a group** — the steps of one demo share one compose project, and
  `test:e2e-full` starts by taking that project down. Group members never run at the
  same time, but a red one does not skip the others: `<demo>-php` is backend-only
  and keeps its own verdict when `<demo>-check` fails.

Reordering is not the lever it looks like. The three steps of the `chat` demo share
a group, so 19 + 169 + 618 = 806s of them can never overlap: **13m26s is the floor of
a full run at any lane count**, below even what two lanes could otherwise pack
(772s). Against a current cost of about 14m30s, ordering by the critical path behind
each step buys 22 seconds, and counting group load as well buys 85 — measured
2026-09-05 (HIL-854). Both were declined: every faster order puts `chat-e2e` beside
the live cluster fleet, which once cost it 16m10s against 9m36s (HIL-752). The
numbers, and what re-measuring them can quietly break, are in the head of
[`scripts/test-suite.php`](../../scripts/test-suite.php).

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
- **green alone** — the run is *inconclusive*, not green. Record which steps
  collided and what the load was, then lower the lane count or fix the timeout that
  lied.

What must never happen is a red waved off as "probably the neighbour" without that
re-run: it is exactly how a genuine regression reaches the base wearing the excuse
of concurrency. The check is cheap — one demo block is 1–5 minutes, less than a
single serial full run, and it only happens on red. The other half of the defense
is that Playwright's caps stretch with host load rather than firing
([frontend/testing-strategy.md](frontend/testing-strategy.md)).

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

- A test that **mutates data** is not idempotent across runs on the same database.
  Reset before re-running it (`composer run test:db-reset`, or `test:e2e-up` for
  e2e); the full pass (`test:all` / `test:e2e-full`) resets for you. **Do not treat a
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

  A fork is a form of its own. The child inherits the live PHPUnit run, so: an
  immediate `exit()` at the end of the child's branch; not one assertion on the
  child's path — a red one there does not fail the test, it carries the child to
  the end of the run and prints a second PHPUnit report; and the wait in
  `finally`, through `pcntl_waitpid()`. The suite's only carrier of the form is
  `framework/tests/Unit/AsyncHttpClientTest.php:443` (`serveTlsResponseInChild()`;
  the `exit` at `:494`, the wait at `:155`). HIL-929 intends to retire that
  regression test once an e2e through the emulator covers it — the reference
  shows the form and promises nothing about the file.

  Two samples, and what picks between them. `framework/tests/Unit/OrphanReaperTest.php`
  (HIL-450) is the sample of **structure**: real children through the
  framework's `Hilos\Core\Process`, stopped in `tearDown()` (`:51-64`), a
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
