import { defineConfig } from '@playwright/test'
import { resolve } from 'node:path'

// spec-owner: stand — synthetic CLI fixtures without an application or browser.
export default defineConfig({
  testDir: '.',
  testMatch: ['cases.spec.ts', 'slots.spec.ts'],
  outputDir: resolve('test-results'),
  workers: 1,
  retries: 0,
  repeatEach: 1,
  metadata: { hilosTimeoutScale: 1 },
  reporter: [['list'], ['json', { outputFile: resolve('report.json') }]],
})
