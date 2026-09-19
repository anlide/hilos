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
a **viewport descriptor**. It has two forms, and a frame carries exactly one:

```
{ page, tableKey, filter, sort, limit, anchor, anchorDirection }   paging
{ page, tableKey, filter, sort, limit, pageIndex }                 a jump
```

`sort` is the order the window runs in: the **list** of its `{field, direction}`
components in the sequence they apply, one of them for a click on a column header
and more for an order the table declared. An empty list and a missing key both
mean no order. `anchor` is an open map of the sort key's values, the primary key
included;
`anchorDirection` is `after` or `before`; `anchor: null` asks for the edge the
direction points away from — the start of the set with `after`, its end with
`before`. There is **no** `offset` in either form, and a frame carrying both
forms is refused rather than resolved: either reading of it is a window the
client did not ask for.

The window is taken by key — "give me twenty rows after this one" — and not by
offset — "skip two hundred thousand, give me twenty". A key window costs the same
at any depth and does not shift because somebody deleted a row above it. The
price is the page number and the exact total, and the count section below says
what the table shows instead.

`pageIndex` is the one address a key cannot express: page seven has no anchor
until somebody has shown it. It exists because the mockup has numbered pages, and
it lives exactly where the count is exact — the count section below is what
decides that. The server, not the client, turns the number into rows to skip: only
it knows the total at the moment of the request, and it counts from whichever end
of the set is nearer, so the worst skip is half the set and the last page costs
what the first does. Ask for a neighbouring page this way and the count is paid for
nothing — its boundary is already in hand, which is what `nextPage` / `prevPage`
use and what `setPage` does not.

### The first window is declared on the backend

A table's **first** window is not declared by the client at all: its size and its
order are stated on the table definition (`windowSize()`, `defaultSort()`), and
the page subscription builds the window from them and answers with it. There is
one declaration of each and one reader: the window says on arrival what size and
what order it ran at, and the controller takes both from there rather than
holding an opinion beside the backend's.

A tab that is already holding a window reports it instead, in the `tableWindows`
map of its `page_subscribe`, and the server serves back what the descriptor
names. That is what a reconnect is: the same page, the same window, the reader's
place kept. A controller that has never received a window reports none — it does
not know its own size or order until one arrives — and the table's declaration
answers for it, which is the same cold entry a second time.

**Every declared order ends with the primary key**, so the order is total.
Without that, a column with repeats (a status, a kind) lets two adjacent pages
show one row twice and another not at all — and the server cannot say where an
arriving row falls relative to the window, which is what the classification below
stands on. Which orders a table may declare, what it must refuse when asked for
another, and what it answers instead, is
[table-sort-orders.md](table-sort-orders.md).

The subscription remembers **two** things and needs both:

- the **boundary keys** of the window it delivered (`firstAnchor`, `lastAnchor`)
  — without them there is nothing to judge an arriving row against;
- the **rows it actually rendered** — without them there is nothing to compare a
  change against, and a delta would be about the entity instead of the screen.

What of a row is rendered, the client says: a descriptor may carry `rendered`, the
fields inside the row's slots the table draws. The core builds the list from the
declared columns (`hilosTableRenderedKeys`, `tableRendered.ts`) — every column's
key, plus the fields each cell names in `reads` because it draws from more than its
own key. The actions column has no key to count, so its `reads` is required, and an
empty list is how it says it reads nothing. A table with no declared frame sends no
list, and the server then compares the whole row. The server keeps a digest of each
delivered row whole and a second one of its drawn part (`TableViewportSubscription`),
and the two answer different questions — see the next section.

The list rides every `table_viewport` frame and the report of a held window at
`page_subscribe`. One window can carry it in neither: the cold entry, which the server
builds from the table's declaration and sends with `page_response` before the table
has mounted. For that window the controller sends **`table_rendered`** once, when the
page's answer brings the first window of a table that held none
(`TableViewportControllerOptions.sendRendered`). The server answers it with nothing:
it reads the held window from the table once more and lays the list over it
(`BrowserContext::declareTableRendered()`, `TableViewportSubscription::withRendered()`).
The rows themselves were never kept, so the drawn part of a row is taken from that
second read, and only for a row whose whole digest is still the one delivered — a row
that changed in between stays compared whole until it is delivered again, a delta too
many rather than one too few. A table opening with a preset asks for its own window
at once and needs no such frame.

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

## What the page declares

> **The declaration below is drawn by all three views.** Vue, React, and Angular
> render the bar and the footer from it, with the order menu in the bar, the page
> numbers in the footer and the place of a column in a composite order on its
> header, and the one room of live messages above the rows with the row tint and
> the waiting marks beside it, the bar of work under a row and the project's
> places next to running work, the cards a declared table becomes on a narrow
> screen, and the panel a row and a card expand into. What was built on top of
> them since — the worded empty states and the marks of a quiet source on cells
> and headers — is drawn by Vue alone until their own parity leaves (HIL-817,
> HIL-818).
> Six framework tables declare one — settings, users, the delivery journal,
> backups, the channels hub and a channel's fields. The framework's log pages and
> the verifier circle on the backups page still hand their view columns, a label
> and an empty text as props, and a table that declares nothing keeps drawing the
> bar and the footer it drew before.

**The page declares; the view draws.** The framework owns the whole bar above the
table and the whole footer below it, and a page that wants a title, a filter, or
a main action there says so rather than rendering one. Otherwise every page
builds its own bar, and the delivery log stops looking like the user list, which
stops looking like settings — each of them fixing the narrow screen and the empty
states again.

The declaration is one value, `HilosTableFrame`
(`framework/frontend/core/src/table/tableFrame.ts`), handed to the controller as
its `frame` option:

| Declared | What it is |
|---|---|
| `title`, `subtitle` | what the table is called |
| `search` | present means the table searches; carries the placeholder |
| `filters` | the filters offered in the bar, each a dropdown, a date range, or a toggle |
| `mainAction` | the one button at the right of the bar, offered again by the empty state |
| `columns` | the columns, in display order |
| `bulkActions` | the operations offered for the marked rows — key, label, danger |
| `empty` | what the table says when it is empty and nothing is filtering it |

