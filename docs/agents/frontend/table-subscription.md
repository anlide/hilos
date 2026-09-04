# Table Subscriptions

> **The rules below are the target, and the code is still catching up (not in
> the code yet — HIL-783).** Until that epic closes, a table in this repository
> may still behave the way this document no longer describes. What is still
> outstanding is on the epic's board and deliberately not listed here: the board
> stays current, a copy in a doc does not.

A table in Hilos does not load pages. It subscribes to the window of rows on
screen, and the data inside it changes on its own. Every rule below follows from
that, and none of them belong to an ordinary table.

A table belongs to exactly one page, and a page may host many tables (1:1 and
1:N; never N:M — see [data-model.md](data-model.md)). Tables are the heavy
durable primitive; the lighter list and append-stream primitives are in
[data-model.md](data-model.md).

What a table *looks* like is not here. The approved surface — cards on a narrow
screen, the four empty states, the highlight as a Bootstrap contextual class, the
appearance of the selection panel and of the progress bars — is the mockup
`hilos-ops/mockups/components/table/index.html`, and this document points at it
instead of restating it. Here: subscription mechanics, live changes, and the wire
contract.

## The rule everything follows from

**A table never moves on its own, and never stays quiet about having drifted
from the truth.**

The first half forbids the list to slide out from under the person reading it: no
row appears, disappears, or changes place while a reader is aiming at it, and
nothing scrolls the window but the reader. The second half forbids the state in
which a reload would show something other than the screen: when the live window
has drifted from the real set, the table says so itself.

Both halves are load-bearing, and each covers the other's failure. Freezing the
window without announcing the drift produces a screen that quietly lies; applying
everything as it arrives produces a list nobody can click. So **F5 is not a way
to learn the truth**: a reload shows exactly what the screen already showed, and
what the reader has not asked for stays announced rather than applied. Waiting
and reloading arrive at the same place.

Everything below — the Apply gate, the announcement bar, the taxonomy — is
derived from this one rule.

## The viewport descriptor

The client declares, per visible table (addressed by its `page` and `tableKey`),
a **viewport descriptor**:

```
{ page, tableKey, filter, sort, limit, anchor, anchorDirection }
```

`anchor` is an open map of the sort key's values, the primary key included;
`anchorDirection` is `after` or `before`; `anchor: null` asks for the start of
the set. There is **no** `offset`.

The window is taken by key — "give me twenty rows after this one" — and not by
offset — "skip two hundred thousand, give me twenty". A key window costs the same
at any depth and does not shift because somebody deleted a row above it. The
price is the page number and the exact total, and the count section below says
what the table shows instead.

**Every declared order ends with the primary key**, so the order is total.
Without that, a column with repeats (a status, a kind) lets two adjacent pages
show one row twice and another not at all — and the server cannot say where an
arriving row falls relative to the window, which is what the classification below
stands on. Which orders a table may declare, what it must refuse when asked for
another, and what it answers instead, is HIL-785's document.

The subscription remembers **two** things and needs both:

- the **boundary keys** of the window it delivered (`firstAnchor`, `lastAnchor`)
  — without them there is nothing to judge an arriving row against;
- the **rows it actually rendered** — without them there is nothing to compare a
  change against, and a delta would be about the entity instead of the screen.

The server therefore knows each connection's concrete window and notifies it only
about what that connection shows, not about every change to the table.

### The window changes only by explicit user action

`filter`, `sort`, `anchor`, and `limit` change **only** by an explicit user
action — filter, sort, paginate, navigate, or press Show on the announcement bar.
Nothing on the live stream moves the window.

An explicit window change is **authoritative**: the snapshot that arrives already
carries everything that was waiting, so the pending queue is emptied rather than
replayed, the selection is dropped, and no placeholder survives into the new
window. A reconnect is the same event with the same outcome: filter, sort, and
page are restored so the reader does not lose their place, and whatever had
accumulated before the break is gone — the window that arrives outranks it.

## Custom filters and search-as-filter

- **Project filters are extensible.** The framework does not know in advance what
  backups, deliveries, or orders are filtered by. It owns the place in the bar
  and the shapes available — a dropdown, a date range, a toggle — and the page
  declares which filters its table has.
- **No filter is ever applied on the client.** They all ride to the server as one
  open map and become query conditions there. Filtering locally would make the
  count, the pages, and the live classification lie at once.
- **Search is a filter, not a framework feature.** There is no framework page
  search and no command palette. A keystroke is an explicit window change →
  loading → the backend returns the window, the same path as any sort or
  paginate (debounce is an implementation detail).
- **A facet is a number beside a filter option**, not a filter of its own: it
  says how many rows would remain if that option were chosen, and the server
  computes it in one grouped query rather than by trying the options. On a large
  set a facet obeys the same ceiling as the total.

