import { test, expect } from '@playwright/test'
import { grantAdminToSelf } from '../helpers/adminGrant'

// No-refresh navigation e2e: the shell's gear moves to the framework dashboard
// and the brand moves back home through the core navigator (HilosRouter),
// without reloading the document or dropping the WebSocket. A hard navigation
// would fire a fresh `load`; the count staying put proves every transition
// stayed in the same live document, and `conn-state` staying `connected` proves
// the socket was never torn down.
test('navigates main <-> dashboard with no reload or reconnect', async ({
  page,
}) => {
  // The gear exists only for an admin, so the walk starts from the grant. It
  // leaves the browser on the main page with the socket up — where this walk
  // begins — so the load counter starts right after it and every later
  // transition has to stay inside that same document.
  await grantAdminToSelf(page)

  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  // Gear -> dashboard.
  await page.getByTestId('nav-admin').click()
  await expect(page.getByTestId('dashboard-view')).toBeVisible()
  // The framework dashboard renders the real admin section cards from the
  // catalog (HilosDashboardPage), not a placeholder.
  await expect(
    page.locator('[data-id^="dashboard-card-"]').first(),
  ).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/hilos')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  expect(fullLoads).toBe(0)

  // Brand -> home.
  await page.getByTestId('nav-brand').click()
  await expect(page.getByTestId('self-user')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  expect(fullLoads).toBe(0)
})
