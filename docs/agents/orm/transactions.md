# ORM: Transactions

Read this before opening a database transaction, before writing code that must
run only once a write commits, before a framework method needs the caller's
transaction, and when the `NESTABLE-TRANSACTION` guard fails. The mechanism is
`Hilos\Database\Database`; this document is the rule it enforces.

## Core Rule

A transaction is opened, and then closed by exactly one successful commit or one
rollback, inside the handler that opened it. The pair is always the same shape,
and the commit is the last statement of the `try`:

```php
Database::transactionStart();
try {
    // writes
    Database::transactionCommit();
} catch (HilosException $failure) {
    Database::transactionRollback();
    throw $failure;
}
```

While the transaction is open, the announcements of its writes wait. The commit
of the outermost level releases them in the order the writes were made; a
rollback drops them, and so does a commit that fails. Nothing outside the
transaction learns of a row the database may never hold.

## What waits for the commit, and what does not

Two announcements wait:

- the DB-sync frame to the other processes of the node and the cluster
  (`db_sync_created`, `db_sync_updated`, `db_sync_deleted`, `db_sync_cleared`),
  together with the registration that awaits its echo — a dropped frame leaves
  no echo awaited;
- the fact on the source bus for every subscriber that is a *reaction* — the
  log level, the setting presets, the legal acceptance projection, a project's
  own subscriber.

Three things do not wait:

- a *mirror* of this process's memory — a subscriber marked
  `SourceMirrorSubscriberInterface`, today the view cache. It repairs the memory
  the code inside the transaction reads through, so it hears the write at once;
  deferred, a read inside the transaction would answer with a row the
  transaction has already deleted;
- everything runtime: an RT fact and an RT sync frame go out at the write, as
  they always did. The runtime is not covered by the transaction, and holding
  its announcements without restoring its memory on a rollback would leave this
  process alone with a change nobody else has (the memory side is HIL-1165);
- a signal the caller queues itself — to a user, a group, an agent. That is a
  message of the caller's, not an announcement of a change; the framework's own
  such messages are sent after the commit by their callers.

`Database::afterCommit(Closure $announce)` is the one door: it runs the closure
at once when no transaction is open on the current connection, and holds it on
the innermost open level otherwise. Use it for anything of your own that must
happen only if the writes stand — never for something that has to happen
whether they stand or not.

## Nesting

A plain `Database::transactionStart()` never nests. Called while a transaction
is open on the connection, it refuses with `NestedTransactionRefusedException`
before any SQL is sent; the open transaction is untouched and is rolled back by
its own caller's catch. This is the guard against the MySQL behavior the rule
replaces: a second `START TRANSACTION` silently committed the first one.

A transaction nests only when every level of the chain — the outermost and the
new one included — was opened with `Database::transactionStartNestable()`. The
nested level is a savepoint named by its depth. Its commit releases the savepoint
and hands the announcements it held to the level under it; they leave when the
outermost level commits. Its rollback undoes its own writes alone and drops its
own announcements alone. One level in the chain without the mark refuses the
nested start.

**The framework never marks a transaction nestable.** The owner's words: the
framework may know how to nest, and may never do it. A framework method that
needs the caller's transaction joins it with a method that neither starts nor
commits — `LegalAcceptanceCommands::record()` is the shape: called inside the
registration landing's transaction, it writes and returns, and `accept()` is the
same work wrapped in a transaction of its own for a caller that has none. A
project may mark its transactions; the `NESTABLE-TRANSACTION` guard reads
`framework/backend` alone and refuses the mark there, with no list of exceptions
([../code-style/automated-checks.md](../code-style/automated-checks.md)).

## The transaction ends with its handler

A handler is one unit of a worker's tick — a daemon message, the project's tick,
an agent's tick together with its stop, the signal dispatch, the analytics tick —
or one CLI command. A transaction open at the end of its handler is rolled back
by the framework, its held announcements are dropped, and
`TransactionLeftOpenException` is raised: in a worker it is contained as a
failure of that very unit, on the same card and with the same project hook as a
failure the unit raised itself; in the CLI the command fails with the error exit
code. The test bases do the same right after the test body and fail the case for it,
so one case that forgets its rollback does not refuse every case after it a
start. The worker's shutdown is outside this: the stop hooks it runs on the way
out are no unit of a tick, and what one of them leaves open dies uncommitted with
the process, charged to nobody.

This is what gives the picture the rule was written for: while a transaction
runs, whoever is outside it sees the old value; after the commit, everyone sees
the new one; after a rollback, nobody ever saw anything.

## The edges

- **A commit with no open transaction** is refused with
  `TransactionNotOpenException`: the caller believes its writes are saved, and
  they either went out one by one or vanished with a closed connection.
- **A rollback with no open transaction** does nothing, like `ROLLBACK` in
  MySQL. After a commit whose released announcement failed, the caller's catch
  rolls back a transaction that already stands, and loses nothing by it.
- **A commit that fails** — `COMMIT` or `RELEASE SAVEPOINT` refused — rolls its
  own level back, drops what the level held and leaves the level standing as
  *failed*. The caller's rollback closes it without SQL; a second commit of it is
  refused. The level is not removed early on purpose: in a nested chain the
  caller's rollback would otherwise reach the parent.
- **A released announcement that fails** reaches the caller of the commit,
  after every other held announcement was made: the commit already stands, and
  a swallowed failure here would be a sync that vanished without a trace.
- **No reconnect inside a transaction.** A query on a connection that lost its
  link is not retried on a new one while a transaction is open on it: the new
  session would know nothing of the transaction, the remaining writes would
  autocommit, and the commit would release announcements of rows the broken
  transaction never wrote. The query fails to the caller, whose catch rolls
  back. Closing the connection by hand under a transaction leaves the level
  failed the same way.
- **What a rollback does not restore.** The memory of the writing process — the
  cached rows, a collection loaded whole, the view cache the mirror repaired —
  and the runtime keep the rolled-back change until they are reloaded
  (not in the code yet — HIL-1165).

## Anti-Patterns

```php
// Wrong: a second start inside an open transaction. Refused before any SQL.
Database::transactionStart();
$this->recordConsent($userId);   // opens a transaction of its own inside

// Right: the inner step joins the caller's transaction.
Database::transactionStart();
$this->legalAcceptanceCommands()->record($userId, $revisions);
```

```php
// Wrong: the announcement is made whether the write stands or not.
Database::transactionStart();
Hilos::$sr->sendToUser($userId, ...);   // a message of the caller's, sent now

// Right: what must happen only on a commit is handed to afterCommit().
Database::afterCommit(fn () => $this->rebuildIndexFor($userId));
```

```php
// Wrong, in framework/backend: the mark. NESTABLE-TRANSACTION refuses it.
Database::transactionStartNestable();
```
