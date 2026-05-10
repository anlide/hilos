# ORM: Frontend Representation

Read this before sending DB or RT data to the browser.

## Core Rule

Use typed frontend projections for browser-facing DB/RT payloads. Do not add new
browser shaping, privacy filtering, or runtime overlays to DB/RT View item
`toArray()` methods.

`toArray()` is still valid for backend row serialization, DTO serialization,
table rows, and RT sync/runtime rows. It is not the preferred owner for
project-specific browser state.

## Boundaries

| Need | Put it in |
|---|---|
| Public browser state for one DB item | `Frontend/DTO/*Projection` plus a projector |
| Runtime-backed browser state | Frontend projection DTO, fed by `Hilos::$rt` typed APIs |
| Page-specific state collection | `FrontendChangesDTO` with `FrontendStateCollectionKey` |
| Table row payload | Concrete table row DTO or table helper |
| Generic legacy entity payload | Existing `EntitiesChangesDTO` path only when already established |
| RT sync or delete tombstone row | Concrete `Runtime/State/Item/*::toArray()` |
| Backend object/entity row | Object/entity `toArray()` |

For example, users are projected through `UserFrontendStateProjector` and
`FrontendUserProjection`, while their DB View item remains a backend model API.
Attachment drafts are projected through `AttachmentDraftFrontendStateProjector`
and `AttachmentDraftSignalData`, while `StateAttachmentDraft::toArray()` remains
the RT sync row contract.

## Workflow

1. Decide whether the payload is browser state, table state, DB sync, RT sync,
   or backend-only serialization.
2. For browser state, inspect existing `Frontend/*Projector`,
   `Frontend/DTO/*Projection`, `FrontendStateCollectionKey`, and the matching
   TypeScript parser/store shape.
3. Keep DB/RT View items as typed model access APIs: expose reusable properties
   and bridges through `__get()`, but do not make their `toArray()` the browser
   contract.
4. If runtime data is involved, read it through existing RT collection/item APIs
   such as `Hilos::$rt->connections->summaryForUser($userId)`.
5. If the browser needs a new state collection or changes an existing payload
   shape, stop for the contract approval gate before editing signal DTOs or
   frontend parsers.
6. Update backend DTO/projection tests and frontend parser/receiver tests
   together.
7. Validate through the narrow composer script selected by
   `docs/agents/testing.md`.

## Preferred Shape

Send public user state through explicit frontend collections:

```php
use Demo\Chat\Frontend\DTO\FrontendUserConnectionStatsProjection;
use Demo\Chat\Frontend\DTO\FrontendUserProjection;
use Demo\Chat\Frontend\FrontendStateCollectionKey;

$collections[FrontendStateCollectionKey::USERS][] =
    FrontendUserProjection::fromDbUser($user)->toArray();

$collections[FrontendStateCollectionKey::USER_CONNECTION_STATS][] =
    (new FrontendUserConnectionStatsProjection(
        userId: (int) $user->id,
        onlineSessionCount: Hilos::$rt->connections->summaryForUser((int) $user->id)->onlineSessionCount,
    ))->toArray();
```

Use table rows for screen-specific row shape:

```php
foreach (Hilos::$db->users as $user) {
    $rows[] = $this->rowFromUser($user)->toArray();
}
```

Keep RT sync rows on state items:

```php
public function toArray(): array
{
    return [
        self::draftId => $this->draftId,
        self::acceptKey => $this->acceptKey,
    ];
}
```

That RT state row is input for sync and projection decisions; it is not the
browser payload.

## Legacy Entity Paths

Some existing entity payloads still use `EntitiesChangesDTO`, which serializes
DB collections through `DbCollection::toArray(idAsIndex: false, toFrontend:
true)`. Treat this as a generic legacy entity path, not as the default for new
browser contracts.

When touching one of those paths:

- prefer migrating the affected model to typed frontend projections;
- do not add new model-specific browser filters to `DbItem::toArray()`;
- do not send private fields such as tokens through `EntitiesChangesDTO`;
- keep existing `toFrontend` behavior only when a legacy entity path still
  depends on it and no projection migration is in scope.

## Anti-Patterns

Do not send user browser state through a DB item serializer:

```php
// Wrong: hides frontend contract in the DB View item.
$payload = Hilos::$db->users[$userId]->toArray(toFrontend: true);
```

Use an explicit projection:

```php
$payload = UserFrontendStateProjector::fullForUser(Hilos::$db->users[$userId])->toArray();
```

Do not reuse RT View item arrays as browser payloads:

```php
// Wrong: exposes runtime row fields such as acceptKey or quarantine filename.
$drafts[] = $draft->toArray();
```

Use the browser DTO:

```php
$drafts[] = AttachmentDraftSignalData::fromDraft($draft)->toArray();
```

Do not compute reusable runtime state in a page/table loop by bypassing the
model API:

```php
// Wrong: duplicates runtime lookup at the caller.
$onlineSessionCount = count(Hilos::$rt->connections->forUser($user->id));
```

Use the established typed runtime summary or item property:

```php
$summary = Hilos::$rt->connections->summaryForUser((int) $user->id);
```

## Hard Rules

- Do not add new browser-facing fields, privacy filters, or runtime overlays to
  DB/RT View item `toArray()` methods.
- Do not send raw RT state rows or RT View item arrays to the browser.
- Do not put page-specific runtime overlays into generic entity payloads.
- Do not put frontend representation logic in Entity classes.
- Use stable key constants from the owning DTO, projection, table row, entity,
  object, or context for boundary arrays.
- Keep frontend parsers and backend DTO/projection tests synchronized with any
  changed browser payload shape.
