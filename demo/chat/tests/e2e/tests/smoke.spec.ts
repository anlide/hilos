import { test, expect } from '@playwright/test'

// Category-1 smoke (testing-strategy.md): the Vue app renders from the built
// artifact through the docker test stack. Contains, not equals: since step
// 7.1 the root also carries the connection-state indicator.
test('blank page renders', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByTestId('app-root')).toContainText('Hilos')
})
