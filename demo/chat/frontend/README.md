# Frontend - Vue 3 Chat Application

Vue 3 + TypeScript + Vite chat frontend with WebSocket client integration.

## Prerequisites

- Work from the full Hilos repository: `framework/` must be a sibling of `demo/`
- Docker and Docker Compose

The frontend resolves shared code from `../../../framework/frontend/src` via `@hilos/sdk`. No frontend symlink is required.

## First-Time Setup

Run from `demo/chat/`:

```bash
composer run install-deps
composer run frontend-install
```

If needed, create `.env` first:

```bash
composer run setup-env
```

## Development Mode

Development mode uses Vite in Docker with hot reload.

```bash
composer run daemon-start
composer run frontend-dev
```

Open `http://localhost:5173`.

Backend daemon/WebSocket endpoints remain in Docker; Vite serves only the frontend.

## Build Mode

Build mode produces `dist/` with `vite-ssg`, then serves it through Nginx over HTTPS.

```bash
composer run frontend-build
composer run daemon-start-build
```

Open `https://localhost` and accept the self-signed certificate warning.

Direct deep-links also work in build mode, for example:

- `https://localhost/admin/users`
- `https://localhost/hilos/users`

## Useful Commands

- `composer run frontend-install` - Install frontend npm dependencies in Docker
- `composer run frontend-dev` - Start Vite dev server in Docker
- `composer run frontend-build` - Build frontend in Docker and write `dist/`
- `composer run frontend-stop` - Stop the Vite dev container
- `composer run daemon-start` - Start MySQL + daemon for dev mode
- `composer run daemon-start-build` - Start MySQL + daemon + Nginx for build mode
- `composer run daemon-stop` - Stop the local Docker stack

## Notes

- Shared frontend components, routes, stores, and views are loaded from `framework/frontend/src`.
- Demo-specific route overrides are wired through `createHilosRoutes(...)`.
- If you change `docker/nginx.conf.template`, rebuild the Nginx image:

```bash
docker compose -f docker/docker-compose.local.yml --profile full up -d --build --force-recreate chat-nginx-local
```
