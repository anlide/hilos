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
and later `roles`. The framework owns the browser table, the subscribe, and the
actions. `backup` is the same mode with a wider engine — the framework also owns
its monopoly agent, cron schedule, `mysqldump` child command, and retention
pruner, and the project supplies only a catalog (reference registry + optional
schedule), the backup env values, and the registration/binding; see the backup
recipe in [admin-feature-scaffold.md](admin-feature-scaffold.md).
The project:

- declares the content — a catalog (settings), or extra fields on the base
  entity (hilos-users), or the collection it binds;
- sets one `SUBSCRIPTION_AGENT_TYPE`;
- ships a `BrowserContext` so the table's snapshot reaches the browser — an empty
  subclass suffices (the framework default is `null`); see *Browser delivery* below;
- registers the page/table in `Hilos` topology (see
  [app-topology.md](../app-topology.md)).

The project must NOT copy the table query, the catalog-merge, the value-source
logic, or the action routing. Those are framework-owned.

### Mode 2 — Project-owned feature, by pattern

For an app-specific admin table that deliberately diverges from a framework one:
`admin_users` in the chat demo is the live reference (a users-like table built on
purpose to differ from `hilos_users`). The project owns the concrete entity but
reuses the SAME generic bases — `TableDefinition` browser-merge, the page-action
CRUD lifecycle. The project writes only the row shape, the declared DB/RT
sources, and the field mapping; never the engine.

A new admin feature (e.g. a project's own audit table) is built right and easily
by following Mode 2, not by copying a framework page wholesale.

## The framework/project boundary

| Layer | Framework owns | Project supplies |
|---|---|---|
| Browser table | the merge/query/mutation engine + base row contract | the row's extra fields, the declared sources, the field map |
| Page | subscribe + the action lifecycle (ack/error) | `SUBSCRIPTION_AGENT_TYPE`, registration |
| Actions | the add/update/delete dispatch over `Hilos::$table` | nothing for a framework feature; the entity's actions for a Mode-2 one |
| Data | settings collection (`HilosDbContext`); the hilos-user base entity | a project entity (Mode 2), extra hilos-user fields, the settings catalog |
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
in both `subscribeSnapshot` and `emitBrowserSignals`, using the table's own
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
  `HilosUsersContext<TUser extends HilosUserProfile>`: the project passes its
  collection in; the framework code stays type-agnostic.
- Abstract the presence source. The hilos-users table merges DB users with an
  online/presence summary; the framework owns the merge, the project binds its
  own RT connections collection as the presence source rather than the framework
  hard-coding a project RT key.

## hilos-users base

The framework owns the base hilos-user entity: `id`, `admin`, `block`, plus the
computed presence/online summary. A project extends it with whatever it needs
(name, last-activity, e-mail, …) through the field-extension point above. This
matches the frontend `User` type, which already carries the mandatory RBAC
`superadmin`/`blocked` flags.

`admin_users` is NOT a hilos-users extension — it is a separate, project-owned
table (Mode 2). Keep the two distinct; the framework feature is the panel
operators, the project table is the project's own.

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
  feature; keep `admin_users` separate from `hilos_users`.

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
