import { test, expect } from '@playwright/test'

// Category-1 smoke (testing-strategy.md): the blank Angular app — the step-5.2
// conformance shell — renders from the built artifact through the docker test
// stack.
test('blank page renders', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByTestId('app-root')).toHaveText('Hilos simple-poll (Angular)')
})
