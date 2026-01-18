# WebSocket Test Demo Project

Simple demo project for testing WebSocket functionality of Hilos v2 framework.

## What is this?

This is a chat application demo that showcases WebSocket real-time communication:
- **Backend**: PHP daemon with WebSocket server (Hilos framework)
- **Frontend**: Vue 3 + TypeScript chat interface with auto-reconnect

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

**Start frontend dev server:**
```bash
composer run frontend-dev
```

## Documentation

For detailed instructions on:
- Frontend development and build: see [frontend/README.md](frontend/README.md)
- Backend setup: see framework documentation
- Docker configuration: see `docker/` directory
