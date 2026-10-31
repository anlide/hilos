# Binance BTC Tracker Demo Project

**Complexity: 4/5**

Background computation over a live market: BTC/USDT from Binance, folded into
timeframes and read by an indicator, on a real-time Hilos application.

## What is this?

### Today

The empty shell of the application, and the smallest complete Hilos project
on Vue — the one to copy (`docs/new-project/README.md`):

- **Sign-in** by password, on the framework surface; registration and recovery
  confirm the address with a code sent by mail.
- **Home page** that says who is looking: "Browsing anonymously" for a visitor,
  "Signed in as …" with an account.
- **Admin dashboard** at `/hilos` with the **Maintenance** section — the
  verifier circle a protected-mode freeze lets through — and the **Backup**
  section: database archives created, deleted and, outside prod, restored,
  kept as files in `data/backup`, the **Settings** section, carrying the
  example keys and the keys of the log and delivery sections, the **Users**
  section: the people with their presence and a person's card — rename,
  takeover, rights, block and a scheduled deletion — the **Logs**
  section: the live tail, the streams, the workers, the rotated batches and the
  logging modes, and the **Communications** section: the email and SMS channels,
  a channel's settings and its delivery journal. The other admin sections
  arrive one by one.
- **Notifications**: the bell in the header for a signed-in person, and their
  own channel switches at `/profile/notifications` — the one profile page here.
- **Public pages** About, Terms, Privacy and License, prerendered into the build.

The backend is the base set: one app agent owning the connections and the
home page and the notification settings, the Hilos index agent with the
dashboard, the Maintenance, Backup, Settings, Users and Communications sections
and the footer pages, the logs agent serving the Logs section
with the framework log store, carrier and aggregator behind it, the framework
backup agent, settings and notifications libraries, the email and SMS
delivery agents, and the framework sign-in libraries. It
keeps no table of its own — people, sessions, the tables of signing in, the
settings, the verifier circle and the notifications with their deliveries are
the framework's, and the archives and the logs are files.

### What it is going to show

- **One pair, BTC/USDT**: every minute taken from the Binance stream, with the
  history back-filled from the start of the pair.
- **Fifteen timeframes** folded from the minutes, and **RSI(14)** on each.
- **Signals**: chains of RSI zones and divergences across the timeframes,
  ending in a recommendation.
- **Paper trades** a robot opens and closes on those signals — no money is
  involved.
- **Telegram**: a bot that says what the robot recommended, entered and closed.
- **Chart**: candles with an RSI panel, the zones, divergences and trade
  levels marked on it, and the RSI of all fifteen timeframes in one strip.

## Change Log

The demo declares `CHANGE_LOG` and journals exactly `hilos_user`, `hilos_identity`,
`hilos_second_factor` and `hilos_setting`. The journal is the primary database name
plus `-change-log`, on the same MariaDB server with the same host, port, user,
password and charset. No additional environment keys are needed. The primary
migration track creates its six tables; connection 1 runs no separate migrator.
Backup includes the pair and the journal's personal-data verdicts.

Before updating an existing local volume, provision the derived database once
(or repeat the same command safely). Run from this demo's directory:

