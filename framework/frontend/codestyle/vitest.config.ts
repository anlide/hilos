// The code-style guard test project: every frontend rule, run over its seeded
// fixtures by a test of its own and over the real SDK and demo sources by the one
// guard test they share. No browser and no build — a checker reads sources
// through the compiler API.
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    name: 'codestyle',
  },
})
