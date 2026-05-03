import { test, expect } from '@playwright/test'

/**
 * hilos-chat is the concrete Hilos E2E suite for demo/chat.
 *
 * Framework Hilos pages are abstract, so the E2E target must be a real demo
 * that wires route overrides, page factory implementations, agents, DB seeds,
 * and frontend assets. Future demos can add their own hilos-<demo> suites with
 * the same shape.
 */
test.describe('hilos-chat', () => {
  test.fixme('renders the Hilos dashboard with all primary section cards', async ({ page }) => {
    await page.goto('/hilos')

    await expect(page.getByRole('heading', { name: 'Hilos' })).toBeVisible()
    await expect(page.getByRole('link', { name: 'User management' })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Settings' })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Daemon' })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Guardian' })).toBeVisible()
  })

  test.fixme('keeps Hilos dashboard navigation on concrete demo routes', async ({ page }) => {
    await page.goto('/hilos')

    await page.getByRole('link', { name: 'Settings' }).click()
    await expect(page).toHaveURL('/hilos/settings')

    await page.goto('/hilos')
    await page.getByRole('link', { name: 'Users' }).click()
    await expect(page).toHaveURL('/hilos/users')
  })

  test.fixme('renders Hilos breadcrumbs for nested pages with route params', async ({ page }) => {
    await page.goto('/hilos/users/1')

    await expect(page.getByRole('navigation', { name: /breadcrumb/i })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Users' })).toBeVisible()
  })

  test.fixme('subscribes to hilos_users and opens a hilos_user detail page', async ({ page }) => {
    await page.goto('/hilos/users')

    await expect(page.getByRole('heading', { name: /Users/i })).toBeVisible()
    await page.getByRole('link', { name: /user|#\d+/i }).first().click()

    await expect(page).toHaveURL(/\/hilos\/users\/\d+/)
  })

  test.fixme('edits a Hilos user through the detail page modal', async ({ page }) => {
    await page.goto('/hilos/users/1')

    await page.getByRole('button', { name: 'Edit' }).click()
    await page.locator('#hilos-user-name').fill('Hilos Edited User')
    await page.getByRole('button', { name: 'Save' }).click()

    await expect(page.getByText('Hilos Edited User')).toBeVisible()
  })

  test.fixme('loads logs overview and log viewer pages with deterministic stub data', async ({ page }) => {
    await page.goto('/hilos/logs')

    await expect(page.getByRole('heading', { name: /Logs/i })).toBeVisible()
    await page.getByRole('link', { name: /Viewer/i }).click()
    await expect(page).toHaveURL('/hilos/logs/view')
  })

  test.fixme('opens daemon subpages without console errors', async ({ page }) => {
    const errors: string[] = []
    page.on('console', (message) => {
      if (message.type() === 'error') {
        errors.push(message.text())
      }
    })

    for (const path of [
      '/hilos/daemon',
      '/hilos/daemon/agents',
      '/hilos/daemon/workers',
      '/hilos/daemon/cron',
      '/hilos/daemon/websockets',
    ]) {
      await page.goto(path)
      await expect(page.locator('main')).toBeVisible()
    }

    expect(errors).toEqual([])
  })

  test.fixme('keeps public HTTP access to /hilos blocked while SPA navigation works after handshake', async ({ page, request }) => {
    const res = await request.get('/hilos')
    expect([403, 404]).toContain(res.status())

    await page.goto('/')
    await page.getByTestId('nav-admin').click()

    await expect(page).toHaveURL('/hilos')
    await expect(page.getByRole('heading', { name: 'Hilos' })).toBeVisible()
  })
})
