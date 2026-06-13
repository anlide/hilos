import vue from '@vitejs/plugin-vue'
import { defineConfig, loadEnv } from 'vite'

// Dev/build config for the chat demo — the end project that consumes @hilos/vue.
// The dev server listens on every interface so the host browser reaches it
// through the container's port mapping. HMR runs on native filesystem events:
// the project lives on the WSL2 filesystem, so inotify works and no polling is
// needed (see docs/agents/frontend/build-and-docker.md).
export default defineConfig(({ mode }) => {
  // Same-origin attachment downloads: the browser requests /chat/attachment on
  // the Vite origin (so the session cookie rides along, no token in the URL) and
  // Vite forwards it to the daemon's HTTP router. VITE_ATTACHMENT_TARGET is set
  // by docker-compose.local.yml; test/prod use nginx, so this proxy is dev-only.
  const env = loadEnv(mode, '.', 'VITE_')

  return {
    plugins: [vue()],
    server: {
      host: true,
      port: 5173,
      strictPort: true,
      proxy: {
        '/chat/attachment': {
          target: env.VITE_ATTACHMENT_TARGET || 'http://chat-local:8090',
          changeOrigin: true,
        },
      },
      fs: {
        // Serve the SDK's bundled assets in dev. @hilos/vue is a file:
        // dependency symlinked from framework/frontend, whose node_modules (the
        // Bootstrap-Icons font lives there) sits outside this app's root — so the
        // monorepo root must be in Vite's serving allow list. The path is
        // relative to this config's directory (the app root); the production
        // build inlines the font, so this is a dev-only concern.
        allow: ['../../..'],
      },
    },
  }
})
