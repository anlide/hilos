import { test, expect } from '@playwright/test'
import { gotoPage } from '../../../../../framework/frontend/e2e/index.js'

// Category-1 smoke (testing-strategy.md): the React app renders from the built
// artifact through the docker test stack. Contains, not equals: the root is the
// SDK application shell carrying the brand, the connection indicator, and the
// routed page.
test('blank page renders', async ({ page }) => {
  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('app-root')).toContainText('Hilos Flowers')
})