## What applies at once and what waits

The boundary is one: **a change to a value inside a row applies at once; a change
to the membership or the order of the rows waits for the reader.** A list must
not move under the hands that are aiming at it, but a value that refreshed in
place moved nothing, and there is nothing to hold.

| What happened | What the table does | Why |
|---|---|---|
| A value changed in a shown row and the row stays where it is | applies **at once**, highlighted, raises no badge | Nothing moved. There is nothing to hold. |
| A field changed that no column renders, and the row keeps its place | **nothing at all** | The delta compares the rendered row, not the entity. |
| The edit moves the row under the current order | **waits**, the row is marked *will move* | A move is movement under the reader. |
| The shown row was deleted or left the filter | **waits**, the row is marked *will leave* | Same: its place in the list changes. |
| **The reader's own** create or edit | applies **at once**, taking the place the sort gives it | They pressed the button and are looking at the result. |
| Somebody else's new row | depends on where it lands — see the next section | It may appear by itself only where it moves nothing. |

Because a change that touches no rendered column produces no delta at all, **an
Apply button after which the screen looks the same cannot happen**. A badge that
resolves to nothing visible is a defect, not caution.

The rendered-row test decides the **value** outcomes only. Place is judged
separately and wins: a field the table does not render can still be part of the
sort key, and a change to it moves the row. That is a move — it waits like any
other — and staying silent about it because no cell would look different is
exactly how a window drifts away from the set.

On the wire this taxonomy is `table_viewport_delta.kind`, which has three values:
`row_updated` (a value in place — applied at once, highlighted), `row_moved` (a
move — waits), `row_removed` (gone from the set — waits).

**The reader's own change is the only exception to the gate.** The tab that made
an edit picks up its own echo immediately; every other tab sees the gate. The
core marks the row before the action (`expectOwnChange`) and applies the echo in
place when it returns (`applyOwnDelta`); a failed action drops the mark. That
correlation is by a row key the client knew *before* the action, so it does not
cover a create whose key the server mints — that one rides its own frame,
`table_viewport_own_create`, which carries the place the row takes.

A backend may **not** declare an ordinary mutation live to step around the gate.
The one case that used to be allowed to — a row reporting work in progress — is
no longer a row at all; see *Showing work in progress* below.

## Where an arriving row lands

A new row somebody else created is judged by **its place in the current order**,
not by the end of the list. Four outcomes:

| Where it falls under the current order | What the reader sees |
|---|---|
| **At the end of the window**, and the window has room | it **arrives on its own**, moving nothing: below it there is space (`table_viewport_append`) |
| **Inside the window**, between shown rows | it is **announced** — inserting it would shift everything below |
| **Above the window**, on an earlier page | it is **announced** — otherwise the window would silently drift from the set |
| **Below the window**, on a later page | **only the count changes** (`table_viewport_count`); the shown rows are not concerned |

The rule is not "new rows never appear at the top". It is **a new row appears by
itself only where its appearance moves nothing**. For a newest-first list that is
never; for an oldest-first list it is almost always the tail. The edge of the
list is beside the point — what is computed is the place in the current order,
which is why the order has to be total.

## Announcing what the window cannot show

What the window cannot admit is announced rather than dropped, and the
announcement is a count, not a list of rows.

- **The core accumulates announced rows as a number**, keyed by placement; the
  view shows a bar ("N new rows above the window") and never the rows themselves.
- **Show is an ordinary window change.** It re-asks for the window with the
  current filter, sort, and anchor: what was waiting is discarded, and the window
  that arrives is identical to a cold load. This is the same authoritative path
  as any explicit change — see *The window changes only by explicit user action*.
- **A dropped connection resolves the same way.** Filter, sort, and page come
  back, everything that accumulated before the break is gone, and the arriving
  window is the truth.

On the wire this is `table_viewport_announce` (page, tableKey, rowKey, placement,
totalCount, totalExact, pageCount), where `placement` is `above` or `inside`.

## Apply

**After Apply the screen always changes** — a button after which it is unchanged
does not exist. The taxonomy above is what guarantees that: only moves and
removals ever wait, and both are visible.

Apply resolves exactly those two, on the rows already shown:

- a **move** takes the row to the place the current order gives it;
- a **removal** leaves a **placeholder** in the row's slot. The layout does not
  collapse, nothing is pulled up from the next page, and the window does not
  move. Otherwise Apply becomes a jump of the list and the reader loses the row
  they were looking at. The placeholder stays until the window changes.

**Apply never brings a row in.** An announced row arrives through Show, which is
a window change and not an Apply; a row that qualified for the tail was live from
the start and never waited. Whatever Apply resolves was already on screen.

Opening an edit or delete dialog resolves what waits on that row first
(`applyAndResolve`), so a dialog never opens on a row whose removal has already
arrived.

