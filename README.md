# Hilos Framework v2

**High-performance cluster framework with lightweight daemon for PHP**

Hilos is an event-driven, non-blocking framework designed for building scalable real-time applications. It features a daemon-worker architecture with agent-based task distribution, built-in WebSocket and HTTP servers, and a powerful ORM system.

## 🤖 For AI Agents

**[→ Documentation for AI Agents](docs/ai-agents.md)**

Set up your AI assistant (Claude Code, Codex, Cursor, Gemini, Windsurf, Aider) from the shared rule source:

```bash
composer ai:install
```

This materializes per-tool config (skills, entry points) from `agents.md`, `docs/agents/`, and `skills/hilos-*`. Re-run it after pulling rule changes; see [docs/agents/ai-tools.md](docs/agents/ai-tools.md).

## ✨ Key Features

- **🐳 Docker-first**: Designed for development and deployment in Docker
- **🚀 Daemon-Worker Architecture**: Lightweight daemon process managing multiple worker processes
- **⚡ Event-Driven**: Epoll-based event loop for non-blocking I/O operations
- **🌐 WebSocket & HTTP Servers**: Built-in servers for real-time and RESTful applications
- **🤖 Agent System**: Distributed task execution through agents in worker processes
- **📡 Signal Routing**: Flexible signal-based communication between components
- **💾 Advanced ORM**: Entity/Object/Db pattern for database operations; runtime data seamlessly complements database data
- **🔄 Database Migrations**: Version-controlled schema management
- **⏰ Cron Jobs**: Built-in cron scheduler
- **📝 Comprehensive Logging**: Structured logging with rotation and agent-specific logs
- **🛠️ CLI Tools**: Command-line interface for management and migrations
- **⚠️ Exception Handling**: Well-designed exception hierarchy for reliable error handling
- **🖥️ Vue + TypeScript Frontend**: Frontend SDK that harmoniously extends the backend

## 🏗️ Architecture

Hilos follows a daemon-worker architecture pattern:

```
                    ┌─────────────┐
                    │   Daemon    │  ← Main process managing servers and routing
                    └──────┬──────┘
                           │
        ┌──────────────────┼──────────────────┐
        │                  │                  │
   ┌────▼────┐        ┌────▼────┐        ┌────▼────┐
   │ Worker  │        │ Worker  │        │ Worker  │  ← Worker processes
   │    #1   │        │    #2   │        │    #N   │
   └────┬────┘        └────┬────┘        └────┬────┘
        │                  │                  │
   ┌────┼────┐        ┌────┼────┐        ┌────┼────┐
   │    │    │        │    │    │        │    │    │
┌──▼──┐┌▼──┐┌▼──┐  ┌──▼──┐┌▼──┐┌▼──┐  ┌──▼──┐┌▼──┐┌▼──┐
│Agent││Agt││Agt│  │Agent││Agt││Agt│  │Agent││Agt││Agt│
└─────┘└───┘└───┘  └─────┘└───┘└───┘  └─────┘└───┘└───┘
     ↑                  ↑                  ↑
  Multiple agents per worker
```

- **Daemon Process**: Manages HTTP/WebSocket/Worker servers, handles signal routing, and coordinates workers
- **Worker Processes**: Execute multiple agents that perform business logic
- **Agents**: Isolated units of work identified by type and optional index (each worker can run multiple agents)
- **Event Loop**: Non-blocking I/O using PHP Event extension (epoll-based)

## 📋 Requirements

Hilos is designed to run in Docker. If you need to run it outside Docker, you will need:

- PHP 8.5 or higher
- PHP Extensions:
  - `ext-posix` — Process control
  - `ext-pcntl` — Process forking
  - `ext-sockets` — Socket operations; load before `ext-event`
  - `ext-mysqli` — MySQL database
  - `ext-mbstring` — String operations
  - `ext-ctype` — Character type checking
  - `ext-event` — Pinned PECL event 3.1.6 with the Hilos peer-certificate and
    buffer-event lifetime patch, built with OpenSSL and sockets support

