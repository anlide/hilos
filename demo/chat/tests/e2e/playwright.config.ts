import { defineConfig, devices } from '@playwright/test'

import { scaledTimeouts } from '../../../../framework/frontend/scripts/timeout-scale.mjs'

// e2e drives the BUILT frontend artifact served by the test nginx, with a
// booted daemon behind it (docs/agents/frontend/testing-strategy.md). The
// dockerized runner gets BASE_URL from its compose service; the localhost
// default matches the chat-nginx-test host port for a host-side run.

// Every cap is stretched when the host is starved, so a full run that shares the
// box with a second demo lane gets slower rather than red (HIL-527). The chosen
// factor and the load and memory behind it are printed as the step starts.
const timeouts = scaledTimeouts()

export default defineConfig({
  testDir: './tests',
  timeout: timeouts.test,
  expect: { timeout: timeouts.expect },
  globalSetup: './global-setup.ts',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  // One retry headroom in CI: e2e rides a real network and a live daemon.
  retries: process.env.CI ? 2 : 0,
  // The tests share one database and daemon, so CI serializes them.
  workers: process.env.CI ? 1 : undefined,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.BASE_URL ?? 'https://localhost:8446',
    actionTimeout: timeouts.action,
    navigationTimeout: timeouts.navigation,
    // The test nginx generates a self-signed certificate on first start.
    ignoreHTTPSErrors: true,
    trace: 'on-first-retry',
    // Stable-id selectors only (testing-strategy.md): every interactive
    // element carries a data-id; tests never select by text or position.
    testIdAttribute: 'data-id',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
