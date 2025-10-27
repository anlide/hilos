# Hilos v2

High-performance cluster framework with lightweight daemon.

## Quick Start

### Using Docker (Recommended)

```bash
# Build and start daemon
make build
make up

# Or start in background
make start

# Check status
make status

# Monitor in real-time
make monitor

# Stop daemon
make stop
```

### Data Directory

The `data/` directory is mounted into the Docker container and contains:
- `data/logs/` - All log files (daemon, workers, errors)
- `data/run/` - PID files and runtime data

This directory is excluded from git and persists between container restarts.

### Manual CLI Commands

```bash
# Start daemon in foreground
./hilos daemon:start --foreground

# Start daemon in background
./hilos daemon:start

# Check status
./hilos daemon:status

# Stop daemon
./hilos daemon:stop

# Restart daemon
./hilos daemon:restart

# Monitor daemon
./hilos daemon:monitor
```

## Project Structure

```
hilos-v2/
├── hilos                   # CLI executable
├── composer.json          # Composer configuration
├── Makefile              # Convenience commands (Unix automation)
├── README.md             # This file
├── data/                 # Persistent data (mounted to Docker)
│   ├── logs/            # Log files
│   └── run/             # PID files and runtime data
├── docker/              # Docker configuration
│   ├── Dockerfile       # Docker image definition
│   └── docker-compose.yml # Docker Compose setup
└── framework/src/        # Framework source code
    ├── Bootstrap/        # Entry points
    │   ├── cli.php      # CLI entry point
    │   ├── daemon.php   # Daemon entry point
    │   └── worker.php   # Worker entry point
    ├── Core/            # Core framework
    │   ├── CLI/         # CLI interface
    │   ├── Daemon/      # Daemon management
    │   └── Worker/      # Worker processes
    ├── Logging/         # Logging system
    └── Utils/           # Utilities and constants
```

### About Makefile

Makefile - это утилита для автоматизации команд в Unix-системах. Позволяет запускать команды через `make <command>` вместо длинных `docker-compose` команд. В Windows можно использовать WSL или Git Bash.

## Development

The framework is designed to be minimal and focused. Current implementation includes:

- ✅ CLI interface with daemon management
- ✅ Proper daemonization with log redirection
- ✅ Docker support for easy deployment
- ✅ Constants-based configuration (no magic strings)
- ✅ Structured logging system

## Next Steps

This is a minimal working version. The full framework will include:
- Socket system (WebSocket, HTTP, CLI, Swarm, Worker)
- Cluster management
- Signal routing
- API system
- Database ORM
- And much more...
