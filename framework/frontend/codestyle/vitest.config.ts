// The code-style guard test project: the TypeScript half of the WIRE-KEY-CASE
// rule, run over the seeded fixtures and over the real SDK and demo sources. No
// browser and no build — the checker reads sources through the compiler API.
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    name: 'codestyle',
  },
})