Everything else on the bar and everything in the footer is the framework's:
the count and its precision, the row range, the pager, "Nothing found", the row
skeleton, the announcement and staleness bars. **A page owns the content of a
cell and nothing around it.**

Three things follow from the declaration and are read off the controller's
`frame` getter rather than recomputed per view layer — the card a row projects to
on a narrow screen is a fourth and has its own section below:

- **the declared filters with their values** — one filter as declared, the value
  it holds, and whether it is active. A date range holds both bounds under
  `{ from, to }`, because in the bar it is one control over two filter-map keys;
- **the count of active filters** — the badge in the bar. It counts DECLARED
  filters that hold a value, not the size of the filter map: search rides that
  map and has its own field, and a route preset counts only when a control is
  declared for its key;
- **the state of the body** — `loading`, `empty`, `empty_filtered`, or `rows`.
  Deciding this in the core is the point: three view layers deciding it apart
  would drift. `loading` holds while a window change has gone unanswered for
  longer than 400 ms (`WINDOW_SKELETON_MS`), or before any window has arrived —
  which a reader practically never sees, the first window riding the page's own
  answer. Until the threshold the previous rows stay, so a quick answer draws no
  skeleton; past it the view draws one as tall as the previous window, or
  `pageSize` rows when that window was empty. The state is read by every table,
  declared frame or not: "Nothing found" names the query and the active
  declared filters and resets through `resetFilters()`, while `empty` speaks
  the declared `empty` and `mainAction`, or the page's `empty` slot where
  nothing is declared. A page that refuses altogether is none of these — that is
  `HilosRouter.pageError`, the page's own refusal, not a state of its table.

### The card a row projects to

On a narrow screen a row is drawn as a card, and the framework builds that card
from the very columns the page already declared — a project writes no second
markup for its table, and intervenes only when it wants a layout of its own
(mockups/components/table section 9). The core answers where each declared column
goes; the page keeps drawing the content of every cell, on a card exactly as in a
row. **The card carries the layout of the columns, never their values.**

A card has four places and no fifth: the **title** at the head, the **badge** at
the right of that head, the **fields** in its body, and the **actions** across
its foot. Only fields carry a label — the title and the badge are drawn bare, as
the mockup draws them, and deciding that once in the core is what keeps three
view layers from labelling them three ways.

By default the layout follows the declaration order: the virtual `actions` column
goes to the foot, the first remaining column becomes the title, and the rest
become fields in the order they were declared. **No column becomes the badge by
default** — which column carries a state is known to the page alone, and a badge
guessed wrong would quietly lift a column out of the body of the card.

A page that wants otherwise marks the column itself with `card`, the optional
field of `HilosTableColumn`: `'title'`, `'badge'`, `'field'`, or `'hidden'` for a
column the card leaves out — a table of eight columns is a scroll on a phone, and
which of them are secondary is the page's own knowledge. A mark outranks the
default, wherever the marked column stands in the declaration. Where two columns
carry the same mark, the first by declaration wins and the second becomes a
field; the `actions` key outranks any mark on it, that column having no value
of its own to title a card with. The mark changes nothing on a wide screen: the
header, the column order, and sorting never read it.

The layout is derived once, with the rest of the frame, and read off
`frame.card` — it follows from the declaration alone, which does not change over
the life of a table, so it is not wrapped in a signal and null exactly when
`declaration` is. Drawing the card is a view's job, all three views draw it, and
what follows is what drawing it means.

**Which branch is seen is a matter of Bootstrap's visibility utilities, not of
JavaScript.** The table carries `d-none d-md-block` and the cards `d-md-none`, so
the boundary is the one place `md` is — the same boundary at which a modal
becomes a sheet from the bottom. Nothing reads the width of the window: there is
no `matchMedia` anywhere in the frontend, and a view that read one would have to
guard itself for the server, where `@hilos/prerender` renders it with no window
at all. There is no width at which both branches are seen and none at which
neither is, and crossing the boundary re-renders nothing — both are mounted, and
only the showing of them changes.

**Both branches stand in the document at once, so a row's `data-id` is in it
twice** — its own (`hilos-table-row-<rowKey>` against
`hilos-table-card-<rowKey>`) and any the page put inside a cell, which is drawn
in both. A test therefore aims at a row THROUGH the container of the branch it
means; the cards have one of their own, `hilos-table-cards`. This is the price of
the line above and it was taken knowingly: one markup deciding the width in JS
would cost every view a window guard.

**A card carries everything the framework says about a row, not a smaller set of
it.** The removed row keeps its place as a card of one line; the marks of the
framework — the snowflake of a quiet source, "will move", "will leave" — stand in
the head of the card BESIDE the page's badge rather than instead of it, the card
being a second projection of the same columns; the bar of work running over the
record stands at the foot of the card across its whole width, `progress` on a
column saying nothing there, a card having no columns in a row; and the loading
and empty words are repeated in the card branch, or the phone would be left
looking at a blank space where the sentence is.

**The cards are a list and the list holds cards only.** A set of alike records
with no role of its own is read out as a run of text, with no way to say "eight
records" — so the cards are a `list` of `listitem`s, named by the very heading
that names the table, there being one table and no reason for a second name for
it. Both names are never live at once: the hidden branch leaves the
accessibility tree with its display. What the table says in words when it has no
rows stands BESIDE that list rather than inside it, a sentence being no item of
a list; the container of the branch holds the two.

**A declared table hands the page one slot per column, `#cell-<key>`, and writes
the cell around it.** That is what makes a cell addressable by column at all, and
it is what lets the card be built without a second markup: one slot fills the
`<td>` of the row and the line of the card alike. The wide branch draws the cell
of every declared column even where the page filled nothing into it — a row one
cell short is a row narrower than its header — while the card leaves that place
out entirely, a label with nothing under it reading as a value lost rather than
as an empty field. A column may carry `cellClass` for the classes its `<td>`
needs, e.g. `text-end` under a numeric header; the card does not read it, a line
of a description list not being a cell of a table.

