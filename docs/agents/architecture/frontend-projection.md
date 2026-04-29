# Frontend Projection

Frontend projection is the worker-local layer that turns backend source facts
into frontend wire payloads.

## Boundary

Each worker owns its own `Hilos::$frontend` projection context. The daemon does
not keep frontend projection state and does not decide which frontend
collections changed.

The input to the projection is DB/RT sync:

1. Local code writes DB or RT through the normal collection/actions layer.
2. DB_SYNC/RT_SYNC is queued.
3. The worker records that sync fact in its local frontend projection and sends
   the sync to the daemon.
4. The daemon applies/broadcasts the sync to workers.
5. Every other worker applies the sync and records the same fact in its own
   frontend projection.

The originating worker receives its daemon echo too, but it consumes the
self-broadcast marker and does not record the fact a second time.

## Delivery

Projection flush happens in the worker after queued DB/RT sync messages are
sent. The projection produces already-addressed WebSocket deliveries:

- signal name, for example `new_event` or `table_mutation`;
- payload DTO;
- target `acceptKey`.

The worker sends these deliveries to the daemon as normal `WS_USER` signals.
The daemon only routes the frame to that exact accept key.

This is why duplicate frontend broadcasts do not happen: a connection is served
by one worker, and each worker targets only accept keys present in its own local
subscription mirror.

## Subscription Mirror

The daemon still owns the global subscription registry for WebSocket routing.
Workers keep a local mirror only for projection decisions:

- page subscribe stores page and params by accept key;
- page update merges params;
- page unsubscribe and connection close remove local entries;
- group subscriptions are mirrored for completeness.

Projection code should use this local router mirror to find interested accept
keys. It must not query daemon state directly.

## Tables

Tables are projection targets. A DB/RT source fact can produce a table row
mutation through `Hilos::$table`.

For example, an RT connection create/delete changes a user's presence and
online session count. The source fact is `RtChatContext::connections`, but the
table mutation is for user-backed tables such as `adminUsers` and `hilosUsers`.

The current table delivery policy is server-authoritative immediate
`table_mutation` to local subscribers. `table_mutation_pending` is not the
default projection mechanism.

## Rules

- Do not build frontend fan-out in agents/pages when the source state already
  changes DB or RT.
- Do not put projection state in the daemon.
- Remote DB_SYNC/RT_SYNC must invalidate the receiving worker projection.
- Self-broadcast DB_SYNC/RT_SYNC echoes must not invalidate projection again.
- If a delete projection needs fields such as `userId`, include the previous row
  in the delete sync payload before removing the DB/RT item.
