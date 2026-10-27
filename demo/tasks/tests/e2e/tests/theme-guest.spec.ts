import { expect, test, type BrowserContext, type Page } from '@playwright/test'

import {
  clearCustomSetting,
  THEME_DEFAULT_KEY,
  THEME_PICK_STORAGE_KEY,
  chooseHeaderTheme,
  emulateSystemTheme,
  expectThemeHeader,
  expectThemeMode,
  setCustomSetting,
  expectPageReady,
  gotoPage,
  PAGE_READY,
  signUpAdmin,
  typeInto,
} from '../../../../../framework/frontend/e2e/index.js'

test('guest choices synchronize both ways and survive reload and a new tab', async ({
  context,
  page,
}) => {
  await emulateSystemTheme(page, 'light')
  await gotoPage(expect, page, '/', PAGE_READY)
  const other = await context.newPage()
  await emulateSystemTheme(other, 'light')
  await gotoPage(expect, other, '/', PAGE_READY)

  // The fresh browser follows the catalog default. Choosing that same row is
  // still a choice, and native Enter activation must close and return focus.
  const toggle = page.getByTestId('nav-theme')
  await expect(toggle).toHaveAttribute('data-position', 'system')
  await toggle.focus()
  await toggle.press('ArrowDown')
  await expect(page.getByTestId('nav-theme-option-light')).toBeFocused()
  await expect(
    page.getByTestId('nav-theme-option-system').locator('.badge'),
  ).toBeVisible()
  await page.getByTestId('nav-theme-option-light').press('End')
  await expect(page.getByTestId('nav-theme-default')).toBeFocused()
  await page.getByTestId('nav-theme-default').press('ArrowUp')
  await expect(page.getByTestId('nav-theme-option-system')).toBeFocused()
  await page.getByTestId('nav-theme-option-system').press('Enter')
  await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
  await expect(toggle).toBeFocused()
  expect(
    await page.evaluate(
      (key) => localStorage.getItem(key),
      THEME_PICK_STORAGE_KEY,
    ),
  ).toBe('system')
  await toggle.click()
  await expect(
    page.getByTestId('nav-theme-option-system').locator('.badge'),
  ).toHaveCount(0)
  await toggle.press('Escape')

  await chooseHeaderTheme(expect, page, 'dark')
  await expectThemeMode(expect, page, 'dark')
  await expectThemeHeader(expect, page, 'dark')
  await expectThemeMode(expect, other, 'dark')
  await expectThemeHeader(expect, other, 'dark')

  await chooseHeaderTheme(expect, other, 'light')
  await expectThemeMode(expect, other, 'light')
  await expectThemeHeader(expect, other, 'light')
  await expectThemeMode(expect, page, 'light')
  await expectThemeHeader(expect, page, 'light')

  await chooseHeaderTheme(expect, page, 'dark')
  expect(
    await page.evaluate(
      (key) => localStorage.getItem(key),
      THEME_PICK_STORAGE_KEY,
    ),
  ).toBe('dark')
  await page.reload()
  await expectPageReady(expect, page)
  await expectThemeMode(expect, page, 'dark')
  await expectThemeHeader(expect, page, 'dark')

  const newlyOpened = await context.newPage()
  await emulateSystemTheme(newlyOpened, 'light')
  await gotoPage(expect, newlyOpened, '/', PAGE_READY)
  await expectThemeMode(expect, newlyOpened, 'dark')
  await expectThemeHeader(expect, newlyOpened, 'dark')
})

