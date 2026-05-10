---
name: hilos-frontend-representation
description: Work with Hilos DB/View item frontend representation, DbItem::toArray(), DbCollection::toArray(), toFrontend, withCalculation, computed properties, frontend-safe payloads, table rows backed by DB items, and model-level fields such as onlineSessionCount or presence.
---

# Hilos Frontend Representation

Use this skill when adding or reviewing browser payloads for DB-backed items,
typed frontend state projections, table rows, or payloads that call
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

- `toFrontend: true` is the framework's generic DB item serialization boundary.
  It is still used by entity sync, collection serialization, and direct DB item
  payloads.
- Page-specific frontend state should prefer typed DTO/projection collections
  with stable constants and matching TypeScript parsers.
- Runtime-only fields such as presence or connection counters do not belong in
  a generic user/entity payload just because the browser needs them.
- Computed values that describe one DB item belong on the View item, Object
  item, typed DTO, or signal payload, not in ad hoc table/page loops.
- Tables own row shape only when the row is genuinely screen-specific.

## Workflow

1. Decide whether the value belongs to a generic DB item payload, a typed
   frontend state collection, a signal payload, or a table row.
2. Inspect the existing backend DTO/projection/table row constants and the
   matching TypeScript parser/store shape.
3. Inspect the View item `__get()` and `toArray()` implementation.
4. Inspect existing Object item fields and runtime bridge properties.
5. If runtime data is involved, inspect the RT collection helper first, such as
   `connections->forUser($userId)`.
6. Add item-level computed fields in View item `toArray()` only when they are
   part of the generic DB item representation.
7. Use typed DTO/projection payloads for page-specific frontend state.
8. Use `$withCalculation` only for optional calculations that callers must
   explicitly request.
9. Keep local View item property constants exactly aligned with property keys,
   for example `onlineSessionCount = 'onlineSessionCount'`.
10. Keep table/page code as orchestration that calls existing model APIs, typed
   DTO contracts, or row factories.
11. Validate through the narrow composer script selected by `$hilos-testing-cli`.

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

Send page-specific frontend state through a typed projection:

```php
use Demo\Chat\Frontend\DTO\FrontendUserConnectionStatsProjection;
use Demo\Chat\Frontend\FrontendStateCollectionKey;

$collections[FrontendStateCollectionKey::USER_CONNECTION_STATS][] = (new FrontendUserConnectionStatsProjection(
    userId: (int) $user->id,
    onlineSessionCount: count($user->connections),
))->toArray();
```

Use `toFrontend: true` for generic DB item payloads that already use the
framework serialization boundary:

```php
if (!isset(Hilos::$db->bots[$botId])) {
    return;
}

$payload = Hilos::$db->bots[$botId]->toArray(toFrontend: true);
```

Build screen-specific table rows through the table row contract:

```php
foreach (Hilos::$db->users as $user) {
    $rows[] = $this->rowFromUser($user)->toArray();
}
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

Use a typed frontend projection or table row instead:

```php
use Demo\Chat\Frontend\DTO\FrontendUserConnectionStatsProjection;

(new FrontendUserConnectionStatsProjection(
    userId: (int) $user->id,
    onlineSessionCount: count($user->connections),
))->toArray();
```

Do not send raw Entity/Object arrays to the browser when View item
`toArray(..., toFrontend: true)` owns field filtering.

## Hard Rules

- Do not bypass `toFrontend: true` for frontend DB item payloads.
- Do not compute item-level frontend fields in tables or pages when the View
  item or typed payload should own them.
- Do not put page-specific runtime overlays into generic entity payloads.
- Do not use `$withCalculation` as a privacy or frontend-safety switch.
- Do not put frontend representation logic in Entity classes.
- Do not duplicate DB/RT aggregation in caller code when an item property can
  expose the value once.
- Do not name View item property constants with suffixes such as `_KEY` when
  the property key itself is the constant name.
