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
  owns the whole add/update/delete lifecycle. The extension points are a catalog
  provider, one `SUBSCRIPTION_AGENT_TYPE`, a project `BrowserContext` (an empty
  subclass — the base context delivers the table snapshot through the
  self-snapshot path), and a thin frontend context wrapper. "Configure-only"
  means no engine code, not zero project files.
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
   `createTable()`, and bind it to the page in `PAGE_TABLES`. Register it; never
   subclass or re-implement it.
4. A `BrowserContext` returned from the project `createBrowser()` — an empty
   subclass is enough. The framework default is `null`, and without a browser
   context the settings page answers a subscription with nothing; the base
   context delivers the table's snapshot through the self-snapshot path
   ([admin-features.md](admin-features.md), *Browser delivery*).
5. Mount the SDK view: map `HilosPages.SETTINGS` to the framework view from
   `@hilos/{vue,react,angular}` `admin/settings/HilosSettingsPage`, through a thin
   project wrapper that binds the `HilosSettingsContext` (`{ scopes, actions }`
   from the project session + connection). The view, the row model, and the
   add/update/delete round-trips are framework-owned — the wrapper is context
   binding, not page logic.

No entity, no RT collection, no table subclass — that is the configure-only
floor. It still includes one empty `BrowserContext` and one thin frontend context
wrapper; those bind the project, they do not re-implement the engine.

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

### backup — configure-only engine + monopoly agent

The framework owns the whole backup engine: the monopoly `BackupAgent`
(`AgentType::HILOS_BACKUP`) that runs the cron schedule and mutates storage, the
`mysqldump` child driven by the `backup:run` command, the retention pruner, the
runtime index, and `Hilos\Pages\Backup\AbstractHilosBackupPage` with the
create / delete / set-keep action lifecycle. The project brings only
configuration and binding — no engine, no action code. It is configure-only like
settings, but the engine is a monopoly agent plus a CLI command, so activation
also registers those. Generate, in any order:

1. A backup catalog — `final class … implements
   Hilos\Core\Catalog\CatalogProviderInterface` — bound through the
   `BACKUP_CATALOG` constant on the project `Hilos` facade (the framework default
   is `null` = subsystem off). It carries two content keys: the per-connection
   reference-table registry under `BackupConstants::CATALOG_REFERENCES` (a list of
   Entity/Object collection class-strings per connection index — the framework
   derives table names from the classes, so the registry survives table renames,
   and keeps their rows under the schema-seed scope; an empty registry is valid —
   schema-seed then captures schema only, with a warning), and an optional
   schedule under `BackupConstants::CATALOG_SCHEDULE` (omit it to take the
   framework default: one daily full backup at 03:00 on the agent mechanism).
2. Environment values through the project `EnvCatalog`: `BACKUP_ENABLED`,
   `BACKUP_DIR`, `BACKUP_CLI_ENTRY`, the retention counters
   (`BACKUP_RETENTION_DAILY` / `WEEKLY` / `MONTHLY` / `YEARLY`), and
   `BACKUP_ERROR_RETENTION_COUNT`. These are framework `EnvConstants` keys the
   agent and pruner read from `Hilos::$env`; the project supplies values, never new
   keys. `BACKUP_DIR` and `BACKUP_CLI_ENTRY` are what the create path *needs*:
   without either, every create is refused up front
   (`BackupAgent::missingCreateConfig()`) — a page action fails with an
   ACTION_ERROR and the agent logs the missing key on start. Give `BACKUP_DIR` a
   working default in the catalog rather than leaving it to the deployment (the
   chat demo computes `demo/chat/data/backup`, keeping the env value an override),
   or the feature activates into a state where nothing can ever be written.
3. A `mysqldump` binary on `PATH` in the runtime image that hosts the agent — the
   `backup:run` child shells out to it (Debian: `default-mysql-client`). Missing,
   it is not a config error but a failed run: the dump exits non-zero and the
   backup is recorded as an error.
4. Register the framework `BackupAgent` + `BackupAgentDaemon` in the project
   `AGENTS` under `BackupAgent::AGENT_TYPE` — it is monopolistic, so it claims a
   monopolistic worker slot ([../../new-project/README.md](../../new-project/README.md),
   *Worker pool*) — and expose the `backup:run` child command
   (`BackupConstants::RUN_COMMAND`) in the project CLI command registry. Both are
   framework-owned; the project only lists them.
5. Register the framework runtime index on the project `RtContext`: the
   `BackupHistories` state collection (`BackupHistory::RT_COLLECTION`) and the
   `BackupRuntime` state item (`BackupRuntime::RT_ITEM`). Files are truth; this RT
   index is a rebuildable projection the agent rescans from `BACKUP_DIR` on start,
   so the project persists no backup DB table. **Then bind the framework
   representation to it** — the state registration alone is a half-activation:

   ```php
   $this->setRepresent(
       StateBackupHistory::RT_COLLECTION,
       BackupHistories::class,          // Hilos\Runtime\View\Collection
       BackupHistoriesActions::class,   // Hilos\Runtime\View\Actions\Collection
       BackupHistoryActions::class,     // Hilos\Runtime\View\Actions\Item
   );
   ```

   The backup agent is monopolistic and the backup page is served by whichever
   worker owns the browser's connection — a different process. Only the actions
   emit `RT_SYNC_*`, so without this line the index exists in the agent's worker
   alone and the page shows an empty table forever
   ([../runtime/rt-context.md](../runtime/rt-context.md), *A collection written
   outside its actions is worker-local*). All four classes are framework-owned;
   the project writes only this call.
6. Register the framework table `Hilos\Tables\Backup\HilosBackupHistoryTable` in
   the project `TableContext`, add a thin subscription-owner page — `final class …
   extends Hilos\Pages\Backup\AbstractHilosBackupPage` carrying only a
   `SUBSCRIPTION_AGENT_TYPE` — to `PAGES`, and bind the table to the page in
   `PAGE_TABLES`. Register the framework table; never subclass it. Add the page's
   nav entry to the project page catalog if it should appear in the admin shell.
7. Mount the SDK view: map `HilosPages.BACKUP` to the framework view from
   `@hilos/{vue,react,angular}` `admin/backup/HilosBackupPage`, through a thin
   project context (`HilosBackupsContext` = `{ connection, scopes, actions }` from
   the project bootstrap). The table, the row view-model, the live-row behavior,
   and the create / delete / keep round-trips are framework-owned — the context is
   binding, not page logic.

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

// configure-only still ships a browser context — an empty subclass; the base
// context delivers the settings snapshot through the self-snapshot path.
final class AppBrowserContext extends Hilos\Core\Browser\Context\BrowserContext
{
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
- Do not skip the project `BrowserContext` for a configure-only feature; without
  it the page answers a subscription with nothing. An empty subclass is the fix.

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
