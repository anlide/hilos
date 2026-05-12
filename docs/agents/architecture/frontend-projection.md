# Frontend Projection

Frontend projection is the worker-local layer that turns backend source facts
into frontend wire payloads.

## Boundary

Each worker owns its own `Hilos::$projection` context (a
`Hilos\Core\Projection\ProjectionContext` subclass). The daemon does not keep
projection state and does not decide which frontend collections changed.

The input to the projection is DB/RT sync:

1. Local code writes DB or RT through the normal collection/actions layer.
2. DB_SYNC/RT_SYNC is queued.
3. The worker records that sync fact in its local projection through
   `Hilos::$projection->record(SourceChange)` and sends the sync to the daemon.
4. The daemon applies/broadcasts the sync to workers.
5. Every other worker applies the sync and records the same fact in its own
   projection.

The originating worker receives its daemon echo too, but it consumes the
self-broadcast marker and does not record the fact a second time.

## Delivery

Projection flush happens in the worker after queued DB/RT sync messages are
sent. `Hilos::$projection->flushToSignalRouter()` produces already-addressed
WebSocket deliveries (`Hilos\Core\Projection\ProjectionDelivery`):

- signal name, for example `new_event` or `table_mutation`;
- payload DTO implementing `SignalDataInterface`;
- target `acceptKey`.

The worker queues these deliveries as normal `WS_USER` signals. The daemon
routes each frame to that exact accept key.

This is why duplicate frontend broadcasts do not happen: a connection is served
by one worker, and each worker targets only accept keys present in its own
local subscription mirror.

## Subscription Mirror

The daemon still owns the global subscription registry for WebSocket routing.
Workers keep a local mirror only for projection decisions:

- page subscribe stores page and params by accept key;
- page update merges params;
- page unsubscribe and connection close remove local entries;
- group subscriptions are mirrored for completeness.

Projection code should use this local router mirror
(`Hilos::$sr->getAcceptKeysForPage()`) to find interested accept keys. It must
not query daemon state directly.

## Tables

Tables are projection targets. A DB/RT source fact can produce a table row
mutation through `Hilos::$table->buildMutationSignalsForSourceEvent(SourceChange, …)`.

A `TableDefinition` subclass may react to multiple sources (DB collections and
RT collections both): `TableDefinition::buildMutationForSourceEvent(SourceChange $change)`
inspects `$change->sourceKey` to decide which sources are observed.

For example, both `AdminUsersTable` and `HilosUsersTable` react to two sources:

- `ChatDbContext::users` — DB user create/update/delete projects directly to a
  user row mutation;
- `ChatRtContext::connections` — connection lifecycle flips the user's
  `onlineSessionCount` and `presence` summary projected into the same row, so
  the table emits an Update mutation for the affected user id.

The current table delivery policy is server-authoritative immediate
`table_mutation` to local subscribers. `table_mutation_pending` remains
available for explicit acting-user-driven flows.

## Subscribe Snapshot

The default `AbstractPage::onSubscribe()` delegates to
`Hilos::$projection->subscribeSnapshot(static::PAGE, $acceptKey, $params)`.
Per-page projection subclasses (planned: `Hilos\Core\Projection\PageProjection`)
build the initial snapshot payload and the framework queues it as a single
`WS_USER` signal to the subscribing accept key.

Pages override `onSubscribe()` only when they need domain or routing parameter
checks before (or instead of) delegating to the projection layer.

## Source Change DTO

`Hilos\Core\Projection\SourceChange` is the unified source-fact carrier:

- `kind` — `KIND_DB` or `KIND_RT`;
- `sourceKey` — DB or RT collection key;
- `sourceId` — row id or runtime state id (always serialized as `string`);
- `mutationType` — `TableMutationType` (Create/Update/Delete);
- `row` — full row on Create, diff on Update, previous row on Delete when
  available.

`SourceChange` extends `BaseDTO`, so it is also the serializable payload used
by `EmitDbChangeSignalData` on the worker-to-daemon transport.

## Rules

- Do not build frontend fan-out in agents/pages when the source state already
  changes DB or RT.
- Do not put projection state in the daemon.
- Remote DB_SYNC/RT_SYNC must invalidate the receiving worker projection.
- Self-broadcast DB_SYNC/RT_SYNC echoes must not invalidate projection again.
- If a delete projection needs fields such as `userId`, include the previous row
  in the delete sync payload before removing the DB/RT item.
- A `TableDefinition` should declare every DB/RT source that materially
  changes its row shape so that a single source fact never has to be bridged
  by imperative code in the projection layer.
