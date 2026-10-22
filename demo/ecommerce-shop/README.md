# E-commerce Shop Demo Project

**Complexity: 5/5**

"Hilos Flowers": a flower shop on a real-time Hilos application — stock,
carts and deliveries that move on every open page at once.

## What is this?

### Today

The empty shell of the shop, and the smallest complete Hilos project on React
— the one to copy (`docs/new-project/README.md`):

- **Sign-in** by password, on the framework surface; registration and recovery
  confirm the address with a code sent by mail.
- **Home page** that says who is looking: "Browsing anonymously" for a visitor,
  "Signed in as …" with an account.
- **Admin dashboard** at `/hilos` with **Settings** (the three example keys),
  **Users** (the list and a person's card: rename, takeover and rights; no
  account merge), **Backup** (database archives) and **Maintenance** (the
  verifier circle). The other admin sections arrive with the leaves that
  bring them.
- **Notifications**: the bell in the header for a signed-in person, without
  delivery.
- **Public pages** About, Terms, Privacy and License, prerendered into the build.

The backend is the base set: one app agent owning the connections and the
home page, the Hilos index agent with the dashboard, Maintenance, Backup,
Settings, Users and the footer pages, the settings library, the notifications
library, the backup agent, and the framework sign-in libraries. It keeps no
table of its own — people, sessions and the tables of signing in are the
framework's.

### What it is going to show

The design lives in `hilos-ops/mockups/demos/ecommerce-shop`:

- **Storefront**: flower categories as tiles, a category as a table or as
  tiles, with filters and an order; a one-of-a-kind item sold to somebody else
  leaves every open page at once.
- **Product page**: stock, and live counters of who is looking at it right now
  and how many carts hold it.
- **Search** at the top of every page, answering always — an AI chain first,
  words last.
- **Cart** that follows a visitor into the account they sign in to, with
  quantities and a voucher.
- **Checkout** into a parcel locker, paid on a stand-in payment page: no money
  is involved.
- **Orders**: the delivery of each moving step by step on its own, in the
  order and in the list of orders.
- **Shop administration**: warehouses, categories, products and their stock,
  vouchers and orders.
