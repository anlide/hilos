# Area: Rollback-Scoped Integration Tests

Read this before writing or changing a PHP integration test that opts into the
rollback lifecycle, or changing its transaction, fixture, SQL, or ORM-reset
behavior.

## Opt In Explicitly

Only `#[Hilos\Testing\IntegrationTest]` on a concrete test class opts into the
rollback lifecycle. A PHPUnit group, test name, or directory does not opt in.
The class must extend `Hilos\Testing\RollbackIntegrationTestCase`, directly or
through an environment base. The example here uses
`FrameworkRollbackIntegrationTestCase`.

Unmarked integration classes and their existing base classes keep their current
lifecycle. Do not convert legacy tests as part of an unrelated change.

## Managed Lifecycle

The rollback base owns final `setUp()`, `assertPostConditions()`, and
`tearDown()` methods. A marked case must not replace those methods, other
managed PHPUnit lifecycle methods, or its class-level setup and teardown hooks.
Use the base's case hooks instead:

1. `prepareIntegrationEnvironment()` opens connections, prepares schema, and
   mounts context before a rollback scope exists.
2. The base starts the handler and rollback scope, then calls
   `setUpInTransaction()` for case fixture rows.
3. The unchanged test method runs inside that managed scope.
4. The base rolls back every scope root, ends the handler, performs a requested
   ORM reset while its target context is still mounted, and calls
   `releaseIntegrationEnvironment()` to restore the environment.

Environment bases own `prepareIntegrationEnvironment()` and
`releaseIntegrationEnvironment()`. Keep schema setup and teardown outside the
root; put case rows in `setUpInTransaction()`. Setup failure, assertion or test
failure, exception, skip, rollback failure, reset failure, and release failure
all enter managed cleanup. Cleanup attempts later phases after an earlier phase
fails, and reports cleanup failures without replacing the primary setup or test
failure.

**Checked automatically: `INTEGRATION-TEST`.** The source guard recognizes the
explicit marker, requires the rollback base, and rejects marked cases that take
over the managed lifecycle.

## Rollback Scope

`Database::beginRollbackScopeForTest()` is the test-only entry, protected by the
non-production gate. The returned `TestRollbackScope` exposes
`rollback(): void`; it has no commit operation. The harness owns this entry. A
test case does not start or close the scope itself.

Only one test scope may be active in a PHP process. Starting one refuses if any
connection has an open or failed transaction. Creating the scope does not open
a connection or send SQL. A rollback root is added lazily when a connection is
first used by SQL, a transaction start, or memory-only transactional work, so
each connection touched by the case is covered.

The synthetic root is neutral to application nesting. The first application
transaction start on a connection creates a savepoint under it; a later plain
application start still refuses. Application commits release their savepoint
and hand held work to the test root. An application commit cannot commit the
root, and an ordinary bare rollback does not close it. Roots are owned by the
scope token; repeated cleanup is harmless. Cleanup attempts every root and
reports a leaked application level or failed root after the full cleanup
attempt. Admission and scope-cleanup refusals use
`TestRollbackRefusedException`; an application level left open is reported as
`TransactionLeftOpenException` after cleanup. The framework still never marks
its transactions nestable.

**Checked automatically: `TEST-ROLLBACK-SCOPE`.** The scope-entry call belongs
to the shared rollback harness and technical suite roots. In a marked case,
`INTEGRATION-TEST` also rejects manual scope entry from the case and its
resolved test-local helpers.

## SQL Inside The Scope

Use Hilos database APIs for the case. Verify persisted writes with an independent
`Database::sql(...)->rows()` read, not by reading the row back through the ORM
under test. The SQL guard checks the complete rendered query before execution,
including every statement in a multi-query. It admits only `SELECT`, `INSERT`,
`UPDATE`, `DELETE`, `REPLACE`, `WITH`, `SHOW`, and `EXPLAIN`; DDL, transaction
control, `SET`, `LOCK`, `CALL`, and other statement families are refused.

