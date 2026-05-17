---
name: hilos-frontend-representation
description: Work with Hilos browser-facing DB/RT payloads, BrowserContext page-shaped rows, typed frontend projections, FrontendChangesDTO collections, legacy EntitiesChangesDTO paths, table rows, and boundaries between frontend DTOs and DB/RT toArray serializers.
---

# Hilos Frontend Representation

Use this skill when adding or reviewing browser payloads for DB-backed or
runtime-backed items, BrowserContext page-shaped rows, typed frontend state
projections, table rows, or legacy payloads that still call
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

- Page-shaped DB/RT browser state should go through `BrowserContext` and
  `BrowserPageSignalData`; project-wide frontend state should go through typed
  frontend projections and `FrontendChangesDTO` collections.
- DB/RT `toArray()` methods are backend serializers, legacy entity serializers,
  table row serializers, DTO serializers, or RT sync row serializers depending
  on the owning class. They are not the default owner for new browser payloads.
- Runtime-only fields such as presence, connection counters, upload progress,
  and attachment draft internals belong in typed frontend projections or table
  rows, not generic entity payloads.
- RT state row `toArray()` is an internal sync/runtime contract; do not treat it
  as a browser payload.
- Tables own row shape only when the row is genuinely screen-specific.

## Workflow

1. Decide whether the value belongs to page-shaped browser state, project-wide
   frontend state, a signal payload, a table row, DB sync, RT sync, or
   backend-only serialization.
2. Inspect existing `BrowserContext`, page/table `BROWSER` config,
   backend DTO/projection/table row constants, and the matching TypeScript
   parser/store shape.
3. Inspect the View item `__get()` and existing bridge properties for model
   access, but do not add new browser filtering to View item `toArray()`.
4. Inspect existing Object item fields and runtime bridge properties.
5. If runtime data is involved, inspect the RT collection helper first, such as
   `connections->summaryForUser($userId)`.
6. Use `BrowserPageSignalData` for page-shaped browser rows and typed
   DTO/projection payloads for project-wide frontend state.
7. Keep legacy `toFrontend` entity paths only when the existing generic entity
   channel still depends on them and migration is out of scope.
8. Keep table/page code as orchestration that calls existing model APIs, typed
   DTO contracts, or row factories.
9. Validate through the narrow composer script selected by `$hilos-testing-cli`.

## Examples

Expose an item-level runtime bridge for model access:

```php
public function __get(string $name): mixed
{
    return match ($name) {
        RtChatContext::connections => Hilos::$rt->connections->forUser($this->id),
        default => parent::__get($name),
    };
}
```

Send page-shaped user state through browser table configs:

```php
[
    BrowserFieldKey::SOURCE => ChatBrowserSource::RT_CONNECTIONS,
    BrowserFieldKey::ROW_KEY => Connection::userId,
    BrowserFieldKey::COMPUTED => [
        UserConnectionSummary::presence,
        UserConnectionSummary::onlineSessionCount,
    ],
]
```

Build screen-specific table rows through the table row contract:

```php
foreach (Hilos::$db->users as $user) {
    $rows[] = $this->rowFromUser($user)->toArray();
}
```

## Anti-Patterns

Do not send user browser state through a DB item serializer:

```php
$payload = Hilos::$db->users[$userId]->toArray(toFrontend: true);
```

Use a browser row contract:

```php
$payload = new BrowserPageSignalData([
    ChatBrowserTable::MAIN_USERS => [
        BrowserPageSignalData::rows => $rows,
    ],
]);
```

Do not send RT View item arrays to the browser:

```php
$draftRows[] = $draft->toArray();
```

Use the browser DTO:

```php
$draftRows[] = AttachmentDraftSignalData::fromDraft($draft)->toArray();
```

## Hard Rules

- Do not add new browser-facing fields, privacy filters, or runtime overlays to
  DB/RT View item `toArray()` methods.
- Do not send raw RT state rows or RT View item arrays to the browser.
- Do not put page-specific runtime overlays into generic entity payloads.
- Do not put frontend representation logic in Entity classes.
- Keep frontend parsers and backend DTO/projection/browser tests synchronized
  with any changed browser payload shape.
