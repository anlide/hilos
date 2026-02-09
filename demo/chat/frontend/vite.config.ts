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
  if (pathEnv.includes(':\\') || pathEnv.match(/\/[a-z]:\//i)) {
    return true
  }
  
  return false
}

const isWindowsHost = detectWindowsHost()
const needsPolling = isDocker && isWindowsHost

// Resolve SDK path for Docker
// Framework is mounted at /hilos/framework in Docker container
const dockerSdkPath = '/hilos/framework/frontend/src'

let resolvedSdkPath: string

// In Docker: use mounted volume path
if (isDocker && existsSync(dockerSdkPath)) {
  resolvedSdkPath = dockerSdkPath
} else {
  // Fallback for local development (should not happen in Docker)
  resolvedSdkPath = resolve(fileURLToPath(new URL('../../../framework/frontend/src', import.meta.url)))
}

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  resolve: {
    preserveSymlinks: false, // Resolve symlinks to their real paths
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
      '@hilos/sdk': resolvedSdkPath
    },
    // Explicitly tell Vite to look for node_modules in current project directory
    // This is critical when framework code imports dependencies like vue-router, pinia, vue
    // Without this, Vite searches node_modules relative to framework path (/hilos/framework/...)
    // instead of project path (/app/...)
    // @ts-expect-error - resolve.modules is a valid Rollup resolver option but not in Vite's ResolveOptions type
    modules: [
      fileURLToPath(new URL('./node_modules', import.meta.url)), // Project's node_modules first
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
  }
})
