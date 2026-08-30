# Runtime: RtState

`RtState` is the base class for individual runtime state items (rows in an RT collection).
Its `toArray()` method is a runtime sync row serializer, not a browser payload
contract. Browser-facing runtime state belongs in BrowserContext rows, typed
frontend state payloads, or signal DTOs.

## Structure

```php
final class MyState extends RtState {
    // Declare field name constants
    public const string userId = 'userId';
    public const string status = 'status';

    // Real typed properties; prefer them over magic-only @property docs.
    private(set) int $userId = 0;
    public string $status = '';

    // Factory methods
    public static function create(int $userId): static { ... }
    public static function fromRow(array $row): static { ... }

    // Required: collection key this state belongs to
    /** @return string Runtime collection key */
    public static function getRtCollectionKey(): string {
        return RtMyContext::myCollection;
    }

    // Required: unique id within collection
    /** @return string Runtime row id */
    public function getId(): string { return (string)$this->userId; }

    // Required: serialize runtime row for sync (not a browser payload)
    public function toArray(): array { ... }

    // Required: apply partial update
    public function applyDiff(array $diff): void { ... }

    // Do not add __get()/__set() for declared row fields.
}
```

## Inheriting a framework row: stages and the composition template

Some runtime rows are declared by the framework and filled in by the project.
Connections are the one such row today (`Hilos\Runtime\State\Item\HilosConnection`
and the stage above it, `HilosSessionConnection`). Two rules govern them, and a
future inheritable row is expected to follow both.

**A project connects by inheritance, and declines a field by stage.** The
framework finds these collections by type, not by name — `RtContext::presenceSource()`
looks for the presence interface, `connectionsSource()` and
`sessionConnectionsSource()` for the state base — so extending the base *is* the
declaration "these are Hilos connections". PHP gives a subclass no way to drop a
property its parent declares, so "I do not carry that field" cannot be said by
removing it. It is said by standing on a lower stage:

| Stage | Row carries | Seams it unlocks |
|---|---|---|
| `HilosConnection` | `acceptKey`, `?int userId` | `findAuthenticated()`, `findByUser()`, presence in the users table |
| `HilosSessionConnection` | the above plus `?string sessionToken` | `findAllBySessionToken()`, the sessions library's read of a session's sockets, the session carry-over |

A new framework seam names the **minimum** stage it needs, never the fullest. A
project that has not reached that stage then lacks the *method*, so the gap is a
type error at the seam rather than an honest-looking empty result at runtime.
The stages are mirrored on all four layers — State item, State collection, View
item, View collection — so a project stands on one stage everywhere.

**The base half of the row cannot be skipped.** `fromRow()`, `toArray()` and
`applyDiff()` are `final` on the base and run the base half themselves, including
`markRtSyncBaseline()`; the project half is four abstract hooks:

```php
final class Connection extends HilosSessionConnection {
    protected function initOwn(): void { $this->connectedAt = time(); }
    protected function hydrateOwn(array $row): void { ... }
    protected function ownToArray(): array { return [ ... ]; }
    protected function applyOwnDiff(array $diff): void { ... }
}
```

The base runs first and the hook second, in all four. That order does not protect
the base keys — `toArray()` merges the project half over the base one, so a
project field named like a base field takes the key and hides it. Name project
fields around the base; never restate one. Between stages the framework chains its
own halves through
`parent::` (`initBase`/`hydrateBase`/`baseToArray`/`applyBaseDiff`) — those are a
detail of the base, not an API a project calls.

`create()` is the single exception to `final`: the session stage widens its
signature with the token, and a `final` method cannot be overridden. It is
`final` on the last stage, so a project never overrides the factory.

**A project that serves pages must stand on the base.** Non-empty `PAGES` means
browsers subscribe, which means there are connections; the deferred activation
check refuses a runtime context that answers `connectionsSource()` with null.
A headless project (`PAGES = []`, demo cluster) is asked for none.

## Writing to state

