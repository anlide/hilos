# Table Subscriptions

How a table stays current without "jumping" under a live update stream: the
client subscribes to the specific rows its viewport shows, and a change to a
shown row hangs as a pending update the user applies deliberately. Counts and
new-row appends update live — they never rearrange the rows on screen. Tables are
the heavy durable primitive; the lighter list and append-stream primitives are in
[data-model.md](data-model.md).

A table belongs to exactly one page, and a page may host many tables (1:1 and
1:N; never N:M — see [data-model.md](data-model.md)). This document is about how that table's
rows are subscribed and kept in sync.

## The viewport descriptor

The client declares, per visible table (addressed by its `page` and `tableKey`),
a **viewport descriptor**:

```
{ tableKey, filter, sort, offset, limit }
```

The server runs it and therefore knows that connection's concrete row-set — the
exact row-ids on screen. Subscriptions are scoped to that set, so a connection is
notified only about what it actually shows, not about every change to the table.

## What the server watches

For each connection's viewport, the server compares every source change against
the exact row-set it delivered to that connection and emits one of:

- **row-updated** — a shown row's content changed;
- **row-removed** — a shown row was deleted (or left the filtered set);
- **count-change** — the total or page count changed under the filter, but no
  shown row did;
- **append** — a new row entered the set while the viewport sits on the last
  page with room, so the row is added at the tail.

The first two are scoped to the rows on screen; the last two are navigation
events that do not disturb the displayed rows.

## Pending vs live

Changes split by whether they touch the rows on screen:

- **row-updated and row-removed hang as pending.** Neither auto-applies; each
  shows as a badge with an "Apply" affordance, so the table never rearranges
  itself under the user's hands. The user applies them deliberately.
- **count-change and append apply live.** A count-change updates the pager
  (total and page count) at once; an append adds the new row at the tail of a
  last-page-with-room viewport at once. Neither moves or reorders the rows
  already shown, so neither needs the pending gate.

### The pending taxonomy

- a shown row's content changed → a pending **update** on that row;
- a shown row was deleted → a pending **remove** on that row.

A new row or a count change is **not** pending and there is **no** "list changed"
banner: a count change is a live pager update, and a qualifying new row is a live
tail append.

## Apply — in place, and it never moves the viewport

Applying pending changes mutates the **currently displayed** rows in place:

- **row-updated** → update the row;
- **row-removed** → replace the row with a **placeholder** in its slot — do not
  collapse the layout, and do not pull a replacement from the next page. The
  placeholder stays until the viewport changes.

Apply resolves only pending row-updates and row-removes; counts and appends are
already live. Applying pending **never** moves the viewport: no auto-jump, no
auto-backfill from adjacent pages, no sorted-position insert. A new row reaches
the displayed window only as a live tail append (last page with room) or on the
next explicit re-navigation or re-filter.

## The viewport changes only by explicit user action

The descriptor's `filter`, `sort`, `offset`, and `limit` change **only** by an
explicit user action — filter, sort, paginate, or navigate. Conversely, any
explicit viewport change **applies all pending first** (the window is being
recomputed anyway). So pending accumulates passively and is resolved either by an
explicit Apply or by the user changing the window.

## Anchor by row-id, not offset — the frozen viewport

Anchor the window by row-id, not by a raw offset. A server-side deletion *above*
the window must not silently shift which rows a given offset returns; row-id
anchoring keeps the displayed window stable against changes outside it.

This is the **frozen-viewport** rule: a live change never reorders the rows on
screen and never pulls in a shift-in row from another page. Only an explicit
viewport change recomputes the window; the sole live additions are a tail append
and the pager count.

## Server-computed deltas (option A)

The server holds each connection's viewport descriptor, the exact row-id keys it
last delivered (the rows really on screen), and the filtered total. On a source
change it diffs the changed row **point-wise** against that remembered set — in
the set → `row-updated` / `row-removed`; a membership or count shift with no shown
row touched → `count-change`; a qualifying new row → `append`. It does not
re-query the whole window on the live path, and it does not fan out to connections
that do not show the affected row.

