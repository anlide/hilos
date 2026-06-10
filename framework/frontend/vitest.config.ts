// Vitest root config for the SDK workspace: one `vitest run` executes every
// package's test project. Unit tests run against source, per
// docs/agents/frontend/testing-strategy.md.
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    projects: ['core', 'vue'],
  },
})
