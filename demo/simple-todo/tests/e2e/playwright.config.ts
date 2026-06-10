import { defineConfig, devices } from '@playwright/test'

// e2e drives the BUILT frontend artifact served by the test nginx
// (docs/agents/frontend/testing-strategy.md). The dockerized runner gets
// BASE_URL from its compose service; the localhost default matches the
// todo-nginx-test host port for a host-side run.
export default defineConfig({
  testDir: './tests',
  globalSetup: './global-setup.ts',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  // One retry headroom in CI: e2e rides a real network.
  retries: process.env.CI ? 2 : 0,
  // Serial in CI: once the daemon and its database join the stack at rewrite
  // step 7, the tests share them.
  workers: process.env.CI ? 1 : undefined,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.BASE_URL ?? 'http://localhost:8087',
    trace: 'on-first-retry',
    // Stable-id selectors only (testing-strategy.md): every interactive
    // element carries a data-id; tests never select by text or position.
    testIdAttribute: 'data-id',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
