import { defineConfig, devices } from '@playwright/test'

// e2e drives the BUILT frontend artifact served by the test nginx, with a
// booted daemon behind it (docs/agents/frontend/testing-strategy.md). The
// dockerized runner gets BASE_URL from its compose service; the localhost
// default matches the poll-nginx-test host port for a host-side run.
export default defineConfig({
  testDir: './tests',
  globalSetup: './global-setup.ts',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  // One retry headroom in CI: e2e rides a real network and a live daemon.
  retries: process.env.CI ? 2 : 0,
  // The tests share one database and daemon, so CI serializes them.
  workers: process.env.CI ? 1 : undefined,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.BASE_URL ?? 'https://localhost:8448',
    // The test nginx generates a self-signed certificate on first start.
    ignoreHTTPSErrors: true,
    trace: 'on-first-retry',
    // Stable-id selectors only (testing-strategy.md): every interactive
    // element carries a data-id; tests never select by text or position.
    testIdAttribute: 'data-id',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
