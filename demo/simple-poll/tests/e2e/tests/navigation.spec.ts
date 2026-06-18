import { test, expect } from '@playwright/test'

// No-refresh navigation e2e: the shell's gear moves to the framework dashboard
// and the brand moves back home through the core navigator (HilosRouter),
// without reloading the document or dropping the WebSocket. A hard navigation
// would fire a fresh `load`; the count staying put proves every transition
// stayed in the same live document, and `conn-state` staying `connected` proves
// the socket was never torn down.
test('navigates main <-> dashboard with no reload or reconnect', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await page.goto('/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('self-user')).toBeVisible()
  const loadsAfterColdLoad = fullLoads

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
  expect(fullLoads).toBe(loadsAfterColdLoad)

  // Brand -> home.
  await page.getByTestId('nav-brand').click()
  await expect(page.getByTestId('self-user')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  expect(fullLoads).toBe(loadsAfterColdLoad)
})
