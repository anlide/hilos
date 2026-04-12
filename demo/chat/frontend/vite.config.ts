import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'
import { existsSync } from 'node:fs'
import { resolve } from 'node:path'

// Detect if running in Docker AND on Windows host
const isDocker = process.env.DOCKER === 'true' || existsSync('/.dockerenv')

// Detect Windows host from inside Docker container
// Windows paths in Docker are often mounted as /mnt/c/... or contain Windows-style paths
// Also check for Windows-specific environment variables that Docker Desktop might pass
const detectWindowsHost = (): boolean => {
  // Check if explicitly set via environment variable
  if (process.env.WINDOWS_HOST === 'true') return true
  if (process.env.WINDOWS_HOST === 'false') return false
  
  // If not in Docker, check platform directly
  if (!isDocker) {
    return process.platform === 'win32'
  }
  
  // Inside Docker: check for Windows-specific indicators
  // Docker Desktop on Windows often sets these or mounts paths with Windows-style
  // Check for COMPUTERNAME (Windows-specific) or paths that suggest Windows host
  if (process.env.COMPUTERNAME) return true
  
  // Check current working directory path for Windows-style indicators
  const cwd = process.cwd()
  if (cwd.includes('/mnt/c/') || cwd.includes('/mnt/d/') || cwd.match(/^\/[a-z]:\//i)) {
    return true
  }
  
  // Check process.env.PATH for Windows-style paths (if mounted)
  const pathEnv = process.env.PATH || ''

  return !!(pathEnv.includes(':\\') || pathEnv.match(/\/[a-z]:\//i));
}

const isWindowsHost = detectWindowsHost()
const needsPolling = isDocker && isWindowsHost
const projectNodeModulesPath = fileURLToPath(new URL('./node_modules', import.meta.url))
const resolvedSdkPath = resolve(fileURLToPath(new URL('../../../framework/frontend/src', import.meta.url)))

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  resolve: {
    preserveSymlinks: false, // Keep real paths stable across host and Docker
    alias: [
      { find: '@', replacement: fileURLToPath(new URL('./src', import.meta.url)) },
      { find: '@hilos/sdk', replacement: resolvedSdkPath },
      // Force framework imports to reuse the demo app's runtime dependencies.
      { find: /^vue$/, replacement: fileURLToPath(new URL('./node_modules/vue/dist/vue.runtime.esm-bundler.js', import.meta.url)) },
      { find: /^vue-router$/, replacement: fileURLToPath(new URL('./node_modules/vue-router/dist/vue-router.mjs', import.meta.url)) },
      { find: /^pinia$/, replacement: fileURLToPath(new URL('./node_modules/pinia/dist/pinia.mjs', import.meta.url)) },
      { find: /^@unhead\/vue$/, replacement: fileURLToPath(new URL('./node_modules/@unhead/vue/dist/index.mjs', import.meta.url)) },
      { find: /^@unhead\/vue\/client$/, replacement: fileURLToPath(new URL('./node_modules/@unhead/vue/dist/client.mjs', import.meta.url)) },
      { find: /^@unhead\/vue\/server$/, replacement: fileURLToPath(new URL('./node_modules/@unhead/vue/dist/server.mjs', import.meta.url)) },
      { find: /^@unhead\/vue\/components$/, replacement: fileURLToPath(new URL('./node_modules/@unhead/vue/dist/components.mjs', import.meta.url)) },
      { find: /^@unhead\/vue\/plugins$/, replacement: fileURLToPath(new URL('./node_modules/@unhead/vue/dist/plugins.mjs', import.meta.url)) },
      { find: /^@unhead\/vue\/utils$/, replacement: fileURLToPath(new URL('./node_modules/@unhead/vue/dist/utils.mjs', import.meta.url)) },
      { find: /^@unhead\/vue\/legacy$/, replacement: fileURLToPath(new URL('./node_modules/@unhead/vue/dist/legacy.mjs', import.meta.url)) },
      { find: /^@unhead\/vue\/scripts$/, replacement: fileURLToPath(new URL('./node_modules/@unhead/vue/dist/scripts.mjs', import.meta.url)) },
    ],
    // Explicitly tell Vite to look for node_modules in current project directory
    // This is critical when framework code imports dependencies like vue-router, pinia, vue
    // Without this, Vite searches node_modules relative to framework path
    // instead of the demo frontend directory.
    // @ts-expect-error - resolve.modules is a valid Rollup resolver option but not in Vite's ResolveOptions type
    modules: [
      projectNodeModulesPath, // Project's node_modules first
      'node_modules' // Fallback
    ],
    // Ensure dependencies from demo project are always used, not from framework's location
    dedupe: ['vue', 'vue-router', 'pinia']
  },
  optimizeDeps: {
    // Pre-bundle these dependencies so Vite can resolve them correctly
    // even when imported from framework code
    include: ['vue-router', 'pinia', 'vue']
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    // Enable polling for hot reload on Windows/Docker
    watch: needsPolling ? {
      usePolling: true,
      interval: 1000
    } : undefined,
    // Proxy for WebSocket connections (optional, can connect directly)
    proxy: {
      '/ws': {
        target: 'ws://localhost:8092',
        ws: true,
        changeOrigin: true
      }
    }
  },
  // Production build configuration
  build: {
    outDir: 'dist',
    sourcemap: true,
    emptyOutDir: true
  },
})
