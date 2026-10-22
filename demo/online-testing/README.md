# Online Testing Demo Project

**Complexity: 3/5**

"Hilos Testing": online tests on a real-time Hilos application — scores,
attempts and verdicts that move on every open page at once.

## What is this?

### Today

The testing room has its Angular shell and operations admin sections. The tests,
attempts and verdicts of the planned product have not been built yet:

- **Sign-in** by password, on the framework surface; registration and recovery
  confirm the address with a code sent by mail.
- **Home page** that says who is looking: "Browsing anonymously" for a visitor,
  "Signed in as …" with an account.
- **Admin dashboard** at `/hilos`, with Settings, Users (list and card), Logs and
  Maintenance. A signed-in user has the notification bell, without delivery channels.
- **Public pages** About, Terms, Privacy and License, prerendered into the build.

The backend has one app agent owning the connections and home page, the Hilos
index agent serving the dashboard, admin pages and footer, settings and
notifications libraries, and the logs section, store, carrier and aggregator
agents. The sign-in libraries remain framework-owned. The demo keeps no
product table of its own; people, sessions, notifications and settings use
framework tables.

### What it is going to show

The design lives in `hilos-ops/mockups/demos/online-testing`:

- **Tests**: a live table of the tests you have not taken yet, marked when they
  carry pictures or are checked by an AI; a visitor sees the list, and tests are
  taken after signing in.
- **Taking a test** in one sitting: every question at once, pictures above the
  options, one or several right answers; the score and the review come right
  after — or, for a test an AI checks, answers in your own words and a verdict
  that arrives later.
- **Passed tests**: how many, the average score and what is left, and the
  review of any attempt.
- **Test administration**: tests of either kind, their questions with pictures
  and right answers, reference answers for the AI, publishing and closing.
- **Reports**: a live summary of every test and the report of one — the spread
  of scores, the attempts, and how each question was answered.
- **AI check**: the chain that checks answers — an API, a local model, and your
  own AI over MCP last — and the queue of attempts waiting in it.
- **Your AI**: the tools an administrator's AI uses over MCP to read the answers
  to check and write its verdict.

## Change Log

The demo declares `CHANGE_LOG` and journals exactly `hilos_user`, `hilos_identity`,
`hilos_second_factor` and `hilos_setting`. The journal is the primary database name
plus `-change-log`, on the same MariaDB server with the same host, port, user,
password and charset. No additional environment keys are needed. The primary
migration track creates its six tables; connection 1 runs no separate migrator.
This demo does not activate Backup.

Before updating an existing local volume, provision the derived database once
(or repeat the same command safely). Run from this demo's directory:

```bash
docker compose -f docker/docker-compose.local.yml exec online-testing-mysql-local \
  /usr/local/bin/provision-change-log.sh
```

This only creates a missing database and grants access; it does not reset or
remove existing data. Fresh local and test databases run the same provisioning
from the MariaDB init directory. Local keeps its volume; test keeps its tmpfs.
The framework's shared `test:db:reset`, invoked by
`composer run test:db-reset`, recreates both test databases because the demo
declares `CHANGE_LOG`. To regenerate triggers
on the test stand after migrations, use `composer run test:change-log:generate`;
repeating it leaves matching files untouched. Commit the complete generated set
before starting a daemon. Startup prepares monthly partitions and installs the
files under its existing schema rollout claim; a repeated start keeps matches.

For dev with an external MariaDB, its operator provisions the derived database
before startup using the actual primary name, application user and host:

```sql
CREATE DATABASE IF NOT EXISTS `<primary>-change-log`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON `<escaped-primary>-change-log`.* TO '<app-user>'@'<app-host>';
```

Escape `_` and `%` in the database identifier used by `GRANT`, where they are
patterns even inside backticks. The provisioning script validates names and the
64-character limit and performs that escaping. It never clears a database.

The backend registers all three journal pages and their table bindings.
The Angular views mount all three: the dashboard at `/hilos/change-log`, Tracked
tables at `/hilos/change-log/tables`, and one table with its history at
`/hilos/change-log/tables/{tableId}/{record?}`.

## First run

All tooling runs in project-defined docker containers — never on the host
(`docs/agents/frontend/build-and-docker.md`).

```bash
composer run setup-env          # create .env and tests/.env from the examples
composer run install-deps       # composer install in the PHP container
# once per checkout, from the repo root: install the framework SDK npm workspace.
# @hilos/* are linked via file: symlinks, so their deps (e.g. zod) resolve against
# framework/frontend/node_modules — without this the build fails to resolve them:
#   docker compose -f framework/docker/docker-compose.frontend.yml \
#     run --rm hilos-frontend-cli npm install
composer run frontend:install   # npm install in the frontend container
composer run daemon-start       # MySQL + phpMyAdmin + Mailpit + daemon + Angular dev server
```

