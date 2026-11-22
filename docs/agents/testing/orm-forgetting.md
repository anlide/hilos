# Testing — Forgetting Loaded ORM State

Read this before using or proposing to forget loaded ORM state — by CLI, direct
API or a helper. Outside tests it is neither used nor proposed unless the owner
explicitly asks.

## Forgetting loaded ORM state

Use this operation in tests that need the next ORM read to start from cold
memory without changing database rows.

Outside tests, agents must neither use nor propose this operation for approval. Use outside tests only when the owner explicitly asks for it.

This rule covers `test:orm:forget`, the direct `forgetLoadedDataForTest()` and
`forgetHoldingForTest()` APIs, and indirect use of the same operation through a
helper or another command.
An explicit owner request does not authorize changing `APP_ENV` to gain
admission or bypassing the environment gate. Ordinary restore and `reHydrate()`
remain governed by their own contracts; this restriction is on the test-only
forget operation.

Rollback-scoped integration cases request a cold ORM reset after rollback by
implementing `Hilos\Testing\BreaksOrmState`; see [Rollback-scoped integration tests](integration-tests.md#cold-orm-reset-after-rollback).

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

**Forget the holding too when the test stands in for a process that does not
hold the set.** The cold reset keeps a collection that was loaded whole
promised whole, so a holder that started earlier in this PHP process — a
library's `onStart()` calling `preloadAll()`, in this test or in any test
before it — leaves the set read whole on its next read. A reader that a
non-holder answers by SQL (`whereColumnIs()`, `whereColumnIn()`,
`countByColumn()`) is then never reached, and the same reader rewritten as a
walk passes here and refuses in production.
`Hilos::$db->forgetHoldingForTest(<collection key>, ...)` does the cold reset
for the named collections and returns each to the state its mount gave it: a
lazy collection is partial again and a column read goes to SQL; a walk of a
`KEY` or `FULL_ON_ACCESS` collection refuses with
`CollectionNotFullyLoadedException`, and a `BATCH` one reads the table whole on
a walk, as right after its mount. A `FULL_ON_ACCESS` collection stays partial
only until its first read by key, which reads the table whole, as right after
its mount. A `NONE` collection keeps its whole-table promise, because every
process reads it whole. Without keys it does this for every mounted collection
and clears every View item cache. An unmounted key refuses with
`ObjectCollectionNotFoundException` before anything changes; admission and open
transactions refuse exactly as for the cold reset. Truth-source claims are
untouched: a library that keeps its claim still writes through its actions.

**Choose by the process the surface lives in.** A test of a table or a page
that production serves from a process not holding another library's set calls
it in `prepareIntegrationEnvironment()`, after the last start that preloads
that set and before the rollback scope, naming that set; its cases then run the
production read, and one case asserts `isAllLoaded()` is false before and after
the read. A holder's own test does not call it and does not clean up after
itself: a test that needs a non-holder asks for it in its own preparation. A
test that only needs fresh reads uses the cold reset.

```php
protected function prepareIntegrationEnvironment(): void
{
    parent::prepareIntegrationEnvironment();
    Hilos::$db->forgetHoldingForTest(EcommerceShopDbContext::productWarehouseStocks);
}
```

---

