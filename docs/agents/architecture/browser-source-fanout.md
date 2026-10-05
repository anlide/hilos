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

The originating worker receives its daemon echo too, but it recognizes its own
broadcast and does not record the fact a second time. Every DB sync payload
carries the `emitter` identity of the process that broadcast it, compared with
the receiving process's own. A collection clear carries no row id and is
recognized by that identity alone; a row sync needs the pair — the stamp says
whose fact it is, and only then is the `(collectionKey, id)` self-broadcast
marker consumed. An unstamped payload counts as someone else's, so a fact that
cannot be attributed is applied rather than swallowed.

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

`AbstractPage::onSubscribe()` answers a subscription with one `page_response`
(HIL-1236). It builds the page's own part first (`buildPagePayload()`), hides it
from a viewer of the admin view mode and lays the catalog identity under it
(`withPageIdentity()`), then asks
`Hilos::$browser?->buildSubscribeSnapshot(...)` for the page-shaped browser
part — built and returned, nothing sent; the windows of the page's viewport
tables are registered on the way — and sends the own part laid over it
(`PagePayload::over()`). The browser part is not hidden a second time: its rows
come hidden column by column already, and hiding it as a section of the page
would leave a viewer whole tables of hidden marks. The counts beside each
window's filters follow the answer (`sendSnapshotFacetCounts()`). The method
is `final`, so the one frame cannot be lost by an override that forgets
`parent::`.

A page that needs route-param validation, domain checks, or specialized
subscribe behavior (for example a custom snapshot that is not browser-table
shaped) puts that work in `onSubscribeBeforeResponse()`, which runs ahead of the
answer; work that may only run once the subscription is answered goes in
`onSubscribeAfterResponse()`. The access checks ride on neither:
`PageSignalRouter` reaches the whole verdict — level, freeze, declared params,
declared guards — before `onSubscribe()` runs at all.

