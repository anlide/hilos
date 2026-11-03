// The @hilos/react test project: adapter logic over @hilos/core. Hook tests
// render through @testing-library/react, so the project runs in a DOM
// environment.
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    name: 'react',
    environment: 'jsdom',
    // On a loaded host (a full run on seven lanes beside e2e) a case that renders
    // a real table frame took 10 to 12 s against the default 5 s (2026-11-03),
    // while its whole file passes in under two seconds alone.
    testTimeout: 15_000,
  },
})
