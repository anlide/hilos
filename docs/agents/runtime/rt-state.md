# Runtime: RtState

`RtState` is the base class for individual runtime state items (rows in an RT collection).
Its `toArray()` method is a runtime sync row serializer, not a browser payload
contract. Browser-facing runtime state belongs in typed frontend projections or
signal DTOs.

## Structure

```php
final class MyState extends RtState {
    // Declare field name constants
    public const string userId = 'userId';
    public const string status = 'status';

    // Real typed properties; prefer them over magic-only @property docs.
    public private(set) int $userId = 0;
    public string $status = '';

    // Factory methods
    public static function create(int $userId): static { ... }
    public static function fromRow(array $row): static { ... }

    // Required: collection key this state belongs to
    public static function getRtCollectionKey(): string {
        return RtMyContext::myCollection;
    }

    // Required: unique id within collection
    public function getId(): string { return (string)$this->userId; }

    // Required: serialize runtime row for sync (not a browser payload)
    public function toArray(): array { ... }

    // Required: apply partial update
    public function applyDiff(array $diff): void { ... }

    // Do not add __get()/__set() for declared row fields.
}
```

## Writing to state

State fields must be real typed properties so PhpStorm and static analysis can
resolve code like `$this->stateCollection[$id]->status`. Prefer plain public
typed properties for mutable RT fields. Use PHP 8.4 asymmetric visibility for
immutable ids:

```php
public private(set) int $userId = 0;
public string $moderationMessage = '';
public int $moderationUpdatedAt = 0;
```

Use PHP 8.4 property hooks only when a field needs normalization or invariant
logic:

```php
private string $moderationMessageValue = '';

public string $moderationMessage {
    get => $this->moderationMessageValue;
    set => $this->moderationMessageValue = (string)$value;
}
```

Do not mirror declared RT fields through `@property`, `__get()`, or `__set()`.
Those hide the actual row shape from the IDE and make action code depend on
runtime magic. Keep magic access only as a framework fallback for unknown
dynamic access.

Inside `Runtime/` item actions, write typed state fields and call `sync()`:

```php
$this->state->status = $newStatus;
$this->sync();        // broadcasts RT_SYNC_UPDATED to all workers
```

Or use an item **Actions** class when the caller has the collection key:

```php
Hilos::$rt->connections[$acceptKey]?->actions->setUserId($newUserId);
```

Custom RT actions must mutate typed state fields and call `sync()`. Do not call
`applyDiffToState()` or expose arbitrary `applyDiff*()` action methods from
application code; `applyDiff()` is for inbound RT synchronization after another
worker already made the write.

Collection actions may create rows or operate on the whole collection. Do not
add a collection action that accepts a runtime row id to update or delete that
one row. Load the item by key and put the write on that item's actions.

Collection actions expose `$this->stateCollection` as a typed magic property for
create, ensure, clear, and bulk logic that legitimately owns the collection.
Repeat the concrete `@property-read` annotation on every collection actions
class; the base generic documents the contract, but PhpStorm often needs the
local annotation:

```php
/**
 * @extends RtActions<ViewChatUserState, UserStates, StateUserStates>
 * @property-read StateUserStates $stateCollection
 */
final class UserStatesActions extends RtActions {}

$this->ensureCanWrite();
$this->stateCollection[$userId]->moderationMessage = '';
$this->stateCollection[$userId]->sync();
$this->clearCollectionCache();
```

Concrete `RtStates` subclasses should also narrow `get()` and `offsetGet()`:
`get()` returns `?StateFoo` and accepts nullable IDs for optional lookups, while
`offsetGet()` returns `StateFoo` and throws when the row is missing. This keeps
`$collection[$id]` usable for required rows and lets PhpStorm resolve declared
state properties without falling back to `?RtState`. Never cast a nullable
state key to string before deciding whether it is absent; `null` must not
address the empty-string state key.

## Reading from state

Application code normally reads through the view item or collection:

```php
if (
    isset(Hilos::$rt->userStates[$userId])
    && Hilos::$rt->userStates[$userId]->lastOutboundSubmittedAt > 0.0
) {
    // ...
}
```

Direct backing-state reads (`getStateCollection()`, `$this->stateCollection`,
or `RtContext::getStateCollection()`) are allowed only in files under
`Database/` or `Runtime/`. Agents, pages, tables, signal handlers, tests, and
other application orchestration code must use caller-facing collection/item
APIs. If an approved reusable lookup is missing, add it to the owning
`Runtime/` or `Database/` layer first; during transparent data-shape refactors,
prefer explicit field access unless the new method was approved by name.

Concrete `Runtime/View/Item/*` classes must rely on their
`@extends RtItem<StateFoo>` template when reading declared state fields. Read
state fields directly through `$this->_state->fieldName`; do not introduce a
local `/** @var StateFoo $state */ $state = $this->_state;` alias only to
recover the type.

## Lifecycle

- Created: collection actions such as `create(...)`, `register(...)`, or `ensure(...)`
- Updated: item actions when the item key is known, or direct field set + `sync()` inside RT internals
- Deleted: item actions when one item key is known; collection actions only for clear/bulk cleanup
- On worker sync: `applyDiff()` called with changed fields only

## markRtSyncBaseline()

Call at end of `create()`/`fromRow()` to snapshot current state.
`sync()` diffs against this baseline to detect what changed.
