import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'
import { libInjectCss } from 'vite-plugin-lib-inject-css'

// Library build for @hilos/react: compile the components and emit a single ES
// module to dist/index.js. Type declarations are emitted separately by tsc (see
// the package build script). react, react-dom, the JSX runtime, and @hilos/core
// stay external — a consumer resolves them from its own install, so they are
// never bundled in.
//
// libInjectCss makes the bundle self-import its emitted stylesheet, so a
// consumer that imports the dist bundle gets Bootstrap with no extra step — the
// "the SDK ships Bootstrap" rule (styling-rules.md) holding in the built
// artifact, not only in dev where the consumer resolves the SDK to source.
export default defineConfig({
  plugins: [react(), libInjectCss()],
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
        id === 'react' ||
        id === 'react-dom' ||
        id === 'react/jsx-runtime' ||
        id === '@hilos/core' ||
        id.startsWith('@hilos/core/'),
    },
  },
})
