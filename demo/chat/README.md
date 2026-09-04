# Hilos Chat Demo

**Complexity: 3/5**

Demo project: real-time chat over WebSocket with AI-assisted moderation, built on
the Hilos framework. Shows the agent architecture running in worker processes.

## What is this?

A chat application demo that showcases WebSocket real-time communication with
extended features:

### Base Functionality
- **Backend**: PHP daemon with WebSocket server (Hilos framework)
- **Frontend**: Vue 3 + TypeScript SPA consuming the Hilos frontend SDK (`@hilos/vue`)
- Real-time message exchange
- Conversational bots supporting chat
- Bot-moderator for chat management

### Enhanced Capabilities
- **AI moderation**: an AI bot-moderator analyzes messages in real-time and
  removes spam, insults, and unwanted content
- **User management**: ban users, issue warnings
- **Donation integration**: donation notifications in chat
- **High-frequency processing**: message processing scaling to many users
- **Advanced frontend**: fast message scrolling, filtering, moderator panel

## Quick Start

All tooling runs in Docker — backend (Composer/PHP) and frontend (Node/Vite)
alike. **Nothing is installed on the host.**

### Prerequisites

- Docker and Docker Compose (Docker Desktop with the WSL2 backend, or native
  Linux Docker).
- The full Hilos repository, so `framework/` is a sibling of `demo/`. The frontend
  consumes the SDK package `@hilos/vue` through a local `file:` dependency into
  `framework/frontend/vue`; Vite and TypeScript resolve it via a `development`
  export condition into the SDK source, so HMR reaches into the SDK too.

### First run

From `demo/chat/`, everything in containers:

1. **Environment file** (creates `.env` from `.env.example` if missing):
   ```bash
   composer run setup-env
   ```
2. **Backend dependencies** (Composer → `vendor/`):
   ```bash
   composer run install-deps
   ```
3. **Frontend dependencies** (npm, in the Node containers). First install the
   framework SDK workspace, then the demo app. The SDK step is required on a fresh
   checkout: `@hilos/core` / `@hilos/vue` are linked in via `file:` symlinks, so their
   own dependencies (e.g. `zod`) resolve against `framework/frontend/node_modules` —
   without this step Vite fails with `Failed to resolve import "zod"`.
   ```bash
   # from the repo root: install the SDK npm workspace once per checkout
   docker compose -f framework/docker/docker-compose.yml \
     run --rm hilos-frontend-cli npm install
   # then, from demo/chat/: install the demo app's own frontend deps
   composer run frontend:install
   ```
4. **Start the dev stack** (MySQL + phpMyAdmin + daemon + Vite dev server):
   ```bash
   composer run daemon-start
   ```
   The daemon applies database migrations on startup.
5. **Seed default data** (bots, moderation rules, chat settings):
   ```bash
   composer run db:seed:apply -- 001
   composer run db:seed:apply -- 002
   composer run db:seed:apply -- 003
   composer run db:seed:apply -- 004
   composer run db:seed:apply -- 005
   ```
6. Open the app and tooling:
   - **Frontend (Vite dev, HMR):** http://localhost:5173
   - **phpMyAdmin:** http://localhost:8080 — server `mysql-local`, user `root` /
     `hilos_root_pass`, database `hilos-demo-chat`
   - **Mailpit (mail, SMS and Telegram of the stand):** http://localhost:8025
   - Daemon HTTP status: http://localhost:8090 · WebSocket: `ws://localhost:8092`

   Every channel of the stand ends in that inbox: mail goes to Mailpit over
   SMTP, and an SMS or Telegram message is caught by the stand gateway and
   forwarded there as a letter to `<number>@sms.stand` or
   `<number>@telegram.stand`, with the message text as its subject. There is no
   file with the code on disk; the dev stack (`docker-compose.dev.yml`)
   publishes its own Mailpit on http://localhost:8028.

### Stop the stack

```bash
composer run daemon-stop      # stop containers (data is preserved)
# or: docker compose -f docker/docker-compose.local.yml down
```

## Running modes

| Mode | Frontend | When |
|------|----------|------|
| **Dev** (default) | Vite dev server on :5173 with HMR | local development |
| **Build / prod serving** | static `dist/` behind Nginx | production-like; **being reworked** |

**Dev mode** is the default `daemon-start`: the Vite dev server
(`chat-frontend-local`) comes up with the backend and serves the SPA on :5173, and
the browser talks WebSocket directly to the daemon. HMR runs on native filesystem
events (no polling) when the repository lives on the WSL2 filesystem.

**Build / prod serving** (static build behind Nginx, HTTPS, and SSG prerender for
public pages) is being rebuilt as part of the current frontend rewrite — see
[docs/agents/frontend/README.md](../../docs/agents/frontend/README.md). `composer run frontend:build`
already produces a `dist/` via `vite build`; the Nginx serving path and the SSG
prerender are not finalized here yet.

