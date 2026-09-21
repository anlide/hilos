# Process Fork

Read this before calling `pcntl_fork()` or `pcntl_rfork()` in backend PHP, and
whenever the `PROCESS-FORK` guard fails on a call you added.

## Core Rule

These two PHP builtins are not called anywhere in Hilos:

| Function | What it does |
|---|---|
| `pcntl_fork()` | forks the running process into parent and child |
| `pcntl_rfork()` | forks the running process with resource sharing options |

Both builtins create a child process that inherits the parent's memory space and
open file descriptors.

## Why

A fork creates a child process that inherits everything this process holds — the
live PHPUnit run, the daemon sockets, the database connection, and event loops.

In a test suite, an unhandled child runs the remaining tests concurrently or hangs
the run. A fork in `AsyncHttpClientTest.php` (HIL-732, resolved in HIL-929)
inherited the live PHPUnit run and caused persistent flakes; P-207 documents the
orphan deadline of 10 seconds and 50 ms sleep per run when children linger.

In a daemon, an inherited socket or database connection corrupts the state of both
processes because both read and write to the same connection without synchronization.

The cure is to start a separate, isolated process with its own clean address space
through `Hilos\Core\Process`.

## What this is not about

`pcntl_exec()` is **not** in the family and is not judged by this rule. Replacing
the process image inherits nothing except open file descriptors (see the legitimate
sample in `framework/tests/Unit/AiToolingInstallerTest.php:215`, confirmed excluded
by the owner on 20.09.2026).

`proc_open()` and `Hilos\Core\Process` are **not** judged: they are the canonical
way to start an isolated process (see `framework/backend/Core/Process.php:126` and
`framework/backend/Core/Daemon/BaseManager.php:35` `PROCESS_FUNCTIONS`).

Signal handling and process waiting primitives (`pcntl_waitpid()`, `pcntl_signal()`,
`pcntl_signal_dispatch()`, `pcntl_async_signals()`) are likewise not in the family.

## Blind spots

A green run means "no forks written head-on", because the guard reads only tokens
in call position. The rule is entitled to be narrower than its document, but not
wider (see [automated-checks.md](automated-checks.md)):

- Invocation through a variable: `$f = 'pcntl_fork'; $f();`
- Invocation through `call_user_func('pcntl_fork')`
- Invocation through an alias: `use function pcntl_fork as spawn; spawn();`

## Adding an exception

The rule carries a list of files allowed to fork the process, and it is **empty**
today: there is not one fork call in the tree. That is the state to keep.

If a call genuinely has to happen, add the repository-relative file path to
`ALLOWED_FORKS` in `framework/tests/CodeStyle/Rule/ProcessForkRule.php` with a
value of `'HIL-<n> — <reason>'`.

There is no baseline record and no in-comment marker: the rule does not read them.
The format of the entry is checked mechanically by `ProcessForkAllowListTest`, and
the substance is judged by the owner on the named leaf ticket.

## The form of an allowed fork

If an exception is granted, the implementation must follow the required form:
- An immediate `exit()` at the end of the child's branch;
- Not one assertion on the child's path — a red assertion carries the child to the
  end of the run and prints a second PHPUnit report;
- The wait in `finally`, through `pcntl_waitpid()`.

See [testing.md](../testing.md).

## Scope

Every scanned root, tests included. A fork in a test suite hangs or corrupts the
test run just as a fork in a daemon hangs or corrupts the node, which is why this
id is absent from the production-only list in `RootKind`.

Checked automatically: `PROCESS-FORK`, see [automated-checks.md](automated-checks.md).
