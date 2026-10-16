// The theme on this second React demo (HIL-1440), as a smoke over what tasks
// covers in depth: the header menu of a guest and of an account — Light, Dark,
// System and back to the site default — and the Appearance section opened for
// real, both of its writes reaching the header, and a viewer of the admin view
// mode who may open both windows and not save. The profile is not switched on
// here, so its Theme row is tasks' alone.
import { test, expect, type Page } from '@playwright/test'

import {
  clearCustomSetting,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'
import { signUp } from '../helpers/session.js'

const SWITCHING_KEY = 'theme.switching_enabled'
const DEFAULT_KEY = 'theme.default'
const PICK_KEY = 'hilos.theme.pick'
const APPEARANCE_PATH = '/hilos/appearance'

type Position = 'light' | 'dark' | 'system'

/**
 * Choose a position through the header menu, or the site default with null.
 *
 * @param page The page whose header is used.
 * @param position The position to choose, or null for the default.
 */
async function choose(page: Page, position: Position | null): Promise<void> {
  const toggle = page.getByTestId('nav-theme')
  await toggle.click()
  await expect(page.getByTestId('nav-theme-menu')).toBeVisible()
  await page
    .getByTestId(
      position === null ? 'nav-theme-default' : `nav-theme-option-${position}`,
    )
    .click()
  await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
}

/**
 * Check the mode the page wears and the position its header icon shows.
 *
 * @param page The page to read.
 * @param position The position the icon marks.
 * @param mode The mode the document wears.
 */
async function expectTheme(
  page: Page,
  position: Position,
  mode: 'light' | 'dark',
): Promise<void> {
  await expect(page.locator('html')).toHaveAttribute('data-bs-theme', mode)
  await expect(page.getByTestId('nav-theme')).toHaveAttribute(
    'data-position',
    position,
  )
}

/** A current value of the fixed two-row table, in the table or its narrow card. */
function currentValue(page: Page, key: string) {
  return shownByTestId(page, `appearance-setting-value-${key}`)
}

/**
 * Save the open Appearance window and wait for the server's answer.
 *
 * @param page The administrator's page with the window open.
 * @param saveId The data-id of the window's Save button.
 * @param sentence The server's success sentence the toast carries.
 */
async function saveWindow(
  page: Page,
  saveId: string,
  sentence: string,
): Promise<void> {
  const save = page.getByTestId(saveId)
  await save.scrollIntoViewIfNeeded()
  await expect(save).toBeVisible()
  await expect(save).toBeEnabled()
  await save.focus()
  await save.click()
  await expect(page.getByTestId('modal')).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-toasts').getByText(sentence),
  ).toBeVisible()
}

/**
 * Put one theme setting back on its catalog default through the settings table.
 *
 * @param page A page of a signed-in administrator.
 * @param key The setting to clear.
 */
async function restoreSetting(page: Page, key: string): Promise<void> {
  await gotoPage(page, '/hilos/settings', PAGE_READY)
  const search = page.getByTestId('hilos-table-search')
  await search.fill('')
  await search.pressSequentially(key, { delay: 10 })
  await expect(page.getByTestId(`hilos-table-row-${key}`)).toBeVisible()
  await clearCustomSetting(page, key)
}

test.afterEach(() => setAdminViewMode(false))

test('a guest picks Light, Dark and System in the header and returns to the default', async ({
  page,
}) => {
  await page.emulateMedia({ colorScheme: 'dark' })
  await gotoPage(page, '/', PAGE_READY)
  await expectTheme(page, 'system', 'dark')

  await choose(page, 'light')
  await expectTheme(page, 'light', 'light')
  await choose(page, 'dark')
  await expectTheme(page, 'dark', 'dark')
  await page.emulateMedia({ colorScheme: 'light' })
  await expectTheme(page, 'dark', 'dark')
  await choose(page, 'system')
  await expectTheme(page, 'system', 'light')
  expect(
    await page.evaluate((key) => localStorage.getItem(key), PICK_KEY),
  ).toBe('system')

  await choose(page, null)
  await expectTheme(page, 'system', 'light')
  expect(
    await page.evaluate((key) => localStorage.getItem(key), PICK_KEY),
  ).toBeNull()
})

