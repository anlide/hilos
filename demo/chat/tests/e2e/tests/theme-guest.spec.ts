import { expect, test, type Page } from '@playwright/test'

import {
  openFirstFrame,
  sidewaysOverflow,
} from '../../../../../framework/frontend/e2e/index.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'

const PICK_KEY = 'hilos.theme.pick'
const DARK_FRAME = { theme: 'dark', background: 'rgb(33, 37, 41)' }
const ICONS = {
  light: 'bi-sun',
  dark: 'bi-moon-stars',
  system: 'bi-circle-half',
} as const
type Position = keyof typeof ICONS

/** Choose through the header and wait for the menu to close and return focus. */
async function choose(page: Page, position: Position): Promise<void> {
  const toggle = page.getByTestId('nav-theme')
  await toggle.click()
  await expect(page.getByTestId('nav-theme-menu')).toBeVisible()
  await page.getByTestId(`nav-theme-option-${position}`).click()
  await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
  await expect(toggle).toBeFocused()
}

/** Check the worn mode separately from the position the icon and rows mark. */
async function expectTheme(
  page: Page,
  position: Position,
  mode: 'light' | 'dark',
): Promise<void> {
  await expect(page.locator('html')).toHaveAttribute('data-bs-theme', mode)
  const toggle = page.getByTestId('nav-theme')
  await expect(toggle).toHaveAttribute('data-position', position)
  await expect(toggle.locator('i')).toHaveClass(`bi ${ICONS[position]}`)
  await toggle.click()
  await expect(page.getByTestId('nav-theme-menu')).toBeVisible()
  for (const row of ['light', 'dark', 'system'] as const) {
    const option = page.getByTestId(`nav-theme-option-${row}`)
    await expect(option).toHaveAttribute(
      'aria-checked',
      String(row === position),
    )
    await expect(option.locator('.bi-check2')).toHaveCount(
      row === position ? 1 : 0,
    )
    if (row === position) {
      await expect(option).toHaveClass(/\bactive\b/)
    } else {
      await expect(option).not.toHaveClass(/\bactive\b/)
    }
  }
  await toggle.press('Escape')
  await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
}

test('guest choices synchronize both ways and survive reload from the first frame', async ({
  context,
  page,
}) => {
  await page.emulateMedia({ colorScheme: 'light' })
  await gotoPage(page, '/', PAGE_READY)
  const other = await context.newPage()
  await other.emulateMedia({ colorScheme: 'light' })
  await gotoPage(other, '/', PAGE_READY)

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
  await expect(page.getByTestId('nav-theme-option-system')).toBeFocused()
  await page.getByTestId('nav-theme-option-system').press('Enter')
  await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
  await expect(toggle).toBeFocused()
  expect(
    await page.evaluate((key) => localStorage.getItem(key), PICK_KEY),
  ).toBe('system')
  await toggle.click()
  await expect(
    page.getByTestId('nav-theme-option-system').locator('.badge'),
  ).toHaveCount(0)
  await toggle.press('Escape')

  await choose(page, 'dark')
  await expectTheme(page, 'dark', 'dark')
  await expectTheme(other, 'dark', 'dark')

  await choose(other, 'light')
  await expectTheme(other, 'light', 'light')
  await expectTheme(page, 'light', 'light')

  await choose(page, 'dark')
  expect(
    await page.evaluate((key) => localStorage.getItem(key), PICK_KEY),
  ).toBe('dark')
  await page.reload()
  await expectPageReady(page)
  await expectTheme(page, 'dark', 'dark')

  // This blocks application bundles and measures both the head attribute and
  // painted background, including the prerendered About document.
  expect(await openFirstFrame(page, '/')).toEqual(DARK_FRAME)
  expect(await openFirstFrame(page, '/about')).toEqual(DARK_FRAME)
  await gotoPage(page, '/', PAGE_READY)
  await expectTheme(page, 'dark', 'dark')

  const newlyOpened = await context.newPage()
  await newlyOpened.emulateMedia({ colorScheme: 'light' })
  await gotoPage(newlyOpened, '/', PAGE_READY)
  await expectTheme(newlyOpened, 'dark', 'dark')
})

test('System follows each guest tab media while explicit choices ignore it', async ({
  context,
  page,
}) => {
  await page.emulateMedia({ colorScheme: 'light' })
  await gotoPage(page, '/', PAGE_READY)
  const other = await context.newPage()
  await other.emulateMedia({ colorScheme: 'dark' })
  await gotoPage(other, '/', PAGE_READY)

  await choose(page, 'system')
  await expectTheme(page, 'system', 'light')
  await expectTheme(other, 'system', 'dark')
  await page.emulateMedia({ colorScheme: 'dark' })
  await expectTheme(page, 'system', 'dark')
  await page.emulateMedia({ colorScheme: 'light' })
  await expectTheme(page, 'system', 'light')
  await expectTheme(other, 'system', 'dark')

  await choose(page, 'dark')
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.emulateMedia({ colorScheme: 'light' })
  await expectTheme(page, 'dark', 'dark')
  await expectTheme(other, 'dark', 'dark')

  await choose(other, 'light')
  await other.emulateMedia({ colorScheme: 'light' })
  await other.emulateMedia({ colorScheme: 'dark' })
  await expectTheme(other, 'light', 'light')
  await expectTheme(page, 'light', 'light')
})

test('a guest can choose a theme at 375px without sideways overflow', async ({
  page,
}) => {
  await page.setViewportSize({ width: 375, height: 812 })
  await gotoPage(page, '/', PAGE_READY)
  await page.getByTestId('nav-theme').click()
  await expect(page.getByTestId('nav-theme-menu')).toBeVisible()
  expect(await sidewaysOverflow(page)).toEqual([0, 0])
  await page.getByTestId('nav-theme-option-dark').click()
  await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
  await expectTheme(page, 'dark', 'dark')
  expect(await sidewaysOverflow(page)).toEqual([0, 0])
})
