import { test, expect } from '@playwright/test'

test.describe('Chat app smoke', () => {
  test('loads main page', async ({ page }) => {
    await page.goto('/')
    await expect(page).toHaveTitle(/Demo WebSocket Chat/)
  })
})
