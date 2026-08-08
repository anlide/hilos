# Error Suppression

Read this before writing `@` in front of a PHP call, before touching a call that
already carries it, or when the `ERROR-SUPPRESSION` guard fails on your change.

## Core Rule

Do not use `@` as a warning silencer. A failing builtin becomes a typed
exception, an explicit check, or a documented degrade — never a value that
silently turns into `false`.

Suppression survives only where an explicit check of the error does the work the
warning would have done, and such a place **must** carry a marker on the line
directly above the call:

```php
// warning-suppressed: <what is checked instead of the warning>
$moved = @rename($tmp, $target);
```

The marker is what keeps the distinction visible: "this may legitimately fail"
stays written down instead of hiding behind a silent operator.

The scope of this rule is production code: `framework/backend` and
`demo/*/backend`. Test code is out, the same boundary
[reflection.md](reflection.md) draws.

**Checked automatically: `ERROR-SUPPRESSION`.**

## Do not

- `@` with no marker above it.
- A marker with an empty reason (`// warning-suppressed:` and nothing else).
- `error_get_last()` after a suppressed call. Reading the error back out of the
  engine is the silent degrade this rule removes, not a way around it.
- Suppressing where the result is not examined at all a few lines later.

## Workflow

1. Ask what the failure means here. If the caller cannot continue without the
   value, it is an exception — go to class C below and call the seam.
2. If the library can report the failure itself, turn its exception mode on
   instead of muting it (class A). A driver that throws needs no `@`.
3. If the failure is normal traffic rather than an error — a non-blocking socket
   with nothing to read — keep the suppression, add the marker, and check the
   error code in the same few lines (class B).
4. If the failure is a deliberate degrade, keep the suppression, add the marker,
   and state the outcome the code falls back to (class D).
5. Run `composer run test:framework:unit`. A new unmarked suppression fails it.

## The four classes and the required shape

**A. A library that can throw — mysqli.** The `@` disappears completely. Set
`MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT` and let mysqli raise
`mysqli_sql_exception`; the mapper turns it into the same typed `Sql*` exception
from a `catch`. The general form: *when a library can report an error as an
exception, switch its mode on rather than muting its warning*.

One call keeps its suppression under a marker: `mysqli_connect` in
`Hilos\Database\Database::connect()`. mysqli reports an unreachable host twice —
as a PHP warning and as the exception — and the warning is the half that does
damage: `BaseManager::errorHandler` logs it and calls `onError()`, which sets
`shouldExit` on a daemon, ending the process that the surrounding retry loop
exists to carry through a blip. The exception carries the same failure, so
nothing is lost by muting the warning. This is the exception that proves the
class: a library's own exception mode replaces `@` only where the library does
not ALSO raise a warning the process treats as fatal.

**B. Non-blocking sockets and streams.** Marker plus an immediate check of the
result and the error code in the same few lines. Do not throw: `EAGAIN` is the
normal outcome of an event-loop tick, and no pre-check exists for it.

```php
// warning-suppressed: EAGAIN is normal here, the error code is read below
$written = @socket_write($socket, $payload);
if ($written === false && socket_last_error($socket) !== SOCKET_EAGAIN) {
    throw new SocketWriteException(...);
}
```

**C. File primitives that owe an exception.** `@` lives only inside
`Hilos\Fs\FsPath`, the context-free primitive layer, and only where the very next
line turns `false` into an `Fs/Exception/*`. Callers use the layer and catch the
exception; they do not call the primitive. The object seam above it
(`Fs/{FsFile,FsDirectory,FsTmpFile,FsTmpDirectory}`, addressed by registered
directory name) calls the same layer, and a subsystem with paths of its own —
Backup, for one — calls it directly and converts `FsException` into its own
taxonomy at its boundary.

**D. Deliberate degrade and teardown.** Marker plus the documented outcome —
`null`, a log line, or a no-op. `/proc` on a non-Linux host, unlinking a temp
file while tearing down, a sidecar's `filesize()`.

```php
// warning-suppressed: /proc is absent off Linux, the caller reads null as "unknown"
$stat = @file_get_contents('/proc/stat');
if ($stat === false) {
    return null;
}
```

## Anti-Patterns

```php
// Wrong: the failure becomes false and travels on as if it were data.
$contents = @file_get_contents($path);

// Wrong: the error is fished back out of the engine after being muted.
$handle = @fopen($path, 'rb');
if ($handle === false) {
    throw new FileReadException(error_get_last()['message'] ?? '');
}

// Right: the seam throws, and the caller says what it does about it.
$contents = (new FsFile($directory, $filename))->read();
```

## Validation

`composer run test:framework:unit` runs the `ERROR-SUPPRESSION` guard over
`framework/backend` and `demo/*/backend`. A hit reads
`ERROR-SUPPRESSION <path>:<line> — <what is wrong> (see <doc>)`. Existing debt is
recorded in `framework/tests/CodeStyle/baseline.txt`, one record per file with
the leaf that owes its removal; the baseline only shrinks. See
[automated-checks.md](automated-checks.md).

A marker is not free either: the `post-commit` hook that watches the base branch
tells the owner when a merged delta legalized a new suppression. The guard fails
on a bare `@`; the notification shows what was let through with a marker.
