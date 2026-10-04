import { defineConfig, devices } from '@playwright/test'

import { scaledTimeouts } from '../../../../framework/frontend/scripts/timeout-scale.mjs'

const baseURL = process.env.BASE_URL
if (!baseURL) {
  throw new Error('run it through: composer run test:cluster:e2e')
}
const phase = process.env.CLUSTER_E2E_PHASE ?? 'live'
const timeouts = scaledTimeouts()

export default defineConfig({
  testDir: './cluster',
  globalSetup: './cluster/global-setup.ts',
  timeout: timeouts.test,
  expect: { timeout: timeouts.expect },
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1,
  reporter: [
    ['list'],
    [
      'html',
      { open: 'never', outputFolder: `playwright-report/cluster-${phase}` },
    ],
    ['../../../../framework/frontend/scripts/unstable-reporter.mjs'],
  ],
  use: {
    baseURL,
    actionTimeout: timeouts.action,
    navigationTimeout: timeouts.navigation,
    ignoreHTTPSErrors: true,
    trace: 'on-first-retry',
    testIdAttribute: 'data-id',
  },
  projects: [
    {
      name: 'live',
      testDir: './cluster/live',
      outputDir: 'test-results/cluster-live',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'after-loss',
      testDir: './cluster/after-loss',
      outputDir: 'test-results/cluster-after-loss',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})
