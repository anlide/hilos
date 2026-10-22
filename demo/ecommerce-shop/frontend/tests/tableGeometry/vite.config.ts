import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// Independent test entry: the production timestamp, SSR and budget stay with the app.
export default defineConfig({
  root: 'tests/tableGeometry',
  base: './',
  plugins: [react()],
  resolve: { dedupe: ['react', 'react-dom'] },
  publicDir: false,
  build: {
    outDir: '../../dist/__table_geometry',
    emptyOutDir: false,
  },
})
