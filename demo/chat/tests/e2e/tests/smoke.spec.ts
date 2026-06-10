import { test, expect } from '@playwright/test'

// Category-1 smoke (testing-strategy.md): the blank Vue app — the step-4
// infrastructure gate — renders from the built artifact through the docker
// test stack.
test('blank page renders', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByTestId('app-root')).toHaveText('Hilos')
})
