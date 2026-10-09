# Architecture: Admin Features

Read this before graduating or building a Hilos admin feature — an admin page
backed by a browser table plus its actions: settings, hilos-users, roles, or a
project's own admin table. This spec is graduated ahead of the code: it is the
target structure the settings/hilos-users graduation moves toward, not a claim
that every base class below already exists.

For the step-by-step order to activate one in a project — which files to create
per layer from the framework base classes — see
[admin-feature-scaffold.md](admin-feature-scaffold.md).

## Core Rule

An admin feature's generic machinery — the browser table projection, the page
subscribe, the action lifecycle — belongs to the framework. A project supplies
only content-binding: a catalog, a collection bound through a generic, and any
extra fields.

This mirrors the frontend, which is already thin: the framework owns the view
and the headless controller, the project passes a small typed context. Do the
same on the backend — do not leave the generic table/page/action code copied
into each project. A project that needs `users`/`settings` should activate,
configure, and use them, not re-author ~1000 lines of table+page+action code.

## Two Modes (one engine)

Both modes ride the same engine — a `TableDefinition` browser-merge plus the
page-action CRUD lifecycle. The only difference is who owns the concrete entity.
A spec section and any skill wrapper must keep this fork explicit.

### Mode 1 — Framework-owned feature: activate, configure, use

For features the framework owns end to end: `settings`, `hilos_users`, `backup`,
and later `roles`. The languages and countries section is one more
([languages-and-countries.md](languages-and-countries.md)). The Daemon section
is one more ([daemon-section.md](daemon-section.md)). The framework owns the
browser table, the subscribe, and the actions. `backup` is the same mode with a
wider engine — the framework also owns its monopoly agent, cron schedule,
`mysqldump` child command, and retention pruner, and the project supplies only a
catalog (reference registry + optional schedule), the backup env values, and the
registration/binding; see the backup recipe in
[admin-feature-scaffold.md](admin-feature-scaffold.md).
The project:

