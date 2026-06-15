# Rules and Violations

The cross-cutting rules every Hilos frontend obeys, plus the catalog of "gross
Hilos violation" patterns. An AI agent building or reviewing frontend code
applies these across every surface; each rule points to the topic document that
elaborates its mechanism.

A **gross Hilos violation** is a pattern the framework treats as categorically
wrong — it breaks an architectural guarantee and must be fixed, not justified.
Such patterns are marked below. The reason for investing in this many rules is
in [ai-first-premise.md](ai-first-premise.md).

Topic documents are referenced by filename; several are still being authored.

## A. Foundational model

- **Authoritative backend — no optimistic updates.** Every user action emits
  (1) an immediate loading or blocked UI state and (2) a signal to the backend;
  frontend state mutates *only* on a backend signal — never optimistically, not
  even for the user's own edits. The backend is the single source of truth for
  every state transition. See [core-and-connection.md](core-and-connection.md).
- **Three orthogonal state machines.** Connection (transport), authentication
  (identity per session), and authorization (permission per resource) are
  separate and fail independently. An authorization denial is localized ("no
  access" on that page or action), never a disconnect or a logout. See
  [core-and-connection.md](core-and-connection.md).
- **All validation is backend-only.** The form submits, the backend judges,
  field errors return via the action's `::fail`. Deciding valid/invalid or
  gating submission with frontend logic is a **gross violation** — it duplicates
  backend logic and diverges. Non-deciding hints (required markers, format
  examples, counters, helper text) are allowed. See [core-and-connection.md](core-and-connection.md).
- **Hiding ≠ securing.** Hiding a control client-side is UX only, never
  protection. Every action and page is authorized server-side, and a declared
  authorizer is *mandatory*: the framework refuses to dispatch an action or
  honor a subscribe with no authorizer attached, and "public" must be declared
  explicitly — never a silent default. See [core-and-connection.md](core-and-connection.md) and
  [wire-protocol.md](wire-protocol.md).
- **No role-gated rendering before identity resolves.** Do not paint
  role-dependent chrome until the role (guest / user / admin / …) is known; then
  render immediately. Public pages render immediately regardless. See
  [core-and-connection.md](core-and-connection.md).

## B. Wire protocol and connection

- **Cookie = auth credential only.** The session credential rides an
  `httpOnly + Secure + SameSite` cookie set by the server; app code cannot read
  it and must never try. Any *non-auth* use of a cookie is a **gross
  violation**. Secrets never go in localStorage (it cannot be made httpOnly);
  non-secret UI state may. The source of truth is a server-side session in
  Hilos's own store, not PHP `$_SESSION`. See [wire-protocol.md](wire-protocol.md).
- **Connection identity is a server-assigned connId.** The per-connection id is
  minted server-side at accept time and held in a `connId ↔ socket` map; the
  client never sends a connection id. An incoming frame's connection is
  determined by the socket it arrived on. `Sec-WebSocket-Accept` is a transport
  artifact only — never an app-level routing or identity token, never placed in
  a client-readable payload, never trusted back from the client. See
  [wire-protocol.md](wire-protocol.md).
- **All signal parsing goes through a discriminated union.** One canonical,
  runtime-validated parse boundary; narrow by the `type` / `dataType` tag with
  compile-time exhaustiveness. Ad-hoc shape-sniffing (`typeof`, `'x' in msg`) to
  identify a message is a **gross violation**. Narrowing is layered: envelope →
  category → concrete typed signal. See [wire-protocol.md](wire-protocol.md).
- **Action lifecycle: deferred loading → personal reply → timeout.** Show
  loading deferred ~0.3s (fast operations never flash a spinner); wait for that
  action's own `::success` / `::fail`, correlated by a client-minted `requestId`
  the backend echoes; treat a ~30s timeout as the third outcome. A timeout
  releases the UI but keeps the orphan recorded so a late reply can reconcile —
  never silently dropped (a late reply surfaces as a toast). See
  [wire-protocol.md](wire-protocol.md).
- **Subscription model.** Changing the current page's params uses
  `page_update_subscription`; tearing down and re-subscribing to change them is
  a **violation**. Cross-page navigation is a single atomic re-subscribe that
  replaces the current page sub (page is 0..1) — not a separate unsubscribe plus
  subscribe. Every page signal carries its page key so the normalizer drops
  signals for a page the client has left. Group subscriptions are 0..N and
  survive navigation. See [wire-protocol.md](wire-protocol.md).
- **Auth seam: framework machinery, project credential.** The framework owns
  cookie mechanics, the session store, revocation, GC, the handshake protocol,
  and a default username/password login. The project plugs in a credential
  verifier (`HilosAuthProvider::verify`) and may override the login flow. See
  [wire-protocol.md](wire-protocol.md).
- **Client build-version check forces refresh.** A build timestamp in `.env`
  rides the handshake; the SPA compares it on every connect/reconnect and forces
  a refresh on mismatch. With a modal open, prefer a "Refresh & keep my draft"
  path (persist the modal descriptor and draft to localStorage, refresh,
  rehydrate) over yanking the refresh out from under an active edit. See
  [wire-protocol.md](wire-protocol.md).
- **File upload only via the WS `frame_binary` channel.** Uploading files
  through any other channel (HTTP multipart, etc.) is a **gross violation**. See
  [wire-protocol.md](wire-protocol.md).

## C. Data model and stores

- **Never dilute the browser payload.** One normalizer boundary is the only code
  allowed to touch the raw backend payload; everything downstream reads typed
  selectors. Reshaping, flattening, or spreading the raw payload into ad-hoc
  local state is a **gross violation**. See [data-model.md](data-model.md).
- **Non-entity stores hold entity references, never copies; one entity per
  `(entityType, id)` per scope.** This invariant is what makes more than one
  table per page safe — an update fans out to all references consistently. See
  [data-model.md](data-model.md).
- **Three keys, never conflated.** `rowKey` (a row's identity within a table,
  not a DB id), `sourceKey` (a fragment-slot alias composing a row), and
  `entityType` (an entity's canonical type) are distinct. A table row is a
  composition of source slots, not one DB row. The entity key is
  `(entityType, id)`. See [data-model.md](data-model.md).
- **No fake tables.** Wrapping non-table data (page scalars, an option set, a
  single value) into a one-row / one-column "table" because `tables` is a
  convenient channel is a **gross violation**. Table data goes to tables, page
  data to the page-data block, an ordered collection to the list primitive. See
  [data-model.md](data-model.md).
- **Cataloged tables are catalog-bound — no free add.** A table whose key set is
  fixed by a PHP catalog (an array of constants, e.g. the settings catalog) offers
  only add-by-key (set an override on an existing cataloged key, from its row),
  edit-by-key, and edit/delete of an orphan — not a free "create a new record"
  control that mints an arbitrary key (which could only become an orphan). The add
  action rejects a non-cataloged key server-side; it does not merely hide the
  control. Exception: a project that explicitly asks for free creation. See
  [data-model.md](data-model.md).
- **Entity-store upsert is a field-merge; absence ≠ null.** A field present in a
  payload overwrites; a field absent is left untouched; an explicit clear is an
  explicit `null`. Safe because each data chunk has one authoritative source.
  Avoid designing subscriptions that deliver divergent field-sets for the same
  `(entityType, id)` (a recommendation, not a hard rule). See [data-model.md](data-model.md).
- **A list is a first-class primitive, distinct from a table.** A list is a
  light ordered collection (entity refs or simple items) with no pending/Apply —
  for catalogs, option sets, menus. An append-stream (chat log: last-N, live
  tail, load-older-upward) is its live variant. Tables stay the heavy primitive.
  A list is delivered incrementally — create appends, update replaces in place,
  delete drops — and its entity-bearing slots are references, so an entity
  update is never re-streamed per list. See [data-model.md](data-model.md) and
  [table-subscription.md](table-subscription.md).
- **Keep the frontend slot→type map in sync with the backend source.** The
  `sourceKey → entityType` override that types entity slots (in entity sections
  and in list items alike) is frontend config, not emitted on the wire. When you
  add or change it, **read the matching backend browser source** and confirm the
  same slot keys and entity types — a stale map silently mis-types a slot and the
  normalizer cannot catch it (the wire slot is opaque). A backend source-shape
  change is a trigger to re-verify every frontend map that reads it. See
  [data-model.md](data-model.md).
- **Ephemeral signals are their own class.** Presence and "typing…" live only in
  a TTL-based, read-only ephemeral slice — never the entity store, never
  pending/Apply. They auto-expire on heartbeat and are dropped and re-derived on
  reconnect (no stale banner). See [data-model.md](data-model.md).

## D. Tables

- **Table↔page is 1:1; a page may host many tables; N:M is not provided.** Reuse
  "the same" table on another page by making a second table from a shared config
  template — not a binding that spans pages. See [table-subscription.md](table-subscription.md).
- **The viewport changes only by explicit user action.** Filter, sort,
  paginate, or navigate change the viewport; applying pending changes never moves
  it (no auto-jump, no backfill). Conversely, any explicit viewport change
  applies pending first. Anchor the window by row-id, not raw offset. See
  [table-subscription.md](table-subscription.md).
- **Search is a table filter, not a framework feature.** There is no
  framework-level page search or command palette; the only framework search is a
  filter inside a table, taking the same path as any other viewport change. See
  [table-subscription.md](table-subscription.md).

## E. Editing and modals

- **Edit only in a modal.** All editing happens in a modal; inline forms are
  forbidden. See [conflict-resolution.md](conflict-resolution.md).
- **The modal owns a baseline / draft / incoming 3-way merge.** On open, freeze
  a baseline snapshot and clone it into an editable draft; keep the edited entity
  live-subscribed so the modal sees incoming committed changes; merge per field
  against the baseline and surface genuine conflicts to the user. Pages outside
  the modal always show committed data; uncommitted drafts never smear onto the
  shared entity. Deviating from this modal-owned merge is a **gross violation**.
  See [conflict-resolution.md](conflict-resolution.md).
- **Entity deleted while its edit modal is open.** The modal stays open, save is
  blocked, the primary button reads "Deleted", and the user's draft stays
  visible and extractable — never silently discarded. See
  [conflict-resolution.md](conflict-resolution.md).

## F. View layer, SDK, and components

- **One page, one module — never a shared page map.** A page's identity,
  content, and render logic live in its own `views/<Page>/` module; collecting
  many pages' titles, leads, parents, or render logic into one file (a "page
  map", an "admin map") wired to a single shared component is a **gross
  violation**. A flat catalog of page *identity* (the route table, the footer
  set, the admin tree) is a registry, not a violation; and a *page-agnostic*
  shared component (a breadcrumb, an admin-page shell) parametrized by a page key
  is the sanctioned reuse. A framework set of **default admin views** for its own
  un-implemented pages — one real, per-file page per catalog key (under
  `@hilos/vue/src/admin/`, each rendering the page-agnostic shell), collected into
  a key → component map (`hilosAdminViews`) — is likewise sanctioned: a project
  spreads it and overrides a key only when it implements that page itself. The
  bright line holds — every page is its own file, no page content/metadata map in
  the project, and only `HilosView` reads the navigator. See
  [page-module-structure.md](page-module-structure.md).
- **All non-visual logic lives in the agnostic core; views are thin and
  per-framework.** See [multiframework-core.md](multiframework-core.md).
- **Data view-models are declared in `types/`, never in a selector or action
  file.** A file that builds selectors, signals, or actions *imports* its
  view-model types; it does not declare them. Data shapes are `interface` /
  `type` (so are the framework's own `Entity` and `User`) — `class` is reserved
  for behavior-bearing objects (the stores, the connection, the routers), never
  a data shape. `types/` is organized by the kind of data each shape projects:
  entities at the root, `lists/` for list-item view-models, `tables/` for
  table-row view-models, `views/<page>/` for page-specific data that is none of
  those. A list-item view-model is an `…Item`, never a `…Row` — `Row` names a
  *table* row, so it implies a table where a list has none. See
  [data-model.md](data-model.md).
- **The agnostic core never imports Vue (the framework).** It uses only a
  neutral signal primitive and plain TypeScript. Agnosticism is proven by minimal
  React and Angular conformance demos kept green in CI — not by full parity. See
  [multiframework-core.md](multiframework-core.md).
- **The SDK is two tiers.** Universal components (modal, loading-button, toast,
  inputs, table shell) offer maximum extension, authored slot-first with scoped
  slots and composables. Page-chunk components (feature pages) are more
  opinionated, with fewer extension points. The mechanism across both is slots +
  scoped slots + shared composables — no mixins; "empty inheritance" is a
  one-line re-export when nothing is customized. See [sdk-packaging.md](sdk-packaging.md).
- **Error boundaries are required.** A standard guard wraps each page or major
  block so one component's runtime error degrades locally instead of blanking the
  long-lived SPA. Ships as a tier-1 `HilosErrorBoundary`. See [sdk-packaging.md](sdk-packaging.md).
- **Standard data-block state components.** Tier-1 skeleton (loading), empty
  ("nothing here"), and error ("failed to load") components are the concrete form
  of "a placeholder for everything"; pages do not reinvent them. See
  [sdk-packaging.md](sdk-packaging.md).
- **Per-page code-splitting.** Each route component is a dynamic `import()` so
  the bundler splits a chunk per page; the loading state during chunk fetch is
  the skeleton, and a stale chunk after redeploy is caught by the build-version
  check. See [build-and-docker.md](build-and-docker.md).

## G. Styling and accessibility

- **Bootstrap classes only.** Express all styling with Bootstrap classes. Inline
  `style`, SFC-trailing `<style>` blocks, global stylesheets, and any
  hand-authored `.css` are a **gross violation**. Custom style declarations live
  only in the Bootstrap Sass layer (variables, maps, custom-utilities), each with
  a comment stating why Bootstrap utilities cannot achieve it. See
  [styling-rules.md](styling-rules.md).
- **Full accessibility in v1.** Focus-trap and focus-return in modals, full
  keyboard navigation, ARIA roles and labels, and visible focus with adequate
  contrast ship from day one. See [styling-rules.md](styling-rules.md).

## H. Feedback and freshness

- **Every user operation emits a toast.** Success/fail feedback follows every
  action (pairs with authoritative-backend); distinct from the stale-data
  banner. See [core-and-connection.md](core-and-connection.md).
- **Stale data is a single global banner, not per-element marks.** When
  on-screen data may be stale, surface it with one global banner; do not mark
  each stale datum. `stale` remains a real per-datum state internally. Projects
  may add extra connection-status indicators. See [core-and-connection.md](core-and-connection.md).
- **No staleness timestamps in the UI.** Never show "synced 3 min ago" or "last
  updated …" — with a connection the data is current, so a timestamp only
  deceives. Not-current is surfaced only by the global banner. See
  [core-and-connection.md](core-and-connection.md).
- **Auth-gated routing is a content-swap, not a redirect.** A guest on a
  non-guest page renders the auth form in place; a logged-in user lacking rights
  renders an explicit "no access" in place. The URL is the source of truth for
  the page sub on cold load. See [core-and-connection.md](core-and-connection.md).

## I. Testing

- **e2e interacts via stable element ids only.** Every interactive element
  carries an id; never text- or position-based selectors. See
  [testing-strategy.md](testing-strategy.md).
- **Backend test state resets fully per test.** Reset the database and daemon
  per test; never transaction-rollback, never data-namespacing. See
  [testing-strategy.md](testing-strategy.md).

## J. Explicit non-goals

- **XSS / CSP is intentionally not a framework rule.** A framework rule cannot
  protect a consumer project from a careless implementer, so this is left to
  per-project agent review. The SDK's own components still render
  user-generated content safely by default — plain good code, not a
  project-facing rule.
- **No converters or PHP→TS codegen in v1.** The AI agent keeps the PHP and TS
  contracts consistent from this specification; codegen is a recorded post-v1
  optimization. See [ai-first-premise.md](ai-first-premise.md).
