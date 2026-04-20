import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const demoSrc = path.resolve(here, '../../frontend/src')
const frameworkSrc = path.resolve(here, '../../../../framework/frontend/src')

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': demoSrc,
      '@hilos/sdk': frameworkSrc,
    },
  },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.ts'],
    globals: false,
  },
})
