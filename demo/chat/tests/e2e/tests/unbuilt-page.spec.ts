import { test, expect } from '@playwright/test'
import {
  gotoPage,
  PAGE_REFUSED,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'

test('answers 404 for a page the view layer has not built', async ({
  page,
}) => {
  for (const path of ['/hilos/roles', '/hilos/daemon/standalone/websockets']) {
    await gotoPage(expect, page, path, PAGE_REFUSED)
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
  await signUpAdmin(expect, page, { entryId: 'message-signin' })
  await gotoPage(expect, page, '/hilos')
  await expect(page.getByTestId('dashboard-card-hilos_users')).toBeVisible()
  const daemonCard = page.getByTestId('dashboard-card-hilos_daemon')
  await expect(daemonCard).toBeVisible()
  await expect(daemonCard).toHaveAttribute('href', '/hilos/daemon')
  for (const key of [
    'hilos_roles',
    'hilos_billing',
    'hilos_mcp_skills',
    'hilos_sil',
  ]) {
    await expect(page.getByTestId(`dashboard-card-${key}`)).toHaveCount(0)
  }
})
