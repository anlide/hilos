import { test, expect } from '@playwright/test'

test.describe('Profile', () => {
  test.fixme('renames the current user from the profile modal', async ({ page }) => {
    await page.goto('/profile')

    await expect(page.getByRole('heading', { name: 'Profile' })).toBeVisible()
    await page.getByRole('button', { name: 'Edit' }).click()

    await expect(page.getByRole('dialog', { name: 'Change Username' })).toBeVisible()
    await page.locator('#username-input').fill('Playwright User')
    await page.getByRole('button', { name: 'Save' }).click()

    await expect(page.getByRole('dialog', { name: 'Change Username' })).toBeHidden()
    await expect(page.getByText('Playwright User')).toBeVisible()
  })

  test.fixme('keeps save disabled for names outside the user length contract', async ({ page }) => {
    await page.goto('/profile')

    await page.getByRole('button', { name: 'Edit' }).click()
    await page.locator('#username-input').fill('A')
    await expect(page.getByRole('button', { name: 'Save' })).toBeDisabled()

    await page.locator('#username-input').fill('A name that is too long')
    await expect(page.getByRole('button', { name: 'Save' })).toBeDisabled()
  })

  test.fixme('shows rename failures in the modal without losing the entered name', async ({ page }) => {
    await page.goto('/profile')

    // TODO: seed or trigger a deterministic backend rename failure.
    await page.getByRole('button', { name: 'Edit' }).click()
    await page.locator('#username-input').fill('Rejected Name')
    await page.getByRole('button', { name: 'Save' }).click()

    await expect(page.getByRole('alert')).toBeVisible()
    await expect(page.locator('#username-input')).toHaveValue('Rejected Name')
  })

  test.fixme('cancels a dirty rename form without changing the displayed user name', async ({ page }) => {
    await page.goto('/profile')

    const currentName = await page.locator('strong.fs-5').textContent()

    await page.getByRole('button', { name: 'Edit' }).click()
    await page.locator('#username-input').fill('Discarded Name')
    await page.getByRole('button', { name: 'Cancel' }).click()

    await expect(page.locator('strong.fs-5')).toHaveText(currentName ?? 'User')
  })
})
