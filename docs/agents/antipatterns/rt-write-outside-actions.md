# Anti-pattern: Writing RT State Outside Its Actions

A runtime collection changed through its `RtStates` state object changes in one
worker and in no other. The write silently does nothing for everyone else.

## What breaks

`RtStates::add()`, `remove()`, and `clear()` are plain array operations. They
queue no `RT_SYNC_*` signal, because the sync signals are emitted by the actions
layer — `RtActions::addStateToCollection()`, `removeStateFromCollection()`,
`clearAllStates()`, item `remove()`, and `RtState::sync()`.

Hilos runs one agent per monopolistic worker, and a browser is served by
whichever worker owns its connection. So the two ends of a feature normally sit
in **different processes**:

```
BackupAgent (worker #10)          browser page (worker #4)
  $states->add($row)   ──✗──>       collection still empty
```

The symptom is not an error. It is a page that shows nothing while the writing
agent's log insists the data is there — and a reload does not fix it, because the
reload lands on a worker whose collection was never populated either. Every
verification that runs *inside* the writing worker (a unit test, a CLI command, a
log line) passes.

## Two mistakes, one symptom

1. **A state collection registered without a representation.** `_stateCollections`
   alone gives the collection no actions class, so the only reachable write path
   is the state object — the wrong one. The registration is a half-activation:

   ```php
   // Wrong: half-activated. Nothing can write to this collection correctly.
   $this->_stateCollections[Foo::RT_COLLECTION] = FooStates::init();
   ```

   ```php
   // Right: the view and its actions are what make the collection writable.
   $this->_stateCollections[Foo::RT_COLLECTION] = FooStates::init();
   $this->setRepresent(
       Foo::RT_COLLECTION,
       FooRows::class,
       FooRowsActions::class,
       FooRowActions::class,
   );
   ```

2. **An owning agent that writes to the state collection because it can.** Being
   the truth source is permission to write, not a delivery mechanism:

   ```php
   // Wrong: the truth source still has to go through the actions.
   $states = Hilos::$rt->getStateCollection(Foo::RT_COLLECTION);
   $states->clear();
   foreach ($scanned as $row) {
       $states->add(Foo::fromRow($row));
   }
   ```

   ```php
   // Right: one signal per real change, so other workers and tables follow.
   Hilos::$rt->fooRows->actions->syncToScan($scanned);
   ```

## Rebuilds are diffs

When an index is re-derived from an external truth (a directory scan, a remote
listing), do not clear and re-add: that is a delete + create for every row, sent
to every subscribed browser, on every refresh. Compare the incoming set against
the current rows and emit only what actually changed — new rows created, missing
rows deleted, changed rows updated.

## How to spot it

- `getStateCollection(...)` followed by `->add(`, `->remove(`, or `->clear(`
  anywhere outside `Runtime/`.
- A `_stateCollections[...] = ...` line with no matching `setRepresent(...)`.
- A feature whose data appears in the agent log but never in the browser.
- A "live" table that only ever shows what was there when the worker booted.

## Related

- [../runtime/rt-context.md](../runtime/rt-context.md) — the representation, the
  actions layer, and the sync mechanism.
- [../agent-system/monopolistic-agent.md](../agent-system/monopolistic-agent.md) —
  single-writer ownership, which this anti-pattern is often mistaken for.
