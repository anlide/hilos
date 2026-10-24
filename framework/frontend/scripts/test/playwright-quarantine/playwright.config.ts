import { defineConfig } from '@playwright/test'
import { resolve } from 'node:path'

// spec-owner: stand — synthetic CLI fixtures without an application or browser.
export default defineConfig({
  testDir: '.',
  testMatch: 'cases.spec.ts',
  outputDir: resolve('test-results'),
  workers: 1,
  retries: 1,
  globalSetup: process.env.HILOS_FIXTURE_SETUP,
  reporter: [
    ['list'],
    ['html', { open: 'never', outputFolder: resolve('playwright-report') }],
    [resolve(__dirname, '../../unstable-reporter.mjs')],
  ],
  projects: [{ name: 'one' }, { name: 'two' }],
})
