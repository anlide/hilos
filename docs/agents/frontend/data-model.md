# Data Model

How the browser payload becomes frontend state: one ingest boundary, a
normalized entity store, references instead of copies, and scope-partitioned
lifetimes. Tables build on this ([table-subscription.md](table-subscription.md)), and editing builds on
it ([conflict-resolution.md](conflict-resolution.md)).

## The "don't dilute" rule

The backend "browser" layer prepares, per subscription, the exact data structure
the frontend receives. **Reshaping, flattening, or spreading that raw payload
into ad-hoc local state is a gross Hilos violation.** Exactly one ingest layer —
the **normalizer** — is allowed to touch the raw payload; everything downstream
reads typed selectors only.

The normalizer splits each payload by kind:

- entity fragments → upserted into the **entity store**;
- entity-derived row columns → **references** into that store;
- a row's own and computed columns → the **table-rows store**;
- page / session / user / group blobs → their **per-scope data stores**.

Downstream code never re-touches the raw payload; it reads selectors that resolve
references reactively. This single boundary is what makes the rest of the model
safe.

## The three kinds of received data

A payload is, roughly: **table rows**, **entity fragments**, and **page-specific
data**. These map to the stores below. A row is not an entity: a table row is a
composition of source slots — an anchor plus relations plus computed cells —
some of which are entity fragments and some of which are not.

## The stores

### Entity store

Normalized, keyed by `(entityType, id)`, and **scope-owned** (not a global
cross-page cache). It is the single source of truth for entity data, holding only
the **committed** server value plus a version per entity. An entity that appears
in fifty rows is stored once and referenced fifty times.

### Table-rows store

Keyed by table. Each row carries its `rowKey`, its own and computed cells, and
**references** for entity-derived columns. It renders by resolving those
references against the entity store. Implemented as `TableRowsStore`, the twin
of the list store; it ingests the `tables` payload section the same way lists
ingest theirs — a snapshot plus live per-row deltas (see [table-subscription.md](table-subscription.md)).

### List store

Keyed by list. Each list is an **ordered sequence of items** keyed by `itemKey`,
fed **incrementally**: a create appends at the end, an update replaces an item in
place (its position is preserved), a delete drops it. An item holds **normalized
slots** — an entity-bearing slot is upserted into the entity store and replaced
by a **reference** there, exactly like an entity slot, while a plain slot (a
scalar, a blob, a foreign-key value) stays inline. Because entity slots are
references, **an entity update fans out through the entity store and is never
re-streamed per list**: a chat author rename arrives once on the user list, and
every event that references that author re-renders for free. The list is the
**light** ordered primitive — no viewport, no pending/Apply; the heavy windowed
primitive is the table-rows store.

### Per-scope data stores

One data store per scope holds that scope's non-table, non-entity data plus
references to the entities and tables it uses:

- **page-data store** — the "third kind": page-scoped scalars and blobs;
- **group-data store** — group-shared data, often an ordered list of entity
  references plus group metadata (e.g. a languages catalog);
- **user-data store** — profile, roles, unread counts;
- **session-data store** — connection and auth state, the tab's current route,
  transient per-tab UI; holds a reference to the current-user entity (the session
  itself is not an entity).

## The four scopes

Data is **scope-partitioned**; page data is not implicitly reused across pages.
There are four scope lifetimes, resolved **most-specific-first**: Page → Session
→ User → Group.

- **Page** (per current page subscription; **dropped on navigation**): the page's
  tables, page-specific data, page-only entities.
- **Session / tab** (per connection; dies with the tab; **not** shared with
  sibling tabs): connection and auth state, the tab's route, transient per-tab UI
  such as open modals, scroll, and drafts.
- **User** (per user; **shared across that user's tabs** — the server pushes to
  all their connections): profile, roles and permissions, global unread counts.
  An edit here fans out to sibling tabs.
- **Group** (per active group subscription; **survives navigation**): data shared
  by a subset of clients — editable catalogs (languages, currencies),
  per-conversation data. This is the explicit cross-page sharing mechanism.
  Truly-everyone data is bootstrapped via a group everyone is in, or into the
  session at the handshake.