- **Your AI**: the shop's read-only tools over MCP — search, products, stock.

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
docker compose -f docker/docker-compose.local.yml exec ecommerce-shop-mysql-local \
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
The React views mount all three: the dashboard at `/hilos/change-log`, Tracked
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
# framework/frontend/node_modules — without this the dev server fails with
# "Failed to resolve import zod":
#   docker compose -f framework/docker/docker-compose.frontend.yml \
#     run --rm hilos-frontend-cli npm install
composer run frontend:install   # npm install in the frontend container
composer run daemon-start       # MySQL + phpMyAdmin + Mailpit + daemon + Vite dev server
```

The daemon applies migrations on startup. Endpoints (defaults):

| Endpoint | URL |
|---|---|
| Frontend dev server (HMR) | http://localhost:5177 |
| Built frontend behind nginx (`daemon-start-build`) | https://localhost:8136 |
| phpMyAdmin | http://localhost:8139 |
| Mailpit (the codes a registration and a recovery send) | http://localhost:8140 |
| Daemon status API | http://localhost:8130/status |
| Daemon WebSocket | ws://localhost:8132 |
| MySQL (from host) | localhost:33069 |

An administrator is made from the command line: `composer run cli -- admin:create
<sessionToken>` makes a browser session an administrator, minting its account when
it has none, and `admin:grant` / `admin:revoke` move the flag on an existing one
(`docs/agents/cli/commands.md`).

These host-side ports are compose *interpolation* values, so `.env` cannot
change them — compose reads them from the shell environment or from
`docker/.env` before any container exists (`HTTP_STATUS_HOST_PORT=18130 composer
run daemon-start`). A port written into `.env` is silently ignored: that file is
the container `env_file`.

## Stack commands

| Command | What it does |
|---|---|
| `composer run daemon-start` | start the local stack (MySQL, phpMyAdmin, Mailpit, daemon, Vite dev server) |
| `composer run daemon-start-build` | the same plus the prod-parity nginx over the built artifact |
| `composer run daemon-stop` | stop the local stack |
| `composer run daemon-restart` | restart the daemon container |
| `composer run daemon-status` | daemon status via the CLI |
| `composer run frontend:install` | `npm install` in the frontend container |
| `composer run frontend:check` | type-check the frontend (`tsc`) |
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
| `composer run pma` | start phpMyAdmin at http://localhost:8139 |

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

`docker/docker-compose.cluster.yml` raises this demo as a Hilos cluster of one
master and two slaves. `m1` is the whole master set and declares no capacity,
so it takes no placed work and stays the leader for as long as it lives:
placement and failover are proved where the leader is stable. The slaves are of
unequal room on purpose — `s1` declares `worker,ram=10,slots=64`, `s2`
`worker,ram=4,slots=64` — because scenario 18 fills them by the room they declare,
not by their number; the slots are what lets them take placed work at all.
All three share one MariaDB 11.4 server, `ecommerce-shop-cluster-mysql`,
with the primary database and its derived journal. It is the only database
address the nodes and cli know. The local aggregate uses default subnet
`10.222.0.0/16`, publishes nothing on the host and has a compose project of its own,
`hilos-ecommerce-shop-cluster`, because the e2e steps take their whole project
down when they start.

Nothing here drives the stand: the framework's shared cluster harness
(`framework/docker/cluster/`) reads the nodes out of the compose file and runs
the scenarios it names — 3 placement, 4 slave-kill failover, 6 hot-join,
9 daemon-crash self-heal, 12 rt replication, 14 rt claim refused,
16 recreated node leaves no phantom fleet (parked, P-441/2), 18 capacity is consumed,
19 worker death on a live node, 37 costless agents consume slots, and
39 person agents across nodes (skipped as flaky since 2026-10-17),
43 waiting work retries when a node returns
(`docs/agents/testing.md`, "The cluster stands — three demos, three shapes"). The framework's probe fleet, claimer and ballast
are in this demo's `AGENTS`, and they start only here: on one node, on the
Playwright stand and in production the rows are carried and nothing is run.

| Command | What it does |
|---|---|
| `composer run test:cluster:up` | build/recreate the default database, three nodes and CLI, retaining data and protected mode |
| `composer run test:cluster:restart` | ordinary down/up with current code, retaining both databases and protected mode |
| `composer run test:cluster:status` | the containers and one line of each node's view: phase, leader, placements |
| `composer run test:cluster:inspect -- m1` | inspect one node as JSON |
| `composer run test:cluster:logs -- m1` | follow one node's container logs; Ctrl-C stops following |
| `composer run test:cluster:client -- m1 test:cluster:inspect` | run an existing CLI command through the selected node |
| `composer run test:cluster:scenarios` | the stand's scenario matrix on a fresh stand; `-- 14 18` runs only the ones named |
| `composer run test:cluster:down-volumes` | take the stand down and wipe its primary and journal databases |
| `composer run test:cluster:down` | stop the stand, retaining database data and protected-mode stores |

Scenario 43 stops both slaves after the ordinary fleet starts. The lone master
declares no capacity, so one uniquely indexed ballast stays `unplaced` with
`nodeId=null` and a `no_declared_capacity` refusal for that master. A second ask
keeps one record. Returning one slave starts the waiting ballast without another
ask; the leader logs waiting and resumed once each. Cleanup restarts the whole
stand without deleting database volumes, restores both slaves and the ordinary
fleet, and removes the extra ballast demand. It runs before 37 and 18; prove
that cleanup against both capacity scenarios on one fresh stand from the root:

```bash
composer -d demo/ecommerce-shop run test:cluster:scenarios -- 43 37 18
```

The companion queue-inheritance proof is scenario 44 on binance-btc-tracker's
three-master stand. It fills both slaves' slots with extra fleet indices,
checks the waiting record's complete snapshot on every surviving master before
killing the leader, then recovers it through re-election and quorum loss.
Giving one slave room starts that target without another ask. Both scenarios
restore the whole original stand without deleting database volumes; 44 runs
after the ordinary leadership scenarios and before later binance scenarios.

Run scenario 39 alone on a fresh stand, from `demo/ecommerce-shop`:

```bash
composer run test:cluster:scenarios -- 39
```

In the full matrix, 39 runs after 9. It raises one person's agent on `s1`,
waits for its ordinary **240-second idle window**, then wakes the same index
on `s2` while the people and sessions libraries remain on `s1`. The idle
check requires both the worker log's stop reason and the placement's removal;
it does not shorten the product timeout. Killing `s2` then moves the active
owner back to `s1`.

The checks use real `admin:grant` / `admin:revoke` row writes and
`second-factor:unlock` writes to the person's child set, with expected CLI
replies and an independent database oracle. The first unlock clears a real
lock; after wake-up and failover, unlock proves the set's Update permission
again and advances `updated_at`. Confirmed facts survive idle and host loss,
the reset-wait sentinel stays intact, and a second person's rows stay unchanged.
Cleanup removes only the fixtures, restarts the same stand without deleting
volumes, and waits for the full mesh and fleet before the next scenario.

The harness commands — `kill`, `partition`, `crash-daemon`, `kill-worker`,
`freeze-worker`, `recreate`, `inspect` and the rest — use the shell entry point
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
`docker/cluster/tooling.yml`. Local services extend the canonical `database.yml`
and `node.yml` there; the canonical services add no extra nodes. Compose 2.40.3,
pinned by the harness, resolves `ports: !reset []` and per-node
`env_file: !override`. Each local node loads tracked `.env.example`,
`common.env` and its fixed `nodes/<id>.env`. The CLI is default-on, with
`APP_ENV=test` and explicit cluster DB/command settings; nodes use `APP_ENV=dev`.
The CLI mounts tracked `tests/.env.example` read-only at `/app/tests/.env`,
inside `/app/tests` tmpfs so Docker cannot create a missing mount target on the
host bind. Test suites keep their own services; no host env file is copied or
overwritten. This cluster stand has no entry, browser profile or extra export
volume.

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

Every new scenario invocation checks its prerequisites, resets the database
once with `down --volumes`, starts the stand and opens recognized saved protected
mode through its owner before fleet readiness. Unknown identities, malformed
stores/replies, refusals and timeouts fail startup. No global reset is inserted
between scenarios or retries, and there is no `--keep-db` mode: a new matrix
discards accumulated ordinary fixtures.

Names and the `/dev/shm` DB directory take `HILOS_STAND_SUFFIX`; the subnet takes
`HILOS_STAND_NET_ECOMMERCE_SHOP_CLUSTER`, and CPU weight takes
`HILOS_STAND_CPU_SHARES`. See the shared guide's
[stand instances](../../docs/agents/testing.md#the-full-run--one-graph-a-bounded-number-of-lanes)
and [cluster lifecycle](../../docs/agents/testing.md#cluster-compose-model).

### Standalone components

Copy `docker/cluster/standalone.env.example` outside the checkout, edit its
paths/endpoints and prepare the selected runtime file from
`docker/cluster/nodes/m1.env`. From this demo directory:

```bash
docker compose --env-file /srv/hilos/ecommerce.env -p ecommerce-db -f docker/cluster/database.yml config
docker compose --env-file /srv/hilos/ecommerce.env -p ecommerce-m1 -f docker/cluster/node.yml config
# After provisioning the inputs, start only this node on its server:
docker compose --env-file /srv/hilos/ecommerce.env -p ecommerce-m1 -f docker/cluster/node.yml up -d --build
```

`--env-file` supplies Compose interpolation; `HILOS_CLUSTER_NODE_ENV` selects
the separate runtime file loaded after `common.env`. Build/bind paths resolve
from `docker/cluster`. The example binds on `0.0.0.0` and publishes DB `3306`,
peer `18095`, WebSocket `18092` and HTTP `18090`; command and worker IPC ports
stay private. Prepare reachable DB/advertise/seed endpoints, excluding the
node itself from seeds, matching mutual-TLS files and built dependencies.
Each slave needs its own runtime identity and capability values. Supply trusted
proxy ranges and shared storage if the deployed application uses them; this
headless stand supplies neither a proxy entry nor an export-storage mount.
These files provision no remote hosts or storage. Standalone config resolution
has been checked; real multi-host deployment has not been tested, and the
harness remains a local single-host runner.

### Database

One MariaDB 11.4 server holds `hilos-demo-ecommerce-shop-cluster` and
`hilos-demo-ecommerce-shop-cluster-change-log`. The fresh data directory
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
before starting Hilos with the new code. From `demo/ecommerce-shop`:

```bash
docker compose -f docker/docker-compose.cluster.yml up -d --wait ecommerce-shop-cluster-mysql
docker compose -f docker/docker-compose.cluster.yml exec ecommerce-shop-cluster-mysql \
  /usr/local/bin/provision-change-log.sh