A page whose delivery failed is re-sent the same way. The client that was told
its page could not be delivered wiped it, so the first delivery that succeeds
afterwards — a fan-out to the subscription or a window it asks for — calls
`resendWholePage()`, and that asks the worker (`PageResender`, bound by
`WorkerManager::run()`) to run the subscribe frame again on the agent its
subscription mirror names: `PageSignalRouter::resendPage()`, the verdict and
`onSubscribe()`, under that agent and that connection, inside the same flush.
It is not a re-subscribe: nothing is booked, and an empty report of windows
hands each table the window the mirror is holding. The worker writes the
serving agent into its mirror on a subscribe and on a re-decision of rights.
What came back decides the mark: answered — cleared; refused — no mark, the
subscription stands as a refused one does; failed — the error frame went out
from the router, so the mark is set silently; unserved — nothing went out, so
the connection is told its page could not be delivered. What tries the debt of
a page owed whole, and what else sets it, is in
[Coming Back Without A Reload](#coming-back-without-a-reload) below.

As a convention, do not use either hook only to send an empty subscription ack
via `sendToUser()` with blank `SignalData` or `BrowserPageSignalData`. Hub pages
without `PAGE_TABLES` normally carry no browser part, and their answer is the
page's own part alone.

## Browser Tables

Browser table configs declare the DB/RT sources that shape a page row. A source
fact can trigger a `BrowserPageSignalData` row update, delete, or a whole-table
clear when the subscribed page includes a table observing that source.

Register browser-only table config classes in `Hilos::BROWSER_TABLES` and bind
them to pages through `Hilos::PAGE_TABLES`; see
[app-topology.md](../app-topology.md). Project browser contexts should resolve
those registries instead of owning local page or table lists.

Screen-specific table rows may still live on concrete table definitions or
browser table config classes. Declare every DB/RT source that materially
changes the browser row so a source fact never has to be bridged by imperative
agent/page fan-out.

A source declaration that names no collection, or names one of an unknown kind,
is a mistake in the config and is refused with `PageInternalErrorException` — on
subscribe it reaches the client as a 500 `subscription_page_error`, and in the
reactive fan-out it is logged and skips that one subscription. A table with a
window is the exception: whatever its live road throws freezes that one window
instead — a line in the log and one `table_viewport_frozen` frame to its
connection, the whole window on the next delivery that succeeds — and the page
around it stays live (HIL-1139). The window remains owed whole until a fact pays
the debt — see [Coming Back Without A Reload](#coming-back-without-a-reload)
below. It used to be
read as "this source is currently empty", which dropped the row fragment (or the
whole collection) with nothing said, so a mistyped `KEY` looked exactly like a
page whose data had not arrived yet. An unknown collection under a well-formed
declaration is a different thing and stays silent: the project may mount it
later, and the fan-out treats it as nothing to deliver yet.

A row config may name the fields of its row a viewer of the admin view mode is
shown in the `notPersonal` key (`BrowserFieldKey::NOT_PERSONAL`, a list of wire
names) — fields of an RT source, `computed` ones, and fields of a DB item that
are not columns (an overlay, a property its object computes; P-443). A field a
DB source reads out of a column is judged by that column's verdict, never by
this key: the start refuses `notPersonal` naming one once the collections are
mounted (`TopologyValidator::validateReferences()`), and at run time the column
keeps its verdict even where the check did not run. The row is hidden for a
viewer once it is whole, after the VIA joins have read the real values
([admin-view-mode.md](admin-view-mode.md), *Personal Fields On The Wire*).

The separate `table_mutation` transport remains server-authoritative immediate
table state. Use it for table-store mutations, not for new page-shaped browser
payloads.

## Coming Back Without A Reload

A table or page that could not reach the tab comes back by itself when the
cause is gone: no F5, no button, no timer. One rule covers every failure
(owner's decision, HIL-1151, 2026-10-04): the server remembers that this
connection is owed a window or a page whole, and facts try that debt. The
reader's actions — changing the window, reconnecting, reloading — keep their
own meaning and are outside this rule.

### What Is Owed

A **window owed whole** is one whose live road froze, with
`table_viewport_frozen` (HIL-1139). A refused window is owed whole too, whether
the refusal arrived as `table_window_refused` in reply to a request or in the
`refusedWindows` section of `page_response` (not in the code yet — HIL-1350).

A **page owed whole** is one whose delivery failed and whose connection was
told `subscription_page_error`. An internal error on the subscription's very
first answer sets the same debt (not in the code yet — HIL-1351). A page's
verdict refusing rights or a missing resource is not a debt: it comes back by
[Preserve-on-fail and live-promotion](page-access-control.md#preserve-on-fail-and-live-promotion)
when its guard starts passing.

There is one debt per window and one per page subscription, whoever set it:
a window that froze and was then refused is still one window owed whole
(not in the code yet — HIL-1350). What the tab was told decides only what it
shows — rows under "not updated since" or the "List unavailable" tile — and
never what the server owes.

A debt lives and dies with what is owed. Remove it where the window or page
subscription is removed: leaving the page, closing the connection, or
re-subscribing. A page owed whole carries its windows' debts: its answer
accounts for each table in `windows` or `refusedWindows`. A window in `windows`
pays its debt; an entry in `refusedWindows` sets it again
(not in the code yet — HIL-1350).

### What A Debt Is Paid With

Use the existing frames; there is no new frame and HIL-943's refusal contract
does not change. A window is paid with a `table_window` of the reply's shape,
followed by its facet counts; the tab lays it down as it does after a broken
socket — see the three roads in
[Backend contract surface](../frontend/table-subscription.md#backend-contract-surface-the-gate).
A page is paid with the same one `page_response` that answers a subscription,
re-sent on its serving agent (`PageResender` → `PageSignalRouter::resendPage()`;
see [Page Snapshots](#page-snapshots)).

Any window pays the window's debt. A refusal never pays it: it changes the
visible state to the tile, and the debt stands
(not in the code yet — HIL-1350).

While a window is owed whole, send it no live frame: no `table_viewport_delta`
of any kind, including `row_stale`; no `table_viewport_count`,
`table_viewport_append`, `table_viewport_own_create`, `table_viewport_announce`,
or `table_viewport_unannounce`; no second `table_viewport_frozen`. This already
holds for a frozen window. It holds for a refused window too, and a refused
window is not told it froze (not in the code yet — HIL-1350). The tab also
drops these frames; that is a defense, not the rule's enforcement.

### The Facts That Try A Debt

1. A change of the table's own source that the table built for this window
   without a throw proves that its road is back. It tries a frozen window's
   debt today (HIL-1139), and a refused window's debt too
   (not in the code yet — HIL-1350). For a page owed whole, the fact is the
   first successful delivery to it: a fan-out to the subscription or a window
   it asked for.
2. The database came back: the worker's first successful query after it lost
   its database connection tries all that worker's debts — windows and pages
   — once each, in the next flush (not in the code yet — HIL-1352). The fact
   stays inside the worker that saw it; it is not announced to other
   processes, each of which has its own connection and debts (owner's
   decision, 2026-10-04) (not in the code yet — HIL-1352).
   The table that needs this fact is step-up on `/hilos/security/2fa`: it has
   no sort, search, or filter, and its live source — the step-up setting keys
   — almost never changes. After a database failure, it used to have only F5.
3. Reserved, not built: the table's holder came up. When the
   [table agent](table-agents.md) exists, its start joins this list as another
   fact; the rule needs no rewrite.

No timer, no probe, no repeat button: a fact, not a clock (HIL-943 Design).

### One Try

A fact tries a debt once. A try that succeeds sends the whole window or page
and pays the debt; a try that fails sends nothing, not even a refusal, and
the debt waits for the next fact.

A try that fails again writes no new log line: the line was written when the
debt was set (not in the code yet — HIL-1350). Set aside a window that could
not be built for the rest of that flush; this already holds for a frozen
window. The server starts the try. The tab never asks again on its own.

### The Two Cases That Still Wait

Both cases were accepted by the owner on 2026-10-04 and are recorded here;
they are also recorded in the database fact's docblock
(not in the code yet — HIL-1352).

1. A worker on which nothing happens after the database comes back learns of
   it only on its first query. Its debts wait until then. No timer probes the
   database: that would be a clock, not a fact.
2. A table whose query timed out on a live, overloaded database, and whose
   data does not change afterwards, waits for a change or the reader. A
   timeout is not a lost connection and does not trigger fact 2. A timeout
   caused by a lock is paid by the lock holder's commit: that commit is a
   source change, fact 1.

Removing the stand's `test:table:refuse` lever is not a fact either. On the
stand, a change of the table's source made after removing the lever brings
the window back (not in the code yet — HIL-1350).

## Source Change DTO

`Hilos\Core\Source\SourceChange` is the source-fact carrier used by browser
fan-out:

- `kind` — `KIND_DB` or `KIND_RT`;
- `sourceKey` — DB or RT collection key;
- `sourceId` — row id or runtime state id, serialized as `string`; empty on a
  collection-scoped clear;
- `mutationType` — `TableMutationType` (`Create`, `Update`, `Delete`, `Clear`);
- `row` — full row on create, diff on update, previous row on delete when
  available, empty on clear.
- `previous` — previous values of the fields carried by an update, empty for
  other mutation types.

The `row` and `previous` payloads arrive in two vocabularies: database mutations
on create and update carry entity columns (`user_id`), database deletes carry the
object representation (`Object::toArray()`), and runtime mutations carry state
property names. A list or table declaration names fields using object names
(`Session::userId`). The fan-out matches a declared field — whether a row key or
a trigger — under either name, using the same correspondence that reads joins
(`Objects::columnForField()`).

An update can move a joined item from one logical browser row to another. Fan-out
rebuilds both the current and previous row keys, while a list anchored to the
subscribing connection considers only its anchor key and emits neither rows nor
deletes for another subscriber's key.

`Clear` is collection-scoped, not row-scoped: it carries the `sourceKey` with an
empty `sourceId`/`row` and means "every row in this collection was removed". It
is queued by the framework `Objects::deleteAll()` (signal `DB_SYNC_CLEARED`,
DTO `DbSyncClearedSignalData`), so any DB collection's bulk truncate fans out
without per-project wiring. A table observing the cleared source emits a
`cleared: true` marker in its list/table section; the frontend truncates the
list/table before applying any rows delivered in the same payload. Unlike
create/update/delete, a clear is browser-only and is not dispatched to agents.

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
- Fan-out closes only what a page declared as its contract; for a fact outside
  it that still invalidates an open screen, see
  [../signals/screen-invalidation.md](../signals/screen-invalidation.md).
- Do not bring back a failed table or page by a timer or by the tab asking
  again on its own: the server owes it whole, and a fact pays the debt — see
  [Coming Back Without A Reload](#coming-back-without-a-reload).
