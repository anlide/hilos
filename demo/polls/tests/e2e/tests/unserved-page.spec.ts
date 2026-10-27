import { test, expect } from '@playwright/test'
import {
  gotoPage,
  PAGE_REFUSED,
  grantAdminToSelf,
} from '../../../../../framework/frontend/e2e/index.js'

test('answers 404 for a page the project does not serve', async ({ page }) => {
  await gotoPage(expect, page, '/hilos/backup', PAGE_REFUSED)
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  const error = page.getByTestId('page-error')
  await expect(error).toBeVisible()
  await expect(error).toHaveAttribute('data-error-code', '404')
  await expect(error).toContainText('Page Not Found')
})

test('draws no card for a page the project does not serve', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos')

  await expect(page.getByTestId('dashboard-card-hilos_settings')).toBeVisible()
  await expect(page.getByTestId('dashboard-card-hilos_backup')).toHaveCount(0)
})