composer run test:cluster:up
```

Provisioning uses the existing `MYSQL_DATABASE`, `MYSQL_USER` and
`MYSQL_ROOT_PASSWORD`. It is repeatable: it creates a missing journal and
refreshes its grant without DROP or reset. Provisioning again and restarting
on the same data directory preserve both primary and journal rows. The stand
mounts `/dev/shm/hilos-ecommerce-shop-cluster-mysql-data` and the shared
`test-stand.cnf`; `test:cluster:down` preserves that data, while
`test:cluster:down-volumes` and each fresh scenario run clear it. Local and test
volumes are separate and are not part of this transition. `/dev/shm` is host
memory: container replacement retains it, but a host reboot clears it.

Historical migration: the former replica is removed by the old compose file's `down`; its data
stays at `/dev/shm/hilos-ecommerce-shop-cluster-replica-data`. Scenario 31
remains in the shared harness, with no current stand carrying it. Specialized
database topologies and six new demos are P-567, deferred to 2026-10-24;
replication is not a passing check of this stand.

### TLS fixtures

`docker/tls/` holds the certificates of this stand, and they are **stand
fixtures only**: the authority behind them signs nothing else, its key is not
in the repository, and it is not the authority of any other stand on the same
machine. Nothing here is a template for a real cluster — issue your own.

| File | What it is | Used by |
|---|---|---|
| `ca.pem` | the stand authority's certificate, no key | `CLUSTER_TLS_CA_FILE` of the three nodes |
| `m1.pem`, `s1.pem`, `s2.pem` | a node certificate (CN = node id) followed by its key | `CLUSTER_TLS_CERT_FILE` of that node |

Reissuing means a new set in full: the old authority's key is gone, so no
single file can be replaced alone. The commands run in the image of the
stand's cli container, which exists after the first
`composer -d demo/ecommerce-shop run test:cluster:up` (or `docker compose -f
demo/ecommerce-shop/docker/docker-compose.cluster.yml build
ecommerce-shop-cluster-cli`). The framework's own commands print PEM to stdout
and the host shell writes the files, so they come out owned by you rather than
root. Keep the authority file (`cluster-ca.pem`) outside the repository and
delete it when done — from the repository root:

```bash
cli() {  # one framework CLI command in a throwaway container, no network needed
  docker run --rm --network none --user "$(id -u):$(id -g)" \
    -v "$PWD/demo/ecommerce-shop":/app:ro -v "$PWD/composer.json":/hilos/composer.json:ro \
    -v "$PWD/composer.lock":/hilos/composer.lock:ro -v "$PWD/framework":/hilos/framework:ro \
    -v /tmp/cluster-ca:/ca:ro -w /app -e APP_ENV=dev \
    hilos-ecommerce-shop-cluster-ecommerce-shop-cluster-cli:latest php backend/Bootstrap/cli.php "$@"
}
mkdir -p /tmp/cluster-ca && t=demo/ecommerce-shop/docker/tls
cli cluster:tls:ca > /tmp/cluster-ca/cluster-ca.pem
cli cluster:tls:trust /ca/cluster-ca.pem > $t/ca.pem
for n in m1 s1 s2; do cli cluster:tls:issue $n /ca/cluster-ca.pem > $t/$n.pem; done
rm -r /tmp/cluster-ca
```

The node certificates are valid for ten years (the authority's lifetime); a
node warns in its log from 30 days before the end.

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.