The contract is one in all three views — one piece of content per column, keyed
by the column, content only — and only the way a view takes a piece of content
is its own (multiframework-core.md, the slot seam). Vue takes a named slot per
column, `#cell-<key>`. React takes a map of renderers by column key, the prop
`cells`, `{ name: (row, rowKey) => … }`: every place this view hands to the page
is already a function prop. Angular takes one `ng-template` per column marked with
the `hilosTableCell` directive and the column's key,
`<ng-template hilosTableCell="name" let-row let-rowKey="rowKey">`, collected
through `contentChildren` — a template looked up by a static name (`#row`) cannot
carry a key known only at run time. "The page filled nothing" reads in each view
as the absence it is: no slot, no key in the map, no marked template.

**A table drawing its frame from props keeps the whole-row slot and gets no
cards** (`#row` in Vue and Angular, the `row` prop in React).
There is nothing to address a cell by in that epoch, so there is nothing to build
a card out of, and such a table keeps its horizontal scroll at every width until
its page moves onto the declaration.

**A title is declared only when it tells tables apart.** The one table of a
framework admin page declares none: the page heading above already names it, the
bar draws no heading of its own, and the table takes its accessible name from the
page heading, whose id the admin shell hands down (Vue `hilosPageHeadingIdKey`,
React and Angular their own context and token). A page holding two tables names
both.

**A preset is not a filter of the bar.** The channel a route names reaches the
table as `initialFilter`, and the window the page's own answer carries was built
without it — the backend serves a cold entry by the table's declaration alone. So
a table opening with a preset draws none of that window's rows: it keeps the order
and the size the window says and asks for its own, the skeleton standing until it
arrives.

Several filter-map entries are set in one window change with `setFilters()`, and
`resetFilters()` returns the map to the filters the table opened with —
`initialFilter`, not an empty map, since a route preset arrives that way. Search
is cleared along with them, being an entry of the same map.

The declaration carries no counts beside its options — those are the table's to
take, and they arrive on a frame of their own ("Facet counts" below) — and no
executor for a bulk action: what an operation does to each row, and how it names
the ones it left alone, is the bulk-action contract below.

### The panel a row expands into

A field that did not fit a column of its own waits in a panel under the row, and
the reader opens it with the control at the end of that row
(mockups/components/table section 4). It is not a device for the phone: a long
value is shown this way on a wide screen too, instead of stretching the table
around it.

Such a field is marked where the columns are declared — `detail: true` on
`HilosTableColumn` — and not listed a second time somewhere else. A second
register of the fields of one table would be two places to keep in step, and
nothing would catch them drifting apart; the card next door is built from the
declaration for the same reason.

**A marked column leaves the row entirely.** It is not in the header, takes no
width in the row, and offers no sort control — there is no header to click. It is
not in the card's main set on a narrow screen either: the card expands to it,
rather than carrying it in its body. What the column keeps is its label, which
becomes the label of the field in the panel, and its place: the panel holds the
fields in declaration order, and there is no second answer to that question.

**Removing the cell of a marked column from the `#row` slot is the page's own
duty — in the props epoch, which is the only one that has such a slot.** The
framework does not see the markup a page writes, so it cannot check this; a page
that marks a column and keeps its `<td>` gets a row one cell wider than its
header. In a DECLARED table the question does not arise: the framework draws the
cells itself, from the columns left standing in the row, and a marked one is
simply not among them.

**The framework owns the panel, the page owns its values.** The room under the
row, the order of the fields, their labels, the control and its accessibility are
the framework's; every value comes from the page, exactly as the content of a
cell does, and in the same form in each view. Vue takes a named slot per field,
`#detail-<key>`. React takes a map of renderers by column key, the prop
`details`, `{ lastError: (row, rowKey) => … }`. Angular takes one `ng-template`
per field marked with the `hilosTableDetail` directive and the column's key,
`<ng-template hilosTableDetail="lastError" let-row let-rowKey="rowKey">`,
collected through `contentChildren`. Each is handed the row and its key, as a
cell is. "The page drew nothing" reads as the absence it is — no slot, no key in
the map, no marked template — and shows a dash rather than an empty line, which
would read as "there is no value"; a renderer that returned nothing is not that
absence.

**On a narrow screen the panel is inside the card**, opened from a control in the
head of it and drawn between the fields and the controls — it goes on with the
very pairs of label and value the fields above are, while the controls and the
bar of the row's own work stay the bottom block. It is one state, held on the row
by the controller, and one pair of words; what it is NOT is one id — the card
mints an id base of its own, both branches standing in the document at once and a
borrowed id breaking the tie between control and panel on both.

**The state lives in the controller and goes with the window.** `expandRow(rowKey,
expanded)` takes the state the row is going to rather than toggling — the view
already draws it off `expanded` on that row — and a placeholder is refused, there
being no values under it to unfold. Any number of rows may be open at once: a
reader opens two records precisely to compare them. A search, a filter, an order
or a page turn closes every panel, through the very `changeWindow()` that clears
the marks; a key that leaves the window loses its panel and comes back collapsed.
A live update does NOT close a panel — showing the new value is what the open
panel is for — and neither does a pending change, which is about the place of a
row and not about what the reader opened. An APPLIED removal does close it: what
is left in that slot is a placeholder.

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
- **The table declares which of its fields the search reads**, the way it
  declares its sort vocabulary: `searchableFields()` is a `wire field => column`
  map, and one declaration serves every path — the ORM window, a table's own SQL,
  and the in-memory filter. An empty declaration is a full one and means this
  table has no search: a term arriving at it is refused out loud, because both
  silent answers (the same window back, or an empty one) look like something else
  that already happens.
