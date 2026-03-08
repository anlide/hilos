import { test, expect } from '@playwright/test'

test.describe('Chat app WebSocket connection', () => {
  test('shows online after WebSocket connects', async ({ page }) => {
    await page.goto('/')

    // Wait for WebSocket connection (offline badge disappears)
    await expect(page.getByTestId('nav-offline')).toBeHidden({ timeout: 20000 })
  })
})
