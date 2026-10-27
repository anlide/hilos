import { test, expect } from '@playwright/test'
import { gotoPage } from '../../../../../framework/frontend/e2e/index.js'

// Category-1 smoke (testing-strategy.md): the Vue app renders from the built
// artifact through the docker test stack. Contains, not equals: since step
// 7.1 the root also carries the connection-state indicator.
test('blank page renders', async ({ page }) => {
  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('app-root')).toContainText('Hilos')
})
