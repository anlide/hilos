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

## Binary messages (file upload)

After `FILE_UPLOAD_READY` signal, frontend sends raw binary WS frames.
Server receives via `onSignalFrameBinary()` in agent.
