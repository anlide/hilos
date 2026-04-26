# Frontend SDK: Backend Contract

How frontend and backend communicate: actions (client → server) and signals (server → client).

## Actions (client → server)

Frontend sends an action:
```json
{ "type": "message", "data": { "text": "Hello world" } }
```

Backend receives this as `WS_ACTION` signal → routed to agent's `onSignalAction()`.

The agent's associated Page handles the action via `onAction(acceptKey, actionName, dto)`.
`ActionPayloadDTO` contains the raw `data` array; parse it into a typed DTO inside `onAction`.

## Signals (server → client)

Server sends:
```json
{ "type": "new_event", "data": { "id": 42, "text": "Hello world", "userId": 7 } }
```

Frontend registers listeners:
```ts
ws.on('new_event', (data: NewEventData) => {
    store.addEvent(data)
})
```

## Page subscription

```ts
// Subscribe to page
ws.send('PAGE_SUBSCRIBE', { page: 'main', params: {} })

// Server responds with subscription signal carrying initial state
// e.g. 'subscription_page_main' with full entity snapshot
```

On the wire `params` is still a `Record<string, string>` — transport DTOs
(`WebSocketPageSubscribeSignalDTO` / `WebSocketPageUpdateSubscriptionSignalDTO`)
carry `array<string, string> $params` unchanged. Once the router has the
signal, it wraps the raw map in a `PageRouteParams` value object and passes it
to the page:

```php
public function onSubscribe(string $acceptKey, PageRouteParams $params): void;
public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void;
```

Pages never index `$params` directly. They either call a typed accessor
(`$params->requirePositiveInt('userId')`) or parse the whole bag into a
`AbstractPageSubscribeParamsDTO` subclass up front; see
[code-style/page-action-handlers.md](../code-style/page-action-handlers.md#subscribe-handlers-and-route-params).

### Subscription errors

If route params are missing or malformed, the page-level code throws a
`PageSubscriptionException` subclass and the router replies with a structured
`subscription_page_error` signal instead of tearing down the connection. The
two param-specific error codes are:

- `missing_page_route_param` (HTTP 400) — key absent or empty.
- `invalid_page_route_param` (HTTP 400) — value fails the typed contract
  (non-numeric for `getInt`, `<= 0` for `getPositiveInt`, unknown enum case,
  etc.).

Frontend can render subscription errors without assuming a specific param
shape — `errorCode` is stable, `message` is for diagnostics only.

## Entities snapshot

On subscription, server typically sends `EntitiesChangesDTO`:
```json
{
  "entities": {
    "full": { "users": [...], "events": [...] },
    "created": [],
    "updated": [],
    "deleted": []
  }
}
```

Frontend applies this as initial state and subscribes to incremental updates.

## Table snapshots

Pages that include table data send full table snapshots in the `tables` payload:

```json
{
  "tables": {
    "users": {
      "rows": [{ "id": 1, "name": "Ada" }],
      "totalCount": 1,
      "offset": 0,
      "limit": 0
    }
  }
}
```

Backend page subscriptions load this through
`TableDefinition::getFullSnapshot()`, which returns `TableSnapshotDTO`.
The public `TableDefinition::getPage(TablePageQueryDTO $query)` shape is
reserved for future paging/partial loading, but currently throws
`NotImplementedException`; frontend code must treat table subscription data as
a full snapshot.

## Table mutation delivery

`table_mutation` is an immediate mutation. Backend sends it directly to the
initiating connection, usually with `sendToUser()`, and the frontend applies it
to the table rows immediately.

`table_mutation_pending` is an external mutation. Backend uses it for
broadcast or group delivery, usually excluding the initiator, and the frontend
adds it to the table pending queue until the user applies pending changes.

Routing metadata stays in backend routing wrappers only. Fields such as
`acceptKey`, `targetAcceptKey`, `excludeAcceptKey`, and `targetGroup` must not
appear in the WebSocket `data` payload for table mutations; frontend parsers
reject such payloads.

## Binary messages (file upload)

After `FILE_UPLOAD_READY` signal, frontend sends raw binary WS frames.
Server receives via `onSignalFrameBinary()` in agent.