- **A match stays a substring one and costs a full pass.** The term is compared
  with `%term%` over the declared fields joined by OR, so no index is under it and
  the whole set is read on every window. That is deliberate and named: the
  declaration is what a future index would stand on, and until then the promise is
  the fields, not the speed. Wildcards a reader types stand for themselves.
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
exactly how a window drifts away from the set. Leaving the set is judged the same
way, on the whole row.

A field a cell reads and no column names is a field whose change never reaches the
screen, and nothing reports it: the row simply stops refreshing that value. That is
why `reads` names what the dialogs a cell opens read from the row, too.

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

**There is no second exception, and no mechanism for one.** A backend used to be
able to declare an ordinary mutation *live* and have it step around the gate; the
only thing that ever did — a row reporting work in progress — is no longer a row
at all (see *Showing work in progress* below), so the flag went with it. It is
gone from the mutation a table builds, from the `table_viewport_delta` frame, and
from the client that read it: a table that wants something shown outside the gate
declares a **bar**, not a row that claims not to be one.

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

A window with a search or a filter is judged the same way, but only after the
row source has said the row is in its set (`containsRow`, the same question the
count asks — see *What moves the count while the window sits there*). Placing a
row that is not in the set against the rows that are would invent a place, so
the set is settled first and the order second; a row outside the set reaches
that window as nothing at all. A window that asked for no order, and a filtered
window whose table cannot answer the question, cannot read a place: the row
reaches them as the count only.

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
pass over the database. Where the count is a ceiling there is no page to jump to,
so `pageIndex` does not arise and jumping deep is done by value — a date in the
filter. Where it is exact, the numbers are real and a jump by number is what
serves them.

**The ceiling is 500** and is a framework constant, settable by neither a table
nor a project: one behavior always. It applies only to a **windowed** query. A
query with no limit reads the whole set anyway, so its count is exact and free,
and a set already held in PHP memory is counted as it always was — there is
nothing to save there, and reporting a ceiling over a number already in hand
would be a lie.

The count is taken by counting **to the ceiling plus one**. That one extra row
is what tells "exactly 500" apart from "more than 500"; without it an exact set
of exactly the ceiling would be reported as approximate.

### What moves the count while the window sits there

A live change moves the count only when it can be settled by a question about
**one row** — never by counting the set again, which is the pass this whole
section exists to avoid. The question is `containsRow`, and it is asked of the
row source rather than answered from the filter map: two descriptions of one
condition drift apart silently. The same answer decides more than the number: a
created row in a filtered window is placed, and so appended or announced, only
when the source says it belongs to the set (see *Where an arriving row lands*).
The question is put once per change, whichever of the two needs it first.

- **A created row** — one the set did not hold a moment ago. It adds one if it
  belongs to the set, and moves nothing if it does not.
- **A change or a removal of a row the window is holding** — the window is part
  of the set, so the row was in it. A removal takes one off; a change takes one
  off only if the row has left the set.
- **A change or a removal of a row outside the window** — nobody can place it.
  Whether it was in the set before the change is a question about its previous
  state, and no previous state is kept: a source update carries the changed
  columns and a delete need carry no row at all. **The count stands still and no
  frame is sent.** It becomes right again at the next window request — a page
  turn, a new search or sort, a resubscribe.
- **A table that does not implement the question** answers "cannot say", and
  keeps the whole-set re-query it always had. That is what the default is for: a
  project table that never heard of this contract must not quietly stop counting.
- **A refused question** is logged as an error with the table, page and row key.
  A silent "the count did not change" is otherwise indistinguishable from a
  source that failed to answer.

### Silence above the ceiling

While `totalExact` is false **no count frame is sent at all**. "At least 500" is
neither truer nor newer for one more row, and finding out whether the set has
fallen back under the ceiling would cost exactly the pass the ceiling avoids. A
window in that state becomes exact again only by asking for a window.

The one crossing that does travel is **upward**: an exact count that grows past
the ceiling sends one frame — `totalCount` = the ceiling, `totalExact: false`,
no `pageCount` — and then goes quiet. Without it the pager would sit on an exact
number it has outgrown.

Delivery of rows is not affected by any of this. A tail append and an own-create
still arrive over a set whose count stopped at the ceiling; what they carry is
the ceiling with `totalExact: false` and no `pageCount`.

### Jumping by number without an exact count

The client shows no page numbers and sends no `pageIndex` while the count is
inexact. The backend is honest without it anyway: a `pageIndex` that arrives is
counted **from the start of the set**, because "the nearer end" is derived from
the exact total. For the same reason no page is refused as lying past the end —
there is no known end — and an empty window is a legitimate answer.

## Facet counts

A dropdown filter can show beside each option **how many rows picking it would
leave** — "full 412", "partial 88" — and beside "Any" the size of the set with
the filter lifted. The number is there so that a choice is not made blind. It
filters nothing, and the value, the badge, the reset, the search and the footer
behave as they do without it.

**Only the table can count.** The open filter map becomes a condition inside the
table and nowhere else, so the question goes to the table
(`ViewportTable::facetCounts`), which hands its own count of a set to
`TableFacetTally::forFilters`. The tally decides which sets are counted; the table
counts each of them the way it serves a window. A second description of the
`WHERE` written for the numbers' sake would drift from the first, and the drift
would show as a number the window, once picked, does not agree with. The default
answer is null — "cannot count" — and a table giving it keeps a dropdown with no
numbers.

**An option is counted over the set without its own filter**: every other filter
and the search stand, and the filter itself is set to that option. Counted with
the filter in place, every option but the picked one would answer zero, and "how
many would this leave" would have no answer. "Any" is the same set with the
filter lifted and nothing in its place.

**Each count stops at the ceiling** the window's own count stops at, and says so:
`exact: false` is drawn as "500+". A set held in PHP memory is counted exactly,
as its window is. One grouped query over an input cut at the ceiling was
rejected on purpose — its numbers would be the spread of the first 500 rows
passed off as the spread of the set. The price is one count per option, which is
why a dropdown offering **more than 20 options** (`TableConstants::FACET_OPTION_LIMIT`,
`HILOS_TABLE_FACET_OPTION_LIMIT`) is not counted at all: numbers beside some
options and none beside the rest would read as zeros.

