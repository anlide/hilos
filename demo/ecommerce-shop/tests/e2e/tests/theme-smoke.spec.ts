import { expect, test } from '@playwright/test'

import {
  APPEARANCE_PATH,
  THEME_PICK_STORAGE_KEY,
  chooseHeaderTheme,
  emulateSystemTheme,
  expectAppearanceRows,
  expectThemeHeader,
  expectThemeMode,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'
import { signUp } from '../helpers/session.js'

// spec-owner: demo — proves the shop shell mounts theme controls and Appearance.

// Full guest, account and Appearance behavior is covered by the tasks kit specs.
test.afterEach(() => setAdminViewMode(false))

test('a guest picks Light, Dark and System in the header and returns to the default', async ({
  page,
}) => {
  await emulateSystemTheme(page, 'dark')
  await gotoPage(page, '/', PAGE_READY)
  await expectThemeMode(expect, page, 'dark')
  await expectThemeHeader(expect, page, 'system', true)

  await chooseHeaderTheme(expect, page, 'light')
  await expectThemeMode(expect, page, 'light')
  await expectThemeHeader(expect, page, 'light')
  await chooseHeaderTheme(expect, page, 'dark')
  await expectThemeMode(expect, page, 'dark')
  await expectThemeHeader(expect, page, 'dark')
  await emulateSystemTheme(page, 'light')
  await expectThemeMode(expect, page, 'dark')
  await chooseHeaderTheme(expect, page, 'system')
  await expectThemeMode(expect, page, 'light')
  await expectThemeHeader(expect, page, 'system')
  expect(
    await page.evaluate(
      (key) => localStorage.getItem(key),
      THEME_PICK_STORAGE_KEY,
    ),
  ).toBe('system')

  await chooseHeaderTheme(expect, page, null)
  await expectThemeMode(expect, page, 'light')
  await expectThemeHeader(expect, page, 'system', true)
  expect(
    await page.evaluate(
      (key) => localStorage.getItem(key),
      THEME_PICK_STORAGE_KEY,
    ),
  ).toBeNull()
})

test('an account keeps its header pick across a reload and returns to the default', async ({
  page,
}) => {
  await emulateSystemTheme(page, 'light')
  await signUp(page)
  await expectThemeMode(expect, page, 'light')
  await expectThemeHeader(expect, page, 'system', true)

  await chooseHeaderTheme(expect, page, 'dark')
  await expectThemeMode(expect, page, 'dark')
  await expectThemeHeader(expect, page, 'dark')
  await page.reload()
  await expectPageReady(page)
  await expectThemeMode(expect, page, 'dark')
  await expectThemeHeader(expect, page, 'dark')

  await chooseHeaderTheme(expect, page, null)
  await expectThemeMode(expect, page, 'light')
  await expectThemeHeader(expect, page, 'system', true)
  await page.reload()
  await expectPageReady(page)
  await expectThemeMode(expect, page, 'light')
  await expectThemeHeader(expect, page, 'system', true)
})

test('the Appearance card opens the mounted section with both live settings rows', async ({
  page,
}) => {
  await emulateSystemTheme(page, 'light')
  await grantAdminToSelf(page)
  await gotoPage(page, '/hilos', PAGE_READY)
  const card = page.getByTestId('dashboard-card-hilos_appearance')
  await expect(card).toBeVisible()
  await card.click()
  await expectPageReady(page)
  expect(new URL(page.url()).pathname).toBe(APPEARANCE_PATH)
  await expectAppearanceRows(expect, page)
  await expectThemeMode(expect, page, 'light')
  await expectThemeHeader(expect, page, 'system', true)
})
