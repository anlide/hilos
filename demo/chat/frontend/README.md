# Frontend - Vue 3 Chat Application

Vue 3 + TypeScript + Vite chat frontend with WebSocket client integration.

## Development Setup

### Install Dependencies

```bash
npm install
```

### Development Server (Local)

Run frontend development server locally (recommended for Windows):

```bash
npm run dev
```

Frontend will be available at `http://localhost:5173`

**Note:** Backend should be running in Docker with WebSocket port exposed (see main README).

### Environment Variables

Create `.env` file in project root (`demo/chat/.env`):

```env
VITE_WEBSOCKET_HOST=localhost
VITE_WEBSOCKET_PORT=8092
VITE_WEBSOCKET_PROTOCOL=ws
```

Variables with `VITE_` prefix are injected at build time and accessible via `import.meta.env`.

## Production Build

### Build for Production

```bash
npm run build
```

This creates optimized production build in `dist/` directory.

### Preview Production Build

```bash
npm run preview
```

### Production Deployment

For production deployment with Docker:

1. Build the frontend:
   ```bash
   npm run build
   ```

2. Start Docker services (includes Nginx serving frontend):
   ```bash
   cd ../docker
   docker-compose -f docker-compose.prod.yml up -d
   ```

Frontend will be served via Nginx at `http://localhost:8080` (or `FRONTEND_PORT` from `.env`).

## Features

- **Vue 3** with Composition API
- **TypeScript** for type safety
- **Pinia** for state management
- **Vue Router** for navigation
- **Bootstrap 5.1** for UI styling
- **Auto-reconnect** WebSocket client
- **Real-time chat** interface

## Scripts

- `npm run dev` - Start development server
- `npm run build` - Build for production
- `npm run preview` - Preview production build
- `npm run type-check` - Type check (if configured)
