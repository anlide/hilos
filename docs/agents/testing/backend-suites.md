# Testing — Backend Suites

Read this when running the framework's PHP suites or a demo's backend suite: the
composer scripts that start the test databases, prepare their schemas and run
unit, integration or one integration piece — never `phpunit` directly from the
host.

## Framework backend (`framework/`)

Composer scripts live in the repo-root `composer.json`:

| Script | What it does |
|---|---|
| `composer run test:framework:install-deps` | Install PHPUnit & framework dev deps inside the test container. |
| `composer run test:framework:up` | Build the backup receiver image from the cache, start `mysql-framework-test`, `mysql-framework-remote-test`, `mysql-framework-catalog-test`, `mysql-framework-restore-test`, and `sshd-framework-test`, and wait until they are healthy. |
| `composer run test:framework:databases` | Recreate the integration-piece and derived change-log schemas on the three MariaDB services (`mysql-framework-test`, `mysql-framework-remote-test`, and `mysql-framework-restore-test`). Needs `up`. |
| `composer run test:framework:piece -- <K>` | Run integration piece K on its own database: the classes whose crc32 of the short name lands on K, as defined in `scripts/framework-pieces.php`. Needs `up` and `databases`. |
| `composer run test:framework:unit` | Run framework unit tests (`framework/tests/Unit`). |
| `composer run test:framework:integration` | Run framework integration tests (`framework/tests/Integration`). Requires DB. |
| `composer run test:framework:all` | Run the framework backend steps of the graph, tag `framework-php`: the four integration pieces run sequentially, each on its own database, beside the independent `framework-unit`; the stand comes up before them and goes down after a green finish. |
| `composer run test:framework:down[-volumes]` | Stop (and optionally wipe volumes). |

Framework integration uses two real MariaDB services on the stand network:
`mysql-framework-test` and `mysql-framework-remote-test`. The remote service has
its own host and credentials, no published host port, and the same configured
physical database names on a separate server. `test:framework:databases`
recreates the same piece schemas and derived `-change-log` schemas on both
services, using the current primary piece names, then grants each service's app
user access to them. The remote service supports real cross-server ORM routing
tests; the built-in change log stays on the primary server's derived schema and
uses row connection 0. The public composer target names are unchanged, and the
framework unit runner has no database-service dependency.

`mysql-framework-catalog-test` is a third MariaDB service, owned by
`FullRestoreTriggerIntegrationTest`. Its global TRIGGER grant observes every
schema on that server, so a separate schema on either shared service cannot
isolate its prepare/recheck boundary from concurrent pieces. The fixture uses
`HILOS_TEST_CATALOG_DB_HOST`, creates and drops its own schemas and accounts,
and opens its root control connection on `information_schema`; it needs no
piece schemas or application account from `test:framework:databases`. The
service inherits the remote service's image, tmpfs, healthcheck and network,
with no published port. Other framework integration classes keep using the
primary and remote services. All four integration pieces share the
`framework-integration-catalog` group and run sequentially: server-wide metadata
observers on a shared service can see DDL from other piece schemas. Framework
unit tests and other stands remain independent and may run in parallel.

`mysql-framework-restore-test` is a fourth MariaDB service, owned by
`BackupRestorerIntegrationTest` and `FullRestorePlannerIntegrationTest`. These
fixtures verify full backup restore boundaries, planning rechecks, schema
snapshots, and catalog drift. Running them against the shared primary service
risked cross-talk from concurrent piece DDL and global server mutations:
in particular, `BackupRestorerIntegrationTest` executes `SET GLOBAL sql_mode`,
which now runs on this dedicated server rather than on the shared primary.
The service is exposed to the test container via `HILOS_TEST_RESTORE_DB_HOST`.
Unlike the catalog server, this endpoint provisions regular application
credentials (`MYSQL_USER`/`MYSQL_PASSWORD`) and prepares the default journal
schema and grants via `init-change-log-test.sh`. Both integration paths are
supported: default DB and journal schemas after `test:framework:up` for
standalone `test:framework:integration`, and piece schemas plus derived
`-change-log` schemas prepared alongside the other endpoints by
`test:framework:databases` before piece 1 runs. `FullRestorePlannerIntegrationTest`
creates and drops its own owned schemas and accounts on this server through root
connections. The two-server restore case in `BackupRestorerIntegrationTest`
(`testEveryPreparedTargetIsImportedIntoItsConfiguredSchema`) continues to target
the remote service under `hilos_remote` on secondary index 2, with the primary
target on `mysql-framework-restore-test`. The service inherits the common test
database template with tmpfs, healthcheck, container suffix, and unexposed host
ports; teardown removes it via `test:framework:down` or wipes its volumes with
`test:framework:down-volumes`.

`MomentStorageSchemaIntegrationTest`
uses the existing remote service for its own disposable pair of named schemas,
with a root control connection to `information_schema`; it creates and removes
that pair on every outcome. This keeps its live DDL away from the primary
service's server-wide metadata observers and introduces no new test service or
environment variable.

---

## demo/chat backend (`demo/chat/`)

Composer scripts live in `demo/chat/composer.json`. Run from `demo/chat/`:

| Script | What it does |
|---|---|
| `composer run test:up` | Start `mysql-test`. |
| `composer run test:db-wait` | Wait until the test DB is reachable. |
| `composer run test:db-reset` | Reset the whole test stand (`cli.php test:stand:reset`: logs → backups → published files → database). |
| `composer run test:unit` | Run unit tests (`tests/Unit`). No DB needed. |
| `composer run test:integration` | Run integration tests (`tests/Integration`). Requires DB. |
| `composer run test:phpunit` | Run both PHPUnit suites. |
| `composer run test:all` | `db-reset` → `phpunit` → `down`. Runs every available test type for demo/chat. |
| `composer run test:down[-volumes]` | Stop (and optionally wipe volumes). |

**Typical local loops:**

- Pure unit test iteration: `composer run test:unit` (fast, no DB).
- Integration iteration: `composer run test:up && composer run test:db-reset && composer run test:integration`
  (first run and again after a `test:down`: the database lives in memory;
  subsequent iterations can skip `db-reset` only if the test mutates neither
  schema nor data — a data-mutating test needs a reset before each rerun).
- Full pass before a PR: `composer run test:all` (PHPUnit suites).

---

