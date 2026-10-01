import vue from '@vitejs/plugin-vue'
import { defineConfig, loadEnv, type Plugin } from 'vite'

// Stamp the frontend build timestamp into the dist output. The daemon reads
// dist/build-timestamp.txt at startup and ships it in the handshake welcome
// frame, and the frontend forces a refresh when a reconnect reports a newer
// build (docs/agents/frontend/build-and-docker.md). Build-only: the dev server
// leaves HILOS_BUILD_TIMESTAMP at its 'dev' default. The file is emitted through
// Rollup so this config needs no node: imports (and thus no @types/node).
function buildTimestampPlugin(): Plugin {
  return {
    name: 'hilos-build-timestamp',
    apply: 'build',
    generateBundle() {
      this.emitFile({
        type: 'asset',
        fileName: 'build-timestamp.txt',
        source: new Date().toISOString(),
      })
    },
  }
}

// Dev/build config for the binance-btc-tracker demo — an end project that
// consumes @hilos/vue. The dev server listens on every interface so the host
// browser reaches it through the container's port mapping. HMR runs on native
// filesystem events (see docs/agents/frontend/build-and-docker.md).
export default defineConfig(({ mode }) => {
  // The proxy targets default to the local daemon service by its compose name;
  // VITE_WS_TARGET / VITE_DATA_EXPORT_TARGET override them. Test and prod use
  // nginx, so both proxies are dev-only.
  const env = loadEnv(mode, '.', 'VITE_')

  return {
    plugins: [vue(), buildTimestampPlugin()],
    server: {
      host: true,
      port: 5173,
      strictPort: true,
      proxy: {
        '/_hilos/data-export': {
          target:
            env.VITE_DATA_EXPORT_TARGET ||
            'http://binance-btc-tracker-daemon-local:8090',
          changeOrigin: true,
        },
        // Same-origin WebSocket: serving the page and the socket from the same
        // origin lets the session cookie (SameSite=Strict) and rotation ticket
        // ride the connection without cross-site issues; in test/prod nginx does the same.
        '/ws': {
          target:
            env.VITE_WS_TARGET ||
            'http://binance-btc-tracker-daemon-local:8092',
          ws: true,
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
