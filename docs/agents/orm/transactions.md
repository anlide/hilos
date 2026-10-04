# ORM: Transactions

Read this before opening a database transaction, before writing code that must
run only once a write commits, before keeping memory of your own that has to go
back when a write does not stand, before a framework method needs the caller's
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

One transaction covers the database and the memory of this process — the row
cache and the runtime. While it is open, the announcements of its writes wait.
The commit of the outermost level releases them in the order the writes were
made; a rollback drops them, and so does a commit that fails. Nothing outside
the transaction learns of a change that may never stand, and a rollback puts the
memory back as it was before the transaction, with the rows.

## What waits for the commit, and what does not

Two announcements wait, for a row and for a runtime state alike:

- the sync frame to the other processes of the node and the cluster
  (`db_sync_created`, `db_sync_updated`, `db_sync_deleted`, `db_sync_cleared`,
  `rt_sync_created`, `rt_sync_updated`, `rt_sync_deleted`), together with the
  registration that awaits its echo — a dropped frame leaves no echo awaited;
- the fact on the source bus for every subscriber that is a *reaction* — the
  log level, the setting presets, the legal acceptance projection, the outgoing
  RT sync, a project's own subscriber.

Two things do not wait:

- a *mirror* of this process's memory — a subscriber marked
  `SourceMirrorSubscriberInterface`: the view cache, the memory of an account's
  standing, the legal admin audience. It repairs the memory the code inside the
  transaction reads through, so it hears the write at once, a runtime write
  included; deferred, a read inside the transaction would answer with a row the
  transaction has already deleted. A rollback tells it what it put back, as a
  fact of its own;
- a signal the caller queues itself — to a user, a group, an agent. That is a
  message of the caller's, not an announcement of a change; the framework's own
  such messages are sent after the commit by their callers.

`Database::afterCommit(Closure $announce)` is the one door: it runs the closure
at once when no transaction is open on the current connection, and holds it on
the innermost open level otherwise. Use it for anything of your own that must
happen only if the writes stand — never for something that has to happen
whether they stand or not.

`Database::onRollback(Closure $undo)` is its pair: "put this back unless it
commits". It keeps the closure on the innermost open level, and with no open
level it keeps nothing — outside a transaction a write stands the moment it is
made. The write doors of the ORM and the runtime use it for the memory they
change; use it for memory of your own that a write inside a transaction changes:

```php
Database::transactionStart();
try {
    $previous = $this->countsByUser[$userId] ?? null;
    Database::onRollback(function () use ($userId, $previous): void {
        $this->countsByUser[$userId] = $previous;
    });
    $this->countsByUser[$userId] = $count;
    Hilos::$db->users[$userId]?->actions->rename($name);
    Database::transactionCommit();
} catch (HilosException $failure) {
    Database::transactionRollback();
    throw $failure;
}
```

## Nesting

A plain `Database::transactionStart()` never nests. Called while a transaction
is open on the connection, it refuses with `NestedTransactionRefusedException`
before any SQL is sent; the open transaction is untouched and is rolled back by
its own caller's catch. This is the guard against the MySQL behavior the rule
replaces: a second `START TRANSACTION` silently committed the first one.

A transaction nests only when every level of the chain — the outermost and the
new one included — was opened with `Database::transactionStartNestable()`. The
nested level is a savepoint named by its depth. Its commit releases the savepoint
and hands the announcements it held and its memory journal to the level under
it; they leave when the outermost level commits. Its rollback undoes its own
writes alone, puts back its own memory alone and drops its own announcements
alone. One level in the chain without the mark refuses the nested start.

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
the new one; after a rollback, nobody ever saw anything. The other way round
holds too, with no code of its own: a change another process made while the
transaction ran is applied by a unit of this worker's tick of its own — the
daemon message that carries it — and the transaction does not outlive its unit,
so the code inside the transaction works with the old value and the change is
applied after it ends.

## The MySQL part opens at the first query

A start sends no SQL and needs no connection. The first query on the connection
sends `BEGIN`, then a `SAVEPOINT` for every nested level started since, in
order; a refused `BEGIN` or savepoint reaches the caller of that query, and the
levels stay unopened. So a transaction that touches only the runtime never opens
its MySQL part at all — the master holds no database connection, and work of
that kind needs none. A level that never reached the server commits and rolls
back without SQL. The ban on reconnecting inside a transaction holds from the
`BEGIN`: before it the server holds no transaction to lose. A closed connection
fails only the levels that reached the server.