```bash
docker compose -f docker/docker-compose.local.yml exec binance-btc-tracker-mysql-local \
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

The backend registers all eight Change Log pages and their table bindings.
The Vue views mount Overview at `/hilos/change-log`, Feed at
`/hilos/change-log/feed`, Tracked tables at `/hilos/change-log/tables`,
and Noisy columns at `/hilos/change-log/noisy`. A table's Overview is at
`/hilos/change-log/tables/{tableId}`, with Columns at `/columns`,
Triggers at `/triggers`, and History at `/history/{record?}` beneath that address.

## First run

All tooling runs in project-defined docker containers — never on the host
(`docs/agents/frontend/build-and-docker.md`).

```bash
composer run setup-env          # create .env and tests/.env from the examples
composer run install-deps       # composer install in the PHP container
# once per checkout, from the repo root: install the framework SDK npm workspace.
# @hilos/* are linked via file: symlinks, so their deps (e.g. zod) resolve against
# framework/frontend/node_modules — without this the dev server fails with
# "Failed to resolve import zod":
#   docker compose -f framework/docker/docker-compose.frontend.yml \
#     run --rm hilos-frontend-cli npm install
composer run frontend:install   # npm install in the frontend container
composer run daemon-start       # MySQL + Mailpit + daemon + Vite dev server
```

The daemon applies migrations on startup. Endpoints (defaults):

| Endpoint | URL |
|---|---|
| Frontend dev server (HMR) | http://localhost:5176 |
| Built frontend behind nginx (`daemon-start-build`) | https://localhost:8116 |
| Mailpit (the codes a registration and a recovery send) | http://localhost:8120 |
| Daemon status API | http://localhost:8110/status |
| Daemon WebSocket | ws://localhost:8112 |
| MySQL (from host) | localhost:33067 |

An administrator is made from the command line: `composer run cli -- admin:create
<sessionToken>` makes a browser session an administrator, minting its account when
it has none, and `admin:grant` / `admin:revoke` move the flag on an existing one
(`docs/agents/cli/commands.md`).

These host-side ports are compose *interpolation* values, so `.env` cannot
change them — compose reads them from the shell environment or from
`docker/.env` before any container exists (`HTTP_STATUS_HOST_PORT=18110 composer
run daemon-start`). A port written into `.env` is silently ignored: that file is
the container `env_file`.

## Stack commands

| Command | What it does |
|---|---|
| `composer run daemon-start` | start the local stack (MySQL, Mailpit, daemon, Vite dev server) |
| `composer run daemon-start-build` | the same plus the prod-parity nginx over the built artifact |
| `composer run daemon-stop` | stop the local stack |
| `composer run daemon-restart` | restart the daemon container |
| `composer run daemon-status` | daemon status via the CLI |
| `composer run frontend:install` | `npm install` in the frontend container |
| `composer run frontend:check` | type-check the frontend (`vue-tsc`) |
| `composer run frontend:lint` | lint the frontend (`eslint . --max-warnings 0`) |
| `composer run frontend:format-check` | check frontend formatting (`prettier --check .`) |
| `composer run frontend:build` | production build into `frontend/dist` |
| `composer run frontend:logs` | follow the dev-server logs |

## Database commands

| Command | What it does |
|---|---|
| `composer run db:migration:up` | apply pending migrations |
| `composer run db:migration:down` | roll back the last migration |
| `composer run db:migration:status` | migration status |
| `composer run db:schema:status` | compare schema against entities |

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

`docker/docker-compose.cluster.yml` raises this demo as a Hilos cluster: three
masters, `m1`–`m3`, that declare no capacity and so take no placed work; two
slaves, `s1` and `s2`, both `worker,ram=10`; and `x1`, a node certified by an
authority the cluster does not trust, under the compose profile `stranger` so
it never comes up with the rest. All of them share one MariaDB 11.4 server,
with the primary database and its derived journal, on default subnet
`10.221.0.0/16`. This local aggregate publishes nothing on the host.
It is a compose project of its own,
`hilos-binance-btc-tracker-cluster`, because the e2e steps
take their whole project down when they start.
The one nginx entry is `10.221.0.30` inside the stand network, unpublished on the
host. `hilos_stand_master=m1|m2|m3` selects the master behind it; without the cookie
nginx rotates over the masters, and an unknown name returns 421. The response header
`X-Hilos-Stand-Master` carries the responding master's upstream address.
The nodes' `data_export` is one volume of this stand, shared by every node,
rather than the demo's host directory used by the local and test stacks.

Nothing here drives the stand: the framework's shared cluster harness
(`framework/docker/cluster/`) reads the nodes out of the compose file and runs
the scenarios it names — 1 master-slave mesh, 2 master-master, 5 leader-kill
re-election, 7 quorum-loss, 8 split-brain prevention, 10 cross-node browser,
13 rt partition converges (skipped as flaky, P-169), 17 foreign certificate
refused, 20 rt set width across nodes, 23 verifier circle on every master, 25
freeze settles on every master, 29 a node with its own cluster directory
refused on both ends, and 30 a ready export copy outliving the node its agent
lived on, 32 a node with another admin view mode refused on both ends,
33 every master takes browsers, 34 a tab is the same on every master under
protected mode, 35 rt row deleted while cut off is swept, and 36 a slave
cut off with its leader stops its work (skipped as flaky since 2026-10-10;
`docs/agents/testing.md`, "The cluster stands — three demos, three shapes"),
38 manual maintenance from any node, 41 i18n catalog taken once, 42 another
default language refused, 44 waiting work survives leadership changes, and
45 upload pool across nodes. The
`entry-welcome <master> [<token>] [<pass>]` harness command reads the first
WebSocket welcome through the stand entry and reports whether that browser is
inside or on the maintenance stub.
The framework's probe fleet and runtime-set probe are in this demo's `AGENTS`,
and they start only here: on one node, on the Playwright stand and in
production the rows are carried and nothing is run.

| Command | What it does |
|---|---|
| `composer run test:cluster:up` | build/recreate the default database, five nodes, entry and CLI, retaining data and protected mode |
| `composer run test:cluster:restart` | ordinary down/up with current code, retaining both databases and protected mode |
| `composer run test:cluster:status` | the containers and one line of each node's view: phase, leader, placements |
| `composer run test:cluster:inspect -- m1` | inspect one node as JSON |
| `composer run test:cluster:logs -- m1` | follow one node's container logs; Ctrl-C stops following |
| `composer run test:cluster:client -- m1 test:cluster:inspect` | run an existing CLI command through the selected node |
| `composer run test:cluster:scenarios` | the stand's scenario matrix on a fresh stand; `-- 17 20` runs only the ones named |
| `composer run test:cluster:e2e` | fresh browser suite: Daemon/recovery, live, after-loss; build first with `test:e2e-build`; `-- --grep circle` points it |
| `composer run test:cluster:down-volumes` | take the stand down, database included |
| `composer run test:cluster:down` | stop the stand, retaining database data and protected-mode stores |

Scenario 44 temporarily gives both slaves `worker,ram=10,slots=16`. Extra fleet
indices 2000..2032 fill the slots until one stays `unplaced` with `nodeId=null`
and exact slot refusals. At least one extra must really run and write its RT row;
the ceiling counts every live placement, including the demo's agents. Before
killing the leader, every surviving master must report a complete snapshot
containing that waiting target at the leader's term and revision or later.
The next leader must finish rebuilding and recover the queue; left as the only
master, it must lose quorum and leadership while retaining that snapshot.
Returning the masters must rebuild the queue again. Restoring the first slave's
64 slots then starts the target without another placement ask, with one record
and an advancing RT row on its real host. The supervisor declares only 0..9,
so it cannot recreate this demand for the test. This proves recovery of delivered
in-memory replicas; it does not promise a durable or quorum-committed queue.
Cleanup always restarts the full original compose without deleting volumes,
restores the mesh, stock capabilities and ordinary fleet, and removes every
extra agent and queued record before the next scenario. Run it with its ordinary
leadership neighbours on a fresh stand from the repository root:

```bash
composer -d demo/binance-btc-tracker run test:cluster:scenarios -- 5 7 8 44
```

The e2e profile adds Mailpit and a Playwright runner. The browser enters through
one stand entry; every context selects a master with `hilos_stand_master` before
its first navigation. Browsers, including the protected-mode operator, start
on a follower master. In `tests/e2e/cluster/live`, backup checks a completed
archive, logs checks the overview and a slave's live file, protected-mode checks
the stub, the verifier circle and admission across masters, and sessions checks
sign-in, sign-out and access re-decisions across masters. Manual maintenance closes
through the UI on follower F; another administrator enters by code and opens it on
master T. Visitors on every master see the closure, while the operator and circle
member keep their documents. All six pages reload themselves on opening. Actual
WebSocket upgrades prove each master's address, and inspect snapshots plus marked
daemon log tails prove there was no freeze. Point that case with
`composer run test:cluster:e2e -- --grep 'cluster manual maintenance:'`.
The three tests in `cluster/live/theme.spec.ts` check live theme settings on
every master, choices and reset between independent account sessions, new tabs
with personal Dark and after reset, and stale Dark reconnect without adoption.
They inspect complete theme frames and actual WebSocket upstreams; live updates
keep documents and sockets, and the leader and term stay unchanged. Point them
with `composer run test:cluster:e2e -- --grep 'cluster theme'`.
Change Log in `cluster/live/change-log.spec.ts` checks one remote writer and one
receipt through every master. The person's `started` owner is observed on a
slave before and after the block, away from the leader serving the card. Fresh
reads through each master must show the same receipt and single entry IDs,
actor, action, writer agent, `block` values `0` → `1` and complete attribution
header, with the leader and term unchanged. Point it with
`composer run test:cluster:e2e -- --grep 'cluster change log:'`.
The backup spec asks
the harness to stop the slave holding its archive; after placement moves,
`cluster/after-loss` checks the archive's out-of-reach row and offline log refusal.
The stand remains up after this command, as it does after `test:cluster:scenarios`.
Every browser run starts with empty node log directories, rotation archives
included, except `.gitkeep` and `protected-mode.state.json*` stores and unfinished
stores. Cleanup runs with nodes stopped and never removes a saved freeze.
Copy the previous run's logs before starting the next one.

The harness commands — `kill`, `partition`, `cut`, `crash-daemon`, `inspect`,
`stranger up`, `own-directory s1 on` and the rest — use the shell entry point
from this demo directory. It builds and runs a disposable container, using the
current repository through a mount. The first invocation builds its image
automatically; later builds use Docker's normal cache. It runs as your UID:GID
and mounts the selected local Docker socket with its numeric group. This path
needs ordinary Linux shell utilities, the Docker CLI and an accessible local
Unix Docker daemon; it does not need host Python, PHP, Composer or a Compose
plugin. Docker Desktop, rootless, SSH and multi-machine daemons are outside the
supported target. See the
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
and `entry.yml` in that directory; the canonical services add no extra nodes.
Compose 2.40.3 (pinned by the harness) supports the local `ports: !reset []`
and per-node `env_file: !override`. Local nodes load tracked `.env.example`,
`common.env` and their fixed `nodes/<id>.env`. The CLI is default-on, with
`APP_ENV=test` and explicit cluster DB/command settings; nodes use `APP_ENV=dev`.
The CLI mounts tracked `tests/.env.example` read-only at `/app/tests/.env`,
inside `/app/tests` tmpfs so Docker cannot create a missing mount target on the
host bind. Test suites keep their own services. No host env file is copied or
overwritten. Only browser tooling uses `e2e`; the stranger uses `stranger`.

From this demo directory, ordinary startup is one direct Compose command:

```bash
docker compose -f docker/docker-compose.cluster.yml up -d --build --force-recreate
```

`test:cluster:up` runs that command, without building unused profiles or clearing
logs. Ordinary down/up and `test:cluster:restart` preserve primary/journal rows
and protected-mode stores. A manual maintenance window remains `verifying`
across restart. Inspect it with
`composer run test:cluster:client -- m1 protected-mode:inspect`; use
`maintenance:disable` through the same alias to open manual maintenance.
Test freezes open with `test:protected-mode:open`, backup restores with
`protected-mode:open`, through their recorded initiator.

Each new scenario/browser invocation validates prerequisites, resets the
database once with `down --volumes`, starts the stand and opens recognized saved
protected mode through its owner before waiting for fleet/browser readiness.
Unknown identities, malformed stores/replies, refusals and timeouts fail startup.
There is no database reset between scenarios, retries or browser phases, and no
`--keep-db` mode. These fresh test calls discard ordinary fixtures.

Names, the shared export volume and the `/dev/shm` DB directory take
`HILOS_STAND_SUFFIX`; the subnet takes
`HILOS_STAND_NET_BINANCE_BTC_TRACKER_CLUSTER`, and CPU weight takes
`HILOS_STAND_CPU_SHARES`. See the shared guide's
[stand instances](../../docs/agents/testing.md#the-full-run--one-graph-a-bounded-number-of-lanes)
and [cluster lifecycle](../../docs/agents/testing.md#cluster-compose-model).

### Standalone components

`docker/cluster/standalone.env.example` describes one component on one server.
Copy it outside the checkout, edit its paths/endpoints and prepare the selected
runtime file from `docker/cluster/nodes/m1.env`. From this demo directory:

```bash
docker compose --env-file /srv/hilos/standalone.env -p tracker-db -f docker/cluster/database.yml config
docker compose --env-file /srv/hilos/standalone.env -p tracker-m1 -f docker/cluster/node.yml config
docker compose --env-file /srv/hilos/standalone.env -p tracker-entry -f docker/cluster/entry.yml config
# After provisioning the inputs, start only this node on its server:
docker compose --env-file /srv/hilos/standalone.env -p tracker-m1 -f docker/cluster/node.yml up -d --build
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

