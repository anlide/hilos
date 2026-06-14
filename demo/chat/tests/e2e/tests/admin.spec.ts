import { test, expect } from '@playwright/test'

// Admin tree navigation e2e: the framework dashboard lists the Hilos admin
// sections, and every section / sub-page / deep link resolves over the live
// socket with no document reload. Each `/hilos` page is its own module rendered
// through the framework HilosAdminPage shell (breadcrumb + children resolved
// from the core admin tree), so this proves both the dashboard menu and the
// per-page admin routing.
test('navigates the admin tree with no reload or reconnect', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await page.goto('/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  const loadsAfterColdLoad = fullLoads

  // Gear -> dashboard, which lists the sections as cards.
  await page.getByTestId('nav-admin').click()
  await expect(page.getByTestId('dashboard-view')).toBeVisible()
  await expect(page.getByTestId('dashboard-card-hilos_daemon')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/hilos')

  // Dashboard card -> a top-level section page with its sub-navigation.
  await page.getByTestId('dashboard-card-hilos_daemon').click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Daemon')
  expect(new URL(page.url()).pathname).toBe('/hilos/daemon')
  await expect(
    page.getByTestId('hilos-admin-child-hilos_daemon_workers'),
  ).toBeVisible()

  // Section -> a sub-page, then back up through the breadcrumb.
  await page.getByTestId('hilos-admin-child-hilos_daemon_workers').click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Workers')
  expect(new URL(page.url()).pathname).toBe('/hilos/daemon/workers')
  await page.getByTestId('hilos-breadcrumb').getByText('Daemon').click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Daemon')
  expect(new URL(page.url()).pathname).toBe('/hilos/daemon')

  // The whole tour stayed in one live document on one socket.
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  expect(fullLoads).toBe(loadsAfterColdLoad)
})

// A parametrized admin page resolves on cold load through its own page module:
// the Billing section has graduated from the shared stub to one-folder-per-page
// views rendered by the framework HilosAdminPage shell, so the route param is
// captured and the leaf renders with the framework breadcrumb built from the
// core admin tree (HILOS_ADMIN_PAGES).
test('cold-loads a parametrized admin page through its own module', async ({
  page,
}) => {
  await page.goto('/hilos/billing/stripe/payments')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Payments')

  // The framework breadcrumb walks back to the provider and the billing hub,
  // keeping the providerId from the current route.
  const breadcrumb = page.getByTestId('hilos-breadcrumb')
  await expect(breadcrumb.getByText('Billing')).toBeVisible()
  await breadcrumb.getByText('Billing', { exact: true }).click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Billing')
  expect(new URL(page.url()).pathname).toBe('/hilos/billing')
})