Three forms of a number and no more: the count, "500+", and 0 — written, not
left out, because "this leaves nothing" is exactly what the number is shown for.

### Two frames

- **`table_facets`** (client → server) — `{ page, tableKey, facets }`, the option
  values to count, by filter key. The controller sends it when the table's first window lands, if there is
  anything to count — not at construction, where a socket still connecting would
  drop it — and again whenever the options change after that; an empty map drops
  the list. The server keeps the list beside the connection's window
  (`SubscriptionRegistry`) and not inside it: the viewport is rebuilt from every
  window frame, and the list has to outlive all of them.
- **`table_facet_counts`** (server → client) —
  `{ page, tableKey, facets: { <filterKey>: { any: {count, exact}, options: { <String(value)>: {count, exact} } } } }`.
  A filter the table does not count is absent altogether; there is no empty entry
  that could be drawn as a row of zeros.

The counts travel in a frame of their own rather than inside `table_window`: the
view declares its options when it mounts, and the first window has left with the
page's answer by then. The live count of the set travels the same way.

### When the counts are sent

By the server, without being asked again:

- **when `table_facets` arrives** — every declared filter;
- **after a `table_viewport` window, only for the filters whose set moved.** A
  filter's counts move when the search or any *other* filter changed, so changing
  one filter counts all the others again and not itself. A page turn, a new size
  or a new order changes no filter and sends nothing. The first window a table has
  on the connection sends every count;
- **after the `page_response` of a subscription whose reported window names
  `facets`** — a tab coming back after a broken socket reports its options with
  its window (`TableViewportDescriptor.facets`) and gets its counts back. The
  counts follow the answer: before it the table has no window to hold them.

A live change of a row does not move the counts. Correcting a number would take
the row's previous value — the per-source mechanism the count's ceiling declined
— and the numbers catch up with the next change of the set.

### What the client holds

`ingestFacetCounts` lays a frame over the counts held, filter by filter: a frame
naming one filter leaves the others where they were. While a window change is
waiting the previous numbers stay, as the footer's do. `HilosTableFilterView.facets`
is null until the first counts arrive, and for good when the filter is not a
dropdown, the table does not count, or the list is over the limit. A count that
failed is the same null to the reader — and a line in the log with the table,
the page and the connection; the window the counts follow is untouched.

## Showing work in progress

Running work is **not a record of the set**, and it does not get a row. A
synthetic row with an invented key enters the count, catches the selection
checkbox, and leaves a placeholder behind when it ends — none of which it has any
use for — and it needed a hole in the pending gate on top of that. Work shows as
a **bar**, and there are exactly three:

1. **The row bar** — drawn under its own row and stretched beneath the columns
   the project names, usually the content ones rather than the selection or the
   actions. Which ones those are is said by the **column declaration** — the
   optional `progress` flag on `HilosTableColumn`; no column marked stretches the
   bar under the whole row. It is tied to the row key: the row goes, the bar
   goes. The caption above it belongs to the project, and the view hands it over
   as a **slot** (`row-progress` in Vue, the `rowProgress` render prop in React,
   the `#rowProgress` template in Angular) rather than reading a key of `detail`.
2. **The table bar** — for work that has no row of its own. The place above the
   table is the framework's; **the content beside the bar is the project's** and
   is arbitrary: a title, a counter, a link, a cancel button. This is the reserve
   for a product's own business logic. The view hands that place over as **slots**
   too (`table-progress` on the line the track runs under and
   `table-progress-action` beside it in Vue; `tableProgress` and
   `tableProgressAction` render props in React; `#tableProgress` and
   `#tableProgressAction` templates in Angular), each receiving the whole bar,
   `detail` and all. The bar is one of the four live messages that share the one room above
   the table, and the most junior of them: while changes wait for Apply, rows wait
   to be shown, or a source is behind, the line is theirs and the bar stands
   beside it as an icon (`tableLive.ts`, and
   [styling-rules.md](styling-rules.md), "The room a live message takes"). It reads no key out of
   `detail` itself: `detail` is the project's arbitrary payload, so a view
   reading keys from it would invent a naming contract nobody declared and oblige
   every other view layer to repeat it letter for letter.
3. **The bulk-action bar** — lives inside the selection panel and belongs to the
   framework entirely. Deleting forty records takes time, and a button that says
   nothing for that long is not acceptable. The table bar must not be borrowed
   for this: it is the project's.

Common to all three: **a progress bar takes no part in the live-change rules, is
not in the count, cannot be selected, and cannot be sorted.** It appears and
disappears on its own, leaving nothing behind, because work that finished is not
a deleted record and has no place to hold. Work that named no total is drawn as
a striped track running end to end and reports no number to assistive tech — an
estimate is a thing work can honestly not have, and a bar without one still says
that something is running.

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
  carries `rowKeys` **or** `filter`, exactly one of the two; the reply answers
  **acceptance** and carries the `progressKey` of the run, and `touched` and
  `untouched: [{ rowKey, reason }]` arrive after it on a `table_bulk_report`
  frame addressed to the connection that asked. The outcome does not ride the
  reply because a run over a condition outlives the client's action timeout, and
  holding the action open would fail work that is going fine
  ([wire-protocol.md](wire-protocol.md), *When the work outlives the reply*).
  The shape of the report is unchanged by the move: only its carrier is.
- **The declaration carries the sender.** One operation declares `run(target)`
  beside its key and its label, and that is what the panel calls: the view owns
  the button and the confirmation, while which action the press becomes is the
  page's, exactly as the main action's `press` is. The target is read off the
  controller **at the moment of the confirmation** and not when the button was
  pressed — between the two the reader may have cleared a checkbox.
- **Every bulk operation is confirmed in a modal**, `danger` or not: `danger`
  changes how the button reads and nothing else. Irreversible work over twenty
  records from a single click is the one outcome the confirmation exists against,
  and two behaviors on one place would be a flag the frame does not carry.
