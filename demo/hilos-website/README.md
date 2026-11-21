# Hilos Website Demo Project

**Complexity: 1/5**

The official Hilos site at hilos-dev.org, itself an application on Hilos: what
Hilos is, the WAWTech video and the catalog of the demos.

## What is this?

### Today

A Vue site on Hilos:

- **Home** at `/` explains Hilos and shows the WAWTech walkthrough video.
  The header shows "Hilos" as text, with no sign-in button.
- **Demo catalog**: two groups — working demos (Chat and E-commerce shop),
  followed by ten application starters. Each entry carries a description,
  its frontend, a source link and, where available, an "Open demo" link.
  Entries without a public address say so. The markup lives in
  `frontend/src/views/Main/DemoCatalog.vue`; edit it and rebuild to change
  the catalog.
- **Video**: put `hilos.mp4` in the installation's `media/` data directory
  (`demo/hilos-website/data/media/hilos.mp4` locally). nginx serves it
  read-only at `/media/hilos.mp4`. The file stays out of git; when it is
  missing, the player frame shows a placeholder.
- **Search engines**: `/` is prerendered into `home.html`, with the story and
  catalog available without JavaScript, a description, Open Graph metadata
  and a canonical URL. About, Terms, Privacy and License are prerendered too;
  Privacy explains visit recording. Deep links receive the `index.html` SPA
  shell. `robots.txt`, `sitemap.xml` and home metadata use `HILOS_SITE_ORIGIN`
  from the build environment: `https://localhost` by default and
  `https://hilos-dev.org` in the production compose file.
- **Sign-in only at `/hilos`**, and registration is closed: an address without
  an account is told so, and no account is created. The one account is issued
  by a command — `admin:create <sessionToken>` makes the browser session an
  administrator; on the profile's "Ways to sign in" tab the owner then adds a
  password, confirmed by a code sent by mail.
- **Admin**: Analytics — the visits of the site — Settings, and the journal of
  the email deliveries.

The site has no e2e: its PHP tests and frontend checks and unit tests run in
its test stack, and the owner accepts the visible result by hand.

## Run it locally

```bash
composer run setup-env          # create .env from .env.example (tests/.env is tracked)
composer run install-deps       # composer install in the PHP container
# once per checkout, from the repo root: install the framework SDK npm workspace.
#   docker compose -f framework/docker/docker-compose.frontend.yml \
#     run --rm hilos-frontend-cli npm install
composer run frontend:install   # npm install in the frontend container
composer run daemon-start       # MySQL + Mailpit + daemon + Vite dev server
```

The daemon applies migrations on startup. Endpoints (defaults):

| Endpoint | URL |
|---|---|
| Frontend dev server (HMR) | http://localhost:5185 |
| Built frontend behind nginx (`daemon-start-build`) | https://localhost:8296 |
| Mailpit (the codes the site sends) | http://localhost:8300 |
| Daemon status API | http://localhost:8290/status |
| Daemon WebSocket | ws://localhost:8292 |
| MySQL (from host) | localhost:33085 |

A file put into `data/media/` is served by the nginx of `daemon-start-build` at
`https://localhost:8296/media/<name>`.

These host-side ports are compose *interpolation* values, so `.env` cannot
change them — compose reads them from the shell environment or from
`docker/.env` before any container exists (`HTTP_STATUS_HOST_PORT=18290 composer
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
| `composer run cli -- admin:create <sessionToken>` | make a browser session the administrator |
| `composer run frontend:install` | `npm install` in the frontend container |
| `composer run frontend:check` | type-check the frontend (`vue-tsc`) |
| `composer run frontend:lint` | lint the frontend (`eslint . --max-warnings 0`) |
| `composer run frontend:format-check` | check frontend formatting (`prettier --check .`) |
| `composer run frontend:build` | production build into `frontend/dist` |
| `composer run frontend:logs` | follow the dev-server logs |

## Tests

| Command | What it does |
|---|---|
| `composer run test:install-deps` | install the locked PHP dependencies in the test CLI container |
| `composer run test:db-reset` | reset the test database |
| `composer run test:phpunit` | run the whole PHPUnit suite |
| `composer run test:check` | frontend type-check, unit tests, lint and format check |
| `composer run test:down` | stop the test stack |

## Thin on purpose

The site carries only the owner's sign-in and Analytics. A change that switches
a framework feature on in every demo leaves this one out, and the site has no
cluster stand. The thinner the site, the cheaper its move.

## Its own repository

Planned: the site moves to its own repository, `hilos-dev/website`, and
installs Hilos as a package (HIL-2153).

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.
