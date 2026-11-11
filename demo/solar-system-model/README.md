# Solar System Model Demo Project

Solar System Model is an active React demo with its own MariaDB service. Today
it carries the framework's sign-in, profile and admin sections and a set of
framework e2e specs; the 3D model described under
[Planned product](#planned-product) is not built yet.

## What runs today

- The React application shell has an empty public home page.
- The public header has no sign-in control. Sign-in and registration open as a
  form in place on `/hilos`, a page closed to a guest.
- Signed-in users have the standard Profile, Sign-in, Security and Data pages.
- The `/hilos` admin area has Analytics, Users and Settings. Notification
  delivery requires the Communications deliveries journal, so that one
  Communications page is mounted, without the rest of the section.
- The standard About, Terms, Privacy and license pages are mounted.
- The e2e suite carries framework specs moved from tasks and ecommerce-shop —
  profile, profile data, time zone and photo, account deletion, theme, Analytics
  and table geometry with its React fixture — plus Solar's own
  `activation.spec.ts`.
- Local development and the test stand each use this project's MariaDB service.
  It holds the framework's tables only; the project has no tables of its own.

## Prerequisites and first setup

Use Composer 2.8 or newer, Docker Compose v2 and an available Docker daemon. Use
Composer to invoke the documented scripts; PHP and Node project dependencies,
checks and builds run in project containers.

From `demo/solar-system-model`:

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
installs Solar's own frontend dependencies in its container. From
`demo/solar-system-model`, run it after the framework workspace install:

```bash
composer run frontend:install
```

## Local application

From `demo/solar-system-model`, start the local stack:

```bash
composer run daemon-start
```

This starts MariaDB, Mailpit, the daemon and the Vite development server. The
daemon applies pending migrations during startup. Open `/hilos` to register or
sign in; local verification and recovery messages can be read in Mailpit.

| Local endpoint | Default address |
|---|---|
| React dev server with HMR | <http://localhost:5181> |
| Built app behind nginx | <https://localhost:8216> |
| Mailpit inbox | <http://localhost:8220> |
| Daemon status | <http://localhost:8210/status> |
| Daemon WebSocket | <ws://localhost:8212> |
| MariaDB from the host | `localhost:33077` |

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

The host ports above are the defaults in Solar's Compose files. Solar's daemon,
nginx and Mailpit ports sit in the 8210–8229 block; the local stack claims the
`10.206.0.0/16` network and the test stack `10.216.0.0/16`. The shared plan is
under "Ports" in
[`docs/new-project/README.md`](../../docs/new-project/README.md). Their
published values and stand identity can follow the shared Compose override and
stand instance configuration; the daemon's in-container ports remain 8090, 8091
and 8092.

## Database migrations

Solar's Schema track is the immutable
`backend/Database/Migration/Schema/001_framework_baseline.sql` pair; framework
changes arrive after it as generated migration pairs. Keep the baseline
unchanged. Solar is registered in the repository's
[`framework-projects.json`](../../framework-projects.json), so
`composer db:framework:pull` from the repository root includes Solar. For a
Solar-only update, run the normal pull from this project, review and commit the
generated migration pairs, then apply pending migrations:

```bash
composer run cli -- db:framework:pull
composer run db:migration:up
composer run db:migration:status
composer run db:schema:status
```

The baseline command is for a new, empty Schema track; it does not regenerate
Solar's existing history. See the
[migration guide](../../docs/agents/orm/migrations.md) for the framework pull
and migration rules.

## Tests

PHP commands run in the test CLI container:

| Command | What it does |
|---|---|
| `composer run test:install-deps` | Install the locked PHP dependencies in the test CLI container |
| `composer run test:unit` | Run the unit suite and topology registry guard |
| `composer run test:integration` | Run the integration suite against the test database |
| `composer run test:phpunit` | Run all PHPUnit suites |

For an integration run, prepare and clean up the test database from
`demo/solar-system-model`:

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
| `composer run test:e2e-build` | Build the app and table-geometry fixture for the test stack |
| `composer run test:e2e-install` | Install the Playwright dependencies |
| `composer run test:e2e-check` | Type-check the e2e test code |
| `composer run test:e2e` | Run the suite against an already raised test stack |
| `composer run test:e2e-full` | Run the full clean e2e lifecycle |

The clean lifecycle tears down the prior test stack, prepares a fresh test
database, builds the frontend, installs and checks Playwright, starts the daemon
and nginx, runs the tests and tears the stack down. To point the same clean
cycle at one real spec, run from `demo/solar-system-model`:

```bash
composer run test:e2e-full -- tests/activation.spec.ts
```

The test browser reaches nginx inside Compose as
`https://solar-system-model-nginx-test`. For manual inspection from the host,
use `https://localhost:8218`. The test nginx also publishes HTTP on port
`8217`, which redirects to the default HTTPS port. Test MariaDB is published at
`localhost:33078`; its data directory is in memory and is discarded with the
container. The test daemon publishes status on `8213` and WebSocket on `8215`.
The test Mailpit service has no host port: the e2e runner reads its mailbox at
`http://solar-system-model-mailpit-test:8025` inside the Compose network.

If a run stops before its final cleanup, use `composer run test:down`. To also
remove Compose's named volumes, use `composer run test:down-volumes` when their
data should be discarded.

For test selection and stand lifecycle details, see
[`docs/agents/testing.md`](../../docs/agents/testing.md) and the
[frontend testing strategy](../../docs/agents/frontend/testing-strategy.md).

## Planned product

Everything in this section describes the planned product, not what runs today:
none of the model, its admin parameters or its 3D scene is built yet.

**Complexity: 4/5**

### What is this?

Static website with comprehensive admin panel for managing parameters of planets and their moons.
Admin can set parameters for all celestial bodies: size, distance from Sun, rotation speed,
orbital period, orbital inclination, textures, colors, atmospheric effects.

Simple browser 3D engine (Three.js or similar) renders the entire solar system in real-time.
3D visualization of planets, moons, orbits, asteroids, comets.
Realistic lighting from Sun, shadows, reflections, atmospheric effects.

### Features

- **Admin panel**: Comprehensive admin interface for managing planet and moon parameters
- **3D visualization**: Browser-based 3D engine renders solar system in real-time
- **Real-time updates**: Changes in admin panel immediately reflected in 3D model through WebSocket
- **Navigation UI**: Zoom, pan, rotate camera, switch between views (top, side, free camera)
- **Planet focus**: Focus on specific planet, follow planet, time acceleration/deceleration
- **Orbit display**: Display orbits, labels, names of planets and moons
- **Texture management**: Upload custom textures, configure colors, atmospheric effects
- **Physical simulation**: Planets move in orbits with correct periods, time acceleration for observation
- **Accurate parameters**: Orbital parameters based on real data

### Technical Highlights

- 3D graphics work
- Browser 3D engines
- Parameter management through admin panel
- Real-time 3D scene updates
- Physical simulation
- Interactive 3D space navigation

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.