- **The panel stands on any of three counts** — something is marked, **or** a
  bulk bar is running, **or** a report is on screen — and the ordinary controls
  of the bar come back only when none of the three holds. Rows a run deletes drop
  out of the selection by themselves, so "the panel while something is marked"
  would take the running bar and the not-yet-arrived report off the screen in the
  very case the panel is there for.
- **The report is dismissed by the reader**, and that is its only exit short of
  the next run: the core holds it until a run replaces it, and the dismissal is
  state of the view, kept against the `progressKey` it dismissed. Paging,
  filtering and re-sorting leave it standing, for the reason they leave a bar
  standing — it is about the work, not about the window.
- **A silent partial success is forbidden.** The server judges each row
  separately, and the report names the untouched rows one by one. "39 of 40
  deleted" without names is a message after which the reader has to go looking.
  The report carries a `rowKey` and a reason, and the human name of that row is
  the page's: the framework hands the place over as a **slot** (`bulk-untouched`
  in Vue, the `bulkUntouched` render prop in React, an
  `<ng-template #bulkUntouched>` in Angular — each given the `rowKey` and the
  `reason`) and prints the key where the page filled nothing. It cannot do
  better — the row has left the window by then.
- A table may declare **no** bulk actions, and then it has no selection column at
  all. Which edge that column sits on is the project's choice, and within one
  installation it is the same edge everywhere — a choice of the VIEW, which draws
  the column; the core declares no edge, having nowhere to draw one and no reader
  for it. The view takes it as **one injection for the whole application** —
  `app.provide(hilosTableSelectionEdgeKey, 'end')` in Vue,
  `<HilosTableSelectionEdgeContext.Provider value="end">` in React,
  `{ provide: HILOS_TABLE_SELECTION_EDGE, useValue: 'end' }` in Angular —
  defaulting to the left edge in all three where the project provides nothing. A
  prop on each table would hand the product the right to disagree with itself,
  which is the one thing the rule is about.
- **The selection is state of the window controller**, next to the pending changes
  and the placeholders, because every rule above is a rule about the window. It
  reads as one of the two shapes the request carries — the row keys, or the filter
  condition — and as the count and the header checkbox a panel is drawn from; the
  keys are held raw and shown as their intersection with the live rows, which is
  what makes a row that left drop out of the selection on its own.

## Per-source staleness

A table can be assembled from several sources, and one of them can fall behind
while the rest are live. The row carries `staleSources` — the list of
`sourceKey`s whose values have stopped updating — and the bar above the table is
the union of that over the window.

**The list rides inside the row envelope**, in every frame a row travels in:
`page_response`, `table_window`, `table_viewport_delta`, `table_viewport_append`
and `table_viewport_own_create`. It is **absent** on a row that is entirely
current, so the ordinary case pays nothing, and a tab that opened while a source
was already behind learns of it from the first row it is given rather than
waiting for a link to move.

**A change of the list arrives as its own kind of delta**, `row_stale`, carrying
the row key and the new list and no row. Neither of the other two kinds would do:
an ordinary pending delta shows the mark only after Apply is pressed, which is
exactly the interval in which a frozen number goes on looking fresh, and a live
one replaces the row and resolves everything queued for it — a source going quiet
would then apply an edit the reader has not accepted. `row_stale` therefore
**does not pass through the Apply gate**, and it touches neither the row's values
nor anything pending on it: the mark is a statement *about* the data, not a change
to it. The gate holds the position and the composition of rows, and that boundary
is unaffected.

**Who names the frozen sources is who assembled the fragment.** A declaratively
built row is asked per source by the framework; a typed table names its own,
because its fragment can be a summary over many runtime rows and only it knows
which of them went into it. And freshness stays **out of the delivered row's
digest**: it is not content, and counted in it would raise a content delta for
every row of the window the moment a link dropped.

**This is not a lost connection.** The transport is up, the server answered, and
the other columns are live and true; refusing the whole page here would be a lie
in the other direction. The rule "either the connection is there or there is no
work" is about transport; this is one *source* lagging inside a live answer.

**A column names the source it is built from**, through the optional `source` on
its declaration — the slot key `staleSources` names. Without it the framework does
not know what the column is made of, so a column that declares none is never
counted stale; the bar still appears over a window with a quiet source, in a
generic wording, because a table whose columns named nothing would otherwise show
yesterday's number in silence.

What is stale is **marked in the three places the framework owns**: a bar above
the table saying in words what froze, why, and that the rest is live; a snowflake
in the header of every column built from a quiet source, carried inside its sort
control alongside a hidden warning (or beside the label when unsortable); and a
snowflake in the narrow row-state cell at the end of every row whose own values
are behind. The mark the mockup draws *inside* the cells of the lagging column
is **not one of them and cannot be**: the body of a row comes from the page through
the `#row` slot, so the framework owns no cell to put an icon in — design debt
`D-051`. Showing yesterday's number silently beside today's is the worst of the
options, because it looks fresh. A lagging source does not block the rest — rows
page, filter, and sort by live or stale columns alike — and **a column of a stale
source still sorts**: the header button and the Order menu item remain live and
carry a snowflake with a hidden warning saying what the order is worth
(`sortWarning`). The other orders a table refuses, and what it answers, are in
[table-sort-orders.md](table-sort-orders.md).

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
  `hilos-table-filter-<filterKey>`, `hilos-table-facet-<filterKey>-<value>` — the
  number beside one option of a dropdown filter, keyed by `String(value)`,
  `hilos-table-facet-<filterKey>-any` — the number beside "Any",
  `hilos-table-filters-open` — the button that opens the filters modal below md,
  `hilos-table-filters-done` — the button that closes it. **The copy of a filter
  control inside that modal carries `-modal` at the end of the control's name**,
  because both copies stand in the document at once: `hilos-table-filter-<filterKey>-modal`,
  its `-modal-from`, `-modal-to`, `-modal-clear`, and
  `hilos-table-facet-<filterKey>-<value>-modal`. `hilos-table-order`,
  `hilos-table-order-<orderKey>`, `hilos-table-sort-<key>`. The "Order" menu's
  first item is the way back to the order the table opened in, and it answers to
  the one key no table declares: `hilos-table-order-opening`;
