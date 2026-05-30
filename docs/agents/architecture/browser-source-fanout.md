# Browser Source Fan-Out

Read this before changing DB/RT sync fan-out into browser payloads,
`BrowserContext`, `SourceChange`, or worker-local subscription mirrors.

## Core Rule

Page-shaped DB/RT browser state belongs to `Hilos::$browser`
(`Hilos\Core\Browser\Context\BrowserContext`). Do not use browser fan-out as a
project-wide event bus: declare the concrete page/table contract that owns the
state, or use a typed frontend state payload when the value is not page-shaped.

## Source Flow

Each worker owns its own browser context. The daemon does not keep browser
state and does not decide which frontend collections changed.

1. Local code writes DB or RT through the normal collection/actions layer.
2. DB_SYNC/RT_SYNC is queued.
3. The worker turns the sync payload into `SourceChange` via its browser
   source-change recording path.
4. The worker records the source fact in `Hilos::$browser`.
5. The daemon applies/broadcasts the sync to workers.
6. Every other worker applies the sync and records the same source fact in its
   own browser context.

The originating worker receives its daemon echo too, but it consumes the
self-broadcast marker and does not record the fact a second time.

## Delivery

Browser flush happens in the worker after queued DB/RT sync messages are sent.
The worker then drains the ordinary router queue again so flush output is
delivered in the same tick.

`Hilos::$browser->flushToSignalRouter()` emits page-shaped
`BrowserPageSignalData` payloads addressed to local accept keys subscribed to
matching pages. The daemon routes each addressed `WS_USER` frame to that exact
accept key.

This worker-local fan-out prevents duplicate frontend broadcasts: a connection
is served by one worker, and each worker targets only accept keys present in
its local subscription mirror.

## Subscription Mirror

The daemon still owns the global subscription registry for WebSocket routing.
Workers keep a local mirror for browser decisions:

- page subscribe stores page and params by accept key;
- page update merges params;
- page unsubscribe and connection close remove local entries;
- group subscriptions are mirrored for completeness.

Browser fan-out code should use this local router mirror, for example
`Hilos::$sr->getPageSubscriptions()` or `Hilos::$sr->getAcceptKeysForPage()`.
It must not query daemon state directly.

## Page Snapshots

The default `AbstractPage::onSubscribe()` delegates to
`Hilos::$browser?->subscribeSnapshot(...)` for page-shaped browser payloads.

Prefer leaving that default in place. Override `onSubscribe()` when the page
needs route-param validation, domain checks, or specialized subscribe behavior
(for example a custom snapshot that is not browser-table shaped). After
validation, call `parent::onSubscribe()` so `subscribeSnapshot()` still owns
page-shaped payloads.

As a convention, avoid overriding `onSubscribe()` only to send an empty
subscription ack via `sendToUser()` with blank `SignalData` or
`BrowserPageSignalData`. Hub pages without `PAGE_TABLES` normally send no
initial snapshot, and that is fine.

## Browser Tables

Browser table configs declare the DB/RT sources that shape a page row. A source
fact can trigger a `BrowserPageSignalData` row update or delete when the
subscribed page includes a table observing that source.

Register browser-only table config classes in `Hilos::BROWSER_TABLES` and bind
them to pages through `Hilos::PAGE_TABLES`; see
[app-topology.md](../app-topology.md). Project browser contexts should resolve
those registries instead of owning local page or table lists.

Screen-specific table rows may still live on concrete table definitions or
browser table config classes. Declare every DB/RT source that materially
changes the browser row so a source fact never has to be bridged by imperative
agent/page fan-out.

The separate `table_mutation` transport remains server-authoritative immediate
table state. Use it for table-store mutations, not for new page-shaped browser
payloads.

## Source Change DTO

`Hilos\Core\Source\SourceChange` is the source-fact carrier used by browser
fan-out:

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
- Use `BrowserContext` and page/table `BROWSER` config for page-shaped DB/RT
  browser state.
- Do not put browser state in the daemon.
- Remote DB_SYNC/RT_SYNC must invalidate the receiving worker's browser source
  state.
- Self-broadcast DB_SYNC/RT_SYNC echoes must not invalidate browser consumers
  again.
- If a delete browser row needs fields such as `userId`, include the previous
  row in the delete sync payload before removing the DB/RT item.
- Keep global, non-page broadcasts separate from page-shaped browser payloads.
