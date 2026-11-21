import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [vue({ template: { compilerOptions: { comments: false } } })],
  resolve: {
    conditions: ['development'],
    dedupe: ['vue', '@vue/reactivity'],
  },
  server: {
    // Linked SDK sources live outside the website root, as in its dev config.
    fs: { allow: ['../../..'] },
  },
  test: {
    name: 'hilos-website',
    environment: 'happy-dom',
    include: ['src/**/*.test.ts', 'test/**/*.test.ts'],
  },
})
