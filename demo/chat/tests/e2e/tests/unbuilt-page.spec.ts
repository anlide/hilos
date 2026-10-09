import { test, expect } from '@playwright/test'
import { signUpAdmin } from '../helpers/adminGrant'
import { gotoPage, PAGE_REFUSED } from '../helpers/page'

test('answers 404 for a page the view layer has not built', async ({
  page,
}) => {
  for (const path of ['/hilos/roles', '/hilos/daemon/standalone/websockets']) {
    await gotoPage(page, path, PAGE_REFUSED)
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
    const error = page.getByTestId('page-error')
    await expect(error).toBeVisible()
    await expect(error).toHaveAttribute('data-error-code', '404')
    await expect(error).toContainText('Page Not Found')
  }
})

test('draws no card for a page the view layer has not built', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos')
  await expect(page.getByTestId('dashboard-card-hilos_users')).toBeVisible()
  for (const key of ['hilos_roles', 'hilos_daemon', 'hilos_billing']) {
    await expect(page.getByTestId(`dashboard-card-${key}`)).toHaveCount(0)
  }
  await expect(page.getByTestId('dashboard-view')).not.toContainText(
    'Automation & intelligence',
  )
})
