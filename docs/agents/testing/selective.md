# Testing — What to Run for Which Change

Read this when choosing the tests a change needs and how often to run them, when
two processes disagree about state and you decide between the stand and a unit
test, or when you re-run a test and need to know what state the previous run
left.

## Selective testing — what to run for which change

Match the test set to what changed; do not run everything for every edit. The
heavy suites (full e2e, two-window) cost a Docker stack per demo and minutes of
wall-clock, so they are a **deliberate, infrequent** run — never an inner loop.

| What changed | Run | How often |
|---|---|---|
| PHP backend logic (framework or a demo) | the affected side's PHPUnit — `test:framework:all` for the framework (the gate's own command: the unit suite beside the four integration pieces), or a demo's `test:phpunit` | every change |
| A project topology registry — `Hilos::PAGES` / `GROUPS` / `AGENTS` / `TABLES` / `ACTIONS` / `SIGNALS` / `AGENT_SIGNALS` | that demo's `test:unit` — the `*TopologyRegistryTest` snapshot guard stays red until the new page / agent / action / signal is added to it | every registry change |
| FE core / SDK or a view (`@hilos/*`, TS) | `test:framework:frontend` (check + vitest + lint + format) | every change |
| An Angular view's template | `test:framework:frontend` — plain `tsc` does not read templates, so the `@hilos/angular` check runs a second pass, `ngc --noEmit` over the `tsconfig.build.json` the ng-packagr build uses; a template error — and an Angular extended template diagnostic (`NG8xxx`), which `tsconfig.build.json` raises to an error — is red here, not only in `test:framework:frontend:build` | every Angular template change |
| Wire / signal / subscription **contract** (backend + FE together) | the above **plus** one affected demo's `test:e2e-full` — the cross-boundary path only e2e exercises | when the contract moves |
| Cluster behavior — consensus, membership, placement, the peer link and its TLS, what a write on one node does on another (`framework/backend/Cluster/`, the cluster seams of `DaemonManager` and `WorkerServer`, the harness in `framework/docker/cluster/`) | the scenarios that cover it, on the stand that carries them, pointed: `composer -d demo/<demo> run test:cluster:scenarios -- <numbers>` — a fresh stand every time; that stand's whole matrix before the merge. Which stand carries which scenario: section [The cluster stands — six demos, six shapes](cluster-stands.md#the-cluster-stands--six-demos-six-shapes) | when cluster code moves |
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
of the [step graph](full-run.md#the-full-run--one-graph-a-bounded-number-of-lanes) (FE install + build + check / vitest / lint, then every
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
(see [frontend/build-and-docker.md](../frontend/build-and-docker.md)). If a run
looks suspiciously cheap, the guards' own log lines are the first place to check.

It is **not** part of the inner loop. The two-window coverage lives in chat's
bots and PMD's profile, settings and users specs (Vue), the `users.spec.ts` of
file-gallery-manager (React), concert-tickets's `profile.spec.ts` (Angular — edits in another
tab of the same session) and online-testing's `users.spec.ts` (Angular) —
representative paths per view layer. The **a11y** coverage is the same kind of
separate, rarely-run category — an `a11y.spec.ts` per demo asserting the
accessibility tree over the live socket: table accessible names and `aria-sort`,
keyboard sort operability, the skip link and `aria-current`, the document title
and page-change announcement, one top-level heading per page, and presence
exposed as text. Run it in the full pass or pointed (`test:e2e-full -- a11y.spec`)
while editing a11y; a green inner loop (check + vitest + pointed phpunit) does not
require re-running them. The normative AA requirements those specs guard are in
[frontend/accessibility.md](../frontend/accessibility.md).

A data-mutating e2e is **re-run through the full cycle, pointed or not** — the cycle
resets for you; see [Re-running tests and state between runs](#re-running-tests-and-state-between-runs).

### What no run proves

What only a person sees on a real device or at a real provider is listed in
[manual-checks.md](../manual-checks.md). A change touching one of those surfaces
names the affected numbered items in its hand-over for acceptance; the person
records the result there. A green run does not stand in for those checks.

### A cross-process defect is an e2e defect first

A defect where **two processes see different state** — the master appends an
agent's log and a worker asks for its size; the daemon and the CLI; two nodes of
a cluster — is proved on the stand, where both processes are real rather than
impersonated. That is the default, and it is already how the log tail is tested:
`framework/frontend/scripts/standLogs.mjs` asks the daemon to append lines over
its own command channel, the log-store agent writes them, the master files them,
and the front end reads them through a worker
(`demo/roblox-game-server/tests/e2e/tests/logs.spec.ts`). The helper's header
comment (`framework/frontend/scripts/standLogs.mjs:5`) says why it does not append
to the file itself: "Appending to the file from here instead would prove only
that a file grew."

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
rule for adding a resident are [stand-services.md](../stand-services.md).

Only when neither step applies may a unit test spawn a second process, and then
the reason goes into the test's docblock. What such a test's own tooling erases,
and which sample to copy, is under [Writing new tests](writing-tests.md#writing-new-tests).

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
- The stand's log root, backups, and published files are cleared by the same
  `test:db-reset` (`test:stand:reset`) — published files because their database
  rows no longer exist.
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

