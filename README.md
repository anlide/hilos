# Hilos Framework v2

[![PHP Version](https://img.shields.io/badge/PHP-8.4%2B-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

**High-performance cluster framework with lightweight daemon for PHP**

Hilos is an event-driven, non-blocking framework designed for building scalable real-time applications. It features a daemon-worker architecture with agent-based task distribution, built-in WebSocket and HTTP servers, and a powerful ORM system.

## ✨ Key Features

- **🚀 Daemon-Worker Architecture**: Lightweight daemon process managing multiple worker processes
- **⚡ Event-Driven**: Epoll-based event loop for non-blocking I/O operations
- **🌐 WebSocket & HTTP Servers**: Built-in servers for real-time and RESTful applications
- **🤖 Agent System**: Distributed task execution through agents in worker processes
- **📡 Signal Routing**: Flexible signal-based communication between components
- **💾 Advanced ORM**: Entity/Object/Idea pattern for database operations
- **🔄 Database Migrations**: Version-controlled schema management
- **⏰ Cron Jobs**: Built-in cron scheduler
- **📝 Comprehensive Logging**: Structured logging with rotation and agent-specific logs
- **🛠️ CLI Tools**: Command-line interface for management and migrations

## 🏗️ Architecture

Hilos follows a daemon-worker architecture pattern:

```
┌─────────────┐
│   Daemon    │  ← Main process managing servers and routing
└──────┬──────┘
       │
   ┌───┴───┬──────────┬──────────┐
   │       │          │          │
┌──▼──┐ ┌──▼──┐   ┌──▼──┐   ┌──▼──┐
│Worker│ │Worker│   │Worker│   │Worker│  ← Worker processes
└──┬──┘ └──┬──┘   └──┬──┘   └──┬──┘
   │       │          │          │
┌──▼──┐ ┌──▼──┐   ┌──▼──┐   ┌──▼──┐
│Agent│ │Agent│   │Agent│   │Agent│  ← Agents executing business logic
└─────┘ └─────┘   └─────┘   └─────┘
```

- **Daemon Process**: Manages HTTP/WebSocket/Worker servers, handles signal routing, and coordinates workers
- **Worker Processes**: Execute agents that perform business logic
- **Agents**: Isolated units of work identified by type and optional index
- **Event Loop**: Non-blocking I/O using PHP Event extension (epoll-based)

## 📋 Requirements

- PHP 8.4 or higher
- PHP Extensions:
  - `ext-posix` - Process control
  - `ext-pcntl` - Process forking
  - `ext-sockets` - Socket operations
  - `ext-mysqli` - MySQL database
  - `ext-mbstring` - String operations
  - `ext-ctype` - Character type checking
- PHP Event extension (for epoll-based event loop)

## 🚀 Quick Start

### Installation

```bash
composer require anlide/hilos
```

### Basic Usage

Create a daemon class:

```php
<?php

use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Socket\Server\HttpServer;

class MyDaemon extends DaemonManager
{
    protected function createSignalRouter(): SignalRouter
    {
        return new MySignalRouter();
    }

    protected function onStart(): void
    {
        // Register HTTP server
        $this->registerServer(new HttpServer('0.0.0.0', 8080, $this->signalRouter));
    }

    protected function onTick(): void
    {
        // Your periodic tasks here
    }
}

// Start daemon
$daemon = new MyDaemon();
$daemon->run();
```

Create a worker class:

```php
<?php

use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Agent\AgentManager;

class MyWorker extends WorkerManager
{
    protected function createAgentManager(SignalRouter $signalRouter): AgentManager
    {
        return new MyAgentManager($signalRouter);
    }
}

// Start worker
$worker = new MyWorker();
$worker->run();
```

### Example Project

See the [websocket-test](demo/websocket-test/) demo project for a complete working example:

- Real-time chat application
- WebSocket server implementation
- Agent-based architecture
- Database integration
- Vue 3 + TypeScript frontend

```bash
cd demo/websocket-test
composer install
composer run daemon-start
```

## 📁 Project Structure

```
hilos/
├── framework/              # Core framework code
│   ├── backend/           # PHP backend (main framework)
│   │   ├── API/          # HTTP routing and async client
│   │   ├── Constants/    # Centralized constants
│   │   ├── Core/         # Core components (Daemon, Worker, Agent, Router)
│   │   ├── Database/     # ORM system (Entity, Object, Idea, Migrations)
│   │   ├── DTO/          # Data Transfer Objects
│   │   ├── Exception/    # Exception hierarchy
│   │   ├── Logging/      # Logging system
│   │   ├── Socket/       # Socket servers and clients
│   │   └── Utils/        # Utility classes
│   └── frontend/          # TypeScript/Vue frontend SDK (minimal)
├── demo/                  # Example projects
│   └── websocket-test/   # Working chat application example
└── FEATURES.md           # Complete feature list
```

## 🔧 Core Components

### Daemon Manager
Main process managing servers, workers, and signal routing.

### Worker Manager
Worker process executing agents and handling business logic.

### Agent System
- **Agent**: Unit of work in worker processes
- **Agent Daemon**: Agent representation in daemon process
- **Agent Manager**: Factory for creating and managing agents

### Signal Router
Flexible routing system for inter-component communication:
- System signals
- Page subscriptions (WebSocket)
- Group subscriptions
- User-specific messages

### Database Layer
Three-layer ORM pattern:
- **Entity**: Database row representation
- **Object**: Writable layer with change tracking
- **Idea**: Read-only layer for data access

### Servers
- **HttpServer**: Full-featured HTTP server with routing
- **WebSocketServer**: WebSocket server with subscription management
- **WorkerServer**: Server for worker process management

## 📚 Documentation

- **[FEATURES.md](FEATURES.md)**: Complete list of all framework features
- **[Demo Projects](demo/)**: Example implementations
- **[WebSocket Test Demo](demo/websocket-test/)**: Working chat application

## 🛠️ CLI Commands

Hilos includes a command-line interface for management:

```bash
# Database migrations
php cli.php migration:up          # Apply migrations
php cli.php migration:down         # Rollback migrations
php cli.php migration:status       # Check migration status
php cli.php migration:retry        # Retry failed migration

# Database schema
php cli.php db:schema:status       # Check schema status
php cli.php db:entity:fix          # Fix Entity classes
php cli.php db:object:fix         # Fix Object classes
php cli.php db:idea:fix           # Fix Idea classes

# System
php cli.php status                 # Daemon status
php cli.php monitor                # Monitor daemon
php cli.php help                   # Show help
```

## 🎯 Use Cases

Hilos is ideal for:

- **Real-time Applications**: Chat, gaming, live updates
- **High-Performance APIs**: RESTful and WebSocket APIs
- **Background Processing**: Task queues, scheduled jobs
- **Microservices**: Distributed service architecture
- **IoT Applications**: Device communication and control

## 🚦 Performance

- **Non-blocking I/O**: All socket operations are non-blocking
- **Event-driven**: Efficient event loop using epoll
- **Low Latency**: Optimized for minimal delays
- **Scalable**: Support for multiple workers and agents
- **Resource Efficient**: Lightweight daemon process

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 👤 Author

**Alexander Baranov**
- Email: alexander.baranov@anlide.online

## 🔗 Links

- [Full Feature List](FEATURES.md)
- [Example Projects](demo/)
- [WebSocket Test Demo](demo/websocket-test/)

---

**Note**: This is a high-performance framework designed for production use. Make sure to understand the architecture and follow best practices when building applications with Hilos.
