import { defineConfig } from '@playwright/test'
import base from './playwright.config.js'

// Explicit preparation and loss phases of one run-scoped fixture. They are
// outside the ordinary testDir, so pointed product runs keep their semantics.
export default defineConfig({
  ...base,
  testDir: './standalone',
  outputDir: 'test-results/standalone-status',
  retries: 0,
  reporter: [['list']],
})
