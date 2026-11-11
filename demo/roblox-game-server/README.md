# Roblox Game Server Demo Project

**Complexity: 5/5**

Demo project demonstrating game server architecture for Roblox with low latency.

Roblox Game Server is an active Vue demo. Today it is the application shell with
the framework's operations sections; the game server described under
[What is this?](#what-is-this) is what it is going to show.

## What runs today

- The Vue application shell has an empty home page and the standard sign-in
  flow; registration and recovery confirm the address with a code sent by mail.
- Signed-in users have the standard profile page, with its photo, and Profile
  notifications.
- The `/hilos` admin area has Analytics, Users, Settings, Appearance,
  Communications, Change Log, Moderation, Daemon, Backup, Logs and Maintenance.
- The Change Log journals `hilos_user`, `hilos_identity`, `hilos_second_factor`,
  `hilos_setting` and `hilos_moderation_rule` into its own database beside the
  primary one, on the same MariaDB server.
- Local development and the test stand each use this project's MariaDB service.

The game itself — rooms, teams, ratings, leagues and bots — is not built yet.

## What is this?

This is a game server implementation for Roblox with a specific game mode: team PvP arena with point capture.

### Features

- **Game mode**: Team PvP arena with point capture - players divided into teams fighting for control points
- **Scoring system**: Points, respawn, temporary bonuses for capturing points
- **Rating system**: Players earn points for wins, captures, kills - updated in real-time with leaderboard display
- **Leagues**: Different leagues by rating level (bronze, silver, gold, etc.)
- **Agent-based architecture**: Agents manage separate game sessions (rooms), each room is a separate agent for state isolation
- **High-frequency sync**: Synchronization of player positions, health, statuses at 60 FPS
- **AI bots**: AI agents manage bot players to fill rooms, analyze game situation, help with team balancing
- **Low-latency communication**: WebSocket ensures low-latency communication with Roblox clients
- **Admin panel**: Vue 3 + TypeScript frontend showing room statistics, active players, performance metrics, rating tables

### Technical Highlights

- Game logic implementation
- In-memory state management
- Concurrent event processing from multiple players
- Scaling through multiple agents for different game rooms
- Anti-cheat validation
- Respawn system
- Game resource management
- Real-time rating system updates

## Prerequisites and first setup

Use Composer 2.8 or newer, Docker Compose v2 and an available Docker daemon. Use
Composer to invoke the documented scripts; PHP and Node project dependencies,
checks and builds run in project containers.

From `demo/roblox-game-server`:

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
`demo/roblox-game-server`, run it after the framework workspace install:

```bash
composer run frontend:install
```

## Local application

From `demo/roblox-game-server`, start the local stack:

```bash
composer run daemon-start
```

This starts MariaDB, Mailpit, the daemon and the Vite development server. A
fresh MariaDB data directory also creates the Change Log journal's database. The
daemon applies pending migrations during startup. Register or sign in through
the app; local verification and recovery messages can be read in Mailpit.

| Local endpoint | Default address |
|---|---|
| Vue dev server with HMR | <http://localhost:5180> |
| Built app behind nginx | <https://localhost:8196> |
| Mailpit inbox | <http://localhost:8200> |
| Daemon status | <http://localhost:8190/status> |
| Daemon WebSocket | <ws://localhost:8192> |
| MariaDB from the host | `localhost:33075` |

The built app is optional. Build it before starting the local nginx profile:

```bash
composer run frontend:build
composer run daemon-start-build
```

The default admin area is at `/hilos`. Grant a local signed-in session
administrator access with `composer run cli -- admin:create <sessionToken>`;
the account and session commands are documented in
[`docs/agents/cli/commands.md`](../../docs/agents/cli/commands.md).

Stop the local stack with `composer run daemon-stop`. This stops its containers
and preserves the local MariaDB data under `data/mysql`.

The host ports below are the defaults in this demo's Compose files. Their
published values and stand identity can follow the shared Compose override and
stand instance configuration; the daemon's in-container ports remain 8090, 8091
and 8092.

## Database migrations

The Schema track starts with the immutable
`backend/Database/Migration/Schema/001_framework_baseline.sql` pair. Keep it
unchanged. Roblox Game Server is registered in the repository's
[`framework-projects.json`](../../framework-projects.json), so
`composer db:framework:pull` from the repository root includes it. For an
update of this demo only, run the normal pull from this project, review and
commit the generated migration pairs, then apply pending migrations:

```bash
composer run cli -- db:framework:pull
composer run db:migration:up
composer run db:migration:status
composer run db:schema:status
```

The baseline command is for a new, empty Schema track; it does not regenerate
this demo's existing history. See the
[migration guide](../../docs/agents/orm/migrations.md) for the framework pull
and migration rules.

The Change Log triggers of the journaled tables are files under
`backend/Database/Migration/Triggers`. To regenerate them on the test stand
after migrations, use `composer run test:change-log:generate`; repeating it
leaves matching files untouched. Commit the complete generated set before
starting a daemon. A local MariaDB volume made before the journal existed gets
its database, safely and repeatably, with:

```bash
docker compose -f docker/docker-compose.local.yml exec roblox-game-server-mysql-local \
  /usr/local/bin/provision-change-log.sh
```

See the [Change Log guide](../../docs/agents/architecture/change-log.md) for the
journal's database and its triggers.

## Tests

PHP commands run in the test CLI container:

| Command | What it does |
|---|---|
| `composer run test:install-deps` | Install the locked PHP dependencies in the test CLI container |
| `composer run test:unit` | Run the unit suite and topology registry guard |
| `composer run test:integration` | Run the integration suite against the test database |
| `composer run test:phpunit` | Run all PHPUnit suites |

For an integration run, prepare and clean up the test database from
`demo/roblox-game-server`:

```bash
composer run test:up
composer run test:db-reset
composer run test:integration
composer run test:down
```

`test:db-reset` recreates the primary test database and the Change Log journal's
database beside it.

Frontend and end-to-end commands:

| Command | What it does |
|---|---|
| `composer run test:check` | Install if needed, type-check/lint/format-check the app and lint/format-check e2e |
| `composer run test:e2e-build` | Build the app for the test stack |
| `composer run test:e2e-install` | Install the Playwright dependencies |
| `composer run test:e2e-check` | Type-check the e2e test code |
| `composer run test:e2e-up` | Raise the test stack: a prepared database, the daemon and nginx |
| `composer run test:e2e` | Run the suite against an already raised test stack |
| `composer run test:e2e-full` | Run the full clean e2e lifecycle |

The clean lifecycle tears down the prior test stack, prepares a fresh test
database, builds the frontend, installs and checks Playwright, starts the daemon
and nginx, runs the tests and tears the stack down. To point the same clean
cycle at one real spec, run from `demo/roblox-game-server`:

```bash
composer run test:e2e-full -- tests/activation.spec.ts
```

The test browser reaches nginx inside Compose as
`https://roblox-game-server-nginx-test`. For manual inspection from the host,
use `https://localhost:8198`. The test nginx also publishes HTTP on port `8197`,
which redirects to the default HTTPS port. Test MariaDB is published at
`localhost:33076`; its data directory is in memory and is discarded with the
container. The test daemon publishes status on `8193` and WebSocket on `8195`.
The test Mailpit service has no host port: the e2e runner reads its mailbox at
`http://roblox-game-server-mailpit-test:8025` inside the Compose network.
`test:e2e` runs the runner in the test daemon's PID namespace through
`docker/docker-compose.e2e-restart.yml`, so the Daemon spec can pause the
stand's own test worker by its process during a real agent restart; install,
check and build keep their own namespace. The test daemon keeps
its backups, exports and files under `data/test/` and its logs under
`data/logs-test/`, apart from the local installation's `data/`.

If a run stops before its final cleanup, use `composer run test:down`. To also
remove Compose's named volumes, use `composer run test:down-volumes` when their
data should be discarded.

For test selection and stand lifecycle details, see
[`docs/agents/testing.md`](../../docs/agents/testing.md) and the
[frontend testing strategy](../../docs/agents/frontend/testing-strategy.md).

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.
