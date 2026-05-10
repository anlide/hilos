# ORM: DbItem Bridges

Bridge properties are read-only magic properties on View items or collections
that expose direct relations to other DB/RT items. They are the caller-facing
relation API for ORM data.

## Rule

Entity and Object layers keep scalar row fields. View items expose only their
own scalar fields plus direct relation bridges. Callers should use those
bridges instead of rebuilding relation lookups in pages, tables, agents, or
signal handlers.

Direct means the bridge is resolved from the current item's own scalar fields
or from the current collection key. Do not expose pass-through bridges that
read another bridge and then return that related item's relation. For example,
`EventAttachment` may expose `$eventAttachment->eventMessage`, but it must not
expose `$eventAttachment->event` through
`$eventAttachment->eventMessage?->event`.

Do not flatten scalar fields from related detail rows onto the parent View item.
If `Event` has `eventMessage`, the parent may expose `$event->eventMessage`,
but it must not also expose `$event->message`, `$event->authorUserId`, or
`$event->authorBotId` unless those fields are native `Event` Object fields.

## Bridge Shapes

Use bridge properties for direct relations:

| Relation | Shape | Example |
|---|---|---|
| Many-to-one FK | nullable item | `$eventMessage->event`, `$eventMessage->authorUser` |
| One-to-one detail row | nullable item | `$event->eventMessage`, `$event->eventUserRename` |
| One-to-many children | collection | `$event->attachments`, `$eventMessage->attachments` |
| Runtime overlay for one DB item | nullable RT item or collection | `$user->chatUserState`, `$user->connections` |

Name bridges after the related model when the relation is unambiguous:
`event`, `user`, `bot`, `eventMessage`. Preserve the foreign-key role when the
scalar field name has one: `authorUserId` becomes `authorUser`,
`authorBotId` becomes `authorBot`, `targetUserId` becomes `targetUser`, and
`actorUserId` becomes `actorUser`. Do not collapse role-bearing fields to
`user` or `bot`.

For one-to-one detail rows, name the parent bridge after the detail model:
`eventMessage`, `eventUserRegistration`, `eventUserRename`. For one-to-many
children whose model name is the parent model plus a plural detail suffix, name
the parent bridge after the plural suffix: `Event` to `EventAttachments` is
`attachments`.

Do not add every possible reverse one-to-many bridge just because the schema
can be traversed. Add a parent collection bridge only when the child rows are
keyed by this item's own id or own foreign key, the relation is part of the
domain API or serialization contract, and the collection can expose a typed
method with documented parent-key semantics. It is acceptable for two View
items to expose the same child collection when both items carry the same parent
key and both names are useful in the domain. For example, `Event->attachments`
and `EventMessage->attachments` may both use
`Hilos::$db->eventAttachments->forEventId($eventId)`, while
`EventAttachment->event` is still forbidden because it would be a pass-through
through `eventMessage`.

## View Item Implementation

Define bridge properties in PHPDoc and implement them in `__get()` with the
same property name:

```php
/**
 * @property-read ?EventMessage $eventMessage Message detail for message events
 * @property-read EventAttachments $attachments Published files for this event
 */
final class Event extends DbItem
{
    public const string attachments = 'attachments';

    public function __get(string $name): mixed
    {
        $eventId = $this->_object->id;

        return match ($name) {
            DbChatContext::eventMessage => Hilos::$db->eventMessages[$eventId],
            self::attachments => Hilos::$db->eventAttachments->forEventId($eventId),
            default => parent::__get($name),
        };
    }
}
```

Every public magic property implemented locally on a View item must use a
constant whose name and value exactly match the property key, unless an
existing Object, context, DTO, or table-row constant already owns that key:
`public const string attachments = 'attachments'`, not
`public const string ATTACHMENTS_KEY = 'attachments'`.

Every View item `__get()` match must delegate unknown names to the base item
with `default => parent::__get($name)`. Do not replace the framework fallback
with a local throw or an empty/default return.

If caller code needs detail row scalar data, read it through the bridge:

```php
$event->eventMessage?->message;
$event->eventMessage?->authorUserId;
```

Do not add parent-level shortcut fields for those values.

Do not hide simple bridge lookups in one-use private helpers such as
`eventMessage()`. Inline the bridge branch in `__get()` unless the logic is
genuinely complex or reused.

Non-DB resources derived from the current item's own scalar fields may be
exposed as computed item properties. They are not relation bridges, but they
follow the same naming and fallback rules. For example,
`EventAttachment->file` may resolve `Hilos::$fs->published[$storedName]`
because `storedName` belongs to the attachment itself.

## Null And Empty Semantics

- Nullable ID or missing optional one-to-one relation returns `null`.
- Missing optional many-to-one relation returns `null`.
- Empty one-to-many relation returns an empty typed collection, not `null`.
- DB collection array access treats a `null` offset as a missing optional key:
  `Hilos::$db->collection[$nullableId]` already returns `null`.
- DB collection filter helpers for nullable relation keys should return empty
  typed collections when the key is `null`.
- Do not add `Hilos::$db !== null` checks inside DB View item bridge code; DB
  View items are used in DB context.
- Do not add `?? null` to DB View collection lookups only to force nullable
  bridge semantics; the collection offset contract already owns that behavior.

## Collection Contracts

If a bridge uses array access, the collection must document what the offset
means:

```php
/**
 * Array access uses event_id, so eventMessages[$eventId] returns the
 * message detail for that parent event when it exists.
 */
final class EventMessages extends DbCollection
{
}
```

Use array access only for documented collection keys. If the lookup is not the
collection key, add or use a named collection method:

```php
Hilos::$db->eventAttachments->forEventId($eventId);
```

Named parent-collection methods must document which parent key they accept and
what they return for `null`. For shared parent-key relations, make the shared
key explicit in the method PHPDoc so callers can see why multiple parent items
may use the same collection method.

Read `accessor-contracts.md` before adding a new finder or changing offset
semantics.

## Serialization Boundary

Bridge properties are access API. They are not automatically serialized.

- Add scalar computed fields to `toArray()` only when they are part of the
  caller-facing item payload.
- Add bridge data to `toArray()` only when the `withBridges`, `toFrontend`, or
  local projection contract explicitly requires it.
- Do not leak internal storage fields to frontend payloads just because a bridge
  uses them.
- Do not serialize flattened copies of detail-row scalar fields on the parent
  item. Serialize the bridge item under its bridge key when a payload contract
  explicitly needs that relation data.

## Tests

When adding or changing bridges, cover the relation contract with focused
integration tests:

- present one-to-one detail bridge;
- missing optional bridge returns `null`;
- one-to-many bridge returns an empty typed collection when no children exist;
- callers that need detail-row scalar fields read them through the bridge item;
- collection offset semantics used by bridges work with the documented key.

## Anti-Patterns

Do not:

- rebuild bridge lookups in page, table, agent, or signal-handler code;
- add `findById()` when documented `[$id]` already expresses the key;
- put read-only bridge helpers under `actions`;
- expose relation data as unstructured arrays when a typed View item or
  collection can represent it;
- expose pass-through relation bridges through another bridge item;
- add reverse one-to-many bridges with no caller-facing domain or payload
  contract;
- flatten related detail-row scalar fields onto the parent View item;
- collapse role-bearing FK bridges such as `authorUserId` or `targetUserId`
  into generic `user` bridges;
- add one-use private helper methods for simple nullable relation access;
- make a one-to-many bridge nullable instead of returning an empty collection.