## Commands

### Stack

| Command | Description |
|---------|-------------|
| `composer run setup-env` | create `.env` from `.env.example` if missing |
| `composer run install-deps` | install backend Composer packages (PHP container) |
| `composer run daemon-start` | start the dev stack: MySQL + phpMyAdmin + daemon + Vite dev server |
| `composer run daemon-stop` | stop the stack |
| `composer run daemon-restart` | restart the daemon container |
| `composer run daemon-status` | daemon status snapshot |
| `composer run daemon-monitor` | live daemon monitor |
| `composer run pma` / `pma-stop` | start / stop phpMyAdmin only |

### Database

| Command | Description |
|---------|-------------|
| `composer run db:migration:up` / `down` / `status` / `retry` | migrations |
| `composer run db:seed:apply -- <NNN>` | apply seed `NNN` (e.g. `001`) |
| `composer run db:schema:status` | schema status |

### Frontend (Node, in container)

| Command | Description |
|---------|-------------|
| `composer run frontend:install` | install frontend npm dependencies |
| `composer run frontend:check` | type-check (`vue-tsc`) |
| `composer run frontend:build` | production build (`vite build` → `dist/`) |
| `composer run frontend:logs` | follow the Vite dev server logs |

Frontend tooling runs in the `chat-frontend-cli-local` (on-demand) and
`chat-frontend-local` (dev server) containers. The SDK build and packaging are
documented in [framework/frontend/README.md](../../framework/frontend/README.md).

## AI moderation (Ollama)

AI moderation uses Ollama with a lightweight model (`qwen2.5:0.5b`) for low-latency
allow/block classification. Override via Hilos Settings at `/hilos/settings`
(seed `003` populates defaults).

Ollama runs as a **standalone** framework project, port `11434` exposed to the
host. The demo connects via `LLM_LOCAL_URL` (default
`http://host.docker.internal:11434`) and does not depend on framework internals —
it may be an external AI farm too. See
[Docker + Ollama + GPU](../../docs/docker-ollama-gpu.md).

From the repo root:
```bash
composer run ollama:start
# GPU: ollama:start-gpu-nvidia / ollama:start-gpu-amd
composer run ollama:pull        # pull the models
```

## Testing

Tests use a separate Docker stack (`docker/docker-compose.test.yml`), isolated
from the development environment.

### Unit and integration (PHPUnit)

| Command | Description |
|---------|-------------|
| `composer run test:install-deps` | install PHPUnit and dev packages |
| `composer run test:up` | start the MySQL test container |
| `composer run test:db-reset` | reset the test DB (drop → migrate → seed) |
| `composer run test:db-wait` | wait for MySQL to be ready |
| `composer run test:unit` / `test:integration` / `test:phpunit` | run tests |
| `composer run test:all` | reset DB, run all PHPUnit tests, then the full e2e cycle |
| `composer run test:down` / `test:down-volumes` | stop the test stack |

Typical flow:
```bash
composer run test:up
composer run test:all
```

- `tests/Unit/` — unit tests (no DB)
- `tests/Integration/` — integration tests (require MySQL)

### End-to-end (Playwright)

e2e lives in `tests/e2e/` and drives the **built** frontend artifact served by
the test nginx, with a booted daemon behind it — never the dev server (see
[docs/agents/frontend/testing-strategy.md](../../docs/agents/frontend/testing-strategy.md)).
Tests select elements by stable `data-id` attributes only.

| Command | Description |
|---------|-------------|
| `composer run test:check` | install + typecheck the frontend app (test toolchain) |
| `composer run test:e2e-build` | build the frontend artifact (`frontend/dist`) |
| `composer run test:e2e-install` | install the Playwright runner dependencies |
| `composer run test:e2e-check` | typecheck the e2e test code (in the runner) |
| `composer run test:e2e-up` | start the e2e stack: MySQL (reset) + daemon + nginx |
| `composer run test:e2e` | run e2e tests against the running stack |
| `composer run test:e2e-down` | stop the e2e stack |
| `composer run test:e2e-full` | the whole cycle: build → install → check → up → test → down |

The stack stays up between runs, so the fast loop is one `test:e2e-up` and then
any number of `test:e2e` invocations. Extra Playwright arguments pass through
after `--`, which is how a single test or a tagged group is targeted:

```bash
composer run test:e2e-up
composer run test:e2e                          # everything
composer run test:e2e -- --grep "blank page"   # one test / future @group tag
composer run test:e2e-down
```

Rebuild the artifact (`test:e2e-build`) after frontend changes; restart the
stack (`test:e2e-up`) after backend changes.

## Documentation

- Frontend SDK (packaging, build, dev): [framework/frontend/README.md](../../framework/frontend/README.md)
- Frontend architecture and rules: [docs/agents/frontend/README.md](../../docs/agents/frontend/README.md)
- Backend: framework documentation
- Docker configuration: the `docker/` directory
