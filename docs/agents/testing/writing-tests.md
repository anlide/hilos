# Testing — Writing New Tests

Read this when writing a new test: which kind it is and where it lives, the
reference to copy, a reproduction landing before its fix, a second process
inside a unit test, and what a time-based test may wait on.

## Writing new tests

- **PHPUnit unit tests** (`tests/Unit/`): pure, no DB, no Hilos runtime.
  Use PHPUnit's `TestCase` directly. See
  `demo/chat/tests/Unit/MessageActionDTOTest.php` for reference.
- **Rollback-scoped PHPUnit integration tests** (`tests/Integration/`): opt in
  with `#[IntegrationTest]` on a concrete class and follow
  [Rollback-scoped integration tests](integration-tests.md). Existing
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
  ladder is [A cross-process defect is an e2e defect first](selective.md#a-cross-process-defect-is-an-e2e-defect-first): e2e first, the
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
  (see [process-fork.md](../code-style/process-fork.md)) and admitted only by an
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
  [Time rules](../frontend/testing-strategy.md#time--a-command-brings-the-moment-to-now).
  Real-clock waits outside that exception, every such PHP-test wait regardless
  of duration, and shortening a stand's production timer through env or a
  compose overlay require the owner's approval in the leaf's contract
  ([approval gate](../../../agents.md#contract-approval-gate-hard-stop)).