An entity is owned by a scope and dropped when that scope ends; a reference
resolves most-specific-first. Navigating A→B drops A's page scope, subscribes B,
and shows skeletons until B streams — Session, User, and Group are untouched.
Returning to A re-subscribes and re-streams fresh. This bounds memory without any
LRU or ref-counting, and dissolves most "ragged stale data" problems:
active-scope data is always fresh because it is always re-streamed.

## Entity references and resolution

A reference is a small value: `EntityRef = { type, id }`. It is resolved through
a selector — `useEntity(ref)` in the Vue adapter, a plain function in the
agnostic core ([multiframework-core.md](multiframework-core.md)). Data lives once; references are
everywhere. A single update propagates to every reference, and freshness comes
for free.

## Keys — never conflated

Three keys are distinct and must never be conflated:

- **`rowKey`** — a row's identity *within a table* (used for ordering and pending
  changes; unique per table; **not** a DB id, though it may coincide).
- **`sourceKey`** — the name of a fragment **slot** composing a row. A row is a
  composition of several source slots (anchor + relations + computed), not one DB
  row. A `sourceKey` is a binding-local alias, **not** a global type.
- **`entityType`** — the canonical type of an entity slot.

The entity key is `(entityType, id)` — never `(sourceKey, id)`, because
`sourceKey` is only a local alias.

## Entity detection (the convention)

A "frontend entity" is a curated projection (the backend `fields` projection
already prevents secret-field leaks). Detection is by **convention**: a source
slot that bears a stable `id` is a dedup-able entity keyed `(entityType, id)`,
where `entityType` **defaults to the slot's `sourceKey`** but is **overridable**
via a small map when the binding alias differs from the canonical type (e.g.
`db_author → user`) or two slots share a type.

Two mechanisms are both required and are separate: this convention finds
*entities*, while the table config still maps each *column* to a `source.field`
or a computed cell. Source slots are DB, RT, or computed today, and the **kind
set is open-ended** — never hardcode "DB" into the slot or entity model. As the
backend is reworked, `entityType` may later be emitted per slot server-side,
retiring the frontend override; start with the frontend convention.

The **same `id`-by-convention detection and the same `sourceKey → entityType`
override map govern list-item slots**: a slot bearing an `id` is upserted and
referenced; a slot without one stays inline. A list's references therefore
dedupe against entities delivered elsewhere (the chat user list and the chat
event stream share the one `user` entity).

> **Rule — keep the frontend slot→type map in sync with the backend source.**
> The override map is **frontend config** (declared per page/list in the
> frontend, not emitted on the wire today). Whenever you add or change it, **read
> the matching backend browser source** (its slot keys and the entity types its
> projections carry) and confirm the map names the same slots and types. A stale
> map silently mis-types a slot — an entity merged under the wrong type, or a
> reference that never resolves — and the normalizer cannot catch it, because the
> wire slot is opaque by design. Treat a backend source-shape change as a trigger
> to re-verify every frontend map that reads it.

## The one-entity-per-scope invariant

Within a scope there is **one entity per `(entityType, id)`**, and every table,
modal, and page-data slot holds a **reference** to that single entity. This is
what makes **more than one table per page safe**: an update fans out to every
reference consistently, so "the same entity changes differently in two tables"
cannot happen. (Forbidding multiple tables per page was considered and rejected —
the same entity can also sit in a modal and in page-data, so the invariant, not a
ban, is the real guarantee.) Uncommitted edit drafts stay with the editing modal
(edit-session-scoped), never smeared onto the shared entity — other views show
committed data until merge ([conflict-resolution.md](conflict-resolution.md)).

## Entity-store upsert — field-merge, absence ≠ null

Upserting a fragment is a field-wise merge (a union of fields): a field present
in a payload overwrites; a field **absent** is left untouched (absence is not
`null`); an explicit clear is an explicit `null`. This is safe because each data
chunk has one authoritative source, so concurrent projections never disagree on a
field's value. As a recommendation (not a hard rule), avoid designing
subscriptions that deliver divergent field-sets for the same `(entityType, id)`;
the merge is a safety net, not a license for divergent projections.