The full window snapshot is sent **only** in reply to a viewport request — a
window change, cold load, or reconnect — never on the live stream. The delta,
count, and append signals are each addressed to the one connection they concern.

## Custom filters and search-as-filter

- **Custom filters are extensible.** A table may plug in arbitrary filter inputs
  that resolve to a backend filter — filters are not a fixed set.
- **Search is a filter, not a framework feature.** There is no framework page
  search or command palette. Searching a table is one of its filters: a keystroke
  is an explicit viewport change → loading → the backend returns updated rows, the
  same path as any sort or paginate (debounce is an implementation detail).

## Headless table state machine

Table logic — the viewport descriptor, pending accumulation and Apply, and live
count/append ingestion — is a **headless state machine** in the agnostic core,
with a thin per-framework view on top ([multiframework-core.md](multiframework-core.md)). The view
renders rows and badges and emits user intents; it holds no table logic of its
own.

## Stable selectors (data-id)

The view exposes stable `data-id` selectors for e2e (Playwright's
`testIdAttribute` is `data-id`). The canonical names:

- the table **root** container is `hilos-viewport-table`;
- the cells and controls **inside** it keep the `hilos-table-*` prefix —
  `hilos-table-search`, `hilos-table-row-<rowKey>`, `hilos-table-sort-<key>`,
  `hilos-table-apply`, `hilos-table-pending`, `hilos-table-placeholder`,
  `hilos-table-loading`, `hilos-table-count`, `hilos-table-page`,
  `hilos-table-prev`, `hilos-table-next`.

The `hilos-table-*` cell prefix is the table's own internal naming, not a
leftover: there is **no** `hilos-table` root selector. Select the table by
`hilos-viewport-table`; a test that still selects `hilos-table` is stale.

## Backend contract surface (the gate)

Per-connection viewport tracking is a backend change that passed the Contract
approval gate in [agents.md](../../../agents.md). The subscription registry holds each
connection's descriptor (`{ tableKey, filter, sort, offset, limit }`) plus the
row-id keys it delivered, and emits — addressed to that connection — the
`table_window` snapshot in reply to a viewport request, and on the live stream
`table_viewport_delta` (`row_updated` / `row_removed`), `table_viewport_count`,
and `table_viewport_append`.

## Current implementation status

The viewport model above is shipped (full **option A**) across the framework and
all three view layers; the pre-viewport client-side path was removed.

- **Backend.** `SubscriptionRegistry` holds a per-connection
  `TableViewportSubscription` (immutable descriptor + the delivered row-id keys +
  the filtered total). `TableDefinition::getPage()` serves a window; `ViewportTable`
  — refined by `SelfSnapshotTable` for catalog tables like settings — is the table
  contract. `BrowserContext` replies with the `table_window` snapshot and emits the
  addressed `table_viewport_delta` / `table_viewport_count` / `table_viewport_append`
  signals; a viewport table no longer flows through the `page_response` fan-out
  (lists and data still do).
- **Frontend.** The headless `TableViewportController` (`@hilos/core`) owns the
  descriptor, pending/Apply, and live count/append ingestion; `bindTableViewport`
  routes the four server signals into it; `HilosViewportTable` is the thin view in
  `@hilos/vue`, `@hilos/react`, and `@hilos/angular`. The old client-side
  `TableController` / `HilosTable` are gone.
- **In use.** The framework settings and hilos-users tables run on the viewport
  path in all three demo frameworks.

**Deferred (in scope, not yet distinguished):** `left_set` — a shown row that
stops matching the filter is carried with that reason on `row_removed`, but the
backend does not yet detect it as a distinct case, so such a row currently stays
a `row_updated`. The pager count remains correct.
</content>
</invoke>
