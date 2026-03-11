# WebSocket Test Demo Project

**Complexity: 3/5**

Demo project demonstrating real-time chat with WebSocket and enhanced capabilities.
Shows basic agent architecture in worker processes.

## What is this?

This is a chat application demo that showcases WebSocket real-time communication with extended features:

### Base Functionality
- **Backend**: PHP daemon with WebSocket server (Hilos framework)
- **Frontend**: Vue 3 + TypeScript chat interface with auto-reconnect
- Real-time message exchange
- Conversational bots supporting chat
- Bot-moderator for chat management

### Enhanced Capabilities
- **AI moderation**: AI bot-moderator analyzes messages in real-time, automatically removes spam, insults, unwanted content
- **User management**: Can ban users, issue warnings
- **Donation integration**: Notifications about donations in chat
- **High-frequency processing**: Message processing, scaling to many users
- **Advanced frontend**: Fast message scrolling, filtering, moderator panel

## Quick Start

### Prerequisites

- Docker and Docker Compose
- **Full Hilos repository** — clone the repo at `hilos` root so that `framework/` is a sibling of `demo/`. The frontend uses `@hilos/sdk` from `framework/frontend/src`; TypeScript and Vite resolve it via path mapping.

### Setup

1. **Setup environment file** (creates `.env` from `.env.example` if needed):
   ```bash
   composer run setup-env
   ```

2. **Install dependencies** (backend Composer packages; also triggers Hilos SDK link via post-install):
   ```bash
   composer run install-deps
   ```

3. **Install frontend dependencies** (npm packages):
   ```bash
   composer run frontend-install
   ```

### Running

All components run in Docker containers. See Docker configuration in `docker/` directory for details.

**1. Start Ollama (framework, separate project).** Required for AI moderation and bots. See [Docker + Ollama + GPU](../../docs/docker-ollama-gpu.md).

From repo root:
```bash
composer run ollama:start
# Or GPU: ollama:start-gpu-nvidia / ollama:start-gpu-amd
```

**2. Choose mode: Dev or Build**

| Mode | Use case | Frontend | Daemon |
|------|----------|----------|--------|
| **Dev** | Local development, hot reload | Vite on :5173 | :8090, :8092 |
| **Build** | Production-like, prerendered SEO, HTTPS | Nginx on :80, :443 | :8090, :8092, :8093 |

**Dev mode** (profile `dev`): Vite serves SPA, no Nginx. WebSocket direct to daemon.
```bash
composer run daemon-start
composer run frontend-dev    # Vite on http://localhost:5173
```

**Build mode** (profile `full`): Nginx serves static assets + prerendered HTML (content negotiation via daemon). HTTPS only: port 80 redirects to 443.
```bash
composer run frontend-build      # One-time: npm run build in Docker → dist/
composer run daemon-start-build  # MySQL + daemon + Nginx on :80, :443
# Open https://localhost (browser will warn about self-signed cert — accept to continue)
```

### Frontend and daemon commands

| Command | Description |
|---------|-------------|
| `composer run frontend-build` | Build frontend in Docker (vite-ssg prerender → `dist/`); required for Build mode |
| `composer run frontend-dev` | Start Vite dev server (profile `dev`); hot reload on :5173 |
| `composer run frontend-stop` | Stop Vite dev server |
| `composer run daemon-start` | Start Docker stack (MySQL + daemon), Dev mode — no Nginx |
| `composer run daemon-start-build` | Start stack with Nginx — Build mode, app at **https://localhost** |
| `composer run daemon-stop` | Stop Docker stack |
| `composer run daemon-restart` | Restart daemon container |

## AI moderation (Ollama)

AI moderation uses Ollama with a lightweight model (`qwen2.5:0.5b`) for low-latency allow/block classification. Override via env var `CHAT_MODERATION_MODEL` (e.g. `qwen2.5:3b` for stronger moderation).

Ollama runs as a **standalone** framework project, port 11434 exposed to host. Demo connects via `LLM_LOCAL_URL` (default `http://host.docker.internal:11434`) and does not depend on framework internals—it may be external AI farm too. See [Docker + Ollama + GPU (framework)](../../docs/docker-ollama-gpu.md).