- declares the feature in `Hilos::FEATURES` — the single on-switch, and what the
  activation check reads; see
  [app-topology.md](../app-topology.md#feature-declaration);
- declares the content — a catalog (settings), or, for hilos-users, a subclass
  chain of the people table `hilos_user` when the project needs columns of its
  own (see [people-table.md](people-table.md)),
  or the collection it binds;
- sets one `SUBSCRIPTION_AGENT_TYPE`;
- ships a `BrowserContext` so the table's snapshot reaches the browser — an empty
  subclass suffices (the framework default is `null`); see *Browser delivery* below;
- registers the page/table in `Hilos` topology (see
  [app-topology.md](../app-topology.md)).

A framework-owned section may have no on-switch at all: the mechanism under it
is unconditional, and a feature case for it is banned
([protected-mode.md](protected-mode.md), *Anti-Patterns*). The project activates
such a section by registering its page and table in the topology, with no line
in `FEATURES`; the first one is `hilos_maintenance`
(`Hilos\Pages\Maintenance\AbstractHilosMaintenancePage`), which shows the
verifier circle through the framework's `HilosVerifierCircleTable`. It is also
where an admin closes the system to visitors by hand
([protected-mode.md](protected-mode.md), *Manual Maintenance*)
(not in the code yet — HIL-1362).

A framework section may also come with a feature case that is not its own. The
analytics section has no separate case: `HilosFeature::ANALYTICS` enables both
collection and the section. Activation requires its agent and base page and
refuses them without the feature. The visible overview card follows in HIL-1418.
For what the backend reads and the later pages show,
see [analytics.md](analytics.md#the-admin-section), *The Admin Section*.

The project must NOT copy the table query, the catalog-merge, the value-source
logic, or the action routing. Those are framework-owned.

### Mode 2 — Project-owned feature, by pattern

For an app-specific admin table that deliberately diverges from a framework one:
the chat demo's bots table is the live reference (a project-owned table beside
the framework's `hilos_users`). The project owns the concrete entity but
reuses the SAME generic bases — `TableDefinition` browser-merge, the page-action
CRUD lifecycle. The project writes only the row shape, the declared DB/RT
sources, and the field mapping; never the engine.

A new admin feature (e.g. a project's own audit table) is built right and easily
by following Mode 2, not by copying a framework page wholesale.

## Closing a project's own admin page

A Mode-1 page is closed before the project touches it: `AbstractHilosPage`
inherits `ACCESS_LEVEL = PageAccessLevel::ADMIN` to every descendant, so a
project that activates settings or hilos-users inherits the gate with the page.

A Mode-2 page inherits nothing of the sort. It extends `AbstractPage`, whose
default level is `PUBLIC`, so silence leaves the page — and every action it
declares — open to an anonymous session. Closing it is one line on the page
class:

```php
final class AdminBotsPage extends AbstractPage
{
    public const string PAGE = PageConstants::ADMIN_BOTS;

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::LIBRARY;
}
```

**The route's `admin: true` marker is not that closure.** The marker states
surface *type* to the shell — which nav entry to draw, which route the client
reaction treats as administrative — and it grants nothing and refuses nothing.
Only the server may state access. A page carrying the marker and no server
closure is the worst of the two: it looks gated, so nobody notices it is not.

**What that combination actually does (HIL-652).** A `PUBLIC` page with no
browser guards is skipped by the re-decision sweep, on purpose — its verdict
cannot turn on who is asking, so re-sending it on every rights change would be
pure noise. But the client reaction reads the route marker, so on sign-out it
drops the page data and waits for an answer the server will never send. The tab
sits on an empty waiting page until the person reloads it. Before the sign-out
re-decision existed the same place drew a permanent 403, so the marker was
already writing a verdict it had no right to write; the re-decision only made
the silence visible.

**Level or `ACCESS` guard — pick by which agent serves the page.** Both close a
page and both make it re-decidable; they read identity from different places:

- the **level** resolves through the `isAdmin()` seam, which runs in whatever
  worker serves the page and reads the framework's person table `hilos_user`.
  This is what the framework admin surface itself uses, across agents.
- an **`ACCESS` browser guard** is re-checked by the reactive fan-out inside the
  page's own agent, so it may only sit on a page whose `SUBSCRIPTION_AGENT_TYPE`
  agent OWNS the identity sources it reads — the cross-agent guard rule in
  [page-access-control.md](page-access-control.md). On an agent that merely
  mirrors users and connections it flickers to 403 on a stale read.

An admin page is closed by the level, whichever agent serves it: the admin view
mode ([admin-view-mode.md](admin-view-mode.md)) is given by the level alone — it
opens an `ADMIN` page for looking — while an `ACCESS` guard is judged after the
level and keeps a viewer out, so a page closed by a guard on the admin flag
would be the one admin page a viewer cannot look at. The chat demo's
`AdminBotsPage`, served by the library agent, declares
the level and no guard (HIL-1251). Needing "signed in" rather than "admin" is the
same line with `PageAccessLevel::AUTHENTICATED`, and never a parallel `AUTHENTICATED`
guard.

Closing a page is a behavior change for its tests too: an e2e that used to walk
onto the page anonymously must take the grant first (`signUpAdmin` in the chat
demo), and a second tab of the same browser context inherits the session rather
than needing its own.

## The view mode: what every admin page owes

A Mode-1 page and a Mode-2 page alike are born in the admin view mode
([admin-view-mode.md](admin-view-mode.md)): with the mode on, a non-admin
viewer opens the page, is shown what is not personal, and changes nothing. The
page is safe by default — its fields are hidden until declared, its actions
refused until declared reading — and it owes the viewer five things to be
useful:

- declare its reading actions in `AbstractPage::READING_ACTIONS`;
- declare the not-personal fields of its rows and frames, by the verdict of the
  column or by a declaration on the field — `TableDefinition::wireFields()`,
  the `notPersonal` key of a declarative row, `AbstractPage::dataFields()`, a
  frame DTO's static `wireFields()` — and never declare a column holding a
  person's data not-personal for the viewer's sake;
- build every mutation out of the controls of the mode;
- put no exception text into a frame by itself — a frame of its own carries
  `AbstractPage::failureText()`;
- send its frames by the page's path, never past the personal-data bridge: a
  frame to the page's own subscriber set goes through
  `AbstractPage::frameForViewer()`.

An integration test under a viewer — frames without anything personal, a
writing action refused — travels with the section, not as a leaf of its own.

## The framework/project boundary

| Layer | Framework owns | Project supplies |
|---|---|---|
| Browser table | the merge/query/mutation engine + base row contract | the row's extra fields, the declared sources, the field map |
| Page | subscribe + the action lifecycle (ack/error) | `SUBSCRIPTION_AGENT_TYPE`, registration |
| Actions | the add/update/delete dispatch over `Hilos::$table` | nothing for a framework feature; the entity's actions for a Mode-2 one |
| Data | settings collection (`HilosDbContext`); the people table `hilos_user` | a project entity (Mode 2), a subclass chain of `hilos_user` when the project adds columns, the settings catalog |
| Browser delivery | snapshot + reactive push, incl. the self-snapshot path for catalog tables | a `BrowserContext` (an empty subclass suffices for a framework feature) |
| Frontend | the view + the headless controller (`@hilos/core/admin/*`) | a thin typed context + a wrapper |

## Browser delivery: self-snapshot tables

A framework table still needs a `BrowserContext` to reach the browser, and a new
project must ship one: the framework `createBrowser()` default is `null`, and a
page with no browser context answers a subscription with nothing.

Most browser tables are delivered by source fan-out — the `BrowserContext` builds
rows from DB/RT source items as they change. That holds only when every row maps
to a real source item. A catalog-backed table breaks the assumption: `settings`
rows for on-default keys have no DB row, so source fan-out yields an empty table.

The framework resolves this with the self-snapshot contract
(`Hilos\Core\Table\Definition\SelfSnapshotTable`). Such a table produces its own
browser rows from `getFullSnapshot()` (the catalog+DB merge) and
`buildMutationForSourceEvent()` (a reactive change), serialized by a table-owned
`browserRow()`. The base `BrowserContext` branches on `instanceof SelfSnapshotTable`
in both `buildSubscribeSnapshot` and `emitBrowserSignals`, using the table's own
snapshot instead of source fan-out. `HilosSettingsTable` implements it, so an
empty project `BrowserContext` inherits the whole path — settings needs no project
browser code beyond shipping the (empty) context.

This is why settings is configure-only on the data side yet still requires a
project `BrowserContext`: the delivery is framework-owned, the context is the one
object the project must provide.

## Extension model

Follow the framework extension contract in
[framework-development.md](../framework-development.md):

- Vary behavior through a protected factory/override; the default framework
  implementation stays simple (`return new FrameworkThing();`), the project
  subclass returns `new ProjectThing();`.
- Vary metadata-only content through a catalog provider class-string constant
  (e.g. `SETTINGS_CATALOG`), never an empty subclass that only re-returns a
  catalog.
- Bind a collection through a generic, so the framework never imports the
  project entity type. This is the backend twin of the frontend
  `HilosUsersContext<TUser extends User>`: the project passes its
  collection in; the framework code stays type-agnostic.
- Abstract the presence source. The hilos-users table merges DB users with an
  online/presence summary; the framework owns the merge, the project binds its
  own RT connections collection as the presence source rather than the framework
  hard-coding a project RT key.
- The account `block` column and its reading are the framework's: `block` is a
  column of the people table `hilos_user`, and the framework reads it itself
  wherever a guard runs, as any column of its own collection — there is no
  block source to implement and no door to ask through, and the framework
  declares `users` a process-wide read of its own. Whoever writes `block` sends the
  sessions library `hilos_account_block_changed` {userId}; the library reads the
  flag itself, signs the person out, refuses their sign-in and leaves the
  "Access closed" card the shell draws (HIL-289). A missed frame is caught at
  the next handshake. Lifting the block signs a browser still on the card back
  in on a new token, walking one refused before the second factor on to it, and
  a browser away at that moment at its own handshake; a password change or "end
  other sessions" cancels the return of the others (HIL-1188). The admin card
  is the exception to sending that frame: the sessions library has the person's
  agent write the flag (`writeBlockFlag()`) and enforces it directly on the
  agent's answer (HIL-1404).

## hilos-users base

The framework owns the people table `hilos_user` whole — `id`, `name`, `admin`,
`block`, `last_activity` — plus the computed
presence/online summary. A project that needs more extends it by subclassing the
whole ORM chain and mounting it under the framework key; the rules of the table
are in [people-table.md](people-table.md). The frontend `User` of `@hilos/core`
carries `id`, `admin`, `block`, `name`, `lastActivity`; with it `@hilos/core`
ships `userFromFields` and `USER_ENTITY_TYPE`, so a project binds only its
collection and extends the type only with a field of its own.

A project's own admin table (Mode 2) is not a hilos-users extension. The chat
demo's bots table is that separate table; the framework feature is the panel
operators, the project table is the project's own.

The account card requires `writeBlockFlag()` on the person's agent
(`AbstractUserAgent`, asked by the sessions library, HIL-1404),
`assertAdministratorMayDelete()` on the users library, and `ADMIN_AUDIENCE`.
That audience judges the requesting administrator and protects the last active
one. All three are the framework's implementation, since the columns they read
are the framework's. `writeBlockFlag()` writes the block flag of the person's
`hilos_user` row and refuses a missing account and, both ways, an account folded
into another one — the merge table `hilos_user_merge` is the framework's too
(HIL-1199, [people-table.md](people-table.md), *A Merged Account*); a project
with a refusal of its own overrides it in its agent binding and refuses BEFORE
calling the parent, because the parent writes. `assertAdministratorMayDelete()`
refuses a missing account, an administrator and a folded account, in that order,
and a project with a refusal of its own overrides it and calls the parent first,
because that one only reads. `ADMIN_AUDIENCE`'s default `AdminAudience` answers
the unblocked, unmerged `hilos_user` rows that say admin, and a project points
the constant at a subclass only to narrow that circle further; no demo does.

## Preferred Shape

```php
// Mode 1: the project page is thin — subscription owner only.
// The subscribe signal, the action DTOs, and the add/update/delete lifecycle
// are framework-owned on AbstractHilosSettingsPage; the table key is registered
// in the project TableContext (ChatTableContext::settings => HilosSettingsTable::class).
final class SettingsPage extends AbstractHilosSettingsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}

// The catalog binds once on the Hilos facade, not on the page; the framework
// reads it back through the settings accessor (Hilos::$setting->catalog()).
// The project also ships a BrowserContext — an empty subclass is enough; the base
// context delivers the settings snapshot through the self-snapshot path.
final class Hilos extends \Hilos\Hilos
{
    protected const string SETTINGS_CATALOG = SettingsCatalog::class;

    protected static function createBrowser(): ?BrowserContext
    {
        return new AppBrowserContext();
    }
}

final class AppBrowserContext extends BrowserContext
{
}
```

## Anti-Patterns

- Do not copy a framework admin table's query/merge/mutation code into a project
  to "activate" the feature; bind content to the framework table instead.
- Do not pass `Hilos::$db`/`$rt`/`$setting`/`$table` through constructors to make
  a graduated base reach them; read the facade at the point of use
  (framework-development.md Core rule).
- Do not fold a project's divergent admin table (Mode 2) into the framework
  feature; the chat demo's bots table stays separate from `hilos_users`.

## Exceptions

- A feature with no framework generic yet (a brand-new admin area) starts as
  Mode 2 over the shared bases; it graduates to Mode 1 only if it becomes a
  framework-owned concept.
- A project may override a framework table's row projection through the factory
  point when its data genuinely differs; that is configuration, not copying.

## Frontend side

The frontend is already graduated: headless controllers are agnostic in
`@hilos/core/admin/*`, the views live in the SDK view packages, and a project
mounts them with a thin context. Two follow-ups are tracked, not done here:

- the views exist only in `@hilos/vue`; React/Angular need the port before a
  non-Vue demo can show real pages;
- the project context binding is the frontend twin of the backend collection
  binding above.

See [frontend/sdk-packaging.md](../frontend/sdk-packaging.md) and
[frontend/page-module-structure.md](../frontend/page-module-structure.md).

## Contract Gate

Graduating an admin feature moves the framework/project boundary and touches
contract surfaces. Stop and ask for explicit confirmation, per the root
`AGENTS.md` gate, before changing:

- the hilos-user DB entity fields or row contract;
- RT presence/connection item shape consumed by the merge;
- signal constants, action DTO payloads, or page/table declarative routing.

List the exact fields, DTOs, signals, and routes in the request.

## Validation

Use `$hilos-testing-cli` to choose composer scripts. A graduation must keep the
demo's existing admin e2e green (settings/users already have passing specs — the
move must not regress them), and add framework-level unit coverage for the
graduated base.