## Clearing an entity slot — a `null` slot value

A slot value may be `null` (not a fragment, not an array of them): a payload
delivers `entities: { currentUser: null }`. That is the **downgrade** signal —
the entity left the slot, so the slot's reference is dropped and its selectors
read empty (e.g. the session user on logout, the symmetric inverse of delivering
a fragment). Keep the three cases distinct: an **absent** slot is untouched; a
`null` on a *field* inside a fragment clears that one field (above); a `null` on
the *slot* clears the whole reference.

## Tables, lists, and page data

- **A table belongs to exactly one page (1:1); a page may host many tables (1:N);
  N:M is not provided.** To show "the same" table on another page, make a second
  table from a shared config *template* — reuse is at the definition level, never
  a binding that spans pages. Row data is page-scoped and dropped on navigation.
  The subscription mechanics are in [table-subscription.md](table-subscription.md).
- **A list is a lighter primitive** — an ordered collection of entity references
  or simple items, with no pending/Apply — for catalogs, option sets, and menus;
  an append-stream (chat log) is its live variant.
- **No fake tables.** Wrapping non-table data into a one-row / one-column "table"
  because `tables` is a convenient channel is a gross violation: table data goes
  to tables, page data to the page-data store, an ordered collection to a list.

## Cataloged tables — mutations are catalog-bound

A **cataloged table** has a fixed key set defined by a PHP catalog — an array of
constants on the backend (settings is the first: `SettingsCatalog::getCatalog()`,
keyed by setting-key constants). The backend merges that catalog with the
persisted override rows into **one row per cataloged key**, so every key is always
present even with no DB row behind it; each row carries a `value_source`:
`default` / `reference` (on the catalog default), `override` (a persisted custom
value), or `orphan` (a persisted key no longer in the catalog).

Because the key set is fixed, the table has **no "create a new record"
operation**, and a cataloged-table page offers exactly three mutations:

- **add by key** — give a key on its default a custom value
  (`default` / `reference` → `override`); the row already exists, so this sets an
  override, it does not mint a key. It lives **on the row**, not behind a separate
  "Add" button.
- **edit by key** — change an override, or reset it back to the catalog default.
- **edit / delete an orphan** — the orphan is the only deletable kind; a cataloged
  key cannot be deleted (it would re-appear on its default).

**Do not** add a free "create / add row" control that mints an arbitrary key: it
models a create the domain does not have, and a free-typed key could only become
an orphan. **Exception:** a project that *explicitly* asks for free creation. The
constraint is a backend invariant, not UI hiding — the add action **must** reject
a non-cataloged key server-side ([rules-and-violations.md](rules-and-violations.md), "Hiding ≠ securing"); the
settings add path does so in `SettingsActions::add` (`SettingNotInCatalogException`).

## View-models live in `types/`, by primitive

A selector resolves a store into the shape a view renders — a roster item, a bot
item, an event line. These **view-models** are `interface` / `type` declarations
(never `class`; a class is for behavior — the stores, the connection, the router
— not for a data shape, and the framework's own `Entity` and `User` are
interfaces). They live in `types/`, **not** in the selector file that builds
them: a selector *imports* its view-model, it does not declare it. The `types/`
tree is partitioned by the kind of data each shape projects, mirroring the
primitives above:

- **root** — entities, one object keyed `(entityType, id)` (`User`, `Event`).
- **`lists/`** — list-item view-models, one per list row (`ParticipantItem`,
  `BotItem`). Named `…Item`, because a list is a sequence of items — **not**
  `…Row`, which names a *table* row and would imply a table where there is none.
- **`tables/`** — table-row view-models (the heavy windowed primitive).
- **`views/<page>/`** — page-specific data that is none of the above: the unique
  shapes a single page needs (not an entity, not a list item, not a table row).

## Ephemeral signals — a separate class

Presence and "typing…" are not entity data and not pending changes. They live
only in a TTL-based, read-only **ephemeral** slice, pushed via group or page
signals, auto-expiring on heartbeat and dropped and re-derived on reconnect (no
stale banner). Presence derives from `Hilos::$rt` connection liveness; "typing"
is a fire-and-forget group signal with a TTL.
