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

### Setup

1. **Setup environment file** (creates `.env` from `.env.example` if needed):
   ```bash
   composer run setup-env
   ```

2. **Install dependencies** (backend Composer packages):
   ```bash
   composer run install-deps
   ```

3. **Install frontend dependencies** (npm packages):
   ```bash
   composer run frontend-install
   ```

### Running

All components run in Docker containers. See Docker configuration in `docker/` directory for details.

**Start daemon:**
```bash
composer run daemon-start
```

**Start daemon with GPU acceleration (optional):**
- **NVIDIA**: `composer run daemon-start-gpu-nvidia` (requires [NVIDIA Container Toolkit](https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/))
- **AMD ROCm**: `composer run daemon-start-gpu-amd` (requires ROCm, `/dev/kfd` and `/dev/dri`)

**Start frontend dev server:**
```bash
composer run frontend-dev
```

## AI moderation (Ollama)

AI moderation uses Ollama with a lightweight model (`qwen2.5:0.5b`) for low-latency allow/block classification. Override via env var `CHAT_MODERATION_MODEL` (e.g. `qwen2.5:3b` for stronger moderation).

### GPU acceleration (optional)

By default Ollama runs on CPU. For faster inference, use GPU overrides:

| Vendor | Command | Prerequisites |
|--------|---------|---------------|
| **NVIDIA** | `composer run daemon-start-gpu-nvidia` | [NVIDIA Container Toolkit](https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/) |
| **AMD** | `composer run daemon-start-gpu-amd` | ROCm, `/dev/kfd` and `/dev/dri` |

**Model initialization:** Start the daemon first (`daemon-start` or `daemon-start-gpu-*`), then pull (installs both default qwen2.5:0.5b and optional qwen2.5:3b):
- `composer run ollama-pull` (CPU / default)
- `composer run ollama-pull-gpu-nvidia` (NVIDIA)
- `composer run ollama-pull-gpu-amd` (AMD)

Direct Docker Compose usage:
```bash
# NVIDIA
docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.local.gpu-nvidia.yml up -d

# AMD
docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.local.gpu-amd.yml up -d
```

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
| `composer run test:e2e-up` | Start full stack for Playwright (MySQL + daemon + frontend) |
| `composer run test:e2e-down` | Stop E2E stack |

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

1. Start the E2E stack:
   ```bash
   composer run test:e2e-up
   ```

2. Reset the test database:
   ```bash
   composer run test:db-reset
   ```

3. Install Playwright and run E2E tests:
   ```bash
   cd tests/e2e && npm ci && npx playwright install --with-deps && npm test
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