### GPU acceleration (optional)

| Vendor | Command (from repo root) | Prerequisites |
|--------|--------------------------|---------------|
| **NVIDIA** | `composer run ollama:start-gpu-nvidia` | [NVIDIA Container Toolkit](https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/) |
| **AMD** | `composer run ollama:start-gpu-amd` | ROCm, `/dev/kfd` and `/dev/dri` |

**Model initialization:** After starting Ollama, from repo root: `composer run ollama:pull` / `ollama:pull-gpu-nvidia` / `ollama:pull-gpu-amd`

## Testing

Tests use a separate Docker stack (`docker/docker-compose.test.yml`), isolated from the development environment.

### Prerequisites

- Docker and Docker Compose
- Test stack dependencies installed (see below)

### First-time setup

1. **Install test dependencies** (PHPUnit and dev packages):
   ```bash
   composer run test:install-deps
   ```

2. **Copy test environment** (optional; defaults work for Docker):
   ```bash
   cp .env.test.example .env.test
   ```
   Adjust `.env.test` if running PHPUnit from the host (e.g. `DB_HOST=localhost`, `DB_PORT=33061`).

### Commands

| Command | Description |
|---------|-------------|
| `composer run test:up` | Start MySQL test container |
| `composer run test:down` | Stop test stack |
| `composer run test:down-volumes` | Stop and remove test volumes |
| `composer run test:db-reset` | Reset test DB (DROP → migrate → seed) |
| `composer run test:db-wait` | Wait for MySQL to be ready |
| `composer run test:unit` | Run unit tests |
| `composer run test:integration` | Run integration tests |
| `composer run test:phpunit` | Run all PHPUnit tests |
| `composer run test:all` | Reset DB and run all PHPUnit tests |
| `composer run test:e2e-build` | Build frontend in Docker (required before E2E) |
| `composer run test:e2e-up` | Start E2E stack (MySQL + daemon + Nginx) |
| `composer run test:e2e-down` | Stop E2E stack |
| `composer run test:e2e-install` | Install Playwright deps and browsers |
| `composer run test:e2e` | Run Playwright E2E tests |
| `composer run test:e2e-full` | Full E2E flow: build → up → db-wait → db-reset → install → test → down |

### Typical flow

```bash
# Start MySQL
composer run test:up

# Reset DB and run tests
composer run test:all
```

To run only PHPUnit (without resetting the DB):

```bash
composer run test:phpunit
```

### Playwright (E2E)

**Full flow (recommended):**

```bash
composer run test:e2e-full
```

This builds the frontend, starts the stack (MySQL + daemon + Nginx), waits for MySQL, resets the DB, installs Playwright deps, runs E2E tests, then stops the stack. E2E tests run over HTTPS (`https://localhost`).

**Manual flow:**

0. Build frontend (required; produces `dist/` for Nginx):
   ```bash
   composer run test:e2e-build
   ```

1. Start the E2E stack:
   ```bash
   composer run test:e2e-up
   ```

2. Wait for MySQL and reset the DB:
   ```bash
   composer run test:db-wait
   composer run test:db-reset
   ```

3. Install Playwright (first time only) and run tests:
   ```bash
   composer run test:e2e-install
   composer run test:e2e
   ```

4. Stop the stack when done:
   ```bash
   composer run test:e2e-down
   ```

### Test structure

- `backend/Tests/Unit/` — unit tests (no DB)
- `backend/Tests/Integration/` — integration tests (require MySQL)
- `tests/e2e/` — Playwright E2E tests (full app)

---

## Documentation

For detailed instructions on:
- Frontend development and build: see [frontend/README.md](frontend/README.md)
- Backend setup: see framework documentation
- Docker configuration: see `docker/` directory
- Test environment: see [Testing](#testing) above

## Refactor Notes

- TODO(hilos-refactor): rename legacy CLI commands `db:idea:*` to `db:hilos:*`.
- TODO(hilos-refactor): update bootstrap/CLI parameter naming from `initIdea` to `initHilos` after command migration.
