---
name: hilos-db-rt-state
description: "Connect DB-backed Hilos::$db models with Hilos::$rt runtime overlays. Use when adding computed frontend fields, online status or session counts, DB item runtime properties, table/page state that mixes DB and RT, or when deciding whether aggregation belongs in a View/Object item, RtCollection, table, page, DTO, or signal payload."
---

# Hilos DB/RT State

Use this skill when durable DB data needs live runtime state attached to it.
Start by finding the owning DB item and runtime collection before touching a
table or page.

## Read First

- DB item, object/view layers, and actions: use `$hilos-orm` and
  `docs/agents/orm/db-collection.md`.
- Runtime collections, state items, sync, and truth sources: use
  `$hilos-runtime`, `docs/agents/runtime/rt-context.md`, and
  `docs/agents/runtime/rt-state.md`.
- DB vs RT choice and extension checklist: use `$hilos-data-extension`.
- Table/page subscription and frontend payload contracts: use
  `$hilos-frontend-sdk` when the value is sent to the browser.

## Mental Model

- `Hilos::$db` owns durable business identity and persisted fields.
- `Hilos::$rt` owns live, rebuildable state such as active connections,
  socket-scoped state, uploads, moderation UI state, and transient per-user
  overlays.
- A DB item may expose runtime-linked read properties when the live state is a
  natural attribute of that item for callers.
- Computed frontend fields should live in the DB Object/View item or a typed
  DTO/signal representation when they describe one model item.
- Runtime collection methods should own reusable live lookups such as
  `connections->forUser($userId)`.
- Page and table layers should orchestrate existing DB/RT APIs; they should not
  duplicate filters, joins, or runtime aggregation for model properties.

## Workflow

1. Identify the durable item first, for example `Hilos::$db->users[$userId]`.
2. Identify the runtime overlay, for example
   `Hilos::$rt->connections->forUser($userId)`.
3. Search the DB View item and Object item for existing bridge properties or
   calculated frontend fields.
4. Search the RT View collection for an existing lookup helper before adding a
   new filter in caller code.
5. Put a missing reusable RT lookup on the RT collection.
6. Put an item-level runtime bridge on the DB View item when callers should read
   it as part of the model.
7. Put frontend-safe calculated fields in the DB View item `toArray(...)`, a
   DTO, or a signal payload according to the existing contract.
8. Keep table/page code as query/subscription orchestration that calls the DB
   collection or item API.
9. Verify the truth source owns any RT writes; read-only calculated properties
   may read synchronized runtime state.

## Placement Rules

| Need | Put it in |
|---|---|
| Durable field | DB migration, Entity, Object/View item |
| Live per-item overlay | RT state plus DB View item bridge property |
| Reusable live lookup | RT State/View collection method |
| Computed display value for one DB item | DB Object/View item or typed DTO |
| Table rows from DB items | Table querying `Hilos::$db` collection |
| Page response assembly | Page using existing DB/RT APIs |
| RT mutation | RT collection/item actions owned by truth source |

Use table/page code only when the value is truly screen-specific assembly. If
the same value describes a user, event, room, file, or other model item, move it
to that item or its typed representation.

## Example

For a user online indicator, keep the relationship close to the user model:

```php
// Database/View/Item/User.php
public function __get(string $name): mixed
{
    return match ($name) {
        RtChatContext::connections => Hilos::$rt->connections->forUser($this->id),
        default => parent::__get($name),
    };
}
```

Then compute frontend fields from that bridge:

```php
$result['onlineSessionCount'] = count($this->connections);
$result['presence'] = count($this->connections) > 0 ? 'online' : 'offline';
```

The table should query the DB collection and let item serialization or typed row
construction consume the model API:

```php
return $this->queryDbCollection(Hilos::$db->users, $query);
```

## Anti-Patterns

Do not manually rebuild a model-level runtime overlay in a table:

```php
// Wrong: duplicates runtime lookup and hides a User computed property in table code.
foreach (Hilos::$db->users as $user) {
    $onlineSessionCount = 0;
    foreach (Hilos::$rt->connections as $connection) {
        if ($connection->userId === $user->id) {
            $onlineSessionCount++;
        }
    }
}
```

Do this instead:

```php
$onlineSessionCount = count($user->connections);
```

Do not add a page-local map of runtime rows when the RT collection needs a
lookup method. Do not store durable truth in RT just because the frontend needs a
live overlay.

## Hard Rules

- Check DB item, RT state, and existing bridge properties before adding
  table/page aggregation.
- Keep reusable DB/RT links typed and discoverable on item or collection APIs.
- Do not bypass `Hilos::$db` or `Hilos::$rt` with ad hoc arrays or duplicated
  filters in pages.
- Only the truth source agent writes shared runtime state.
- Do not create Repository or Service wrappers over DB or RT collections.
- Run validation through composer scripts selected by `$hilos-testing-cli`.