The daemon applies migrations on startup. Endpoints (defaults):

| Endpoint | URL |
|---|---|
| Frontend dev server (HMR) | http://localhost:5178 |
| Built frontend behind nginx (`daemon-start-build`) | https://localhost:8156 |
| phpMyAdmin | http://localhost:8159 |
| Mailpit (the codes a registration and a recovery send) | http://localhost:8160 |
| Daemon status API | http://localhost:8150/status |
| Daemon WebSocket | ws://localhost:8152 |
| MySQL (from host) | localhost:33071 |

An administrator is made from the command line: `composer run cli -- admin:create
<sessionToken>` makes a browser session an administrator, minting its account when
it has none, and `admin:grant` / `admin:revoke` move the flag on an existing one
(`docs/agents/cli/commands.md`).

These host-side ports are compose *interpolation* values, so `.env` cannot
change them — compose reads them from the shell environment or from
`docker/.env` before any container exists (`HTTP_STATUS_HOST_PORT=18150 composer
run daemon-start`). A port written into `.env` is silently ignored: that file is
the container `env_file`.

## Stack commands

| Command | What it does |
|---|---|
| `composer run daemon-start` | start the local stack (MySQL, phpMyAdmin, Mailpit, daemon, Angular dev server) |
| `composer run daemon-start-build` | the same plus the prod-parity nginx over the built artifact (run `frontend:build` first) |
| `composer run daemon-stop` | stop the local stack |
| `composer run daemon-restart` | restart the daemon container |
| `composer run daemon-status` | daemon status via the CLI |
| `composer run frontend:install` | `npm install` in the frontend container |
| `composer run frontend:check` | type-check the frontend (`tsc` and a development `ng build`) |
| `composer run frontend:lint` | lint the frontend (`eslint . --max-warnings 0`) |
| `composer run frontend:format-check` | check frontend formatting (`prettier --check .`) |
| `composer run frontend:build` | production build into `frontend/dist`, footer pages prerendered |
| `composer run frontend:logs` | follow the dev-server logs |

## Database commands

| Command | What it does |
|---|---|
| `composer run db:migration:up` | apply pending migrations |
| `composer run db:migration:down` | roll back the last migration |
| `composer run db:migration:status` | migration status |
| `composer run db:schema:status` | compare schema against entities |
| `composer run pma` | start phpMyAdmin at http://localhost:8159 |

## PHP tests

| Command | What it does |
|---|---|
| `composer run test:install-deps` | install PHPUnit into the test toolchain |
| `composer run test:unit` | run the unit suite (topology registry guard) |
| `composer run test:phpunit` | run the whole PHPUnit suite |

## End-to-end tests

e2e runs against the **built** frontend artifact served by the prod-parity
nginx (TLS, `/ws` upgrade proxy) with a booted daemon behind it
(`docs/agents/frontend/testing-strategy.md`). Agent flow:
`composer run test:e2e-full -- <spec>` or `-- --grep "…"` — the full clean cycle
pointed at a subset.

| Command | What it does |
|---|---|
| `composer run test:check` | install + typecheck, lint and format-check the frontend app; lint and format-check the e2e suite (test toolchain) |
| `composer run test:e2e-build` | install + build the frontend for the test stack |
| `composer run test:e2e-install` | install the Playwright deps |
| `composer run test:e2e-check` | typecheck the e2e test code (in the runner) |
| `composer run test:e2e-up` | start the e2e stack: MySQL (reset) + daemon + nginx |
| `composer run test:e2e` | run the e2e suite (`-- --grep "..."` filters) |
| `composer run test:e2e-down` | tear the e2e stack down |
| `composer run test:e2e-full` | build → install → check → up → test → down; `-- <spec>` or `-- --grep "…"` points the same clean cycle at a subset |

## Cluster stand

`docker/docker-compose.cluster.yml` raises this demo as a Hilos cluster of
three equal masters, `m1`–`m3`, each declaring `worker,ram=10,slots=64`: every
master is the consensus and the data plane at once and carries placed work
itself, every placed agent takes one slot, and the work spreads over all three
by equal shares, the leader last only among equal shares — it takes its share
after the two that do not lead. All three share one
MariaDB 11.4 server, `online-testing-cluster-mysql`, with the primary database
and its derived journal. The database is at 10.223.0.10; all of the stand sits
on default subnet `10.223.0.0/16`. This local aggregate publishes nothing on the
host. It is a compose project of its own,
`hilos-online-testing-cluster`, because the e2e steps take their whole project
down when they start.

