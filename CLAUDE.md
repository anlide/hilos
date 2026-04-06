# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Language

- **Chat with user**: Always in Russian.
- **Code comments, PHPDoc, commit messages**: Always in English.

## Commands

### Framework Tests

```bash
composer run test:framework:all          # Full test run (installs deps, starts MySQL, runs tests, stops MySQL)
composer run test:framework:unit         # Unit tests only
composer run test:framework:integration  # Integration tests only
composer run test:framework:phpunit      # PHPUnit directly (MySQL must already be running)
composer run test:framework:up           # Start MySQL test container
composer run test:framework:down         # Stop MySQL test container
```

PHPUnit config: `framework/tests/phpunit.xml`

### Demo Project Scripts (run from demo project root)

```bash
composer run setup-env        # Create .env from .env.example
composer run install-deps     # Install backend Composer packages
composer run frontend-install # npm install
composer run daemon-start     # Start daemon in Docker
composer run daemon-stop      # Stop daemon
composer run daemon-restart   # Restart daemon
composer run daemon-status    # Show daemon status
composer run daemon-monitor   # Real-time monitoring (requires TTY)
composer run cli -- <command> # Run any CLI command in Docker
```

### CLI Commands (inside Docker or bare PHP)

```bash
php cli.php daemon:status
php cli.php daemon:monitor
php cli.php migration:up [--to=<version>] [--force]
php cli.php migration:down <version>
php cli.php migration:status
php cli.php db:schema:status [--table=<name>] [--verbose]
php cli.php db:entity:diff   [--table=<name>]
php cli.php db:entity:fix    [--table=<name>] [--dry-run]
php cli.php db:object:fix    [--table=<name>] [--dry-run] [--force-repair]
```

### LLM / Ollama

```bash
composer run ollama:start              # Start Ollama (CPU)
composer run ollama:start-gpu-nvidia   # Start with NVIDIA GPU
composer run ollama:pull               # Pull Qwen2.5 models (0.5b, 3b, 7b)
```

## Architecture

Hilos is an event-driven, non-blocking PHP framework with a **daemon-worker-agent** pattern.

```
Daemon (main process, ports 8090/8091/8092)
  ├─ HttpServer        (port 8090) — REST API
  ├─ WorkerServer      (port 8091) — daemon↔worker IPC
  ├─ WebSocketServer   (port 8092) — real-time frontend
  └─ SignalRouter      — routes signals between all components
       └─ Worker #1..N (forked processes)
            └─ Agent #1..N (business logic units)
```

### Key components

| Component | Path | Role |
|-----------|------|------|
| `DaemonManager` | `Core/Daemon/` | Main process: server lifecycle, worker forking, signal routing |
| `WorkerManager` | `Core/Worker/` | Worker process: agent lifecycle, epoll event loop |
| `AbstractAgent` | `Core/Agent/` | Base for all agents; `doTick()` runs every tick (<0.1 s) |
| `SignalRouter` | `Core/Router/` | Central broker: page/group/user subscriptions |
| `HttpRouter` | `API/Router/` | REST route registration and dispatch |
| `AsyncHttpClient` | `API/` | Non-blocking outbound HTTP |
| ORM | `Database/` | Entity → Object → Db/DbCollection pattern (see below) |
| `RtContext`/`RtState` | `Runtime/` | Reactive runtime state complementing DB data |
| `AbstractPage` | `Core/Page/` | Page-level signal subscriptions, two-way frontend sync |

### ORM: Entity → Object → Db

- **Entity** — PHP class mapping a DB table (column types, schema info)
- **Object** — extends Entity with runtime properties; what agents work with
- **Db / DbCollection** — data access layer; use `Hilos::$db-><collection>` directly
- **Do not wrap DbCollection in Repository or Service classes**
- After schema changes: `db:entity:fix` → `db:object:fix` (use `--dry-run` first)
- Migrations live in the project's own `migrations/` dir; framework stubs are in `framework/backend/Database/Migration/Stub/`

### Signal flow (frontend → backend → frontend)

1. Frontend sends WebSocket action
2. `SignalRouter` dispatches to subscribed agents/pages
3. Agent processes in `onSignal*()` or next `doTick()`
4. DB/runtime changes emit sync signals (`RtSyncCreated/Updated/Deleted`)
5. Signals broadcast to frontend via WebSocket
6. Frontend Pinia stores update reactively

### Agent rules

- `onTick()` must complete in **< 0.1 seconds**
- Long-running or blocking operations (HTTP calls, Telegram/Slack, heavy math) → use a **monopolistic agent** (`requiresMonopolisticProcess()`)
- For CPU-heavy algorithms that can't be split: use `Core/Process.php`

### Frontend SDK (`framework/frontend/src/`)

Vue 3 + TypeScript + Pinia. Key pieces:
- `WebSocketService` / WebSocket plugin — connection and signal handling
- `ConflictHeader`, `ConflictActions` — required for all concurrent-edit modals
- `Modal`, `Table`, `LoadingButton` — standard UI components
- All data editing must happen in modal overlays to support conflict resolution

## Code Style

- PHP: PSR-12/PSR-1, `declare(strict_types=1)` on every file, 4-space indent
- Nullable types: use `?type` in signatures; use `type|null` only in `@method` PHPDoc tags
- Omit `@return void`
- Namespace roots: `Hilos\` (framework), `App\` / `Demo\` (projects)
- Tables and DB columns: `snake_case`

## Commit Message Format

When the user asks for commit text, provide options in English inside ` ```text ` blocks. First line short, blank line, then `-` bullet lines. No extra blank lines.

## Further Reading

- `docs/reference.md` — full API reference (draft)
- `docs/quality.md` — quality requirements (Consistency, Refresh Stability, Conflict-Safe Editing, Agent Responsiveness)
- `docs/cli-commands.md` — complete CLI reference
- `docs/ai-agents/` — agent-specific guides (feature spec, testing/debugging, code quality validation)
- `framework/agent-openai/README.md` — standalone OpenAI agent service with MCP tools
- `demo/` — 21 example projects (complexity 1–5/5)