import { expect, test } from '@playwright/test'

import { expectPageReady } from '../helpers/page.js'
import { signUp } from '../helpers/session.js'

// spec-owner: demo — proves the BTC Tracker shell activates the shared account theme menu.

test('the signed-in BTC Tracker header saves a theme and keeps it on reload', async ({
  page,
}) => {
  await page.emulateMedia({ colorScheme: 'light' })
  await signUp(page)
  const toggle = page.getByTestId('nav-theme')
  await toggle.scrollIntoViewIfNeeded()
  await expect(toggle).toBeVisible()
  await expect(toggle).toBeEnabled()
  await toggle.focus()
  await toggle.click()
  await expect(page.getByTestId('nav-theme-menu')).toBeVisible()
  const dark = page.getByTestId('nav-theme-option-dark')
  await dark.scrollIntoViewIfNeeded()
  await expect(dark).toBeVisible()
  await expect(dark).toBeEnabled()
  await dark.focus()
  await dark.click()
  await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
  await expect(toggle).toBeEnabled()
  await expect(toggle).toBeFocused()
  await expect(toggle).toHaveAttribute('data-position', 'dark')
  await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'dark')

  await page.reload()
  await expectPageReady(page)
  await expect(page.getByTestId('nav-profile-name')).toBeVisible()
  await expect(toggle).toHaveAttribute('data-position', 'dark')
  await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'dark')
  await expect(toggle).toBeEnabled()
  await toggle.focus()
  await toggle.click()
  await expect(dark).toHaveAttribute('aria-checked', 'true')
  await expect(dark.locator('.badge')).toHaveCount(0)
  await toggle.press('Escape')
  await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
})