Nothing here drives the stand: the framework's shared cluster harness
(`framework/docker/cluster/`) reads the nodes out of the compose file and runs
the scenarios it names — 11 cross-node db fact, 15 db interest addressing
(skipped as flaky since 2026-10-16), 21 schema rolled out once, 22 other
database refused, 24 cut-off leader stops its work (skipped as flaky since
2026-10-10), and 40 journal triggers installed once and unchanged on ordinary
non-leader restart
(`docs/agents/testing.md`, "The cluster stands — three demos, three shapes"). The framework's probe fleet and
database probe are in this demo's `AGENTS`, and they start only here: on one
node, on the Playwright stand and in production the rows are carried and
nothing is run.

| Command | What it does |
|---|---|
| `composer run test:cluster:up` | build/recreate the default database, three nodes, entry and CLI, retaining data and protected mode |
| `composer run test:cluster:restart` | ordinary down/up with current code, retaining both databases and protected mode |
| `composer run test:cluster:status` | the containers and one line of each node's view: phase, leader, placements |
| `composer run test:cluster:inspect -- m1` | inspect one node as JSON |
| `composer run test:cluster:logs -- m1` | follow one node's container logs; Ctrl-C stops following |
| `composer run test:cluster:client -- m1 test:cluster:inspect` | run an existing CLI command through the selected node |
| `composer run test:cluster:scenarios` | the stand's scenario matrix on a fresh stand; `-- 21 40` points the schema and journal-trigger checks |
| `composer run test:cluster:e2e` | Analytics through all three source/viewer master pairs on a fresh stand; build first with `test:e2e-build` |
| `composer run test:cluster:down-volumes` | take the stand down and wipe its primary and journal databases |
| `composer run test:cluster:down` | stop the stand, retaining database data and protected-mode stores |

The browser suite uses the same three masters with an nginx entry at
10.223.0.30 and Mailpit at 10.223.0.20, without host ports. The entry serves
the Angular build's `frontend/dist/index.csr.html`; a missing build fails before
the harness resets the stand. The `e2e` profile supplies Mailpit and Playwright.
Each master receives a real registration and the next master shows its Analytics
session to an administrator; actual WebSocket upgrades, live agent placement,
isolation, action order and body privacy are checked in each pair. Only the live
phase runs, with no fault request or after-loss phase.

The full graph's `online-testing-cluster-e2e` follows `online-testing-e2e` and
shares a group with the scenario matrix. Its snapshot keeps every node's logs
and Playwright evidence before teardown. A direct browser run has no snapshot
collector: save `data/logs-cluster/` and `tests/e2e/playwright-report/cluster-live/`
before repeating it, since the next fresh browser run clears the stopped nodes'
logs and archives. It preserves `.gitkeep` and every `protected-mode.state.json*`
store, including unfinished stores; ordinary `up` does not clear logs.

