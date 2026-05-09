# ORM: DbItem Bridges

Bridge properties are read-only magic properties on View items or collections
that expose direct relations to other DB/RT items. They are the caller-facing
relation API for ORM data.

## Rule

Entity and Object layers keep scalar row fields. View items expose both direct
scalar fields and direct relation bridges. Callers should use those bridges
instead of rebuilding relation lookups in pages, tables, agents, or signal
handlers.

## Bridge Shapes

Use bridge properties for direct relations:

| Relation | Shape | Example |
|---|---|---|
| Many-to-one FK | nullable item | `$eventMessage->event`, `$eventMessage->user` |
| One-to-one detail row | nullable item | `$event->eventMessage`, `$event->eventUserRename` |
| One-to-many children | collection | `$event->attachments`, `$eventMessage->attachments` |
| Runtime overlay for one DB item | nullable RT item or collection | `$user->chatUserState`, `$user->connections` |

Name bridges after the related model when the relation is unambiguous:
`event`, `user`, `bot`, `eventMessage`. Use a specific name when one item has
multiple relations to the same model: `actorUser`, `targetUser`, `authorUser`.

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
            DbChatContext::eventMessage => $eventId === null
                ? null
                : (Hilos::$db->eventMessages[$eventId] ?? null),
            self::attachments => $eventId === null
                ? EventAttachments::initEmpty()
                : Hilos::$db->eventAttachments->forEventId($eventId),
            default => parent::__get($name),
        };
    }
}
```

Prefer the bridge in derived scalar fields:

```php
self::message => $this->eventMessage?->message,
self::authorUserId => $this->eventMessage?->authorUserId,
```

Do not hide simple bridge lookups in one-use private helpers such as
`eventMessage()`. Inline the bridge branch in `__get()` unless the logic is
genuinely complex or reused.

## Null And Empty Semantics

- Nullable ID or missing optional one-to-one relation returns `null`.
- Missing optional many-to-one relation returns `null`.
- Empty one-to-many relation returns an empty typed collection, not `null`.
- Guard nullable keys before collection access. Do not add `Hilos::$db !== null`
  checks inside DB View item bridge code; DB View items are used in DB context.
- Use `?? null` for optional collection-key lookups.

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

## Tests

When adding or changing bridges, cover the relation contract with focused
integration tests:

- present one-to-one detail bridge;
- missing optional bridge returns `null`;
- one-to-many bridge returns an empty typed collection when no children exist;
- scalar fields derived through bridges still match the persisted detail row;
- collection offset semantics used by bridges work with the documented key.

## Anti-Patterns

Do not:

- rebuild bridge lookups in page, table, agent, or signal-handler code;
- add `findById()` when documented `[$id]` already expresses the key;
- put read-only bridge helpers under `actions`;
- expose relation data as unstructured arrays when a typed View item or
  collection can represent it;
- add one-use private helper methods for simple nullable relation access;
- make a one-to-many bridge nullable instead of returning an empty collection.