## What a rollback restores

A rollback puts back the memory the transaction's writes changed through the
doors, step by step in the reverse order of the writes, and then sends the SQL:

- **the object of a row** — an edit goes back to the values last saved before
  it, in the same entity instance and still tied to its row, so the next save is
  an `UPDATE`; an insert goes back to a new object without the key the table
  gave it; a delete is tied to its row again, with the row's values;
- **the membership of a collection** — a key put, dropped or read inside the
  transaction holds what it held before, the very instance a caller or a View
  wrapper still points at, or nothing; a collection cleared, re-read or loaded
  whole holds its rows and its completeness flags again;
- **rows read inside the transaction are forgotten** — they may carry what the
  transaction wrote past the doors, so the next read takes them from the table;
- **the runtime** — a state added, removed or wiped is back where it was, and a
  state's own edit (`sync()`, an edit by diff) goes back through `applyDiff()`
  with its sync baseline. A state that does not override `applyDiff()` is not
  rolled back, exactly as it takes no foreign edit;
- **the mirrors** hear what was put back — created, deleted, the update undone —
  and repair themselves by the same facts they repair on at a write. The
  reactions and the other processes hear nothing: they never heard the change.

The moment is the one the rows go back at: a rollback, a commit that fails, a
connection closed under the transaction, a handler that left it open. Code
running between the failure and its catch already reads memory that matches the
table, and the caller's rollback of a failed level only closes it. A step that
fails stops neither the other steps nor the SQL — memory half put back is worse
than any error; the SQL's failure is raised if there is one, otherwise the first
failed step's (`MemoryRollbackFailedException` around anything outside the Hilos
tree), and the rest are logged with the connection and the level.

**Write through the doors.** A write past them — raw SQL, `Entity::save()` with
no object, an RT state field changed without `sync()` — changed no memory
through a door, and the rollback does not see it. A row read after such a write
inside the transaction is forgotten all the same.

## A connection lost outside a transaction

The next statement reopens a lost connection, even after earlier reconnect attempts
failed. Inside a statement, reconnect waits out temporary connection failures for
the policy's window; a final failure reaches the caller immediately. A link closed
by hand with `Database::close()` stays closed until `Database::connect()` is called.
The new session receives the same charset and collation setup as the first one.

After reconnect, the statement is sent again, as before. A write that sets values
or deletes by key is safe to repeat; refusing it could leave this process's memory
apart from the table. A write that adds a database-numbered row or adds to a value
may stand twice if its first send reached the server. Each resend of a statement
other than `SELECT` or `SHOW` leaves a warning with the query template, never its
parameter values. Inside a transaction, the rule below applies instead: the lost
link is not reopened after `BEGIN`.

## The edges

- **A commit with no open transaction** is refused with
  `TransactionNotOpenException`: the caller believes its writes are saved, and
  they either went out one by one or vanished with a closed connection.
- **A rollback with no open transaction** does nothing, like `ROLLBACK` in
  MySQL. After a commit whose released announcement failed, the caller's catch
  rolls back a transaction that already stands, and loses nothing by it.
- **A commit that fails** — `COMMIT` or `RELEASE SAVEPOINT` refused — rolls its
  own level back, puts its memory back, drops what the level held and leaves the
  level standing as *failed*. The caller's rollback closes it without SQL; a
  second commit of it is refused. The level is not removed early on purpose: in
  a nested chain the caller's rollback would otherwise reach the parent.
- **A released announcement that fails** reaches the caller of the commit,
  after every other held announcement was made: the commit already stands, and
  a swallowed failure here would be a sync that vanished without a trace.
- **No reconnect inside a transaction.** A query on a connection that lost its
  link is not retried on a new one once the transaction has sent its `BEGIN`:
  the new session would know nothing of the transaction, the remaining writes
  would autocommit, and the commit would release announcements of rows the
  broken transaction never wrote. The query fails to the caller, whose catch
  rolls back. Closing the connection by hand under such a transaction leaves the
  level failed the same way, with its memory already put back.

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

```php
// Wrong: memory put back by hand after the rollback. The rollback already put it
// back, and more exactly - the rows that were there, not an empty collection.
} catch (HilosException $failure) {
    Database::transactionRollback();
    $this->objectCollection->clearInMemory();
    throw $failure;
}
```