The event build must provide `EventBufferEvent::sslSocket()` and
`EventBufferEvent::sslGetPeerCertificate(): ?string`. Version 3.1.6 alone does
not prove that the patch is installed, and the getter alone does not prove the
lifetime repair. Rebuild consumers when the native assets change. The
[native build guide](framework/docker/native/event/README.md) is the source for
installation, Docker build contexts, patch updates, checks and license notices.

Outside Docker, prepare a matching PHP 8 CLI and development toolchain
(`phpize`, `php-config`, PHP headers, autoconf, a C compiler, make and
pkg-config), libevent and OpenSSL development headers, curl with CA
certificates, tar, patch, sha256sum and nproc. Install and load sockets first.

Export `PHP_INI_DIR` so its existing `conf.d` directory is scanned by that CLI
PHP (check `php --ini`); the official PHP image supplies this variable, but a
host installation must configure it explicitly. Sockets must load before the
installer's `30-event.ini`, for example through `20-sockets.ini`. Remove any
other event-loading INI entry to avoid loading the extension twice. With that
environment, run `sh framework/docker/native/event/install.sh` from the
repository root, with permission to install into PHP's extension directory,
write `$PHP_INI_DIR/conf.d`, and copy the license notice into
`/usr/local/share/doc/hilos-event`.

## 🚀 Quick Start

### Installation

```bash
composer require anlide/hilos
```

```bash
composer run setup-env
```

```bash
composer run install-deps
```

### Example Projects

The list below describes what each project is for. Some of these catalogs are still scaffolds.

- [binance-btc-tracker](demo/binance-btc-tracker/README.md) — BTC market data, charts, signals and paper trading
- [chat](demo/chat/README.md) — real-time conversations
- [concert-tickets](demo/concert-tickets/README.md) — concert tickets: hall seats and a time-limited hold
- [dkp-system](demo/dkp-system/README.md) — guild DKP points and loot distribution for MMO players
- [ecommerce-shop](demo/ecommerce-shop/README.md) — demo storefront, cart and orders
- [file-gallery-manager](demo/file-gallery-manager/README.md) — photo galleries and authors
- [hilos-website](demo/hilos-website/README.md) — the official Hilos site with documentation and examples
- [mail-monitor](demo/mail-monitor/README.md) — mail server monitoring
- [online-testing](demo/online-testing/README.md) — online tests and results
- [personal-homepage](demo/personal-homepage/README.md) — a personal site with published content pages
- [pmd](demo/pmd/README.md) — a MySQL web administration interface
- [polls](demo/polls/README.md) — polls, responses and results
- [preferans](demo/preferans/README.md) — Sochi preferans card game
- [private-clinic](demo/private-clinic/README.md) — a clinic site, doctor appointments, insurance and video visits
- [research-lab](demo/research-lab/README.md) — samples, protocols, experiments and AI-assisted pattern discovery
- [roblox-game-server](demo/roblox-game-server/README.md) — ratings, leagues and cross-server events for Roblox games
- [small-airport](demo/small-airport/README.md) — flights, gates, ground services and a public arrivals/departures board
- [solar-system-model](demo/solar-system-model/README.md) — a real-scale 3D model of the Solar System
- [tasks](demo/tasks/README.md) — proposals, tasks and triage between them
- [traivan-map-analyzer](demo/traivan-map-analyzer/README.md) — Traivan map analysis

## 📁 Project Structure

