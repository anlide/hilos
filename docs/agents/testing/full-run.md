# Testing — The Full Run

Read this when running or reading the full run: its step graph, lanes, edges and
groups, the built SDK packages step, a database per integration piece, a test
that passed only on a retry, the Playwright quarantine and narrow runs as
evidence.

## The full run — one graph, a bounded number of lanes

Everything the project can be tested with is one graph in
[`scripts/test-suite.php`](../../../scripts/test-suite.php), executed by
[`scripts/run-test-suite.php`](../../../scripts/run-test-suite.php):

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
Steps run **concurrently up to a global limit**: the group whose longest member is
longest goes first and, inside a group, the shortest step first — by the steps' own
hinted durations, not by the chain waiting behind them
([`scripts/launch-order.php`](../../../scripts/launch-order.php)). The limit is sized
from the machine ([`scripts/lane-count.php`](../../../scripts/lane-count.php)): a
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
[frontend/testing-strategy.md](../frontend/testing-strategy.md).

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
- **an edge to `<demo>-deps`** — every step that runs a demo's code (its check,
  php, build and e2e steps, its cluster scenarios, and `log-streams` on tasks)
  depends on `<demo>-deps`, the demo's counterpart of `framework-deps`:
  `test:install-deps` runs `composer install` of the demo's own lock in its test
  CLI container. The run installs what it needs instead of trusting a `vendor/`
  someone put into the working tree by hand — absent for a demo that arrived with
  the base, stale after a lock changed. The demo's `tests/.env` is tracked for the
  same reason, so no step has to create it.
- **a group** — the steps of one demo share one compose project, and
  `test:e2e-full` starts by taking that project down. Group members never run at the
  same time, but a red one does not skip the others: `<demo>-php` is backend-only
  and keeps its own verdict when `<demo>-check` fails.

**The built SDK packages — `fe-dist`.** Right after `fe-build`, one step checks what
the build shipped: `test:dist` of `@hilos/vue`, `@hilos/react` and `@hilos/angular`
(`test/packaging.smoke.mjs` in each) and `test:dist:consumer` of `@hilos/angular`
(`test/packaging.consumer.mjs`), a real Angular CLI AOT build over the finalized APF.
The consumer compiles with the toolchain installed in `demo/online-testing/frontend`
and has no install of its own, so the step depends on `online-testing-check`, which
installs it; when that check is red, `fe-dist` is skipped and the run carries no
packaging verdict. The step runs all four under `--- dist:` headers and fails at the
end, so one red check hides no other. `CI=1` turns the Angular disk cache off, and the
consumer build leaves no root-owned `.angular/cache` in the demo. Its hint stays below
90 s, which ranks it behind the integration catalog, into the free end of the run. A
leaf that changes what a package exports updates these checks in the same leaf
([frontend/sdk-packaging.md](../frontend/sdk-packaging.md#public-entrypoints-and-their-graph)).

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

The lane count is not the lever it looks like, and with six demos the order was
not either. The three steps of the `chat` demo share a group — one stand. On
2026-09-29, their measured
106 + 1227 + 6 = 1339s could not overlap: **22m19s was the floor of that graph
at any lane count** (HIL-1227, run 0659, the test box, seven lanes). That run
took 22m20s, every other step was done by 7m08s, and seven lanes could otherwise
pack that graph into 6m25s. These are historical measurements, not a current
baseline or a promised duration after the
[ordinary carrier expansion](ordinary-carriers.md#ordinary-demo-carriers).
With twelve demos the chat chain no longer bounds the run: the sum of the steps
over the lanes holds it, and the order matters — the `seconds` hints that rank
the steps move the end of a run by minutes (HIL-2023). The lane count still does
not. The numbers are in the head of
[`scripts/test-suite.php`](../../../scripts/test-suite.php).

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
See [frontend/testing-strategy.md](../frontend/testing-strategy.md#a-retried-test-makes-the-step-red-and-its-cause-must-be-traced).

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
[the frontend strategy](../frontend/testing-strategy.md#quarantine--a-static-tag-and-one-attempt-after-main).
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
uses a private `tests/.hilos-cluster-e2e/quarantine/<uuid>` plan/result
directory across the existing repository bind mount. Only its own files are
removed.

Host tooling keeps its own run files out of a demo's `data/`. Docker may
create that directory as root on a fresh tree before any test step starts, so
a host write there passes on one machine and fails on another. The cluster
browser host keeps every run file — the quarantine plan and results, the
daemon and delivery handshakes, the after-loss `fault.json` — in the demo's
tracked `tests/`, under `tests/.hilos-cluster-e2e/`, outside the `tests/e2e`
package whose format check would read it. The root `.gitignore` ignores that
directory by name.

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

