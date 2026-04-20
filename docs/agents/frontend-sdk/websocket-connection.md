# Frontend SDK: WebSocket Connection

The frontend connects to the backend via a single persistent WebSocket.

## Connection lifecycle

1. Frontend opens WS connection to `WEBSOCKET_PORT` (default 8092)
2. Server accepts → assigns `acceptKey` (unique connection identifier)
3. Frontend sends **handshake** with session token
4. Server validates token → `onSignalHandshake()` in agent
5. Agent sends `HANDSHAKE_RESPONSE` back with initial data
6. Frontend subscribes to a page: sends `PAGE_SUBSCRIBE { page, params }`
7. Server routes to page agent → `onSubscribe()` → agent sends initial page data
8. Normal bidirectional signal exchange

## Message format

All WS messages are JSON:
```json
{ "type": "signal_name", "data": { ... } }
```

## Auto-reconnect

The Vue SDK `WebSocketService` handles reconnection automatically on disconnect.
After reconnect: re-handshake + re-subscribe to current page.

## acceptKey

`acceptKey` is the server-assigned WS connection identifier:
- Used to route signals to a specific connection
- Used as the `Connection` runtime state ID
- Generated on handshake, stable for the connection lifetime

## Vue plugin

```ts
// main.ts
app.use(webSocketPlugin, { url: import.meta.env.VITE_WS_URL })
```

Access in components:
```ts
const ws = inject('webSocket') as WebSocketService
ws.send('message', { text: 'hello' })
ws.on('new_event', (data) => { ... })
```
