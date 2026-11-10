import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

// A separate, test-only HTML entry consuming the installed SDK and its Sass.
// Do not import the app config: its timestamp, SSG and budget belong to the app.
export default defineConfig({
  root: 'tests/tableGeometry',
  base: './',
  plugins: [vue()],
  publicDir: false,
  build: {
    outDir: '../../dist/__table_geometry',
    emptyOutDir: false,
  },
})
