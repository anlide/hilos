# Architecture: Admin Feature Scaffold

Read this before generating the activation of a framework-owned admin feature —
settings, hilos-users, and future ones like roles — in a project. This is a
generation recipe for an AI agent: given a framework admin feature, produce the
project-side code that activates it. The normative boundary and the two modes
live in [admin-features.md](admin-features.md); this file is the per-feature
generation order.

**Scope: framework-owned features only.** A project's own divergent admin table
(`admin_users`, Mode 2) is not scaffolded here — that is authoring a new feature,
not activating a framework one. See [admin-features.md](admin-features.md) Mode 2
for that.

## Core Rule

Generate activation from the framework contract, not by copying another project.
The framework owns the engine — the browser-table merge, the page subscribe, the
action lifecycle. The agent generates only the project-side binding each
feature's base class declares as its extension points. Never generate a copy of
the engine.

## Generate against the contract

Each framework feature ships base classes plus the extension points a project
fills. Read the base class, then generate what it leaves abstract — do not look
for a project to clone. Two contract shapes recur:

- **Framework-owned data source (configure-only).** The framework owns the table
  and the DB source; the project supplies only content. Settings is this shape:
  `Hilos\Tables\Settings\HilosSettingsTable` is `final`, the `setting` source is
  on `Hilos\Database\Context\HilosDbContext`, and `Hilos\Pages\AbstractHilosSettingsPage`
  owns the whole add/update/delete lifecycle. The only extension points are a
  catalog provider and one `SUBSCRIPTION_AGENT_TYPE`.
- **Project-owned data behind a framework contract (bound).** The framework owns
  the merge engine and the row/presence contract; the project owns the data and
  binds it. Hilos-users is this shape: `Hilos\Tables\Users\AbstractHilosUsersTable`
  is abstract with five hooks, `AbstractHilosUserTableRow` fixes the base
  `id`/`admin`/`block` fields, and presence flows through the
  `Hilos\Runtime\View\Collection\HilosPresenceSource` interface returning a
  `Hilos\Runtime\View\DTO\HilosUserPresenceSummary`. Bound is not "inventing a
  table" — it is implementing a framework contract.

## Generation recipe

### settings — configure-only

The framework owns everything mechanical. Generate, in any order:

1. A catalog provider class holding this project's setting keys and defaults
   (project content). Bind it through the `SETTINGS_CATALOG` constant on the
   project `Hilos` facade; the framework reads it back via `Hilos::$setting->catalog()`.
2. A subscription-owner page — `final class … extends Hilos\Pages\AbstractHilosSettingsPage`
   carrying only one `SUBSCRIPTION_AGENT_TYPE` — and register it in the project
   `PAGES`.
3. Register the framework table in topology: `TableContext::settings =>
   Hilos\Tables\Settings\HilosSettingsTable::class`, returned from the project
   `createTable()`. Register it; never subclass or re-implement it.
4. Mount the SDK view: map `HilosPages.SETTINGS` to the framework view from
   `@hilos/{vue,react,angular}` `admin/settings/HilosSettingsPage`. No project
   view code.

No entity, no RT collection, no subclass — that is the configure-only floor.

### hilos-users — bound

The framework owns the table merge, the page subscribe + actions, the presence
contract, and the base row. The project generates the data binding the contract
requires, in dependency order (the table merges sources that must exist first):

1. **DB user entity.** Generate the project's panel-operator entity triad and
   migration. `id`, `admin`, `block` are the framework-fixed base fields the
   project persists; add project fields beside them. Register the collection on
   the project `DbContext`. *(Contract Gate: entity fields.)*
2. **RT presence source.** Generate an RT connections collection that
   `implements Hilos\Runtime\View\Collection\HilosPresenceSource`, returning a
   `HilosUserPresenceSummary` from `summaryForUser(?int)`. Register it on the
   project `RtContext`, returned from `createRuntime()`. *(Contract Gate: RT item
   shape.)* Presence comes from this project RT collection — never framework
   analytics, which is process-local and not user-keyed.
3. **Table.** Generate a subclass of `AbstractHilosUsersTable` implementing the
   five hooks (`usersSourceKey`, `presenceSourceKey`, `presenceSource`,
   `rowForUserId`, `resolveUserIdForPresence`) and a subclass of
   `AbstractHilosUserTableRow` that folds the base fields via `baseFields()` and
   adds the project columns. The merge dispatch is `final` in the base — do not
   re-implement it.
4. **Page.** Generate thin concrete pages — `extends Hilos\Pages\Users\AbstractHilosUsersPage`
   / `AbstractHilosUserPage`; the subscribe and the action lifecycle stay
   framework-owned.
5. **Topology + frontend.** Register the page(s) in `PAGES`, the table in
   `TABLES`, the binding in `PAGE_TABLES`. Mount the SDK views: map
   `HilosPages.USERS`/`USER` to the `@hilos/{vue,react,angular}` `admin/users/`
   views, passing the thin typed context — the frontend twin of the backend
   collection binding.

### a future framework feature (roles, …)

A new framework admin feature ships the same shape: a base page (subscribe +
actions), a base table (the engine), and either a framework-owned source or a
project-bound contract. Pick the recipe by which one it is:

- framework owns the data source → follow the **settings** recipe (catalog +
  register the `final` table + thin page + mount);
- data is project-owned behind a framework contract → follow the **hilos-users**
  recipe (generate the binding: entity / presence source + the abstract hooks +
  thin page + mount).

If neither fits — there is no framework base yet — the feature is not ready to
scaffold; it must first be built or graduated per [admin-features.md](admin-features.md).

## Preferred Shape

```php
// configure-only: the generated page is the subscription owner only.
final class SettingsPage extends Hilos\Pages\AbstractHilosSettingsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}

// bound: the generated subclass implements the hooks; the merge stays in the base.
final class UsersTable extends Hilos\Tables\Users\AbstractHilosUsersTable
{
    protected function usersSourceKey(): string { return DbContext::users; }
    protected function presenceSourceKey(): string { return RtContext::connections; }
    protected function presenceSource(): Hilos\Runtime\View\Collection\HilosPresenceSource
    {
        return Hilos::$rt->connections;
    }
    // rowForUserId(), resolveUserIdForPresence() — bind the sources, do not re-merge.
}
```

## Anti-Patterns

- Do not generate a copy of the engine (table query/merge/mutation, or a page
  `onAction` lifecycle); extend the framework base and fill its extension points.
- Do not back presence with framework analytics; implement `HilosPresenceSource`
  on a project RT collection.
- Do not scaffold a project's own divergent table here; that is Mode-2 authoring
  ([admin-features.md](admin-features.md)), not activation of a framework feature.
- Do not generate the table before its DB/RT sources are registered.

## Contract Gate

A bound feature's generated entity and RT item are contract surfaces. Stop and
ask for explicit confirmation, per the root `AGENTS.md` gate, before generating:

- the DB entity fields or migration shape (for hilos-users, the framework-fixed
  `id`/`admin`/`block` contract);
- the RT connection/presence item shape consumed by the merge.

Signals, action DTOs, and routing for a framework feature ship with the framework
base, not per project — they are not generated here.

## Validation

Use `$hilos-testing-cli` to choose composer scripts. After generating, keep the
target project's admin e2e green and add coverage for the project-owned entity
and presence source of a bound feature. The framework base classes named above
are the contract the generated code binds to; do not modify them to make
activation fit.