Provide built dependencies/frontend, matching mutual-TLS files, trusted proxy
ranges and the same pre-provisioned export storage on every node and entry via
`HILOS_CLUSTER_DATA_EXPORT_DIR`. The local aggregate uses its named volume;
standalone binds an existing shared directory. This configuration provisions
neither remote hosts nor shared storage. Config resolution has been checked;
real multi-host deployment has not been tested, and the harness stays local.

### Database

One MariaDB 11.4 server holds `hilos-demo-binance-btc-tracker-cluster` and
`hilos-demo-binance-btc-tracker-cluster-change-log`. The fresh data directory
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
before starting Hilos with the new code. From `demo/binance-btc-tracker`:

```bash
docker compose -f docker/docker-compose.cluster.yml up -d --wait binance-btc-tracker-cluster-mysql
docker compose -f docker/docker-compose.cluster.yml exec binance-btc-tracker-cluster-mysql \
  /usr/local/bin/provision-change-log.sh
composer run test:cluster:up
```

Provisioning uses the existing `MYSQL_DATABASE`, `MYSQL_USER` and
`MYSQL_ROOT_PASSWORD`. It is repeatable: it creates a missing journal and
refreshes its grant without DROP or reset. Provisioning again and restarting
on the same data directory preserve both primary and journal rows. The stand
mounts `/dev/shm/hilos-binance-btc-tracker-cluster-mysql-data` and the shared
`test-stand.cnf`; `test:cluster:down` preserves that data, while
`test:cluster:down-volumes` and each fresh scenario/browser invocation clear it.
The directory survives container replacement only until host reboot: `/dev/shm`
is host memory. Local and test volumes are separate from this transition.

