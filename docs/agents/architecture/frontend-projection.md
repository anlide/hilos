# Browser Source Projection

Read this before changing DB/RT sync fan-out into browser payloads,
`BrowserContext`, `SourceChange`, worker-local subscription mirrors, or the
legacy projection context.

## Core Rule

Page-shaped DB/RT browser state belongs to `Hilos::$browser`
(`Hilos\Core\Browser\Context\BrowserContext`). The legacy
`Hilos::$projection` context may still consume the same `SourceChange` facts
for project-wide broadcasts or old projection-backed snapshots, but new page
state should not be added through `PageProjection`.

## Source Flow

Each worker owns its own browser context. The daemon does not keep browser
state and does not decide which frontend collections changed.

1. Local code writes DB or RT through the normal collection/actions layer.
2. DB_SYNC/RT_SYNC is queued.
3. The worker turns the sync payload into `SourceChange` via its browser
   source-change recording path.
4. The worker records the source fact in `Hilos::$browser` and, while the
   legacy context exists, also in `Hilos::$projection`.
5. The daemon applies/broadcasts the sync to workers.
6. Every other worker applies the sync and records the same source fact in its
   own browser/projection consumers.

The originating worker receives its daemon echo too, but it consumes the
self-broadcast marker and does not record the fact a second time.

## Delivery

Browser/projection flush happens in the worker after queued DB/RT sync messages
are sent. The worker then drains the ordinary router queue again so flush output
is delivered in the same tick.

`Hilos::$browser->flushToSignalRouter()` emits page-shaped
`BrowserPageSignalData` payloads addressed to local accept keys subscribed to
matching pages. The daemon routes each addressed `WS_USER` frame to that exact
accept key.

`Hilos::$projection->flushToSignalRouter()` is still available for legacy
projection users and project-wide broadcasts such as chat events or agent
presence. Do not move those broadcasts into `BrowserContext` unless the payload
is actually page-shaped browser state.

This worker-local fan-out prevents duplicate frontend broadcasts: a connection
is served by one worker, and each worker targets only accept keys present in
its local subscription mirror.

## Subscription Mirror

The daemon still owns the global subscription registry for WebSocket routing.
Workers keep a local mirror for browser/projection decisions:

- page subscribe stores page and params by accept key;
- page update merges params;
- page unsubscribe and connection close remove local entries;
- group subscriptions are mirrored for completeness.

Browser/projection code should use this local router mirror, for example
`Hilos::$sr->getPageSubscriptions()` or `Hilos::$sr->getAcceptKeysForPage()`.
It must not query daemon state directly.

## Page Snapshots

The default `AbstractPage::onSubscribe()` delegates to both layers:

- `Hilos::$projection?->subscribeSnapshot(...)` for legacy projection-backed
  pages;
- `Hilos::$browser?->subscribeSnapshot(...)` for current page-shaped browser
  payloads.

Pages override `onSubscribe()` only when they need domain or routing parameter
checks before or instead of delegating to these layers.

## Browser Tables

Browser table configs declare the DB/RT sources that shape a page row. A source
fact can trigger a `BrowserPageSignalData` row update or delete when the
subscribed page includes a table observing that source.

Screen-specific table rows may still live on concrete table definitions or
browser table config classes. Declare every DB/RT source that materially
changes the browser row so a source fact never has to be bridged by imperative
agent/page fan-out.

The separate `table_mutation` transport remains server-authoritative immediate
table state. Use it for table-store mutations, not for new page-shaped browser
payloads.

## Source Change DTO

`Hilos\Core\Projection\SourceChange` is the shared source-fact carrier used by
browser and legacy projection consumers:

- `kind` — `KIND_DB` or `KIND_RT`;
- `sourceKey` — DB or RT collection key;
- `sourceId` — row id or runtime state id, serialized as `string`;
- `mutationType` — `TableMutationType` (`Create`, `Update`, `Delete`);
- `row` — full row on create, diff on update, previous row on delete when
  available.

`SourceChange` extends `BaseDTO`, so it is also serializable for worker/daemon
transport payloads that need to carry a source fact.

## Rules

- Do not build browser fan-out in agents/pages when the source state already
  changes DB or RT.
- Do not add new page-shaped DB/RT browser state through `PageProjection`; use
  `BrowserContext` and page/table `BROWSER` config.
- Do not put browser or projection state in the daemon.
- Remote DB_SYNC/RT_SYNC must invalidate the receiving worker's browser source
  state.
- Self-broadcast DB_SYNC/RT_SYNC echoes must not invalidate browser/projection
  consumers again.
- If a delete projection needs fields such as `userId`, include the previous
  row in the delete sync payload before removing the DB/RT item.
- Keep global, non-page broadcasts separate from page-shaped browser payloads.
