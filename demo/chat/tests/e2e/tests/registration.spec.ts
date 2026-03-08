import { test, expect } from '@playwright/test'

test.describe('Chat app registration', () => {
  test('new session registers and user appears in participants', async ({ page }) => {
    // Use fresh context (Playwright default) so localStorage has no session
    await page.goto('/')

    // Wait for WebSocket connection
    await expect(page.getByTestId('nav-offline')).toBeHidden({ timeout: 20000 })

    // User should appear in participants list (we are the new user)
    await expect(page.getByTestId('online-users-label')).toContainText('1 online', { timeout: 15000 })
    await expect(page.getByTestId('online-user').first()).toBeVisible({ timeout: 5000 })
  })
})