test('an account keeps its header pick across a reload and returns to the default', async ({
  page,
}) => {
  await page.emulateMedia({ colorScheme: 'light' })
  await signUp(page)
  await expectTheme(page, 'system', 'light')

  await choose(page, 'dark')
  await expectTheme(page, 'dark', 'dark')
  await page.reload()
  await expectPageReady(page)
  await expectTheme(page, 'dark', 'dark')

  await choose(page, null)
  await expectTheme(page, 'system', 'light')
  await page.reload()
  await expectPageReady(page)
  await expectTheme(page, 'system', 'light')
})

test('Appearance opens, both of its writes reach the header, and a viewer may not save', async ({
  page,
  browser,
}) => {
  await page.emulateMedia({ colorScheme: 'light' })
  await grantAdminToSelf(page)
  let switchingSaved = false
  let defaultSaved = false
  const viewerContext = await browser.newContext()
  try {
    await gotoPage(page, '/hilos', PAGE_READY)
    await page.getByTestId('dashboard-card-hilos_appearance').click()
    await expectPageReady(page)
    await expect(page.getByTestId('hilos-admin-title')).toHaveText('Appearance')
    expect(new URL(page.url()).pathname).toBe(APPEARANCE_PATH)
    await expect(currentValue(page, SWITCHING_KEY)).toHaveText('On')
    await expect(currentValue(page, DEFAULT_KEY)).toHaveText('System')
    await expect(page.getByTestId('nav-theme')).toBeVisible()

    await shownByTestId(
      page,
      `appearance-setting-edit-${SWITCHING_KEY}`,
    ).click()
    await page.getByTestId('appearance-switching-input').uncheck()
    switchingSaved = true
    await saveWindow(
      page,
      'appearance-switching-save',
      'Theme switching saved.',
    )
    await expect(currentValue(page, SWITCHING_KEY)).toHaveText('Off')
    await expect(page.getByTestId('nav-theme')).toHaveCount(0)

    await shownByTestId(
      page,
      `appearance-setting-edit-${SWITCHING_KEY}`,
    ).click()
    await page.getByTestId('appearance-switching-input').check()
    await saveWindow(
      page,
      'appearance-switching-save',
      'Theme switching saved.',
    )
    await expect(currentValue(page, SWITCHING_KEY)).toHaveText('On')
    await expect(page.getByTestId('nav-theme')).toBeVisible()

    await shownByTestId(page, `appearance-setting-edit-${DEFAULT_KEY}`).click()
    await page.getByTestId('appearance-default-theme-dark').check()
    defaultSaved = true
    await saveWindow(
      page,
      'appearance-default-theme-save',
      'Default theme saved.',
    )
    await expect(currentValue(page, DEFAULT_KEY)).toHaveText('Dark')
    await expectTheme(page, 'dark', 'dark')

    await setAdminViewMode(true)
    const viewer = await viewerContext.newPage()
    await gotoPage(viewer, APPEARANCE_PATH, PAGE_READY)
    await expect(viewer.getByTestId('view-mode-banner')).toBeVisible()
    await expect(currentValue(viewer, DEFAULT_KEY)).toHaveText('Dark')
    for (const [key, saveId, field] of [
      [
        SWITCHING_KEY,
        'appearance-switching-save',
        'appearance-switching-input',
      ],
      [
        DEFAULT_KEY,
        'appearance-default-theme-save',
        'appearance-default-theme-light',
      ],
    ] as const) {
      await shownByTestId(viewer, `appearance-setting-edit-${key}`).click()
      await expect(viewer.getByTestId(field)).toBeEnabled()
      await viewer.getByTestId(field).click()
      const save = viewer.getByTestId(saveId)
      await expect(save).toBeDisabled()
      await expect(save).toHaveAttribute(
        'aria-describedby',
        /(^| )hilos-view-mode-strip-text( |$)/,
      )
      await viewer.keyboard.press('Escape')
      await viewer.getByTestId('modal-confirm-discard').click()
      await expect(viewer.getByTestId('modal')).toHaveCount(0)
    }
    await expect(currentValue(viewer, SWITCHING_KEY)).toHaveText('On')
    await expect(currentValue(viewer, DEFAULT_KEY)).toHaveText('Dark')
  } finally {
    try {
      if (defaultSaved) await restoreSetting(page, DEFAULT_KEY)
      if (switchingSaved) await restoreSetting(page, SWITCHING_KEY)
    } finally {
      await viewerContext.close()
    }
  }
})
