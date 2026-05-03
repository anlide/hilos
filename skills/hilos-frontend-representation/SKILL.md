---
name: hilos-frontend-representation
description: Work with Hilos DB/View item frontend representation, DbItem::toArray(), DbCollection::toArray(), toFrontend, withCalculation, computed properties, frontend-safe payloads, table rows backed by DB items, and model-level fields such as onlineSessionCount or presence.
---

# Hilos Frontend Representation

Use this skill when adding or reviewing computed frontend fields for DB-backed
items, table rows that serialize DB collections, or payloads that call
`toArray(toFrontend: true)`. Start with `agents.md`, then read
`docs/agents/orm/frontend-representation.md`.

## Read First

- Frontend representation rule:
  `docs/agents/orm/frontend-representation.md`.
- DB collections, View items, Object layer, and actions: use `$hilos-orm`.
- DB item plus runtime overlay: use `$hilos-db-rt-state`.
- Caller-side DB/RT consumption from pages, tables, and actions:
  use `$hilos-app-data-access`.
- Frontend subscription and signal contracts: use `$hilos-frontend-sdk` when the
  payload crosses a page or WebSocket boundary.
- Test command selection: use `$hilos-testing-cli`.

## Mental Model

- `toFrontend: true` asks the View item for a browser-safe representation.
- `DbCollection::toArray(...)` passes serialization flags through to every
  `DbItem::toArray(...)`.
- `withCalculation` is an optional richer-serialization flag; it does not do
  work unless the item implements a branch for it.
- Computed fields that describe one DB item belong on the View item, Object
  item, typed DTO, or signal payload, not in ad hoc table/page loops.
- Tables should use collection serialization for direct DB-backed rows and only
  own row shaping when the row is truly screen-specific.

## Workflow

1. Decide whether the value describes one model item or a screen-specific row.
2. Inspect the View item `__get()` and `toArray()` implementation.
3. Inspect existing Object item fields and runtime bridge properties.
4. If runtime data is involved, inspect the RT collection helper first, such as
   `connections->forUser($userId)`.
5. Add item-level computed fields in View item `toArray()` when they are part of
   frontend representation.
6. Gate browser payload filtering and frontend-only fields with `$toFrontend`.
7. Use `$withCalculation` only for optional calculations that callers must
   explicitly request.
8. Keep table/page code as orchestration that calls `toArray(toFrontend: true)`
   or a typed DTO contract.
9. Validate through the narrow composer script selected by `$hilos-testing-cli`.

## Examples

Add an item-level runtime bridge:

```php
public function __get(string $name): mixed
{
    return match ($name) {
        RtChatContext::connections => Hilos::$rt->connections->forUser($this->id),
        default => parent::__get($name),
    };
}
```

Expose a frontend-safe computed property from the item representation:

```php
use Demo\Chat\Frontend\DTO\FrontendUserConnectionStatsProjection;

public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false, bool $toFrontend = false): array
{
    $result = parent::toArray($withId, $idAsIndex, $withBridges, $withCalculation, $toFrontend);

    if ($toFrontend) {
        unset($result[ObjectUser::sessionToken]);
        $result[FrontendUserConnectionStatsProjection::onlineSessionCount] = count($this->connections);
    }

    return $result;
}
```

Serialize direct DB-backed table rows through the collection:

```php
return $this->queryDbCollection(Hilos::$db->users, $query);
```

or:

```php
$rows = Hilos::$db->users->toArray(idAsIndex: false, toFrontend: true);
```

## Anti-Patterns

Do not compute a model-level frontend field in a table loop:

```php
// Wrong: duplicates runtime lookup and hides a User field in table code.
use Demo\Chat\Tables\AdminUser\AdminUserTableRow;

foreach (Hilos::$db->users as $user) {
    $rows[] = [
        AdminUserTableRow::id => $user->id,
        AdminUserTableRow::onlineSessionCount => count(Hilos::$rt->connections->forUser($user->id)),
    ];
}
```

Use the item representation instead:

```php
use Demo\Chat\Frontend\DTO\FrontendUserConnectionStatsProjection;

$result[FrontendUserConnectionStatsProjection::onlineSessionCount] = count($this->connections);
```

Do not send raw Entity/Object arrays to the browser when View item
`toArray(..., toFrontend: true)` owns field filtering.

## Hard Rules

- Do not bypass `toFrontend: true` for frontend DB item payloads.
- Do not compute item-level frontend fields in tables or pages when the View
  item or typed payload should own them.
- Do not use `$withCalculation` as a privacy or frontend-safety switch.
- Do not put frontend representation logic in Entity classes.
- Do not duplicate DB/RT aggregation in caller code when an item property can
  expose the value once.