- **rows:** `hilos-table-row-<rowKey>`, `hilos-table-cards` — the container of
  the card branch, `hilos-table-card-<rowKey>`, `hilos-table-expand-<rowKey>`,
  `hilos-table-row-detail-<rowKey>`, `hilos-table-placeholder`;

  **A row answers to its selectors twice, once per branch**, both of them
  standing in the document and only one of them shown (the card section above).
  Everything inside a card — the placeholder, the waiting badges, the snowflake,
  the control and the panel, the bar of the row's work, the mark checkbox, and
  any `data-id` the page wrote into a cell — carries the identifier it carries in
  the row, on purpose: the two are the same fact about the same record, and a
  second name for it would be a second thing to keep in step. **So a test names
  the branch it means**: through `hilos-table-cards` on a narrow screen, through
  the table on a wide one. A bare selector finds the table's copy, the table
  being drawn first;
- **the room of live messages:** `hilos-table-live-slot` — the room itself,
  `hilos-table-live-idle` — the invisible twin holding it,
  `hilos-table-live-rest` — the icons of the messages the line yielded,
  `hilos-table-live-status` — the hidden region that speaks them. The line
  carries the handle of the message holding it: `hilos-table-pending-row`,
  `hilos-table-announce`, `hilos-table-stale` or `hilos-table-progress` — so a
  message that yielded the line has no handle of its own until it gets it back;
- **waiting and announcing:** `hilos-table-pending`,
  `hilos-table-pending-move-<rowKey>`, `hilos-table-pending-remove-<rowKey>`,
  `hilos-table-apply`, `hilos-table-announce`, `hilos-table-announce-show`;
- **selection:** `hilos-table-selection`, `hilos-table-selection-count`,
  `hilos-table-select-page`, `hilos-table-select-<rowKey>`,
  `hilos-table-select-all-filtered`, `hilos-table-selection-clear`,
  `hilos-table-bulk-<actionKey>` — the button of one declared operation,
  `hilos-table-bulk-confirm` — the button that confirms it,
  `hilos-table-bulk-report` — the report the run ended with,
  `hilos-table-bulk-report-close` — the cross that dismisses it;
- **work:** `hilos-table-progress`, `hilos-table-progress-row-<rowKey>`,
  `hilos-table-progress-bulk`;
- **counts and paging:** `hilos-table-count`,
  `hilos-table-page-<pageNumber>` (1-based, and only while the count is exact
  enough to have page numbers at all), `hilos-table-prev`, `hilos-table-next`;
  `hilos-table-page` is the single number the props-driven footer prints, and
  only a table that declares no frame still carries it;
- **states:** `hilos-table-loading` — the skeleton as a whole,
  `hilos-table-skeleton-row` — one row of it (one bar in the cards);
  `hilos-table-empty` with `hilos-table-empty-title`, `hilos-table-empty-hint`
  and `hilos-table-empty-action`; `hilos-table-no-matches` with
  `hilos-table-no-matches-terms` and `hilos-table-no-matches-reset`;
  `hilos-table-unavailable` (HIL-943);
- **a source that went quiet:** `hilos-table-stale` — the bar,
  `hilos-table-stale-column-<key>` — the snowflake in a header,
  `hilos-table-stale-row-<rowKey>` — the snowflake in a row-state cell. There is
  no `hilos-table-stale-cell`: the mark inside the cells of the lagging column is
  the page's to draw and the framework's to name for nobody (`D-051`).

## Backend contract surface (the gate)

Per-connection viewport tracking is a backend surface behind the Contract
approval gate in [agents.md](../../../agents.md). The subscription registry holds
each connection's descriptor plus the boundary keys and the rows it delivered,
and everything below is addressed to the one connection it concerns:

| Frame | Direction | Carries |
|---|---|---|
| `page_subscribe` | client → server | `page`, `params`, and an optional `tableWindows`: a map of `tableKey` → the body of a `table_viewport` frame without its address, `rendered` included — the windows this tab is already holding |
| `page_response` | server → client, reply only | the page payload, whose fifth section `windows` is a map of `tableKey` → `rows`, `sort`, `limit`, `totalCount`, `totalExact`, `firstAnchor`, `lastAnchor`, `progress` — the first window of each of the page's viewport tables, and the work running on it |
| `table_viewport` | client → server | `page`, `tableKey`, `filter`, `sort` (a **list** of `{field, direction}`, in the sequence they apply), `limit`, an optional `rendered` (the fields inside the row slots the table draws; absent, rows are compared whole), and then either `anchor` + `anchorDirection` or `pageIndex` — never both |
| `table_rendered` | client → server | `page`, `tableKey`, `rendered` (required, may be empty) — sent once, over the cold window the page's answer brought; nothing is sent back |
| `table_window` | server → client, reply only | `page`, `tableKey`, `rows`, `limit`, `totalCount`, `totalExact`, `pageCount`, `firstAnchor`, `lastAnchor` |
| `table_viewport_delta` | server → client, live | `page`, `tableKey`, `kind` (`row_updated` / `row_moved` / `row_removed` / `row_stale`), `rowKey`, `row`, `position` (`row_moved` only, absent when the table could not name the slot), `reason` (`row_removed` only: `deleted` / `left_set` / `moved_out` — the row was deleted, left the filtered set, or moved past an edge of the window), `staleSources` (`row_stale` only, in place of `row`) |
| `table_viewport_append` | server → client, live | `page`, `tableKey`, `row`, `totalCount`, `totalExact`, `pageCount` — sent **only** when the row's place is the end of the window and the window has room |
| `table_viewport_count` | server → client, live | `page`, `tableKey`, `totalCount`, `totalExact`, `pageCount` |
| `table_viewport_own_create` | server → client, live | `page`, `tableKey`, `row`, `position`, `totalCount`, `totalExact`, `pageCount`, `requestId` — the row takes the place the sort gives it, not the tail |
| `table_viewport_announce` | server → client, live | `page`, `tableKey`, `rowKey`, `placement` (`above` / `inside`), `totalCount`, `totalExact`, `pageCount` |
| `table_progress` | server → client, live | `page`, `tableKey`, `scope` (`row` / `table` / `bulk`), `progressKey`, `rowKey` (`scope: row` only), `current`, `total`, `ended`, `detail` — work already running when a tab subscribes arrives instead in the `progress` key of the `windows` section |
| `table_bulk_report` | server → client, addressed to the initiator | `page`, `tableKey`, `progressKey`, `touched` (a count, never names — changed rows have already arrived as live deltas), `untouched` (`[{ rowKey, reason }]`), `untouchedOmitted` (absent when every name fit under the server's ceiling) |

A **full window snapshot never travels on the live stream**. It travels on one of
two roads and no other: in reply to a `table_viewport` request, which is a window
the reader changed, and in the `windows` section of the page's own
`page_response`, which is the first window of every viewport table the page
declares — a cold load and a reconnect alike. A bulk action is still an ordinary
`action` in the shape given above, but its OUTCOME has a frame of its own,
`table_bulk_report`: the acceptance travels on the reply and the report cannot,
because the run outlives the timeout the reply is bound by. That frame is a
report and never a window — it carries no rows, only how many were touched and
which were not.

The `windows` section carries one key the reply does not, `sort`, and leaves out
one the reply has no reason to carry either, `filter`. The order is there because
nobody asked for it: a cold entry runs in the order the table declares on the
backend, and the tab has no other way to learn what that is. The filter is absent
because a second source of truth about it could only disagree — on a cold entry it
is empty, and on a reconnect the tab sent it and still holds it.

Naming a frame here does not clear the gate. The leaf that implements one still
stops and asks before touching the signal constants, the DTOs, or the routes.

## Where this lives

Addresses, not status — a status list goes stale with every leaf that lands,
an address does not:

| Concern | Where |
|---|---|
| the descriptor and the delivered keys | `framework/backend/Core/Router/TableViewportSubscription.php` |
| the window a tab reports on its subscription | `framework/backend/Core/Table/DTO/TableWindowDescriptorDTO.php` |
| opening each of a page's windows as it is subscribed | `framework/backend/Core/Browser/Context/BrowserContext.php` (`subscribeSnapshot`, `subscribeTableWindow`, `buildTableWindow`) |
| what the first window of a table is | `framework/backend/Core/Table/Definition/TableDefinition.php` (`windowSize`, `defaultSort`) |
| the windows a tab is holding, and the frame that reports them | `framework/frontend/core/src/connection/HilosConnection.ts`, `framework/frontend/core/src/subscription/PageSubscription.ts` |
| judging a mutation against a window, and emitting the live frames | `framework/backend/Core/Browser/Context/BrowserContext.php` (`viewportPlacement`, `tryEmitViewportArrival`, `emitViewportAppend`, `emitViewportAnnounce`, `emitTableProgress`, `viewportTotalAfterMutation`, `rowDeltaForMutation`) |
| placing one row against a window boundary, in the table's own key names | `framework/backend/Core/Table/Definition/ViewportTable.php` (`placeRowAgainst`) |
| the `ORDER BY` and the window query | `framework/backend/Database/Object/Objects.php` |
| the headless state machine | `framework/frontend/core/src/table/TableViewportController.ts` |
| the frame a page declares | `framework/frontend/core/src/table/tableFrame.ts` |
| the counts beside a filter's options | `framework/backend/Core/Table/TableFacetTally.php`, `framework/backend/Core/Browser/Context/BrowserContext.php` (`sendTableFacetCounts`), `framework/backend/Core/Page/PageSignalRouter.php` (`recountFacets`) |
| the card a row projects to | `framework/frontend/core/src/table/tableCard.ts` |
| the panel a row expands into | `framework/frontend/core/src/table/tableDetail.ts` |
| the card, the cell slots and the two branches as they are drawn | `framework/frontend/{vue,react,angular}/src/HilosViewportTable.*` |
| the mark an Angular page puts on the template of one column's cell | `framework/frontend/angular/src/HilosTableCell.ts` |
| the mark an Angular page puts on the template of one field of the panel | `framework/frontend/angular/src/HilosTableDetail.ts` |
| the words a quiet source is marked with, and the columns it froze | `framework/frontend/core/src/table/tableStaleness.ts` |
| the selection a table holds | `framework/frontend/core/src/table/tableSelection.ts` |
| the progress bars a table reports | `framework/frontend/core/src/table/tableProgress.ts` |
| the bulk run a table holds | `framework/backend/Core/Table/Bulk/TableBulkRun.php` |
| the report a bulk action ends with | `framework/frontend/core/src/table/tableBulk.ts` |
| routing the frames into it | `framework/frontend/core/src/subscription/bindTableViewport.ts` |
| the thin view | `framework/frontend/{vue,react,angular}/src/HilosViewportTable.*` |
| the bar the frame is drawn as | `framework/frontend/{vue,react,angular}/src/HilosTableBar.*` |
| one control of one declared filter | `framework/frontend/{vue,react,angular}/src/HilosTableFilterControl.*` |
| the footer under the table | `framework/frontend/{vue,react,angular}/src/HilosTableFooter.*` |
| the one room a table's live messages share | `framework/frontend/{vue,react,angular}/src/HilosTableLive.*` |
| the two worded states of the body — "nothing here yet" and "Nothing found" | `framework/frontend/vue/src/HilosTableEmptyState.vue` |
| the bar a running job is drawn as | `framework/frontend/{vue,react,angular}/src/HilosTableProgress.*` |
| the selection panel and the bulk bar | `framework/frontend/{vue,react,angular}/src/HilosTableSelection.*` |
| the edge the selection column sits on | `framework/frontend/{vue,react,angular}/src/hilosTableSelectionEdge.ts` |
