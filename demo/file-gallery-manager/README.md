# File Gallery Manager Demo Project

File Gallery Manager is an active React demo with its own MariaDB service. Today
it is an empty carrier of the framework's sign-in and admin sections; the gallery
described under [Planned product](#planned-product) is not built yet.

## What runs today

- The React application shell has an empty public home page.
- The header carries a sign-in control: sign-in and registration by password
  open from it on any page, and verification codes are sent by email.
- Signed-in users have the Profile page and its notification channels.
- The `/hilos` admin area has Settings, Users, Analytics, Communications,
  Languages and countries, Daemon, Logs and Change Log.
- The standard About, Terms, Privacy and license pages are mounted.
- The e2e suite carries this demo's own `activation.spec.ts`: a guest opens the
  empty home, and a person who signs in from the header finds their own visit
  in Analytics.
- It also carries the React browser specs of framework admin sections, moved
  here whole: the six languages and countries lifecycle specs
  (`i18n-country-names`, `i18n-country`, `i18n-language-names`,
  `i18n-language`, `i18n-locale`, `i18n-overview`), `daemon`, the three Change
  Log specs (`change-log`, `change-log-table`, `change-log-tables`) and
  `communications` from Tasks, and `users` from Ecommerce Shop.
- Local development and the test stand each use this project's MariaDB service.
  It holds the framework's tables only, and the change log journal keeps its own
  database beside it on the same server; the project has no tables of its own.

## Prerequisites and first setup

Use Composer 2.8 or newer, Docker Compose v2 and an available Docker daemon. Use
Composer to invoke the documented scripts; PHP and Node project dependencies,
checks and builds run in project containers.

From `demo/file-gallery-manager`:

```bash
composer run setup-env       # create .env from .env.example (tests/.env is tracked)
composer run install-deps    # install PHP dependencies in the CLI container
```

Once per checkout, install the framework frontend SDK workspace from the
repository root. Repeat this after framework frontend dependency changes:

```bash
docker compose -f framework/docker/docker-compose.frontend.yml \
  run --rm hilos-frontend-cli npm install
```

The frontend project uses the linked framework packages. `frontend:install`
installs this demo's own frontend dependencies in its container. From
`demo/file-gallery-manager`, run it after the framework workspace install:

```bash
composer run frontend:install
```

## Local application

From `demo/file-gallery-manager`, start the local stack:

```bash
composer run daemon-start
```

This starts MariaDB, Mailpit, the daemon and the Vite development server. The
daemon applies pending migrations during startup. Use the header's sign-in
control to register or sign in; local verification and recovery messages can be
read in Mailpit.

| Local endpoint | Default address |
|---|---|
| React dev server with HMR | <http://localhost:5182> |
| Built app behind nginx | <https://localhost:8236> |
| Mailpit inbox | <http://localhost:8240> |
| Daemon status | <http://localhost:8230/status> |
| Daemon WebSocket | <ws://localhost:8232> |
| MariaDB from the host | `localhost:33079` |

The built app is optional. Build it before starting the local nginx profile:

```bash
composer run frontend:build
composer run daemon-start-build
```

The admin area is at `/hilos`. Grant a local signed-in session administrator
access with `composer run cli -- admin:create <sessionToken>`; the account and
session commands are documented in
[`docs/agents/cli/commands.md`](../../docs/agents/cli/commands.md).

Stop the local stack with `composer run daemon-stop`. This stops its containers
and preserves the local MariaDB data under `data/mysql`.

The host ports above are the defaults in this demo's Compose files. Its daemon,
nginx and Mailpit ports sit in the 8230–8249 block; the local stack claims the
`10.207.0.0/16` network and the test stack `10.217.0.0/16`. The shared plan is
under "Ports" in
[`docs/new-project/README.md`](../../docs/new-project/README.md). Their
published values and stand identity can follow the shared Compose override and
stand instance configuration; the daemon's in-container ports remain 8090, 8091
and 8092.

## Database migrations

The Schema track is the immutable
`backend/Database/Migration/Schema/001_framework_baseline.sql` pair, written from
the facade's features; framework changes arrive after it as generated migration
pairs. Keep the baseline unchanged. The demo is registered in the repository's
[`framework-projects.json`](../../framework-projects.json), so
`composer db:framework:pull` from the repository root includes it. For an update
of this demo alone, run the normal pull from this project, review and commit the
generated migration pairs, then apply pending migrations:

```bash
composer run cli -- db:framework:pull
composer run db:migration:up
composer run db:migration:status
composer run db:schema:status
```

The change log journals `hilos_identity`, `hilos_second_factor`, `hilos_setting`
and `hilos_user`; their generated triggers are committed under
`backend/Database/Migration/Triggers`. After a framework change to one of those
tables, regenerate them on the test stand with
`composer run test:change-log:generate` and commit the result. See the
[migration guide](../../docs/agents/orm/migrations.md) and the
[change log](../../docs/agents/architecture/change-log.md) for the rules.

## Tests

PHP commands run in the test CLI container:

| Command | What it does |
|---|---|
| `composer run test:install-deps` | Install the locked PHP dependencies in the test CLI container |
| `composer run test:unit` | Run the unit suite and topology registry guard |
| `composer run test:integration` | Run the integration suite against the test database |
| `composer run test:phpunit` | Run all PHPUnit suites |

For an integration run, prepare and clean up the test database from
`demo/file-gallery-manager`:

```bash
composer run test:up
composer run test:db-reset
composer run test:integration
composer run test:down
```

Frontend and end-to-end commands:

| Command | What it does |
|---|---|
| `composer run test:check` | Install if needed, type-check/lint/format-check the app and lint/format-check e2e |
| `composer run test:e2e-build` | Build the app for the test stack |
| `composer run test:e2e-install` | Install the Playwright dependencies |
| `composer run test:e2e-check` | Type-check the e2e test code |
| `composer run test:e2e` | Run the suite against an already raised test stack |
| `composer run test:e2e-full` | Run the full clean e2e lifecycle |

The clean lifecycle tears down the prior test stack, prepares a fresh test
database, builds the frontend, installs and checks Playwright, starts the daemon
and nginx, runs the tests and tears the stack down. To point the same clean
cycle at one real spec, run from `demo/file-gallery-manager`:

```bash
composer run test:e2e-full -- tests/activation.spec.ts
```

The test browser reaches nginx inside Compose as
`https://file-gallery-manager-nginx-test`. For manual inspection from the host,
use `https://localhost:8238`. The test nginx also publishes HTTP on port
`8237`, which redirects to the default HTTPS port. Test MariaDB is published at
`localhost:33080`; its data directory is in memory and is discarded with the
container. The test daemon publishes status on `8233` and WebSocket on `8235`.
The test Mailpit service has no host port: the e2e runner reads its mailbox at
`http://file-gallery-manager-mailpit-test:8025` inside the Compose network.

If a run stops before its final cleanup, use `composer run test:down`. To also
remove Compose's named volumes, use `composer run test:down-volumes` when their
data should be discarded.

For test selection and stand lifecycle details, see
[`docs/agents/testing.md`](../../docs/agents/testing.md) and the
[frontend testing strategy](../../docs/agents/frontend/testing-strategy.md).

## Cluster stand

`docker/docker-compose.cluster.yml` declares two masters, `m1` and `m2`, with
no placement capacity, and three slave workers: `s1` with `ram=10`, `s2` with
`ram=6`, and `s3` with `ram=4`; each worker has `slots=64`. Its scenario
matrix contains only **31**, which checks that the replica keeps up with the
primary.

The five nodes and CLI use the `file-gallery-manager-cluster-mysql` database
address, which always reaches the MariaDB primary. An asynchronous read-only
replica receives the application database, the change-log journal and its
grant through replication. Application connections do not read from the
replica, and the stand has no automatic promotion.

One nginx `entry` is the browser-facing endpoint. It serves the built React
app from `frontend/dist` and sends HTTP and WebSocket traffic to the two master
endpoints. The entry and all five nodes share the named `data-export` volume.
The Mailpit service and Playwright runner are available in the `e2e` profile.

From this demo directory:

| Command | What it does |
|---|---|
| `composer run test:cluster:up` | start the cluster stand |
| `composer run test:cluster:restart` | restart the stand with the current code |
| `composer run test:cluster:status` | show container and node status |
| `composer run test:cluster:inspect -- m1` | inspect a node as JSON |
| `composer run test:cluster:logs -- m1` | follow a node's container logs |
| `composer run test:cluster:client -- m1 test:cluster:inspect` | run a CLI command through a selected node |
| `composer run test:cluster:scenarios` | run the declared matrix; `-- 31` selects its scenario |
| `composer run test:cluster:e2e` | run the cluster browser phase |
| `composer run test:cluster:e2e-time-zone` | prepare the cluster browser time-zone environment |
| `composer run test:cluster:down` | stop the stand |
| `composer run test:cluster:down-volumes` | stop the stand and remove its volumes |

### Compose layout and journal replication

The root compose file includes `docker/cluster/local.yml` and
`docker/cluster/tooling.yml`. The local services extend the standalone parts
in `docker/cluster/database.yml`, `docker/cluster/node.yml` and
`docker/cluster/entry.yml`. `docker/cluster/tooling.yml` supplies the CLI and
the e2e-only Mailpit and Playwright runner. The local nodes load `.env.example`,
`common.env` and their fixed `nodes/<id>.env` file.

The primary alone mounts `docker/mysql/init-change-log.sh` and
`docker/mysql/provision-change-log.sh`. Initialization on a fresh primary, or an
explicit provisioning run for an existing database, creates the change-log
journal and grants the application user access. Binary-log replication carries
the main database, journal, grants and trigger definitions to the replica; the
replica does not run the initialization scripts. The application installs
journal triggers through the primary, and row-based replication does not fire
them again when applying row changes on the replica. With binary logging
enabled, MariaDB requires
`log_bin_trust_function_creators=1` for the application user to create those
triggers; without it, MariaDB returns error 1419.

### Standalone components

`docker/cluster/standalone.env.example` shows the Compose interpolation values
for running the database, a node or the nginx entry as separate components.
Copy it outside the checkout, set reachable database and peer addresses, choose
the node identity file, and provide matching TLS files and shared export
storage. From this demo directory, resolve the component configurations with
Compose 2.40.3 or newer:

```bash
docker compose --env-file /srv/hilos/file-gallery-manager.env -p gallery-db -f docker/cluster/database.yml config
docker compose --env-file /srv/hilos/file-gallery-manager.env -p gallery-m1 -f docker/cluster/node.yml config
docker compose --env-file /srv/hilos/file-gallery-manager.env -p gallery-entry -f docker/cluster/entry.yml config
```

`--env-file` supplies Compose interpolation. `HILOS_CLUSTER_NODE_ENV` selects
the separate runtime environment file loaded after `common.env`; paths resolve
from `docker/cluster`. These files describe components but do not provision
remote hosts or shared storage.

### TLS fixtures

`docker/tls/` contains this stand's fixtures only. `ca.pem` is the CA
certificate, with no private key. Each node fixture, `m1.pem`, `m2.pem`,
`s1.pem`, `s2.pem` and `s3.pem`, contains a node certificate followed by its
key for `CLUSTER_TLS_CERT_FILE`. Use certificates issued for the deployment
when running components outside this local stand.

## Planned product

Everything in this section describes the planned product, not what runs today:
none of the gallery, its uploads or its image processing is built yet.

**Complexity: 3/5**

### What is this?

File manager with image gallery. Users upload files through web interface.

### Features

- **File processing**: Separate processing of downloadable files (documents, archives) and images (preview, processing, conversion)
- **Real-time image processing**: Resize, apply filters, format conversion (JPG, PNG, WebP), thumbnail generation
- **AI image moderation**: Checks for unwanted content, automatically removes or hides inappropriate images, generates descriptions
- **Private messaging**: Personal messages between users with file exchange in private chats
- **Multi-threaded processing**: Multiple threads process file uploads in parallel, separate threads process images, AI moderation works in separate thread
- **Frontend**: Drag-and-drop upload, gallery with preview, image processing tools, file download, private messaging system

### Technical Highlights

- File system operations
- File upload/download
- Image processing
- Thumbnail generation
- AI content moderation
- Real-time gallery updates
- Private messages with files

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.