### TLS fixtures

`docker/tls/` holds the certificates of this stand, and they are **stand
fixtures only**: the authority behind them signs nothing else, its key is not
in the repository, and it is not the authority of any other stand on the same
machine. Nothing here is a template for a real cluster — issue your own.

| File | What it is | Used by |
|---|---|---|
| `ca.pem` | the stand authority's certificate, no key | `CLUSTER_TLS_CA_FILE` of the five nodes |
| `m1.pem` … `s2.pem` | a node certificate (CN = node id) followed by its key | `CLUSTER_TLS_CERT_FILE` of that node |
| `x1.pem` | node `x1`, signed by a *foreign* authority | the stranger of scenario 17 |
| `stranger-ca.pem` | that foreign authority's certificate | `x1`'s trust file, so `x1` passes its own start-up check |

Reissuing means a new set in full: the old authority's key is gone, so no
single file can be replaced alone. The commands run in the image of the
stand's cli container, which exists after the first
`composer -d demo/binance-btc-tracker run test:cluster:up`. The framework's own
commands print PEM to stdout and the host shell writes the files, so they come
out owned by you rather than root. Keep both authority files
(`cluster-ca.pem`, `foreign-ca.pem`) outside the repository and delete them
when done — from the repository root:

```bash
cli() {  # one framework CLI command in a throwaway container, no network needed
  docker run --rm --network none --user "$(id -u):$(id -g)" \
    -v "$PWD/demo/binance-btc-tracker":/app:ro -v "$PWD/composer.json":/hilos/composer.json:ro \
    -v "$PWD/composer.lock":/hilos/composer.lock:ro -v "$PWD/framework":/hilos/framework:ro \
    -v /tmp/cluster-ca:/ca:ro -w /app -e APP_ENV=dev \
    hilos-binance-btc-tracker-cluster-binance-btc-tracker-cluster-cli:latest php backend/Bootstrap/cli.php "$@"
}
mkdir -p /tmp/cluster-ca && t=demo/binance-btc-tracker/docker/tls
cli cluster:tls:ca > /tmp/cluster-ca/cluster-ca.pem
cli cluster:tls:trust /ca/cluster-ca.pem > $t/ca.pem
for n in m1 m2 m3 s1 s2; do cli cluster:tls:issue $n /ca/cluster-ca.pem > $t/$n.pem; done
# the stranger: a second, foreign authority
cli cluster:tls:ca > /tmp/cluster-ca/foreign-ca.pem
cli cluster:tls:trust /ca/foreign-ca.pem > $t/stranger-ca.pem
cli cluster:tls:issue x1 /ca/foreign-ca.pem > $t/x1.pem
rm -r /tmp/cluster-ca
```

The node certificates are valid for ten years (the authority's lifetime); a
node warns in its log from 30 days before the end.

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.
