import { test, expect } from '@playwright/test'

// Category-1 smoke (testing-strategy.md): the blank React app — the step-5.1
// conformance shell — renders from the built artifact through the docker test
// stack.
test('blank page renders', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByTestId('app-root')).toHaveText('Hilos simple-todo (React)')
})
