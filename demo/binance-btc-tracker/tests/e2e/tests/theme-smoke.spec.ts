import { expect, test } from '@playwright/test'

import {
  signUp,
  THEME_PICK_STORAGE_KEY,
  chooseHeaderTheme,
  emulateSystemTheme,
  expectThemeHeader,
  expectThemeMode,
  expectPageReady,
} from '../../../../../framework/frontend/e2e/index.js'

// spec-owner: demo — proves the BTC Tracker shell activates the shared account theme menu.

test('the signed-in BTC Tracker header saves a theme, keeps it on reload and resets', async ({
  page,
}) => {
  await emulateSystemTheme(page, 'light')
  await signUp(expect, page)
  await chooseHeaderTheme(expect, page, 'dark')
  await expectThemeHeader(expect, page, 'dark')
  await expectThemeMode(expect, page, 'dark')

  await page.reload()
  await expectPageReady(expect, page)
  await expect(page.getByTestId('nav-profile-name')).toBeVisible()
  await expectThemeHeader(expect, page, 'dark')
  await expectThemeMode(expect, page, 'dark')

  await chooseHeaderTheme(expect, page, null)
  await expectThemeHeader(expect, page, 'system', true)
  await expectThemeMode(expect, page, 'light')
  expect(
    await page.evaluate(
      (key) => localStorage.getItem(key),
      THEME_PICK_STORAGE_KEY,
    ),
  ).toBeNull()
  await page.reload()
  await expectPageReady(expect, page)
  await expectThemeHeader(expect, page, 'system', true)
  await expectThemeMode(expect, page, 'light')
})
