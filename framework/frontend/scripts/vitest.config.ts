// The build-guard test project: the staleness rules behind the SDK prebuild and
// npm-install guards, exercised against a real tmp dir — no browser, no npm.
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    name: 'scripts',
  },
})
