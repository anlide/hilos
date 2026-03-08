import { test, expect } from '@playwright/test'

test.describe('Chat app navigation', () => {
  test('navigates to Home, Profile, Admin', async ({ page }) => {
    await page.goto('/')

    await page.getByTestId('nav-home').click()
    await expect(page).toHaveURL('/')
    await expect(page.getByTestId('nav-home')).toHaveClass(/fw-bold/)

    await page.getByTestId('nav-profile').click()
    await expect(page).toHaveURL('/profile')
    await expect(page.getByTestId('nav-profile')).toHaveClass(/fw-bold/)

    await page.getByTestId('nav-admin').click()
    await expect(page).toHaveURL('/admin')
    await expect(page.getByTestId('nav-admin')).toHaveClass(/fw-bold/)
  })
})