The harness commands — `kill`, `partition`, `crash-daemon`, `inspect` and the
rest — use the shell entry point from this demo directory. It builds and runs a
disposable container, using the current repository through a mount. The first
invocation builds its image automatically; later builds use Docker's normal
cache. It runs as your UID:GID and mounts the selected local Docker socket with
its numeric group. This path needs ordinary Linux shell utilities, the Docker
CLI and an accessible local Unix Docker daemon; it does not need host Python,
PHP, Composer or a Compose plugin. Docker Desktop, rootless, SSH and multi-
machine daemons are outside the supported target. See the
[shared runner details](../../docs/agents/testing.md#cluster-harness-runner).

The existing Composer command names above remain convenient entry points.
`composer run test:cluster:down` still uses `../../scripts/down-stands.php`;
direct `down` below is a standalone path.

```bash
../../framework/docker/cluster/run docker/docker-compose.cluster.yml up
../../framework/docker/cluster/run docker/docker-compose.cluster.yml status
../../framework/docker/cluster/run docker/docker-compose.cluster.yml inspect m1
../../framework/docker/cluster/run docker/docker-compose.cluster.yml down
```

**Destructive:** `down --volumes` deletes the stand's database data. Run it
only when that deletion is intended:

```bash
../../framework/docker/cluster/run docker/docker-compose.cluster.yml down --volumes
```

### Compose layout and lifecycle

The root includes one merged pair, `docker/cluster/local.yml` and
`docker/cluster/tooling.yml`. Local services extend `database.yml`, `node.yml`
and `entry.yml` there; the canonical services add no extra nodes. Compose 2.40.3,
pinned by the harness, resolves `ports: !reset []` and per-node
`env_file: !override`. Local nodes load tracked `.env.example`, `common.env`
and their fixed `nodes/<id>.env`. The CLI is default-on, with `APP_ENV=test`
and explicit cluster DB/command settings; nodes use `APP_ENV=dev`. The CLI
mounts tracked `tests/.env.example` read-only at `/app/tests/.env`, inside
`/app/tests` tmpfs so Docker cannot create a missing mount target on the host
bind. Test suites keep their own services; no host env file is copied or
overwritten. Mailpit and Playwright use the `e2e` profile.

From this demo directory, ordinary startup is one direct Compose command:

```bash
docker compose -f docker/docker-compose.cluster.yml up -d --build --force-recreate
```

`test:cluster:up` runs that command without a separate all-profile build or log
cleanup. Ordinary down/up and `test:cluster:restart` preserve primary/journal
rows and protected-mode stores. A saved manual maintenance window remains
`verifying`. Inspect it with
`composer run test:cluster:client -- m1 protected-mode:inspect`; manual
maintenance opens with `maintenance:disable`, a test freeze with
`test:protected-mode:open`, and a backup restore with `protected-mode:open`,
through the recorded initiator and the same client alias.

Every new scenario/browser invocation validates prerequisites, resets the
database once with `down --volumes`, starts the stand and opens recognized saved
protected mode through its owner before fleet/browser readiness. Unknown
identities, malformed stores/replies, refusals and timeouts fail startup.
No global reset is inserted between scenarios, retries or browser phases, and
there is no `--keep-db` mode: a new invocation discards ordinary fixtures.

Names and the `/dev/shm` DB directory take `HILOS_STAND_SUFFIX`; the subnet takes
`HILOS_STAND_NET_ONLINE_TESTING_CLUSTER`, and CPU weight takes
`HILOS_STAND_CPU_SHARES`. The nodes and entry share host `data/data_export`;
the entry mounts Angular's `frontend/dist` at `/usr/share/nginx/html`. See the
shared guide's
[stand instances](../../docs/agents/testing.md#the-full-run--one-graph-a-bounded-number-of-lanes)
and [cluster lifecycle](../../docs/agents/testing.md#cluster-compose-model).

### Standalone components

Copy `docker/cluster/standalone.env.example` outside the checkout, edit its
paths/endpoints and prepare the selected runtime file from
`docker/cluster/nodes/m1.env`. From this demo directory:

```bash
docker compose --env-file /srv/hilos/online-testing.env -p online-db -f docker/cluster/database.yml config
docker compose --env-file /srv/hilos/online-testing.env -p online-m1 -f docker/cluster/node.yml config
docker compose --env-file /srv/hilos/online-testing.env -p online-entry -f docker/cluster/entry.yml config
# After provisioning the inputs, start only this node on its server:
docker compose --env-file /srv/hilos/online-testing.env -p online-m1 -f docker/cluster/node.yml up -d --build
```

`--env-file` supplies Compose interpolation; `HILOS_CLUSTER_NODE_ENV` selects
the separate runtime file loaded after `common.env`. Build/bind paths resolve
from `docker/cluster`. The example binds on `0.0.0.0`, publishes DB `3306`,
peer `18095`, WebSocket `18092`, HTTP `18090`, and entry `80/443`; command and
worker IPC ports stay private. Runtime DB/advertise/seeds must name reachable
server endpoints, excluding the node itself from seeds. Entry upstreams use
complete `HILOS_CLUSTER_WS_M1/M2/M3` and `HILOS_CLUSTER_HTTP_M1/M2/M3` host:port
pairs, allowing different ports per master. The entrypoints preserve nginx's
own variables during substitution.

Provide built dependencies/Angular frontend, matching mutual-TLS files, trusted
proxy ranges and the same pre-provisioned export storage on every node and entry
via `HILOS_CLUSTER_DATA_EXPORT_DIR`. This only binds an existing shared directory;
it provisions no remote hosts or storage. Standalone config resolution has been
checked; real multi-host deployment has not been tested, and the harness stays
local.

### Database

One MariaDB 11.4 server holds `hilos-demo-online-testing-cluster` and
`hilos-demo-online-testing-cluster-change-log`. The fresh data directory
runs `docker/mysql/init-change-log.sh`, mounted at
`/docker-entrypoint-initdb.d/init-change-log.sh`; it calls
`docker/mysql/provision-change-log.sh`, mounted at
`/usr/local/bin/provision-change-log.sh`. The existing app user receives access
to the derived journal before any Hilos node starts. The nodes then apply the
primary migration track, prepare the journal's partitions and install its
triggers under the existing schema rollout claim. The journal has exactly six
tables and no migration bookkeeping of its own.

Before replacing an older cluster compose file, stop that stand using the old
file, without `--volumes`, so removed services cannot become orphan containers:

```bash
docker compose -f docker/docker-compose.cluster.yml --profile '*' down
```

For an existing ordinary MariaDB data directory, provision the journal as root
before starting Hilos with the new code. From `demo/online-testing`:

```bash
docker compose -f docker/docker-compose.cluster.yml up -d --wait online-testing-cluster-mysql
docker compose -f docker/docker-compose.cluster.yml exec online-testing-cluster-mysql \
  /usr/local/bin/provision-change-log.sh
composer run test:cluster:up
```

Provisioning uses the existing `MYSQL_DATABASE`, `MYSQL_USER` and
`MYSQL_ROOT_PASSWORD`. It is repeatable: it creates a missing journal and
refreshes its grant without DROP or reset. Provisioning again and restarting
on the same data directory preserve both primary and journal rows. The stand
mounts `/dev/shm/hilos-online-testing-cluster-mysql-data` and the shared
`test-stand.cnf`; `test:cluster:down` preserves that data, while
`test:cluster:down-volumes` and each fresh scenario/browser invocation clear it.
The directory survives container replacement only until host reboot: `/dev/shm`
is host memory. Local and test volumes are separate from this transition.

Historical migration: this is a separate disposable data directory. The former Galera data
stays at `/dev/shm/hilos-online-testing-cluster-galera1-data`,
`/dev/shm/hilos-online-testing-cluster-galera2-data` and
`/dev/shm/hilos-online-testing-cluster-galera3-data`: do not delete those
paths or mount them as an ordinary MariaDB data directory. No automatic data
transfer from Galera is provided. Stop its old members and proxy with the old
compose file before switching. The Galera scripts and proxy configuration stay
in Git history. Scenarios 26/27/28 remain in the shared harness, with no current
stand carrying them; specialized database topologies and six new demos are
P-567, deferred to 2026-10-24. They are not passing checks of this stand.

### TLS fixtures

`docker/tls/` holds the certificates of this stand, and they are **stand
fixtures only**: the authority behind them signs nothing else, its key is not
in the repository, and it is not the authority of any other stand on the same
machine. Nothing here is a template for a real cluster — issue your own.

| File | What it is | Used by |
|---|---|---|
| `ca.pem` | the stand authority's certificate, no key | `CLUSTER_TLS_CA_FILE` of the three nodes |
| `m1.pem` … `m3.pem` | a node certificate (CN = node id) followed by its key | `CLUSTER_TLS_CERT_FILE` of that node |

Reissuing means a new set in full: the old authority's key is gone, so no
single file can be replaced alone. The commands run in the image of the
stand's cli container, which exists after the first
`composer -d demo/online-testing run test:cluster:up` (or `docker compose -f
demo/online-testing/docker/docker-compose.cluster.yml build
online-testing-cluster-cli`). The framework's own commands print PEM to stdout
and the host shell writes the files, so they come out owned by you rather than
root. Keep the authority file (`cluster-ca.pem`) outside the repository and
delete it when done — from the repository root:

```bash
cli() {  # one framework CLI command in a throwaway container, no network needed
  docker run --rm --network none --user "$(id -u):$(id -g)" \
    -v "$PWD/demo/online-testing":/app:ro -v "$PWD/composer.json":/hilos/composer.json:ro \
    -v "$PWD/composer.lock":/hilos/composer.lock:ro -v "$PWD/framework":/hilos/framework:ro \
    -v /tmp/cluster-ca:/ca:ro -w /app -e APP_ENV=dev \
    hilos-online-testing-cluster-online-testing-cluster-cli:latest php backend/Bootstrap/cli.php "$@"
}
mkdir -p /tmp/cluster-ca && t=demo/online-testing/docker/tls
cli cluster:tls:ca > /tmp/cluster-ca/cluster-ca.pem
cli cluster:tls:trust /ca/cluster-ca.pem > $t/ca.pem
for n in m1 m2 m3; do cli cluster:tls:issue $n /ca/cluster-ca.pem > $t/$n.pem; done
rm -r /tmp/cluster-ca
```

The node certificates are valid for ten years (the authority's lifetime); a
node warns in its log from 30 days before the end.

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.
