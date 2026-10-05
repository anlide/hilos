# Anti-pattern: Writing RT State Outside Its Actions

Write a mounted runtime collection through its actions. Direct membership
writes bypass the check that the truth source owns the operation and the row's
set, even though `add()` and `remove()` still announce the change.

## What breaks

`RtStates::add()` and `remove()` on a mounted collection publish through
`SourceChangeBus`; `offsetSet()` and `offsetUnset()` call them too. The local
view cache follows the announcement, and `OutboundRtSyncSubscriber` queues
`RT_SYNC_CREATED` / `RT_SYNC_DELETED` for a `LocalWrite`. Delivery does not make
the direct write a permitted one:

- **The owner is not checked.** The base actions check the operation and the
  row's set before writing. A caller that skips them can mutate the store
  without that permission, violating ownership across the cluster.
- **A direct `clear()` stays in one worker.** It announces nothing and leaves
  other copies and cached view wrappers unchanged. `clearAllStates()` checks
  removal permission, queues a deletion per row and clears the view cache.
- **An `add()` over a taken id leaves copies holding the old row.** Follow
  [rt-state.md, Replacing a row](../runtime/rt-state.md#replacing-a-row).

The `RT-STATE-MUTATE` guard refuses direct membership writes outside the base
actions and the sync/snapshot writers named in
[rt-state.md](../runtime/rt-state.md).

## Two mistakes

1. **A state collection registered without a representation.** `_stateCollections`
   alone gives the page no view to read and the agent no actions to write through.
   The collection still gets its name when mounted, so its `add()` and `remove()`
   can announce changes; the direct path bypasses ownership and the guard refuses
   it. The registration is a half-activation:

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

2. **An owning agent that writes to the state collection because it can.** A
   truth-source claim covers specific operations and rows: an agent allowed to
   add and remove may not edit, and an owner of one set may not write another.
   The actions check those permissions; reaching the state object skips them:

   ```php
   // Wrong: the truth source still has to go through the actions.
   $states = Hilos::$rt->getStateCollection(Foo::RT_COLLECTION);
   $states->clear();
   foreach ($scanned as $row) {
       $states->add(Foo::fromRow($row));
   }
   ```

   ```php
   // Right: actions check ownership and send only real changes.
   Hilos::$rt->fooRows->actions->syncToScan($scanned);
   ```

## Rebuilds are diffs

When an index is re-derived from an external truth (a directory scan, a remote
listing), do not clear through actions and re-add: that is a delete + create for
every row, sent to every subscribed browser, on every refresh. Compare the
incoming set against the current rows and emit only what actually changed — new
rows created, missing rows deleted, changed rows updated.

## How to spot it

- `getStateCollection(...)` followed by `->add(`, `->remove(`, or `->clear(`
  outside the base actions or the sync/snapshot writers.
- A write or `unset()` on a backing collection key outside those same writers.
- A `_stateCollections[...] = ...` line with no matching `setRepresent(...)`.
- `add()` of a row whose id may already exist, without removing the old row first.

## Related

- [../runtime/rt-context.md](../runtime/rt-context.md) — the representation, the
  actions layer, and the sync mechanism.
- [../agent-system/monopolistic-agent.md](../agent-system/monopolistic-agent.md) —
  single-writer ownership, which this anti-pattern is often mistaken for.
