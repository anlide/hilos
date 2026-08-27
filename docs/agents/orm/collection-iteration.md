# ORM: Collection Iteration

Read this before walking a Hilos collection with `foreach`, and before writing
code that adds or removes rows while a walk is in progress.

The rule covers the seven mutable collections of the framework — the two view
collections (`RtCollection`, `DbCollection`), the three stores behind them
(`RtStates`, `Objects`, `FilteredCollection`), and the two light containers
(`ObjectCollection`, `EntityCollection`). Every one of them is an
`IteratorAggregate` whose `getIterator()` hands back a fresh generator.

## Core Rule

A walk observes the keys the collection held when the walk started. Mutating
the collection from inside the walk is allowed, and what the walk does about it
is fixed:

- a row **removed** after the walk started is **skipped** — the walk never hands
  out a null row, and it never loses a row that is still there;
- a row **added** after the walk started is **not seen** by that walk;
- a **nested** `foreach` over the same collection is safe — each walk owns its
  own generator, so the inner one cannot move the outer one.

There is no cursor on the collection object, so there is nothing to park and
nothing to restore.

## Workflow

1. Walk the collection directly. Do not collect the keys into an array first in
   order to mutate afterwards; that crutch was needed while the walk was a
   numeric index, and it no longer buys anything.
2. Mutate through the road that owns membership — collection actions for a
   runtime collection, the object collection for a database view — exactly as
   you would outside a walk.
3. When the walk must see the rows a mutation produced, walk again after it.
   One walk will not grow.
4. Keep a second pass only when the *order of operations* demands it — for
   example when a durable write has to land between reading the rows and acting
   on them. Say so in a comment, so the pass is not read as the old crutch.

## Preferred Shape

Remove while walking:

```php
foreach ($this->stateCollection as $state) {
    if ($state->isLiveAt($nowMs)) {
        continue;
    }
    $this->removeStateFromCollection($state->getId());
}
```

Walk one collection inside a walk over the same collection:

```php
foreach ($collection as $outerKey => $outer) {
    foreach ($collection as $innerKey => $inner) {
        // The outer walk resumes exactly where it stood.
    }
}
```

Inside the framework, ask the store for its keys instead of building every
wrapper to read them:

```php
$keys = $objectCollection->keys();
```

## Wrapper Binding

A view wrapper holds the row it was built from. It does not hold the variable
that row arrived in.

The distinction only shows up in a walk, which is why it lives here: `foreach`
reuses one variable for the whole walk, so a wrapper bound to that variable
follows it. Build three wrappers inside one walk and all three end up showing
the row that came last — no error, no warning, just the wrong row on the way to
the browser.

Two shapes are therefore refused, both by the `VIEW-WRAPPER-BIND` guard:

```php
// Wrong: the wrapper is bound to the caller's variable.
$this->_object = &$object;

// Wrong: the signature forces the caller to produce that variable.
protected function createDbItem(Object_ &$object): DbItem
```

Write both without the ampersand:

```php
$this->_object = $object;

protected function createDbItem(Object_ $object): DbItem
```

Nothing is copied by doing so. PHP passes an object by handle either way, so the
wrapper looks at the very same instance it always did, `getObject()` and
`getState()` hand back that instance, and writes through the row's actions land
where they landed before. The single thing that changes is that the wrapper stops
following a variable.

The base wrappers now declare their backing field `readonly`, so a binding by
reference into `DbItem::$_object` or `RtItem::$_state` is refused by the language
itself — `Error: Cannot indirectly modify readonly property`. The guard covers the
fields readonly cannot reach, the nullable collection ones set through a setter,
and any new wrapper hierarchy that has no such field yet.

If you find a walk that guards against this by hand — dropping the binding with
`unset($row)` before the next iteration, or lifting one loop body into a method
so each call gets a local of its own — that guard is stale. Remove it rather than
copying it: a patch outliving its cause reads as a pattern.

## Anti-Patterns

Do not collect first to survive the walk:

```php
// Wrong: the walk survives a removal on its own.
$expired = [];
foreach ($this->stateCollection as $state) {
    $expired[] = $state->getId();
}
foreach ($expired as $id) {
    $this->removeStateFromCollection($id);
}
```

Do not materialize a collection only to read its keys:

```php
// Wrong: builds every wrapper to throw them away.
$keys = array_keys(iterator_to_array($objectCollection));
```

Do not expect a walk to see what you just added:

```php
// Wrong: 'c' is not in this walk, whatever the order of the ifs.
foreach ($collection as $key => $item) {
    if ($key === 'a') {
        $collection->actions->put('c');
    }
}
```

## Exceptions

`ResultSet`, `ResultSetCollection`, and `SqlParamCollection` are plain
`Iterator`s. They are read-once cursors over a fetched result or a parameter
list, nothing mutates them mid-walk, and the contract above does not apply to
them.

## Related

- `docs/agents/orm/db-collection.md` — the layers a database walk crosses.
- `docs/agents/runtime/rt-state.md` — who may change runtime membership at all.
- `docs/agents/orm/accessor-contracts.md` — reading one row rather than all.
