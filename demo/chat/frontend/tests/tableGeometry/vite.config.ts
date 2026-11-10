import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

const require = createRequire(import.meta.url)

// Chat's accessibility consumers use the one PMD-owned source fixture, with
// Chat's installed dependencies and a separate output beside its other fixtures.
export default defineConfig({
  root: fileURLToPath(
    new URL('../../../../pmd/frontend/tests/tableGeometry', import.meta.url),
  ),
  base: './',
  plugins: [vue()],
  publicDir: false,
  resolve: {
    alias: {
      vue: require.resolve('vue/dist/vue.runtime.esm-bundler.js'),
      '@hilos/core/base': require.resolve('@hilos/core/base'),
      '@hilos/vue/base': require.resolve('@hilos/vue/base'),
    },
  },
  build: {
    outDir: fileURLToPath(
      new URL('../../dist/__table_geometry', import.meta.url),
    ),
    emptyOutDir: false,
  },
})
