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
  server: {
    host: true,
    port: 5173,
    strictPort: true,
  },
})
