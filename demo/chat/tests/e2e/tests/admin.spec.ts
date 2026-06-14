import { test, expect } from '@playwright/test'

// Admin stub navigation e2e: the framework dashboard lists the stubbed Hilos
// admin sections, and every section / sub-page / deep link resolves over the
// live socket with no document reload. The whole `/hilos` admin tree renders
// through the single HilosAdminStub component driven by the core navigator, so
// this proves both the dashboard menu and the data-driven stub routing.
test('navigates the admin stub tree with no reload or reconnect', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await page.goto('/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  const loadsAfterColdLoad = fullLoads

  // Gear -> dashboard, which lists the stubbed sections as cards.
  await page.getByTestId('nav-admin').click()
  await expect(page.getByTestId('dashboard-view')).toBeVisible()
  await expect(page.getByTestId('dashboard-card-hilos_daemon')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/hilos')

  // Dashboard card -> a top-level section stub with its sub-navigation.
  await page.getByTestId('dashboard-card-hilos_daemon').click()
  await expect(page.getByTestId('admin-stub-title')).toHaveText('Daemon')
  expect(new URL(page.url()).pathname).toBe('/hilos/daemon')
  await expect(
    page.getByTestId('admin-child-hilos_daemon_workers'),
  ).toBeVisible()

  // Section -> a sub-page stub, then back up through the breadcrumb.
  await page.getByTestId('admin-child-hilos_daemon_workers').click()
  await expect(page.getByTestId('admin-stub-title')).toHaveText('Workers')
  expect(new URL(page.url()).pathname).toBe('/hilos/daemon/workers')
  await page.getByTestId('admin-breadcrumb').getByText('Daemon').click()
  await expect(page.getByTestId('admin-stub-title')).toHaveText('Daemon')
  expect(new URL(page.url()).pathname).toBe('/hilos/daemon')

  // The whole tour stayed in one live document on one socket.
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  expect(fullLoads).toBe(loadsAfterColdLoad)
})

// A parametrized admin page resolves on cold load: the route param is captured
// and the data-driven stub renders the right leaf with its breadcrumb trail.
test('cold-loads a parametrized admin stub page', async ({ page }) => {
  await page.goto('/hilos/billing/stripe/payments')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('admin-stub-title')).toHaveText('Payments')

  // The breadcrumb walks back to the provider and the billing hub.
  const breadcrumb = page.getByTestId('admin-breadcrumb')
  await expect(breadcrumb.getByText('Billing')).toBeVisible()
  await breadcrumb.getByText('Billing', { exact: true }).click()
  await expect(page.getByTestId('admin-stub-title')).toHaveText('Billing')
  expect(new URL(page.url()).pathname).toBe('/hilos/billing')
})