```
hilos/
├── framework/                      # Core framework
│   ├── backend/                    # PHP backend
│   │   ├── AI/                     # AI agents (Superfans, moderation, etc.)
│   │   ├── API/                    # HTTP routing, async client
│   │   ├── Bootstrap/              # Process bootstrap entrypoints
│   │   ├── Constants/              # Constants
│   │   ├── Core/                   # Daemon, Worker, Agent, Router, CLI, Page, EventLoop
│   │   ├── Database/               # ORM (Entity, Object, Db, Migrations, Schema, Filter)
│   │   ├── Environment/            # Environment / config access
│   │   ├── Fs/                     # Filesystem access
│   │   ├── LLM/                    # LLM integration (local + external)
│   │   ├── Pages/                  # Application page controllers
│   │   ├── Runtime/                # Runtime (RtContext, RtState)
│   │   ├── Socket/                 # Servers and clients (HTTP, WebSocket, Worker)
│   │   ├── TruthSource/            # Truth-source registry / ownership
│   │   └── Utils/                  # Utilities
│   ├── frontend/                   # TypeScript/Vue SDK
│   │   └── src/
│   │       ├── components/         # Vue components
│   │       ├── plugins/            # Plugins (websocket)
│   │       ├── router/             # Router
│   │       ├── services/           # Services (WebSocket)
│   │       ├── stores/             # Pinia stores
│   │       └── types/              # Types
│   ├── scripts/                    # Scripts (link-frontend-sdk)
│   └── Stubs/                      # Stubs (event.php)
├── demo/                           # 21 demo projects
├── docs/                           # Documentation
└── LICENSE
```

## 🔧 Core Components

### Backend
- **Daemon Manager** — Main process, server/worker management, signal routing
- **Worker Manager** — Worker process, agent execution
- **Agent System** — Agent, Agent Daemon, Agent Manager
- **Signal Router** — Signal routing, subscriptions (page, group, user)
- **Database Layer** — Entity/Object/Db, migrations, Schema, Filter
- **Servers** — HttpServer, WebSocketServer, WorkerServer
- **API** — HttpRouter, AsyncHttpClient
- **Runtime** — RtContext, RtState for reactive state
- **Page** — AbstractPage, PageSignalRouter

### Frontend SDK
- **Modal** — Modal dialog
- **ConflictHeader**, **ConflictActions** — Components for concurrent editing conflicts
- **Table** — Table component
- **LoadingButton** — Button with loading indicator
- **WebSocketService** — WebSocket service
- **WebSocket plugin** — Vue plugin for WebSocket

## 📚 Documentation

- **[docs/ai-agents.md](docs/ai-agents.md)** — Instructions for AI agents
- **[docs/docker-ollama-gpu.md](docs/docker-ollama-gpu.md)** — Ollama (standalone), GPU (NVIDIA/AMD), LLM_LOCAL_URL
- **[docs/llm-testing-runbook.md](docs/llm-testing-runbook.md)** — driving moderation and bots by hand, local and external providers
- **[docs/quality.md](docs/quality.md)** — Application quality guidelines
- **[docs/code-style.md](docs/code-style.md)** — Code style guide
- **[docs/reference.md](docs/reference.md)** — API reference
- **[docs/cli-commands.md](docs/cli-commands.md)** — CLI commands

## 🎯 Use Cases

Hilos is suitable for:

- **Real-time applications**: Chat, games, live updates
- **High-load APIs**: REST and WebSocket
- **Background processing**: Task queues, cron
- **Microservices**: Distributed architecture
- **IoT**: Device communication

## 🚦 Performance

- **Non-blocking I/O** — All socket operations are non-blocking
- **Event-driven** — Epoll-based event loop
- **Low Latency** — Minimized delays
- **Scalable** — Multiple workers and agents
- **Resource Efficient** — Lightweight daemon process

## 🎯 Mission

The mission of Hilos is to make the internet better. We aim for end users to enjoy higher-quality products, for companies to have high-quality internal tools, and for applications that were previously built outside PHP due to multi-threading limitations to be built in PHP and run reliably. Hilos enables PHP developers to build real-time, multi-threaded applications without switching to other languages.

## 📄 License

MIT — see [LICENSE](LICENSE)
