import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

// Library build for @hilos/vue: compile the Vue single-file components and emit
// a single ES module to dist/index.js. Type declarations are emitted separately
// by vue-tsc (see the package build script). vue and @hilos/core stay external —
// a consumer resolves them from its own install, so they are never bundled in.
export default defineConfig({
  plugins: [vue()],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    lib: {
      entry: './src/index.ts',
      formats: ['es'],
      fileName: () => 'index.js',
    },
    rollupOptions: {
      external: (id) =>
        id === 'vue' || id === '@hilos/core' || id.startsWith('@hilos/core/'),
    },
  },
})
