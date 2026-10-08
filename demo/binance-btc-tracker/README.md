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

The design lives in `hilos-ops/mockups/demos/binance-btc-tracker`:

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
| Frontend dev server (HMR) | http://localhost:5176 |
| Built frontend behind nginx (`daemon-start-build`) | https://localhost:8116 |
| phpMyAdmin | http://localhost:8119 |
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
| `composer run daemon-start` | start the local stack (MySQL, phpMyAdmin, Mailpit, daemon, Vite dev server) |
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
| `composer run pma` | start phpMyAdmin at http://localhost:8119 |

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
it never comes up with the rest. All of them share one MariaDB and one schema,
on the subnet 10.221, and nothing is published on the host. It is a compose
project of its own, `hilos-binance-btc-tracker-cluster`, because the e2e steps
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
refused, 20 rt set width across nodes (parked, P-456), 23 verifier circle on
every master, 25 freeze settles on every master (parked, P-456), 29 a node with
its own cluster directory refused on both ends, and 30 a ready export copy
outliving the node its agent lived on, 33 every master takes browsers, 34 a
tab is the same on every master under protected mode, 35 rt row deleted while
cut off is swept, and 36 a slave cut off with its leader stops its work
(`docs/agents/testing.md`,
"The cluster stands — three demos, three shapes"). The
`entry-welcome <master> [<token>] [<pass>]` harness command reads the first
WebSocket welcome through the stand entry and reports whether that browser is
inside or on the maintenance stub.
The framework's probe fleet and runtime-set probe are in this demo's `AGENTS`,
and they start only here: on one node, on the Playwright stand and in
production the rows are carried and nothing is run.

| Command | What it does |
|---|---|
| `composer run test:cluster:up` | build the images and start the database, the five nodes and the cli container |
| `composer run test:cluster:status` | the containers and one line of each node's view: phase, leader, placements |
| `composer run test:cluster:scenarios` | the stand's scenario matrix on a fresh stand; `-- 17 20` runs only the ones named |
| `composer run test:cluster:e2e` | the browser suite on a fresh stand in live and after-loss phases; `-- --grep circle` points it |
| `composer run test:cluster:down-volumes` | take the stand down, database included |
| `composer run test:cluster:down` | take the stand down the way the test runner does |

The e2e profile adds Mailpit and a Playwright runner. The browser enters through
one stand entry; every context selects a master with `hilos_stand_master` before
its first navigation. Browsers, including the protected-mode operator, start
on a follower master. In `tests/e2e/cluster/live`, backup checks a completed
archive, logs checks the overview and a slave's live file, protected-mode checks
the stub, the verifier circle and admission across masters, and sessions checks
sign-in, sign-out and access re-decisions across masters. The backup spec asks
the harness to stop the slave holding its archive; after placement moves,
`cluster/after-loss` checks the archive's out-of-reach row and offline log refusal.
The stand remains up after this command, as it does after `test:cluster:scenarios`.
Every browser run starts with empty node log directories, rotation archives
included; copy the previous run's logs before starting the next one.

The harness's other commands — `kill`, `partition`, `cut`, `crash-daemon`, `inspect`,
`stranger up`, `own-directory s1 on` and the rest — are called on the module
directly, from `demo/binance-btc-tracker`:

```bash
python3 ../../framework/docker/cluster/cluster.py docker/docker-compose.cluster.yml inspect m1
```

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
