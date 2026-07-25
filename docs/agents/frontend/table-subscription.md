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

## By-design exceptions to the gate

The gate freezes the **rows on screen** — their position and membership — not
always the **value inside a cell**. Four changes are deliberately not gated:

- **Own changes apply at once.** The tab that made an edit picks up its own echo
  immediately instead of queuing it — only *other* tabs see the gate. The core
  marks the row before the action (`expectOwnChange`) and applies the echo in
  place when it returns (`applyOwnDelta`); a failed action drops the mark.
- **An entity-backed cell tracks its edit reactively.** When a row resolves from
  an entity reference — most tables (a piece, a user); settings is the exception,
  its value is inline in the row payload — the cell re-renders the edited value as
  soon as the entity updates, *before* Apply. The gate still holds the row's place
  and the badge still waits, because it is the position and membership that are
  frozen, not the entity's fields; Apply then only clears the badge and the tint.
- **Opening an edit / delete dialog applies pending first** (`applyAndResolve`),
  so the dialog edits the latest committed state, never a stale value — the same
  reasoning as an explicit viewport change, which is authoritative and also
  discards or applies pending.
- **A backend-declared live change applies at once, for everybody.** A row that
  reports *work in progress* — the backup page's in-progress row is the worked
  example — is a status the table shows about an operation, not content the reader
  is studying. The backend marks its mutations live (`TableRowMutationDTO::$live`,
  wire field `live`), and the controller applies them immediately: an update lands
  in place, and a removal takes the row out of the window rather than leaving a
  placeholder, because a status that ended has nothing to hold a place for. Do not
  reach for this to dodge the gate on ordinary data — a row carrying content the
  user reads stays gated, and that is the whole point of the gate.
- **A tail append and a count change are live** (see *Pending vs live* above):
  they add a row at the tail of a last-page-with-room window or update the pager,
  disturbing nothing already shown.

### Rule — what may bypass the Apply gate

The gate exists for one reason: a table must never rearrange itself while someone
is reading it. Everything else follows from that, so **the default is gated** and
each exception has to earn its place.

Apply the test in this order to a row change you are adding:

1. **Is it content the user reads?** Then it is gated. Always — including a row
   your own feature just wrote on the server. "It feels laggy behind Apply" is not
   a reason; the badge *is* the feature.
2. **Did this connection ask for it?** Then it applies at once for that connection
   only (`expectOwnChange`), and stays gated for every other tab. Note the
   mechanism's limit: correlation is by a row key the client knew **before** the
   action, so it does not cover a create whose key the server mints.
3. **Is it a new row or a count?** Then it is already live by taxonomy — an append
   or a count update, never pending.
4. **Is it a report about work rather than data — a progress row, a live status?**
   Only then may the backend declare it live (`TableRowMutationDTO::$live`).

The fourth case is deliberately rare. In the whole framework it has exactly one
user today: the backup page's in-progress row. Before adding a second, all of
these must hold — if any fails, the answer is one of the first three cases, not
this one:

- the row **reports an operation** and disappears when the operation ends;
- it carries **nothing the user could lose** by having it vanish (no edits, no
  content, nothing worth a placeholder);
- it has a **fixed synthetic row key** (`__running__`-style), so it can never
  collide with a data row or be mistaken for one;
- the same feature's **real data rows stay gated** — declaring an entire table
  live is always wrong.

The failure this rule prevents is specific and was seen in the backup page: the
progress row's *arrival* was live (an append) while its *removal* was a pending
delta, so "In progress" stayed on screen after the run had finished, next to the
finished row, with an Apply badge for a change the user never made. A status row
whose two halves disagree about the gate is worse than either choice alone.

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
