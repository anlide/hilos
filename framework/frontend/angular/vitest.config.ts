// The @hilos/angular test project: adapter logic over @hilos/core. Runs in the
// node environment; the Analog Angular plugin and a DOM environment arrive
// with the first component-level tests that need them
// (docs/agents/frontend/testing-strategy.md).
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    name: 'angular',
  },
})
