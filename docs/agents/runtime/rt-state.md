# Runtime: RtState

`RtState` is the base class for individual runtime state items (rows in an RT collection).

## Structure

```php
final class MyState extends RtState {
    // Declare field name constants
    public const string userId = 'userId';
    public const string status = 'status';

    // Private fields
    private int $userId = 0;
    private string $status = '';

    // Factory methods
    public static function create(int $userId): static { ... }
    public static function fromRow(array $row): static { ... }

    // Required: collection key this state belongs to
    public static function getRtCollectionKey(): string {
        return RtMyContext::myCollection;
    }

    // Required: unique id within collection
    public function getId(): string { return (string)$this->userId; }

    // Required: serialize to array for sync
    public function toArray(): array { ... }

    // Required: apply partial update
    public function applyDiff(array $diff): void { ... }

    // Magic getter for read access
    public function __get(string $name): mixed { ... }
}
```

## Writing to state

State fields are private. Write via `__set()` then call `sync()`:

```php
$connection = Hilos::$rt->connections->getStateCollection()->get($acceptKey);
$connection->userId = $newUserId;   // __set triggers
$connection->sync();                // broadcasts RT_SYNC_UPDATED to all workers
```

Or use an **Actions** class (recommended pattern):

```php
Hilos::$rt->connections->actions->setUserId($acceptKey, $newUserId);
```

## Reading from state

```php
$state = Hilos::$rt->userStates->getStateCollection()->get($userId);
if ($state !== null) {
    $msg = $state->moderationMessage; // via __get()
}
```

## Lifecycle

- Created: `RtStates::actions->create(...)` or `ensure(...)`
- Updated: via actions or direct field set + `sync()`
- Deleted: `RtStates::actions->delete($id)`
- On worker sync: `applyDiff()` called with changed fields only

## markRtSyncBaseline()

Call at end of `create()`/`fromRow()` to snapshot current state.
`sync()` diffs against this baseline to detect what changed.
