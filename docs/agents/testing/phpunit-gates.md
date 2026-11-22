# Testing — What Fails a PHPUnit Run

Read this when a PHPUnit run is red for a reason other than a failed assertion —
a code-style rule or its baseline, a deprecation, notice or warning, a test past
60 seconds, a process environment left changed — or when a shared topology
snapshot test is red and you must tell your lines from another leaf's.

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
[code-style/automated-checks.md](../code-style/automated-checks.md).

Moment storage has a separate source guard, `MomentStorageGuardTest`, run by
`composer run test:framework:unit`. It scans the available framework and demo
backend roots and requires zero `MOMENT-WRITE` findings, reporting each
diagnostic. There is no moment storage registry or allowance; see
[Moment Storage Checks](../code-style/automated-checks.md#moment-storage-checks).

Live schema checks are an opt-in integration door, separate from the unit
source scan. `composer run test:framework:integration` includes
`MomentStorageSchemaIntegrationTest`; run it after
`composer run test:framework:up` and `composer run test:framework:databases` as
in the framework setup of [backend-suites.md](backend-suites.md#framework-backend-framework). The existing demo suites — chat, polls, tasks,
binance-btc-tracker, online-testing, ecommerce-shop, preferans and
concert-tickets — run their live moment audit through `EntitySchemaConsistencyTest` with that demo's usual
`composer run test:integration` command from its root after the documented
`test:up` and `test:db-reset` setup. These runs cover the physical axes
`moment_column_missing`, `moment_type` and `moment_auto_write`; the framework
unit source rule reports `MOMENT-WRITE`. A source-only green result does not
replace the live schema pass.

---

## An issue fails the run

Every `phpunit.xml` in the repository — `framework/tests` and each demo's
`tests` — sets `failOnDeprecation`, `failOnNotice` and `failOnWarning` to
`true`. It also sets `failOnPhpunitWarning` to `true`: a warning raised by
PHPUnit is a red run too. This catches failures while an extension bootstraps
or an event subscriber runs; PHPUnit turns exceptions from either into its own
warning, and without this flag a broken time-limit extension could leave a run
green without enforcing its limit. A deprecated call, a notice or a warning is
therefore a red run, not a line in a summary nobody reads. PHPUnit prints the
details itself: each `failOn*` raises the matching `displayDetailsOn*`, so the
output names the file, the line and the test that triggered the issue.

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
Removing one of the four attributes from a configuration is **not** a way out
— it is undoing the gate, and it takes a person's decision.

`PhpunitIssueGateTest` in the framework unit suite keeps the gate on: it reads
every configuration — the framework's and one per demo — and requires the
four attributes and both the process-environment and time-limit extensions.
The next demo is added to the list inside that test — a demo born without its
gate fails there instead of running quiet.

*Why the gate exists.* The warning that a walk over a mutating collection was
skipping a row was printed on every run and counted by nobody, and it stayed in
the tree until it cost a wrong session (HIL-673).

## A test that runs past 60 seconds fails

Every `phpunit.xml` registers
`Hilos\Testing\TimeLimit\TestTimeLimitExtension`. It puts one 60-second
wall-clock limit around each test from `setUp()` through `tearDown()`, and
around the class hooks `setUpBeforeClass()` and `tearDownAfterClass()`. When a
test crosses the limit, the alarm immediately prints its name and PHPUnit
reports an error under that test's name, with the stack location where it was
interrupted. Catching the alarm does not make the test pass: if the test
finishes before the 10-second grace period ends, it is still an error; if it is
still running after those 10 seconds, the runner aborts with the same test name
and a stack trace from the abort point.

The limit has no test or configuration escape hatch: do not use `#[Large]`, a
custom limit, PHPUnit's `enforceTimeLimit`, `defaultTimeLimit` or `timeoutFor*`
settings, or a time-limit extension parameter. A test that reaches the limit
has a defect to fix. Replace a real wait with the feature's test command that
brings the moment to now ([Time rules](../frontend/testing-strategy.md#time--a-command-brings-the-moment-to-now),
[time-based CLI features](../cli/commands.md#time-based-features-no-universal-clock));
split long computation across tests so each test fits, as in
`CodeStyleGuardTest`.

The extension does not cover tests run in a separate process because PHPUnit
does not load extensions in that child process, or data providers. A C call
that hangs without returning to PHP cannot be interrupted by the alarm; the
overall run limit remains the last line of defense. A class hook that swallows
the first alarm and finishes before the next one is also a boundary: the limit
does not guarantee an error for that case.

*Why the limit exists.* The full run for HIL-2056 on 12 November 2026 spent 90
minutes printing dots without identifying the test. `PhpunitIssueGateTest`
requires the time-limit extension in every configuration.

---

## A test leaves the process environment as it found it

Every `phpunit.xml` registers
`Hilos\Testing\ProcessEnvironment\ProcessEnvironmentExtension`. It snapshots
`getenv()` on the way into a test and into a test class, and puts that snapshot
back on the way out: a name the test changed returns to its previous value, and
a name the test added is removed. A variable set in `setUpBeforeClass` lives for
the whole class and leaves with it. A test that fails or is skipped in `setUp`
leaves no environment behind for the next test either.

A test does not put environment variables back itself. An `<env>` value in the
configuration (`APP_ENV=test`) reaches every test, whatever the previous one
removed. Statics sit outside the extension; the test that changed one puts it
back.

*Why.* The framework gate splits integration into pieces by the crc32 of a
class's short name. A test that cleared `APP_ENV` in `tearDown` stayed green
only while the class that needed the variable ran in another piece. A new class,
or a rename, put the two in one piece (HIL-1916, HIL-2097).

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

