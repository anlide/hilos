# Table Subscriptions

How a table stays current without "jumping" under a live update stream: the
client subscribes to the specific rows its viewport shows, and every change hangs
as a pending update the user applies deliberately. Tables are the heavy durable
primitive; the lighter list and append-stream primitives are in [data-model.md](data-model.md).

A table belongs to exactly one page, and a page may host many tables (1:1 and
1:N; never N:M — see [data-model.md](data-model.md)). This document is about how that table's
rows are subscribed and kept in sync.

## The viewport descriptor

The client declares, per visible table, a **viewport descriptor**:

```
{ tableKey, filter, sort, offset, limit }
```

The server runs it and therefore knows that connection's concrete row-set — the
exact row-ids on screen. Subscriptions are scoped to that set, so a connection is
notified only about what it actually shows, not about every change to the table.

## What the server watches

For each connection's viewport, the server watches for three kinds of change:

- **row-updated** — a shown row's content changed;
- **row-removed** — a shown row was deleted or left the filtered set;
- **set-changed** — membership, total, or page count changed under the filter.

## Pending, never auto-applied

None of the three auto-applies. Each **hangs as pending** in the UI (badges, an
"Apply" affordance) so the table never rearranges itself under the user's hands.
The user applies pending changes deliberately.

### The pending taxonomy

- a shown row's content changed → a pending **update** on that row;
- a shown row was deleted → a pending **remove** on that row;
- a new row would enter the filtered set, or the total / page count changed → a
  pending **"list changed"** banner.

## Apply — in place, and it never moves the viewport

Applying pending changes mutates the **currently displayed** rows in place:

- **row-updated** → update the row;
- **row-removed** → replace the row with a **placeholder** in its slot — do not
  collapse the layout, and do not pull a replacement from the next page. The
  placeholder stays until the viewport changes.
- **set-changed** → refresh the count and banner only.

New rows that would enter the set appear only on the next explicit re-navigation
or re-filter. Applying pending **never** moves the viewport: no auto-jump, no
auto-backfill from adjacent pages.

## The viewport changes only by explicit user action

The descriptor's `filter`, `sort`, `offset`, and `limit` change **only** by an
explicit user action — filter, sort, paginate, or navigate. Conversely, any
explicit viewport change **applies all pending first** (the window is being
recomputed anyway). So pending accumulates passively and is resolved either by an
explicit Apply or by the user changing the window.

## Anchor by row-id, not offset

Anchor the window by row-id, not by a raw offset. A server-side deletion *above*
the window must not silently shift which rows a given offset returns; row-id
anchoring keeps the displayed window stable against changes outside it.

## A and A-lite

The model above is **option A**: the server computes scoped deltas per connection
from the viewport descriptor it holds. Start with **A-lite**: the server tracks
the same descriptor but, on a relevant change, sends a lightweight "viewport may
have changed" **nudge**; the client re-fetches its viewport and computes pending
locally. A-lite is less server work and still scoped (no fan-out to all
connections), and it upgrades to full server-computed deltas later without
changing the client's pending model.

## Custom filters and search-as-filter

- **Custom filters are extensible.** A table may plug in arbitrary filter inputs
  that resolve to a backend filter — filters are not a fixed set.
- **Search is a filter, not a framework feature.** There is no framework page
  search or command palette. Searching a table is one of its filters: a keystroke
  is an explicit viewport change → loading → the backend returns updated rows, the
  same path as any sort or paginate (debounce is an implementation detail).

## Headless table state machine

Table logic — the viewport descriptor, pending accumulation, and Apply — is a
**headless state machine** in the agnostic core, with a thin per-framework view
on top ([multiframework-core.md](multiframework-core.md)). The view renders rows and badges and emits
user intents; it holds no table logic of its own.

## Backend contract surface (the gate)

Per-connection viewport tracking is a backend change that passes the Contract
approval gate in [agents.md](../../../agents.md): the subscription registry holds
each connection's `{ tableKey, filter, sort, offset, limit }` and emits the
row-scoped `row-updated` / `row-removed` / `set-changed` deltas (full A), or the
viewport-changed nudge (A-lite). This folds in the table-system rework tracked in
the broader refactor.
