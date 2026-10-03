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
three equal masters, `m1`–`m3`, each declaring `worker,ram=10`: every master is
the consensus and the data plane at once and carries placed work itself, and
the leader is still last among them, so the work lives on the two that do not
lead until a re-election hands the leader what it carried. All three share one
schema on a MariaDB Galera of three members, `galera1`–`galera3`, which every
node writes to through one address, an HAProxy in front of them
(`online-testing-cluster-proxy`, leastconn); reads wait for the cluster's writes
(`wsrep_sync_wait=1`). All of it sits on the subnet 10.223, and nothing is
published on the host. It is a compose project of its own,
`hilos-online-testing-cluster`, because the e2e steps take their whole project
down when they start.

Nothing here drives the stand: the framework's shared cluster harness
(`framework/docker/cluster/`) reads the nodes out of the compose file and runs
the scenarios it names — 21 schema rolled out once, 24 cut-off leader stops its
work, 26 database is one cluster, 11 cross-node db fact, 15 db interest
addressing and 22 other database refused (`docs/agents/testing.md`, "The
cluster stands — three demos, three shapes"). The framework's probe fleet and
database probe are in this demo's `AGENTS`, and they start only here: on one
node, on the Playwright stand and in production the rows are carried and
nothing is run.

| Command | What it does |
|---|---|
| `composer run test:cluster:up` | build the images and start the three database members, the proxy in front of them, the three nodes and the cli container |
| `composer run test:cluster:status` | the containers and one line of each node's view: phase, leader, placements |
| `composer run test:cluster:scenarios` | the stand's scenario matrix on a fresh stand; `-- 24` runs only the ones named |
| `composer run test:cluster:down-volumes` | take the stand down, the database members' volumes included |
| `composer run test:cluster:down` | take the stand down the way the test runner does |

The harness's other commands — `kill`, `partition`, `crash-daemon`, `inspect`
and the rest — are called on the module directly, from `demo/online-testing`:

```bash
python3 ../../framework/docker/cluster/cluster.py docker/docker-compose.cluster.yml inspect m1
```

### Database

`galera1` raises the cluster, and only while `galera2` and `galera3` are silent;
the other two always join, one after the other. That is why
`test:cluster:down` followed by `test:cluster:up` brings the stand back with its
data: `galera1` finds nobody to join and raises the cluster again from what it
kept. The members share `docker/galera/galera.cnf`, the proxy reads
`docker/haproxy/haproxy.cfg`.

`db-sql` runs a statement in `galera1`, and on the member named after it
otherwise:

```bash
python3 ../../framework/docker/cluster/cluster.py docker/docker-compose.cluster.yml db-sql "SHOW GLOBAL STATUS LIKE 'wsrep_cluster_size'" online-testing-cluster-galera2
```

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
demo/online-testing/docker/docker-compose.cluster.yml --profile cli build
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
