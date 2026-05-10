# ORM: Frontend Representation

Use `DbItem::toArray(..., toFrontend: true)` and `DbCollection::toArray(...)`
for generic frontend-safe DB item representation. Page-specific frontend state
should use typed DTO/projection payloads with stable key constants. Runtime-only
telemetry that is needed by one table, detail view, or diagnostic surface should
live in that table row or runtime summary payload.

## Rule

Frontend representation is part of the model contract. Add fields such as
display labels or other durable item-level derived values where the item is
serialized for callers. Table/page code should consume that representation unless
the row is truly screen-specific. Do not add table-only or page-specific runtime
fields such as `presence` or `onlineSessionCount` to a generic entity payload.

## Serialization Pipeline

`DbCollection::toArray(...)` calls each item's `toArray(...)` with the same
flags:

```php
$collection->toArray(idAsIndex: false, toFrontend: true);
```

`DbItem::toArray(...)` starts from the Object layer data and then child View
items may refine the payload:

```php
use Demo\Chat\Database\Object\Item\User as ObjectUser;

public function toArray(
    bool $withId = true,
    bool $idAsIndex = true,
    bool $withBridges = false,
    bool $withCalculation = false,
    bool $toFrontend = false,
): array {
    $result = parent::toArray($withId, $idAsIndex, $withBridges, $withCalculation, $toFrontend);

    if ($toFrontend) {
        unset($result[ObjectUser::sessionToken]);
    }

    return $result;
}
```

`toFrontend: true` means the payload is safe and shaped for the browser. The
base item always keeps primary key fields when `toFrontend` is true, even if
`withId` is false.

## `$toFrontend`

Use `$toFrontend` when the payload crosses the frontend boundary:

- page subscription full payloads;
- DB sync or entity update payloads;
- table rows that are direct projections of one DB collection;
- action results or DTOs that expose DB item state to the browser.

When `$toFrontend` is true, remove private or backend-only fields such as
session tokens, internal state, or moderation details that have a different
signal contract. Add only generic DB item fields here. Use typed frontend state
DTOs for page-specific runtime overlays.

## Frontend State Payloads

Use typed DTO/projection payloads when the browser state is not the generic DB
entity shape:

```php
use Demo\Chat\Frontend\DTO\FrontendUserConnectionStatsProjection;
use Demo\Chat\Frontend\FrontendStateCollectionKey;

$collections[FrontendStateCollectionKey::USER_CONNECTION_STATS][] = (new FrontendUserConnectionStatsProjection(
    userId: (int) $user->id,
    onlineSessionCount: count($user->connections),
))->toArray();
```

The DTO owns payload key constants and should have a matching TypeScript parser
or store shape on the frontend side. Do not copy those keys into generic entity
arrays.

## `$withCalculation`

Use `$withCalculation` for optional calculated fields that are not always needed
by backend callers. It is a pipeline flag passed from collection serialization
to item serialization; it does not calculate anything by itself.

Prefer `$withCalculation` when:

- the calculation is expensive or needs runtime/DB lookups not needed by every
  caller;
- the field is useful outside the browser and should not be tied to
  `$toFrontend`;
- the caller explicitly requests a richer model snapshot.

Do not use `$withCalculation` as a privacy flag. Use `$toFrontend` for frontend
safety and field filtering.

## Computed Item Fields

If a computed value is needed by several callers, expose it through the View
item or a typed payload:

```php
$onlineSessionCount = $user->onlineSessionCount;
```

Simple computed frontend fields may be expressed directly in `__get()` or
`toArray()`. Keep table-only runtime counters in a typed row/runtime summary and
use the View item property as the source of the value.

The runtime bridge should also live on the item or runtime collection:

```php
private const string ONLINE_SESSION_COUNT_KEY = 'onlineSessionCount';

public function __get(string $name): mixed
{
    return match ($name) {
        RtChatContext::connections => Hilos::$rt->connections->forUser($this->id),
        self::ONLINE_SESSION_COUNT_KEY => count($this->connections),
        default => parent::__get($name),
    };
}
```

Then table/detail projections that need the count can use the model API without
putting the count into the generic entity payload:

```php
$row = HilosUserTableRow::fromDbUser($user);
```

## Table Projection vs Model Representation

Use table code for screen-specific row shape, pagination, sorting, and joins
that are truly table-only. Do not place model-level computed fields in table
code just because a table is the first frontend consumer.

| Need | Put it in |
|---|---|
| Hide backend-only field from browser | View item `toArray(..., toFrontend: true)` |
| Add generic frontend field for one model item | View item `toArray()` |
| Add page-specific runtime frontend state | Typed DTO/projection payload |
| Optional richer serialization | `withCalculation` branch in item `toArray()` |
| Runtime overlay for one DB item | View item bridge plus RT collection lookup |
| Direct DB collection table rows | Table row factory or protected table helper |
| Runtime-enriched table/detail rows | Concrete row DTO or runtime summary payload |
| Screen-specific joined row | Concrete table `query()` and typed row class |

## Anti-Patterns

Do not recompute runtime table fields by bypassing the item API:

```php
// Wrong: bypasses the User runtime bridge.
use Demo\Chat\Tables\AdminUser\AdminUserTableRow;

foreach (Hilos::$db->users as $user) {
    $rows[] = [
        AdminUserTableRow::id => $user->id,
        AdminUserTableRow::onlineSessionCount => count(Hilos::$rt->connections->forUser($user->id)),
    ];
}
```

Expose the item-level value once and add it only to the row that needs it:

```php
HilosUserTableRow::fromDbUser($user);
```

Do not send raw Object or Entity arrays to the browser when a View item has
frontend filtering. Do not add frontend-only shaping to Entity classes.

## Checklist

1. Identify whether the value describes one DB item or one screen row.
2. Inspect the View item `__get()` and `toArray()` before changing a table.
3. Inspect the Object item when the value is durable or derived only from DB
   fields.
4. Inspect runtime collection helpers when the value depends on `Hilos::$rt`.
5. Add a View item computed property for item-level frontend fields.
6. Use typed DTO/projection payloads for page-specific frontend state.
7. Use `toFrontend: true` when serializing generic DB item payloads for the browser.
8. Use `withCalculation` only for optional richer calculations.
9. Keep table/page code as orchestration unless the shape is table-specific.

## Hard Rules

- Do not compute model-level frontend fields in table/page code when a View item
  or typed payload should own them.
- Do not expose backend-only fields by bypassing `toFrontend: true`.
- Do not put page-specific runtime overlays into generic entity payloads.
- Do not put frontend representation logic in Entity classes.
- Do not duplicate DB/RT aggregation in tables when an item property can expose
  the same value.
