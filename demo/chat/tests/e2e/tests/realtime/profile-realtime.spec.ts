import { test, expect } from '@playwright/test'

test.describe('Profile realtime @realtime', () => {
  test.fixme('syncs a user-initiated rename across two tabs of the same session', async ({ browser }) => {
    const context = await browser.newContext()
    const firstTab = await context.newPage()
    const secondTab = await context.newPage()

    await firstTab.goto('/profile')
    await secondTab.goto('/profile')

    await firstTab.getByRole('button', { name: 'Edit' }).click()
    await firstTab.locator('#username-input').fill('Synced User')
    await firstTab.getByRole('button', { name: 'Save' }).click()

    await expect(secondTab.getByText('Synced User')).toBeVisible()
  })

  test.fixme('shows a rename conflict when two tabs edit the profile form concurrently', async ({ browser }) => {
    const context = await browser.newContext()
    const firstTab = await context.newPage()
    const secondTab = await context.newPage()

    await firstTab.goto('/profile')
    await secondTab.goto('/profile')

    await firstTab.getByRole('button', { name: 'Edit' }).click()
    await firstTab.locator('#username-input').fill('Mine')

    await secondTab.getByRole('button', { name: 'Edit' }).click()
    await secondTab.locator('#username-input').fill('Theirs')
    await secondTab.getByRole('button', { name: 'Save' }).click()

    await expect(firstTab.getByText(/conflict/i)).toBeVisible()
    await expect(firstTab.getByRole('button', { name: /Accept mine/i })).toBeVisible()
    await expect(firstTab.getByRole('button', { name: /Accept theirs/i })).toBeVisible()
  })
})