test('guest reset clears both tabs and follows later site defaults across reload', async ({
  browser,
  context,
  page,
  baseURL,
}) => {
  const contexts: BrowserContext[] = []
  let admin: Page | undefined
  let adminReady = false
  try {
    const adminContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors: true,
      colorScheme: 'light',
      viewport: test.info().project.use.viewport,
    })
    contexts.push(adminContext)
    admin = await adminContext.newPage()
    await emulateSystemTheme(admin, 'light')
    const other = await context.newPage()
    await emulateSystemTheme(page, 'light')
    await emulateSystemTheme(other, 'dark')
    await gotoPage(expect, page, '/', PAGE_READY)
    await gotoPage(expect, other, '/', PAGE_READY)
    await chooseHeaderTheme(expect, page, 'dark')
    await expectThemeMode(expect, page, 'dark')
    await expectThemeHeader(expect, page, 'dark')
    await expectThemeMode(expect, other, 'dark')
    await expectThemeHeader(expect, other, 'dark')
    await chooseHeaderTheme(expect, page, null)
    await expectThemeMode(expect, page, 'light')
    await expectThemeHeader(expect, page, 'system', true)
    await expectThemeMode(expect, other, 'dark')
    await expectThemeHeader(expect, other, 'system', true)
    for (const tab of [page, other]) {
      expect(
        await tab.evaluate(
          (key) => localStorage.getItem(key),
          THEME_PICK_STORAGE_KEY,
        ),
      ).toBeNull()
    }

    await signUpAdmin(expect, admin)
    await gotoPage(expect, admin, '/hilos/settings', PAGE_READY)
    const search = admin.getByTestId('hilos-table-search')
    await typeInto(search, THEME_DEFAULT_KEY)
    await expect(
      admin.getByTestId(`hilos-table-row-${THEME_DEFAULT_KEY}`),
    ).toBeVisible()
    adminReady = true
    await setCustomSetting(admin, THEME_DEFAULT_KEY, 'dark')
    await expectThemeMode(expect, page, 'dark')
    await expectThemeHeader(expect, page, 'dark', true)
    await expectThemeMode(expect, other, 'dark')
    await expectThemeHeader(expect, other, 'dark', true)
    await page.reload()
    await expectPageReady(expect, page)
    await expectThemeMode(expect, page, 'dark')
    await expectThemeHeader(expect, page, 'dark', true)
    await chooseHeaderTheme(expect, other, null)
    await expectThemeMode(expect, page, 'dark')
    await expectThemeHeader(expect, page, 'dark', true)
    await expectThemeMode(expect, other, 'dark')
    await expectThemeHeader(expect, other, 'dark', true)
    await setCustomSetting(admin, THEME_DEFAULT_KEY, 'light')
    await expectThemeMode(expect, page, 'light')
    await expectThemeHeader(expect, page, 'light', true)
    await expectThemeMode(expect, other, 'light')
    await expectThemeHeader(expect, other, 'light', true)
    for (const tab of [page, other]) {
      expect(
        await tab.evaluate(
          (key) => localStorage.getItem(key),
          THEME_PICK_STORAGE_KEY,
        ),
      ).toBeNull()
    }
  } finally {
    try {
      if (adminReady && admin)
        await clearCustomSetting(admin, THEME_DEFAULT_KEY)
    } finally {
      await Promise.all(contexts.map((context) => context.close()))
    }
  }
})

test('System follows each guest tab media while explicit choices ignore it', async ({
  context,
  page,
}) => {
  await emulateSystemTheme(page, 'light')
  await gotoPage(expect, page, '/', PAGE_READY)
  const other = await context.newPage()
  await emulateSystemTheme(other, 'dark')
  await gotoPage(expect, other, '/', PAGE_READY)

  await chooseHeaderTheme(expect, page, 'system')
  await expectThemeMode(expect, page, 'light')
  await expectThemeHeader(expect, page, 'system')
  await expectThemeMode(expect, other, 'dark')
  await expectThemeHeader(expect, other, 'system')
  await emulateSystemTheme(page, 'dark')
  await expectThemeMode(expect, page, 'dark')
  await expectThemeHeader(expect, page, 'system')
  await emulateSystemTheme(page, 'light')
  await expectThemeMode(expect, page, 'light')
  await expectThemeHeader(expect, page, 'system')
  await expectThemeMode(expect, other, 'dark')
  await expectThemeHeader(expect, other, 'system')

  await chooseHeaderTheme(expect, page, 'dark')
  await emulateSystemTheme(page, 'dark')
  await emulateSystemTheme(page, 'light')
  await expectThemeMode(expect, page, 'dark')
  await expectThemeHeader(expect, page, 'dark')
  await expectThemeMode(expect, other, 'dark')
  await expectThemeHeader(expect, other, 'dark')

  await chooseHeaderTheme(expect, other, 'light')
  await emulateSystemTheme(other, 'light')
  await emulateSystemTheme(other, 'dark')
  await expectThemeMode(expect, other, 'light')
  await expectThemeHeader(expect, other, 'light')
  await expectThemeMode(expect, page, 'light')
  await expectThemeHeader(expect, page, 'light')
})

test('a guest can choose and reset from the header menu', async ({ page }) => {
  await gotoPage(expect, page, '/', PAGE_READY)
  await page.getByTestId('nav-theme').click()
  await expect(page.getByTestId('nav-theme-menu')).toBeVisible()
  await page.getByTestId('nav-theme-option-dark').click()
  await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
  await expectThemeMode(expect, page, 'dark')
  await expectThemeHeader(expect, page, 'dark')
  const toggle = page.getByTestId('nav-theme')
  await toggle.press('ArrowUp')
  const reset = page.getByTestId('nav-theme-default')
  await expect(reset).toBeFocused()
  await expect(reset).toBeVisible()
  await expect(reset).toBeEnabled()
  await expect(reset).toHaveRole('menuitem')
  await expect(reset).not.toHaveAttribute('aria-checked')
  await reset.press('Enter')
  await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
  await expect(toggle).toBeFocused()
  await expectThemeMode(expect, page, 'light')
  await expectThemeHeader(expect, page, 'system', true)
})