## The count and its ceiling

The window reply carries `totalCount` and `totalExact`.

- **`totalExact: true`** — the count is exact and **page numbers appear**
  ("1 – 20 of 128", pages 1 2 3).
- **`totalExact: false`** — the count is a ceiling, shown as "500+", `pageCount`
  is not sent at all, and there are no page numbers: only Back and Next.

This is not a second mode of the table. It is the consequence of whether the
number is known: "page 7 of 512" over a large set means nothing and costs a full
pass over the database. Jumping deep is done by value — a date in the filter —
and not by a page number.

## Showing work in progress

Running work is **not a record of the set**, and it does not get a row. A
synthetic row with an invented key falls under the live-change rules, enters the
count, catches the selection checkbox, and leaves a placeholder behind when it
ends — none of which it has any use for. Work shows as a **bar**, and there are
exactly three:

1. **The row bar** — drawn under its own row and stretched beneath the columns
   the project names, usually the content ones rather than the selection or the
   actions. It is tied to the row key: the row goes, the bar goes. The caption
   above it belongs to the project.
2. **The table bar** — for work that has no row of its own. The place above the
   table is the framework's; **the content beside the bar is the project's** and
   is arbitrary: a title, a counter, a link, a cancel button. This is the reserve
   for a product's own business logic.
3. **The bulk-action bar** — lives inside the selection panel and belongs to the
   framework entirely. Deleting forty records takes time, and a button that says
   nothing for that long is not acceptable. The table bar must not be borrowed
   for this: it is the project's.

Common to all three: **a progress bar takes no part in the live-change rules, is
not in the count, cannot be selected, and cannot be sorted.** It appears and
disappears on its own, leaving nothing behind, because work that finished is not
a deleted record and has no place to hold.

On the wire this is `table_progress` — page, tableKey, scope, progressKey,
rowKey, current, total, ended, detail — where `scope` is `row`, `table`, or
`bulk`; `rowKey` is present only for `scope: row`; and `detail` is the project's
arbitrary payload for the table bar. The name carries no `viewport` on purpose:
the bar does not belong to the window.

## Selection and bulk actions

While nothing is selected the table shows its ordinary bar; from the first
selected row it is replaced by the **selection panel**, so that actions over one
record and over twenty never stand side by side.

- **Selection lives inside the window.** Paging, changing a filter, or
  re-sorting clears it. An action over rows that are not visible is "all matching
  the filter", and it is called by that name.
- **The header checkbox takes only what is visible.** "All matching the filter"
  is a separate button, and it means a **condition**, not a list of keys: over a
  large set there is no exact row count, and promising "12 480 selected" would be
  a lie.
- **Waiting does not touch a selection.** A selected row that was told it will
  move or will leave stays selected; Apply moves it or replaces it with a
  placeholder, and a row that left drops out of the selection so the counter
  falls by itself.
- **A bulk action is an ordinary `action`** — the page names it, as it names
  every other table action. The framework fixes only the shape: the request
  carries `rowKeys` **or** `filter`, exactly one of the two; the reply carries
  `touched` and `untouched: [{ rowKey, reason }]`.
- **A silent partial success is forbidden.** The server judges each row
  separately, and the report names the untouched rows one by one. "39 of 40
  deleted" without names is a message after which the reader has to go looking.
- A table may declare **no** bulk actions, and then it has no selection column at
  all. Which edge that column sits on is the project's choice, and within one
  installation it is the same edge everywhere.

## Per-source staleness

A table can be assembled from several sources, and one of them can fall behind
while the rest are live. The row carries `staleSources` — the list of
`sourceKey`s whose values have stopped updating — and the bar above the table is
the union of that over the window.

**This is not a lost connection.** The transport is up, the server answered, and
the other columns are live and true; refusing the whole page here would be a lie
in the other direction. The rule "either the connection is there or there is no
work" is about transport; this is one *source* lagging inside a live answer.

What is stale is **marked**: a mark in the cells of the lagging source, and a bar
that says in words what froze and why. Showing yesterday's number silently beside
today's is the worst of the options, because it looks fresh. A lagging source
does not block the rest — rows page, filter, and sort by the live columns — but
**a column of a stale source cannot be sorted at all**: an order over stale
values is indistinguishable from a wrong one.

## Headless table state machine

Table logic — the descriptor, the taxonomy and Apply, the announced counts, the
ingestion of counts and appends — is a **headless state machine** in the agnostic
core, with a thin per-framework view on top
([multiframework-core.md](multiframework-core.md)). The view renders rows, bars,
and badges and emits user intents; it holds no table logic of its own.

## Stable selectors (data-id)

The view exposes stable `data-id` selectors for e2e (Playwright's
`testIdAttribute` is `data-id`). **This registry is the single place they are
named**; a view does not invent its own.

