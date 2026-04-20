import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const frameworkSrc = path.resolve(here, '../../frontend/src')

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': frameworkSrc,
    },
  },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.ts'],
    globals: false,
  },
})
