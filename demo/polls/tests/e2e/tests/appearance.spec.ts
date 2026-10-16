// The Appearance section in the Angular kit (HIL-1441): the two theme settings
// in the standard table under the admin header, a window each to edit them, and
// what a save does to every open tab — Off hides the menu and wears the default,
// On brings the personal picks back, a new default moves whoever has not chosen.
// A viewer of the admin view mode opens both windows and moves their fields, and
// Save stays locked. The same scenarios as tasks' appearance.spec.ts, on the
// Angular shell, plus the narrow screen in both palettes from the keyboard.
import { test, expect, type Browser, type Page } from '@playwright/test'

import {
  clearCustomSetting,
  pickThemeBeforeLoad,
  shownByTestId,
  sidewaysOverflow,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'
import { signUp } from '../helpers/session.js'

const SWITCHING_KEY = 'theme.switching_enabled'
const DEFAULT_KEY = 'theme.default'
const PICK_KEY = 'hilos.theme.pick'
const APPEARANCE_PATH = '/hilos/appearance'
const NARROW = { width: 375, height: 812 }

/** A current value of the fixed two-row table, in the table or its narrow card. */
function currentValue(page: Page, key: string) {
  return shownByTestId(page, `appearance-setting-value-${key}`)
}

/**
 * Save the open window through its button and wait for the server's answer.
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

/**
 * Open a visitor tab whose browser already holds a theme pick.
 *
 * @param browser The test's browser.
 * @param pick The browser's pick, or null for none.
 */
async function visitorWithPick(
  browser: Browser,
  pick: 'light' | 'dark' | null,
): Promise<Page> {
  const context = await browser.newContext({ colorScheme: 'light' })
  if (pick !== null) await pickThemeBeforeLoad(context, pick)
  const page = await context.newPage()
  await gotoPage(page, '/', PAGE_READY)

  return page
}

test.afterEach(() => setAdminViewMode(false))

test('the Appearance card opens two typed settings a viewer may look at and not save', async ({
  page,
  browser,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos', PAGE_READY)
  await page.getByTestId('dashboard-card-hilos_appearance').click()
  await expectPageReady(page)
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Appearance')
  expect(new URL(page.url()).pathname).toBe(APPEARANCE_PATH)
  await expect(currentValue(page, SWITCHING_KEY)).toHaveText('On')
  await expect(currentValue(page, DEFAULT_KEY)).toHaveText('System')
  await expect(
    shownByTestId(page, `appearance-setting-default-${SWITCHING_KEY}`),
  ).toHaveText('On')
  await expect(
    shownByTestId(page, `appearance-setting-default-${DEFAULT_KEY}`),
  ).toHaveText('System')

  await setAdminViewMode(true)
  const viewerContext = await browser.newContext()
  try {
    const viewer = await viewerContext.newPage()
    let viewerLoads = 0
    viewer.on('load', () => {
      viewerLoads += 1
    })
    await gotoPage(viewer, APPEARANCE_PATH, PAGE_READY)
    await expect(viewer.getByTestId('view-mode-banner')).toBeVisible()
    await expect(currentValue(viewer, SWITCHING_KEY)).toHaveText('On')
    await expect(currentValue(viewer, DEFAULT_KEY)).toHaveText('System')

    await shownByTestId(
      viewer,
      `appearance-setting-edit-${SWITCHING_KEY}`,
    ).click()
    const switchingDialog = viewer.getByRole('dialog', {
      name: 'Edit · Theme switching',
    })
    await expect(switchingDialog).toBeVisible()
    const switching = viewer.getByTestId('appearance-switching-input')
    await expect(switching).toBeChecked()
    await expect(switching).toBeEnabled()
    await switching.uncheck()
    const switchingSave = viewer.getByTestId('appearance-switching-save')
    await expect(switchingSave).toBeDisabled()
    await expect(switchingSave).toHaveAttribute(
      'aria-describedby',
      /(^| )hilos-view-mode-strip-text( |$)/,
    )
    await switching.check()
    await viewer.getByTestId('appearance-switching-cancel').click()
    await expect(switchingDialog).toBeHidden()

    await shownByTestId(
      viewer,
      `appearance-setting-edit-${DEFAULT_KEY}`,
    ).click()
    const themeDialog = viewer.getByRole('dialog', {
      name: 'Edit · Default theme',
    })
    await expect(themeDialog).toBeVisible()
    const system = viewer.getByTestId('appearance-default-theme-system')
    const dark = viewer.getByTestId('appearance-default-theme-dark')
    await expect(system).toBeChecked()
    await dark.check()
    await expect(dark).toBeChecked()
    const themeSave = viewer.getByTestId('appearance-default-theme-save')
    await expect(themeSave).toBeDisabled()
    await expect(themeSave).toHaveAttribute(
      'aria-describedby',
      /(^| )hilos-view-mode-strip-text( |$)/,
    )
    await system.check()
    await viewer.getByTestId('appearance-default-theme-cancel').click()
    await expect(themeDialog).toBeHidden()
    const loadsBeforeChange = viewerLoads

    // The administrator's save reaches the viewer's open table without a reload.
    await shownByTestId(page, `appearance-setting-edit-${DEFAULT_KEY}`).click()
    await page.getByTestId('appearance-default-theme-dark').check()
    await saveWindow(
      page,
      'appearance-default-theme-save',
      'Default theme saved.',
    )
    await expect(currentValue(page, DEFAULT_KEY)).toHaveText('Dark')
    await expect(currentValue(viewer, DEFAULT_KEY)).toHaveText('Dark')
    expect(viewerLoads).toBe(loadsBeforeChange)
  } finally {
    try {
      await restoreSetting(page, DEFAULT_KEY)
    } finally {
      await viewerContext.close()
    }
  }
})

test('a new default theme reaches guests and accounts and leaves a personal Light alone', async ({
  browser,
}) => {
  const adminContext = await browser.newContext({ colorScheme: 'light' })
  const admin = await adminContext.newPage()
  const visitors: Page[] = []
  let adminReady = false
  try {
    await signUpAdmin(admin)
    await gotoPage(admin, APPEARANCE_PATH, PAGE_READY)
    await expect(currentValue(admin, DEFAULT_KEY)).toHaveText('System')
    adminReady = true

    const guest = await visitorWithPick(browser, null)
    const person = await visitorWithPick(browser, null)
    const lightGuest = await visitorWithPick(browser, 'light')
    visitors.push(guest, person, lightGuest)
    await signUp(person)
    const loads = new Map<Page, number>()
    for (const visitor of visitors) {
      loads.set(visitor, 0)
      visitor.on('load', () => {
        loads.set(visitor, (loads.get(visitor) ?? 0) + 1)
      })
      await expect(visitor.locator('html')).toHaveAttribute(
        'data-bs-theme',
        'light',
      )
    }

    await shownByTestId(admin, `appearance-setting-edit-${DEFAULT_KEY}`).click()
    await expect(
      admin.getByRole('dialog', { name: 'Edit · Default theme' }),
    ).toBeVisible()
    await expect(
      admin.getByTestId('appearance-default-theme-system'),
    ).toBeChecked()
    await expect(
      admin.getByTestId('appearance-default-theme-save'),
    ).toBeDisabled()
    await admin.getByTestId('appearance-default-theme-dark').check()
    await saveWindow(
      admin,
      'appearance-default-theme-save',
      'Default theme saved.',
    )
    await expect(currentValue(admin, DEFAULT_KEY)).toHaveText('Dark')

    for (const visitor of visitors) {
      await expect(visitor.locator('html')).toHaveAttribute(
        'data-bs-theme',
        visitor === lightGuest ? 'light' : 'dark',
      )
      expect(loads.get(visitor)).toBe(0)
    }
    expect(
      await lightGuest.evaluate((key) => localStorage.getItem(key), PICK_KEY),
    ).toBe('light')
  } finally {
    try {
      if (adminReady) await restoreSetting(admin, DEFAULT_KEY)
    } finally {
      await Promise.all([
        adminContext.close(),
        ...visitors.map((visitor) => visitor.context().close()),
      ])
    }
  }
})

test('Off hides the menu and wears the default, On brings the Dark picks back', async ({
  browser,
}) => {
  const adminContext = await browser.newContext({ colorScheme: 'light' })
  const admin = await adminContext.newPage()
  const visitors: Page[] = []
  let adminReady = false
  try {
    await signUpAdmin(admin)
    await gotoPage(admin, APPEARANCE_PATH, PAGE_READY)
    await expect(currentValue(admin, SWITCHING_KEY)).toHaveText('On')
    adminReady = true

    const guest = await visitorWithPick(browser, 'dark')
    const person = await visitorWithPick(browser, 'dark')
    visitors.push(guest, person)
    // Registration carries the browser's Dark pick into the account.
    await signUp(person)
    const loads = new Map<Page, number>()
    for (const visitor of visitors) {
      loads.set(visitor, 0)
      visitor.on('load', () => {
        loads.set(visitor, (loads.get(visitor) ?? 0) + 1)
      })
      await expect(visitor.locator('html')).toHaveAttribute(
        'data-bs-theme',
        'dark',
      )
      await expect(visitor.getByTestId('nav-theme')).toBeVisible()
    }

    for (const enabled of [false, true]) {
      await shownByTestId(
        admin,
        `appearance-setting-edit-${SWITCHING_KEY}`,
      ).click()
      await expect(
        admin.getByRole('dialog', { name: 'Edit · Theme switching' }),
      ).toBeVisible()
      const switching = admin.getByTestId('appearance-switching-input')
      await expect(switching).toBeChecked({ checked: !enabled })
      await expect(
        admin.getByTestId('appearance-switching-save'),
      ).toBeDisabled()
      await switching.setChecked(enabled)
      await saveWindow(
        admin,
        'appearance-switching-save',
        'Theme switching saved.',
      )
      await expect(currentValue(admin, SWITCHING_KEY)).toHaveText(
        enabled ? 'On' : 'Off',
      )

      for (const visitor of visitors) {
        await expect(visitor.locator('html')).toHaveAttribute(
          'data-bs-theme',
          enabled ? 'dark' : 'light',
        )
        await expect(visitor.getByTestId('nav-theme')).toHaveCount(
          enabled ? 1 : 0,
        )
        expect(
          await visitor.evaluate((key) => localStorage.getItem(key), PICK_KEY),
        ).toBe('dark')
        expect(loads.get(visitor)).toBe(0)
      }
    }
  } finally {
    try {
      if (adminReady) await restoreSetting(admin, SWITCHING_KEY)
    } finally {
      await Promise.all([
        adminContext.close(),
        ...visitors.map((visitor) => visitor.context().close()),
      ])
    }
  }
})

for (const palette of ['light', 'dark'] as const) {
  test(`at 375px in ${palette} the settings stand as cards and a window works from the keyboard`, async ({
    browser,
  }) => {
    const context = await browser.newContext({
      viewport: NARROW,
      colorScheme: 'light',
    })
    await pickThemeBeforeLoad(context, palette)
    try {
      const page = await context.newPage()
      await signUpAdmin(page)
      await gotoPage(page, APPEARANCE_PATH, PAGE_READY)
      await expect(page.locator('html')).toHaveAttribute(
        'data-bs-theme',
        palette,
      )
      await expect(page.getByTestId('hilos-table-cards')).toBeVisible()
      await expect(currentValue(page, SWITCHING_KEY)).toHaveText('On')
      await expect(currentValue(page, DEFAULT_KEY)).toHaveText('System')
      expect(await sidewaysOverflow(page)).toEqual([0, 0])

      const pencil = shownByTestId(
        page,
        `appearance-setting-edit-${SWITCHING_KEY}`,
      )
      await expect(pencil).toContainText('Edit')
      await pencil.focus()
      await page.keyboard.press('Enter')
      const dialog = page.getByRole('dialog', {
        name: 'Edit · Theme switching',
      })
      await expect(dialog).toBeVisible()
      const switching = page.getByTestId('appearance-switching-input')
      await expect(switching).toBeFocused()
      await page.keyboard.press('Space')
      await expect(switching).not.toBeChecked()
      await expect(page.getByTestId('appearance-switching-save')).toBeEnabled()
      expect(await sidewaysOverflow(page)).toEqual([0, 0])

      await page.keyboard.press('Escape')
      await expect(page.getByTestId('modal-confirm')).toContainText(
        'Discard changes?',
      )
      await page.getByTestId('modal-confirm-discard').click()
      await expect(dialog).toBeHidden()
      await expect(pencil).toBeFocused()
      await expect(currentValue(page, SWITCHING_KEY)).toHaveText('On')
    } finally {
      await context.close()
    }
  })
}
