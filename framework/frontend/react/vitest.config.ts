// The @hilos/react test project: adapter logic over @hilos/core. Runs in the
// node environment; a DOM environment arrives with the first hook tests that
// need one.
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    name: 'react',
  },
})
