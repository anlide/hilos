# WebSocket Test Demo Project

Simple demo project for testing WebSocket functionality of Hilos v2 framework.

## What is this?

This is a chat application demo that showcases WebSocket real-time communication:
- **Backend**: PHP daemon with WebSocket server (Hilos framework)
- **Frontend**: Vue 3 + TypeScript chat interface with auto-reconnect

## Quick Start

### Prerequisites

- Docker and Docker Compose
- Node.js 18+ (for local frontend development)
- PHP 8.4+ and Composer (for backend)

### Setup

1. **Create `.env` file** in project root:
   ```bash
   cp .env.example .env  # if exists
   # Or create manually with required variables
   ```

2. **Install backend dependencies:**
   ```bash
   composer install
   ```

3. **Install frontend dependencies:**
   ```bash
   cd frontend
   npm install
   ```

### Running

**Local Development (Windows):**
- Backend runs in Docker
- Frontend runs locally
- See [Frontend README](frontend/README.md) for details

**Production (Linux):**
- Both frontend and backend run in Docker
- See [Frontend README](frontend/README.md) for details

## Documentation

For detailed instructions on:
- Frontend development and build: see [frontend/README.md](frontend/README.md)
- Backend setup: see framework documentation
- Docker configuration: see `docker/` directory
