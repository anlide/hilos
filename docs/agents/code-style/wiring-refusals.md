# Wiring refusals

Read this when you are about to write a `catch` around a read of `Hilos::$db` or
`Hilos::$rt`, when the `WIRING-REFUSAL-SWALLOWED` guard fails on your change, or
when you are deciding what an accessor should answer to a collection it cannot
read.

## What a refusal is

A worker reads a collection only if something in that process declared it reads it
— an agent's `READS_DB` / `READS_RT`, a page's browser sources or `READS_DB`, a
framework seam's process-wide read. When nothing did, the read guard in
`DbContext::__get()` and `RtContext::__get()` refuses:
`DbCollectionNotReadableException` and `RtCollectionNotReadableException`.

The refusal is not a failure of the request. The rows are there, and the database
would hand them over — that is exactly why the guard exists. A process the master
does not address is a process no later write reaches, so the copy it caches is
right once and then silently wrong, with nothing in the answer to say from when.
The refusal turns that into a wiring error at the first read instead of a stale row
at some read after it.

Two properties follow, and both matter to whoever catches it:

- **No retry cures it.** The wiring is missing, or the master's confirmation has
  not landed; neither is something the caller can wait out inside one request.
- **It is not about the caller.** A refusal reaching a permission check says
  nothing about the permission, and a refusal reaching a lookup says nothing about
  the row.

## The species marker

Both exceptions carry `Hilos\WiringRefusal`, an empty interface that says what the
failure **is**, the same way `MalformedInput` says an input never parsed. They live
in two exception families — the database one under `HilosException`, the runtime
one under `RtBaseException` — and no base class could reach both. The marker gives
them one name, and one name is what a `catch` and a rule can both say.

The marker forbids nothing. `catch (Throwable)` catches a marked exception like any
other, and PHP offers no way to stop that. What stands in the way is the guard.

## The rule

`WIRING-REFUSAL-SWALLOWED` reports a `try` whose body reads `Hilos::$db->…` or
`Hilos::$rt->…` and whose `catch` takes `Throwable`, `Exception` or
`HilosException` — the three that stand above both species. Production roots only:
`framework/backend`, `scripts`, `demo/*/backend`.

What counts as a read is the collection named after the arrow —
`Hilos::$db->users`, `Hilos::$rt?->connections`, the dynamic `Hilos::$db?->{$key}`
— because that is what reaches `__get()`, where the guard stands, and one method
of the context beside them: `Hilos::$db->getObjectCollection()` is the
application's entrance to the object layer and passes the same guard (HIL-900).
Every other method of the context is not judged
(`Hilos::$db->reHydrateDbBackedCollections()`, and
`Hilos::$db->mountedObjectCollection()`, which is the layer's own entrance and
deliberately unjudged): they never pass the guard and can raise nothing a
narrowing would catch.

A narrower family that happens to contain a refusal — `RtBaseException` over a
runtime read — is not judged either. The rule pins the reflex of catching
everything, not every route a refusal could take.

## The three ways out

**Narrow the catch** to the species you actually expect there. This is the usual
answer, and it is usually one word:

```php
try {
    return [...Hilos::$db->users];
} catch (CollectionNotFoundException) {
    // The project has not mounted it yet; it will.
    return [];
}
```

**Name the refusal in a clause of its own above the broad catch**, when the broad
catch is doing something you want to keep:

```php
try {
    $this->publish(Hilos::$rt->clusterNodes);
} catch (WiringRefusal $refusal) {
    throw $refusal;
} catch (Throwable $e) {
    Logger::error('Cluster nodes could not be published: ' . $e->getMessage());
}
```

The order is the point: a `catch (WiringRefusal)` written *after* the broad clause
never runs, and the rule reports it as if it were not there.

Rethrowing is the usual body of that clause, not the required one. A caller that
cannot raise — an action reply with a modal waiting on it, a probe whose job is to
report what failed — answers there instead, and the guard is satisfied by the
clause: what it asks is that the refusal be told apart from an ordinary failure,
not that it always travel.

**Mark the catch**, when swallowing here is the honest answer, on the line directly
above it:

```php
try {
    return Hilos::$rt->labels !== null;
// read-refusal-swallowed: this IS the mount check, and a refusal is its answer of no
} catch (Throwable) {
    return false;
}
```

An empty reason is a violation of its own, exactly as it is for `@`
([error-suppression.md](error-suppression.md)). The marker is for the case where
the caller's own job is to find out whether the wiring is there; it is not a way to
record that the question was noticed.

## Why the guard rather than a language feature

The incident this came from: three demos wrote their admin check as

```php
try {
    return (Hilos::$db->users[$userId] ?? null)?->admin === true;
} catch (Throwable) {
    return false;
}
```

and a refusal came out of it as a verdict — `PageForbiddenException('Access
forbidden')` to an administrator who had the right, and not a line in the worker's
journal. Diagnosis cost two full runs.

Nothing about the exception could have prevented that. Any species, marked or not,
is caught by `catch (Throwable)`, and a project is entitled to write one over its
own read. So the cure is the guard plus the line the refusal now writes itself
before it is raised: the first makes the reflex visible while the code is being
written, the second makes it visible after it has been.

## Related

- [exceptions.md](exceptions.md) — the exception families, and the `MalformedInput`
  marker this one follows.
- [automated-checks.md](automated-checks.md) — the rule's row and the baseline.
- [rt-state.md](../runtime/rt-state.md) — what a runtime collection is and who
  declares a read of it.
