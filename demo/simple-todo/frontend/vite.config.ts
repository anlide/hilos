import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// Dev/build config for the simple-todo demo — the end project that consumes
// @hilos/react and doubles as the React conformance demo
// (docs/agents/frontend/multiframework-core.md). The dev server listens on
// every interface so the host browser reaches it through the container's port
// mapping. HMR runs on native filesystem events: the project lives on the WSL2
// filesystem, so inotify works and no polling is needed (see
// docs/agents/frontend/build-and-docker.md).
export default defineConfig({
  plugins: [react()],
  resolve: {
    // The @hilos/react file: dependency carries its own react copy (a
    // devDependency for the adapter unit tests), reachable through the
    // symlink's real path. Dedupe forces every react import — including the
    // SDK's — onto this app's single copy; with two copies the SDK hooks
    // would run against a second React with a null dispatcher.
    dedupe: ['react', 'react-dom'],
  },
  server: {
    host: true,
    port: 5173,
    strictPort: true,
  },
})
