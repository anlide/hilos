import { createRequire } from 'node:module'
import { dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

const require = createRequire(import.meta.url)

/**
 * The directory of a package installed for this project, so its subpaths
 * (`react/jsx-runtime`, `react-dom/client`) resolve inside the same copy.
 *
 * @param name The package name.
 * @returns The package's directory under this project's node_modules.
 */
function installed(name: string): string {
  return dirname(require.resolve(`${name}/package.json`))
}

// The shop's accessibility consumers use the one Solar-owned source fixture, with
// the shop's installed dependencies and a separate output beside its other fixtures.
export default defineConfig({
  root: fileURLToPath(
    new URL(
      '../../../../solar-system-model/frontend/tests/tableGeometry',
      import.meta.url,
    ),
  ),
  base: './',
  plugins: [react()],
  publicDir: false,
  resolve: {
    alias: {
      react: installed('react'),
      'react-dom': installed('react-dom'),
      '@hilos/core/base': require.resolve('@hilos/core/base'),
      '@hilos/react/base': require.resolve('@hilos/react/base'),
    },
  },
  build: {
    outDir: fileURLToPath(
      new URL('../../dist/__table_geometry', import.meta.url),
    ),
    emptyOutDir: false,
  },
})
