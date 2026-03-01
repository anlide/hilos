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

## GPU acceleration (optional)

AI moderation uses Ollama. By default it runs on CPU. For faster inference, use GPU overrides:

| Vendor | Command | Prerequisites |
|--------|---------|---------------|
| **NVIDIA** | `composer run daemon-start-gpu-nvidia` | [NVIDIA Container Toolkit](https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/) |
| **AMD** | `composer run daemon-start-gpu-amd` | ROCm, `/dev/kfd` and `/dev/dri` |

**Model initialization:** Pull the moderation model before first run (or after switching to GPU override):
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

## Documentation

For detailed instructions on:
- Frontend development and build: see [frontend/README.md](frontend/README.md)
- Backend setup: see framework documentation
- Docker configuration: see `docker/` directory

## Refactor Notes

- TODO(hilos-refactor): rename legacy CLI commands `db:idea:*` to `db:hilos:*`.
- TODO(hilos-refactor): update bootstrap/CLI parameter naming from `initIdea` to `initHilos` after command migration.