The lexer accounts for quoted strings, identifiers, and comments, so SQL words
inside data are not statements. Executable comments and ambiguous or unterminated
quotes are refused. `Database` checks rendered SQL against the connection's
actual `sql_mode`, including `ANSI_QUOTES` and `NO_BACKSLASH_ESCAPES`; beginning
the scope itself remains lazy and sends no SQL. A SQL-family refusal uses the
existing `DatabaseRuntimeException`.

Prepare schema before the scope with the existing migration stubs. Do not edit
those stubs to make them transactional.

## Cold ORM Reset After Rollback

Add `#[Hilos\Testing\BreaksOrmState]` to a marked class or one of its test
methods only when the test deliberately invalidates already-loaded ORM memory,
such as by changing a warmed row with raw SQL. Correct database writes through
the normal write APIs do not need this marker. Without it, the reset hook is not
called.

The default `ormResetTargets()` returns a list containing
`OrmResetTarget::forCurrentProcess()`. Override it only to name every process
whose ORM memory the test intentionally invalidates:

- `OrmResetTarget::forDaemon(string $host, int $port)` names a node's daemon.
- `OrmResetTarget::forWorker(string $host, int $port, int $workerId)` names a
  worker; its signed worker ID must be nonzero.

A marked reset requires a nonempty valid list. The local target calls
`Hilos::$db->forgetLoadedDataForTest()` in the current process. A remote target
uses the existing `AsyncCommandClient` and `test:orm:forget` command with a
bounded wait. The reply must match the request correlation ID and target process
and worker ID; a refusal, mismatch, or timeout fails the case. The resetter does
not add a daemon, broadcast, peer route, retry, or local fallback.
The reset runs after all roots and `handlerEnd()`, before the environment is
released. It clears loaded DB ORM data only; it does not reset RT state. Obtain
Entity, Object, and DbItem references again after reset because old references
are not rebound.

The reset target controls which process's ORM memory is cleared. It does not
extend the transaction root: database rollback covers connections in the
current PHP process only.

## What The Test Proves

An always-rollback case can show that SQL writes occurred before rollback and
that the database state was restored afterward. It does not prove a real commit,
`afterCommit` behavior, DB/RT synchronization, or external reactions. Held work
is discarded with the root. The root does not roll back a daemon, worker, or peer
process, `AUTO_INCREMENT`, external files, or network effects.

Keep transaction and transport tests that need to prove those behaviors outside
the marked always-rollback form.

## First Example

[`framework/tests/Integration/SecondFactorTrustsRollbackIntegrationTest.php`](../../../framework/tests/Integration/SecondFactorTrustsRollbackIntegrationTest.php)
is the first marked example. It warms ORM rows, deliberately changes one with
raw SQL, calls the unchanged `SecondFactorTrustsActions::capLiveUntil()` action,
and verifies the affected rows through independent SQL before the base rolls
back the fixture.

## Source-Guard Boundaries

`INTEGRATION-TEST` reads FQCN and aliased attributes, follows the transitive
parent chain, checks marker placement and managed lifecycle overrides, and
rejects explicit commits, manual scope entry, native PDO/mysqli bypasses,
procedural mysqli calls, and known unsafe executed SQL in marked cases. It
follows indexed test-local calls through `self`, `parent`, and `static`, traits,
static helpers, and declared receivers. Production callees retain their own
transaction behavior.

The index does not resolve dynamic calls, free-function helpers, returned
receivers, trait adaptations, or interprocess effects. SQL extraction covers
literals, concatenations, preceding local assignments, and literal class
constants at recognized database or native SQL calls. A known forbidden leading
verb can still be reported when the suffix is dynamic. Arbitrary computed SQL
and dynamic receiver types are not statically proven; runtime SQL admission
applies independently.

`TEST-ROLLBACK-SCOPE` checks literal member calls to the scope entry. A method
declaration, string, or comment is not a call. Its allowlist is the shared
framework backend harness and technical suite roots; it does not grant marked
cases permission to manage their own scope.