State fields must be real typed properties so PhpStorm and static analysis can
resolve code like `$this->stateCollection[$id]->status`. Prefer plain public
typed properties for mutable RT fields. Use PHP 8.4 asymmetric visibility for
immutable ids:

```php
private(set) int $userId = 0;
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

Which rows a collection holds changes only through the base `RtActions` methods
— `addStateToCollection()`, `removeStateFromCollection()`, `clearAllStates()`,
and the item's `remove()`. They call `add()`, `remove()` or `clear()` on the
backing collection, which announces the new membership itself, so the view cache
and the outgoing RT sync both follow from one place. A caller that reaches past
them — `getStateCollection()->remove($id)`, `unset($stateCollection[$id])`,
`$stateCollection[$id] = $state` — writes the store and announces nothing, and
every dependent view goes on showing the membership it already had.

Four files change membership directly, and the list is closed:
`Runtime/View/Actions/Collection/RtActions.php` and
`Runtime/View/Actions/Item/RtActions.php` are the base methods themselves, while
`Runtime/RtSyncApplicator.php` and `Runtime/RtSnapshot.php` apply a change this
process did not decide — one that arrived from another worker, or from a
snapshot handed over at startup — and announce nothing on purpose, because
rebroadcasting it would send it back where it came from. The row array
`$this->states` belongs to `RtStates` alone: a concrete collection narrows a
lookup by reading it, and writing it is what `add()`, `remove()` and `clear()`
are for.

A detached copy is outside all of this. `HilosConnections::forUser()` builds one
with `$stateCollection::init()` and fills it row by row, which is legal: the
copy holds the same rows, is mounted under no collection name, and is therefore
a read surface nobody subscribes to rather than a second write path into the
same rows.

Checked automatically: `RT-STATE-MUTATE`, see
[automated-checks.md](../code-style/automated-checks.md).

## Reading a row: required, optional, and a patch

A runtime row was written by `toArray()` on another node, so reading it back asks
one question per key, and there are exactly three answers a field can have. Never
a fourth: a default minted in place of a value that did not arrive hands every
reader below it a value nobody sent. `RtState` carries all three families as
`final protected static` readers, so the state declares which contract a field
has by which reader it calls.

| Family | Key absent | Key holds `null` | Key holds another type | What the field is |
|---|---|---|---|---|
| `require*` | refuses the row | refuses the row | refuses the row | the state cannot be built without it |
| `optional*` | reads `null` | reads `null` | refuses the row | it is legitimately empty |
| `patch*` | leaves the field as it was | reads its own `require*` / `optional*` | refuses the diff | a partial update did or did not carry it |

Full-row readers are `requireString`, `requireInt`, `requireFloat`,
`requireBool`, `requireArray`, `requireStringList`, `optionalString`,
`optionalInt` and `optionalFloat`; each has a `patch*` twin
(`patchOptionalString` for `optionalString`, and so on). A number is the one
widening: `requireFloat` and `optionalFloat` take an integer, because
`json_encode(0.0)` writes `0` and a whole float comes back from the wire an
integer. A number written as text stays refused.

**Do not mint `''`, `0` or `false` for a key that did not arrive.** A field that
is allowed to be empty is declared nullable and read with `optional*`; a field
that is not allowed to be empty is read with `require*` and refuses the row. The
empty string is a value the sender chose, and a state that mints one turns a
frame that broke into a phase somebody selected.

```php
public static function fromRow(array $row): static
{
    $instance = new static();
    $instance->id = self::requireString($row, self::id);
    $instance->jobsDone = self::requireInt($row, self::jobsDone);
    $instance->note = self::optionalString($row, self::note);
    $instance->markRtSyncBaseline();

    return $instance;
}
```

**Inside `applyDiff()` read every field with `patch*`.** A diff carries the
fields that changed, so a key it does not carry means the field did not change —
which is not the same as the field being empty, and `optional*` cannot tell the
two apart. Reading a nullable field of a diff with `optionalString()` answers
`null` to every diff about some other field, and the state clears a field nobody
touched. The patch reader returns the value instead of writing through a
reference, so the field being patched is visible in the calling line:

```php
public function applyDiff(array $diff): void
{
    $this->jobsDone = self::patchInt($diff, self::jobsDone, $this->jobsDone);
    $this->note = self::patchOptionalString($diff, self::note, $this->note);
}
```

A refusal is an `InvalidFormatException`, and it has four doors to come out of:
`RtSyncApplicator::applyCreated()` and `applyUpdated()` for a row that arrived
over sync, `RtSnapshot::replace()` and `replaceScope()` for one handed over at
startup. All four trap it around the single row: the row is dropped, the
collection keeps what it had, and the collection key, the row id and the reason
are named in a warning by `RtSyncApplicator::logRefusedRow()`. One broken row of
one collection therefore costs that row, not the worker.

Checked automatically: `PAYLOAD-SENTINEL`, see
[automated-checks.md](../code-style/automated-checks.md).

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

Direct backing-state reads — `getStateCollection()`, `getStateItem()`,
`RtContext::getStateCollection()`, `RtContext::getStateItem()`, and direct
`$this->stateCollection` access — are allowed only in files under `Database/` or
`Runtime/`. Any other path is a violation regardless of the caller's role: an
agent, page, table, signal handler, or test that reaches a backing state object
is the same leak whether it goes through `getStateCollection()` or
`getStateItem()`. Those callers must use caller-facing collection/item APIs.
Checked automatically: `RT-STATE-REACH`, see
[automated-checks.md](../code-style/automated-checks.md).

Cure a leak by adding a delegate that returns plain values (ids, scalars,
DTOs) on the owning `Runtime/View/Collection` or `Runtime/View/Item` class and
calling that delegate — not by handing a state object back to the caller. A
delegate that returns backing state objects outward is the same violation one
floor up. During transparent data-shape refactors, prefer explicit field access
unless the new method was approved by name.

Concrete `Runtime/View/Item/*` classes must rely on their
`@extends RtItem<StateFoo>` template when reading declared state fields. Read
state fields directly through `$this->_state->fieldName`; do not introduce a
local `/** @var StateFoo $state */ $state = $this->_state;` alias only to
recover the type.

## Lifecycle

- Created: collection actions such as `create(...)`, `register(...)`, or `ensure(...)`
- Updated: item actions when the item key is known, or direct field set + `sync()` inside RT internals
- Deleted: item actions when one item key is known; collection actions only for clear/bulk cleanup
- Each of the three asks the truth-source guard for its own operation, so a source
  granted only some of them is refused on the others by name
- On worker sync: `applyDiff()` called with changed fields only
- On another node: the same `fromRow()` / `applyDiff()` / `remove()`, reached from
  the daemon rather than from a worker — a row travels the mesh as the row the
  writer's process produced

The node dimension changes nothing about how a row is written and one thing about
where: a collection is shared by the whole cluster, the right to write it is
claimed cluster-wide, and every other node holds a read-only replica (see
[rt-context.md](rt-context.md)). A claim covers a set of rows and a set of
operations, so two nodes may each hold a piece of it - one owning the rows it
writes and leaving its neighbour's alone (HIL-589), or one adding and removing
while another edits (HIL-688) - and neither is the two-owner split the node map
refuses. So `fromRow()` has to hold on a payload that
arrived from another machine, not just another process, and a row it refuses is
dropped and logged rather than hydrated as zeros — the same trap, one hop wider.

A node that joins is handed what each owner holds and **replaces** its copy with
it, row by row through `fromRow()`. Nothing is merged: the owner's copy is the
truth about what it sent. An owner of the whole collection sends the collection;
an owner of named rows sends those rows under a scope, and only they are replaced.
A node short of an OPERATION hands over nothing at all - even about the rows it
writes, its copy may be missing what its co-owner wrote.

## markRtSyncBaseline()

Call at end of `create()`/`fromRow()` to snapshot current state.
`sync()` diffs against this baseline to detect what changed.
