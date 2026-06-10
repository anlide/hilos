// The @hilos/angular test project: adapter logic over @hilos/core. Signals
// run framework-free, so the node environment suffices; component-level tests
// live in the consumer demos under the Angular CLI's native unit-test builder
// (docs/agents/frontend/testing-strategy.md).
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    name: 'angular',
  },
})
