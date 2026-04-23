import { test, expect } from '@playwright/test'

test.describe('Chat app navigation', () => {
  test('navigates to Home, Profile, Hilos', async ({ page }) => {
    await page.goto('/')

    await expect(page).toHaveURL('/')
    await expect(page.getByTestId('nav-brand')).toHaveClass(/fw-bold/)
    await page.getByTestId('nav-brand').click()
    await expect(page).toHaveURL('/')

    await page.getByTestId('nav-profile').click()
    await expect(page).toHaveURL('/profile')
    await expect(page.getByTestId('nav-profile')).toHaveClass(/fw-bold/)

    await page.getByTestId('nav-admin').click()
    await expect(page).toHaveURL('/hilos')
    await expect(page.getByTestId('nav-admin')).toHaveClass(/fw-bold/)
  })
})