The table **root** container is `hilos-viewport-table`. There is **no**
`hilos-table` root selector — the `hilos-table-*` prefix is the table's own
internal naming, and a test that selects `hilos-table` is stale.

Everything inside the root keeps the `hilos-table-*` prefix:

- **frame:** `hilos-table-title`, `hilos-table-main-action`,
  `hilos-table-search`, `hilos-table-filters`,
  `hilos-table-filter-<filterKey>`, `hilos-table-order`,
  `hilos-table-order-<orderKey>`, `hilos-table-sort-<key>`;
- **rows:** `hilos-table-row-<rowKey>`, `hilos-table-card-<rowKey>`,
  `hilos-table-expand-<rowKey>`, `hilos-table-row-detail-<rowKey>`,
  `hilos-table-placeholder`;
- **waiting and announcing:** `hilos-table-pending`,
  `hilos-table-pending-move-<rowKey>`, `hilos-table-pending-remove-<rowKey>`,
  `hilos-table-apply`, `hilos-table-announce`, `hilos-table-announce-show`;
- **selection:** `hilos-table-selection`, `hilos-table-selection-count`,
  `hilos-table-select-page`, `hilos-table-select-<rowKey>`,
  `hilos-table-select-all-filtered`, `hilos-table-selection-clear`;
- **work:** `hilos-table-progress`, `hilos-table-progress-row-<rowKey>`,
  `hilos-table-progress-bulk`;
- **counts and paging:** `hilos-table-count`, `hilos-table-page`,
  `hilos-table-prev`, `hilos-table-next`;
- **states:** `hilos-table-loading`, `hilos-table-skeleton`,
  `hilos-table-empty`, `hilos-table-empty-filtered`, `hilos-table-unavailable`,
  `hilos-table-stale`, `hilos-table-stale-cell`.

## Backend contract surface (the gate)

Per-connection viewport tracking is a backend surface behind the Contract
approval gate in [agents.md](../../../agents.md). The subscription registry holds
each connection's descriptor plus the boundary keys and the rows it delivered,
and everything below is addressed to the one connection it concerns:

| Frame | Direction | Carries |
|---|---|---|
| `table_viewport` | client → server | `page`, `tableKey`, `filter`, `sort`, `limit`, `anchor`, `anchorDirection` |
| `table_window` | server → client, reply only | `page`, `tableKey`, `rows`, `limit`, `totalCount`, `totalExact`, `pageCount`, `firstAnchor`, `lastAnchor` |
| `table_viewport_delta` | server → client, live | `page`, `tableKey`, `kind` (`row_updated` / `row_moved` / `row_removed`), `rowKey`, `row` |
| `table_viewport_append` | server → client, live | `page`, `tableKey`, `row`, `totalCount`, `totalExact`, `pageCount` — sent **only** when the row's place is the end of the window and the window has room |
| `table_viewport_count` | server → client, live | `page`, `tableKey`, `totalCount`, `totalExact`, `pageCount` |
| `table_viewport_own_create` | server → client, live | `page`, `tableKey`, `row`, `position`, `totalCount`, `pageCount`, `requestId` — unchanged from HIL-792; the row takes the place the sort gives it, not the tail |
| `table_viewport_announce` | server → client, live | `page`, `tableKey`, `rowKey`, `placement` (`above` / `inside`), `totalCount`, `totalExact`, `pageCount` |
| `table_progress` | server → client, live | `page`, `tableKey`, `scope` (`row` / `table` / `bulk`), `progressKey`, `rowKey`, `current`, `total`, `ended`, `detail` |

The **full window snapshot travels only in reply to a `table_viewport` request** —
a window change, a cold load, or a reconnect — and never on the live stream. A
bulk action gets no frame type of its own: it is an ordinary `action` in the
shape given above.

Naming a frame here does not clear the gate. The leaf that implements one still
stops and asks before touching the signal constants, the DTOs, or the routes.

## Where this lives

Addresses, not status — a status list goes stale with every leaf that lands,
an address does not:

| Concern | Where |
|---|---|
| the descriptor and the delivered keys | `framework/backend/Core/Router/TableViewportSubscription.php` |
| judging a mutation against a window, and emitting the live frames | `framework/backend/Core/Browser/Context/BrowserContext.php` (`tryEmitViewportAppend`, `viewportTotalAfterMutation`, `rowDeltaForMutation`) |
| the `ORDER BY` and the window query | `framework/backend/Database/Object/Objects.php` |
| the headless state machine | `framework/frontend/core/src/table/TableViewportController.ts` |
| routing the frames into it | `framework/frontend/core/src/subscription/bindTableViewport.ts` |
| the thin view | `framework/frontend/{vue,react,angular}/src/HilosViewportTable.*` |
