import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: './tests',
  outputDir: 'test-results/main',
  workers: 1,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  projects: [{ name: 'chromium' }],
})
