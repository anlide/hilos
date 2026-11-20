# Hilos Website Demo Project

**Complexity: 1/5**

The official Hilos site at hilos-dev.org, itself an application on Hilos: what
Hilos is, the WAWTech video and the catalog of the demos.

## What is this?

### Today

A thin Vue shell on Hilos:

- **Home** at `/` is empty for now: the title "Hilos" and a footer that opens
  About, Terms, Privacy and License. The header shows "Hilos" as text, with no
  sign-in button.
- **Sign-in only at `/hilos`**, and registration is closed: an address without
  an account is told so, and no account is created. The one account is issued
  by a command — `admin:create <sessionToken>` makes the browser session an
  administrator; on the profile's "Ways to sign in" tab the owner then adds a
  password, confirmed by a code sent by mail.
- **Admin**: Analytics — the visits of the site — Settings, and the journal of
  the email deliveries.
- **`/media/`**: nginx serves the `media/` directory of the installation's data
  read-only, so a large file such as the video stays out of git.

The site has no e2e: its PHP tests and the frontend check run in the test stack,
and what a person sees the owner accepts by hand.

### What it is going to show

- **Home** at `/`: a short account of what Hilos is and what the demos show,
  and the WAWTech video about Hilos.
- **Demo catalog**: two groups — the filled demos (chat, ecommerce-shop) on
  top, and the scaffolds apart below them: the other demos that have a
  frontend. An entry carries the name, a short description, the frontend, a
  link to the application and a link to the sources. A demo without a public
  address says so on its own entry — a note, not a third group. A demo
  directory without code is not shown.
- **Catalog in code**: the HTML sits right in the code of the home page and
  changes by editing the code and rebuilding. There is no CMS, no editor, no
  editing of the catalog from the admin and no second language.
- **The video file** is not in git: it lies in `media/` of the installation's
  data and is served at `/media/`.
- **Owner-only admin**: sign-in only at `/hilos`, with no sign-in button in the
  header; there is no registration — the single account is issued by a
  command; the admin carries the Analytics section — the visits of the site.
- **Public pages** About, Terms, Privacy and License; Privacy says that the
  site records visits.
- **Open to search engines**: the home page is prerendered into the build — the
  catalog is in the page source without JavaScript.

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
| `composer run test:check` | frontend type-check, lint and format check |
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
