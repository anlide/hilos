import { test, expect, type BrowserContext, type Page } from '@playwright/test'

import {
  signUp,
  APPEARANCE_PATH,
  THEME_DEFAULT_KEY,
  THEME_PICK_STORAGE_KEY,
  THEME_SWITCHING_KEY,
  appearanceSettingValue,
  chooseHeaderTheme,
  clearCustomSetting,
  countSentFrames,
  dismissToasts,
  emulateSystemTheme,
  expectAppearancePageHead,
  expectAppearanceRows,
  expectThemeHeader,
  expectThemeMode,
  openAppearanceSetting,
  saveAppearanceDefaultTheme,
  saveAppearanceSwitching,
  shownByTestId,
  expectPageReady,
  gotoPage,
  PAGE_READY,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'

/** Restore one theme setting through this installation's existing settings page. */
async function restoreSetting(page: Page, key: string): Promise<void> {
  await gotoPage(expect, page, '/hilos/settings', PAGE_READY)
  const search = page.getByTestId('hilos-table-search')
  await search.fill('')
  await search.pressSequentially(key, { delay: 10 })
  await expect(page.getByTestId(`hilos-table-row-${key}`)).toBeVisible()
  await clearCustomSetting(page, key)
}

test.afterEach(() => setAdminViewMode(false))

test('Appearance card, typed settings and live value remain visible to a viewer', async ({
  page,
  browser,
  baseURL,
}) => {
  await emulateSystemTheme(page, 'light')
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, '/hilos', PAGE_READY)
  await expect(
    page.getByTestId('dashboard-card-hilos_appearance'),
  ).toBeVisible()
  await page.getByTestId('dashboard-card-hilos_appearance').click()
  await expectPageReady(expect, page)
  expect(new URL(page.url()).pathname).toBe(APPEARANCE_PATH)
  await expectAppearanceRows(expect, page)
  await expectAppearancePageHead(expect, page)
  await expect(page).toHaveTitle('Appearance · BTC Tracker')
  await page.reload()
  await expectPageReady(expect, page)
  await expectAppearanceRows(expect, page)
  await expectAppearancePageHead(expect, page)
  await expect(page).toHaveTitle('Appearance · BTC Tracker')
  const hilosCrumb = page.getByTestId('hilos-breadcrumb-hilos')
  await hilosCrumb.scrollIntoViewIfNeeded()
  await expect(hilosCrumb).toBeVisible()
  await hilosCrumb.focus()
  await expect(hilosCrumb).toBeFocused()
  await page.keyboard.press('Enter')
  await expectPageReady(expect, page)
  expect(new URL(page.url()).pathname).toBe('/hilos')
  await expect(
    page.getByTestId('dashboard-card-hilos_appearance'),
  ).toBeVisible()
  await page.getByTestId('dashboard-card-hilos_appearance').click()
  await expectPageReady(expect, page)
  expect(new URL(page.url()).pathname).toBe(APPEARANCE_PATH)
  await expectAppearancePageHead(expect, page)

  const contexts: BrowserContext[] = []
  try {
    await setAdminViewMode(true)
    const viewerContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors: true,
      colorScheme: 'light',
    })
    contexts.push(viewerContext)
    const viewer = await viewerContext.newPage()
    let viewerLoads = 0
    viewer.on('load', () => {
      viewerLoads += 1
    })
    const switchingWrites = countSentFrames(
      viewer,
      'appearance_theme_switching_set',
    )
    const defaultWrites = countSentFrames(
      viewer,
      'appearance_default_theme_set',
    )
    await gotoPage(expect, viewer, APPEARANCE_PATH, PAGE_READY)
    await expectAppearanceRows(expect, viewer)
    await expectAppearancePageHead(expect, viewer)
    await expect(
      viewer.getByTestId(`hilos-settings-edit-${THEME_DEFAULT_KEY}`),
    ).toHaveCount(0)
    await openAppearanceSetting(expect, viewer, THEME_SWITCHING_KEY)
    const dialog = viewer.getByTestId('modal')
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
    await switching.press('Enter')
    await expect(dialog).toBeVisible()
    await expect(appearanceSettingValue(page, THEME_SWITCHING_KEY)).toHaveText(
      'On',
    )
    expect(switchingWrites()).toBe(0)
    await switching.check()
    await viewer.getByTestId('appearance-switching-cancel').click()
    await expect(dialog).toBeHidden()

    await openAppearanceSetting(expect, viewer, THEME_DEFAULT_KEY)
    const system = viewer.getByTestId('appearance-default-theme-system')
    const dark = viewer.getByTestId('appearance-default-theme-dark')
    for (const position of ['light', 'dark', 'system']) {
      await expect(
        viewer.getByTestId(`appearance-default-theme-${position}`),
      ).toBeEnabled()
    }
    await expect(system).toBeChecked()
    await dark.check()
    await expect(dark).toBeChecked()
    await expect(system).not.toBeChecked()
    const themeSave = viewer.getByTestId('appearance-default-theme-save')
    await expect(themeSave).toBeDisabled()
    await expect(themeSave).toHaveAttribute(
      'aria-describedby',
      /(^| )hilos-view-mode-strip-text( |$)/,
    )
    await expect(viewer.getByTestId('view-mode-banner')).toBeVisible()
    await expect(viewer.locator('#hilos-view-mode-strip-text')).toBeVisible()
    await dark.press('Enter')
    await expect(dialog).toBeVisible()
    await expect(appearanceSettingValue(page, THEME_DEFAULT_KEY)).toHaveText(
      'System',
    )
    expect(defaultWrites()).toBe(0)
    await system.check()
    await viewer.getByTestId('appearance-default-theme-cancel').click()
    await expect(dialog).toBeHidden()
    const loadsBeforeChange = viewerLoads

    await openAppearanceSetting(expect, page, THEME_DEFAULT_KEY)
    await page.getByTestId('appearance-default-theme-dark').check()
    await saveAppearanceDefaultTheme(expect, page, 'dark')
    await expectAppearanceRows(expect, viewer, true, 'dark')
    expect(viewerLoads).toBe(loadsBeforeChange)
    await restoreSetting(page, THEME_DEFAULT_KEY)
    await expectAppearanceRows(expect, viewer)
    expect(viewerLoads).toBe(loadsBeforeChange)
  } finally {
    try {
      await restoreSetting(page, THEME_DEFAULT_KEY)
    } finally {
      await Promise.all(contexts.map((context) => context.close()))
    }
  }
})

test('Appearance default theme reaches guests and accounts without overriding a personal Light choice', async ({
  browser,
  page: admin,
  baseURL,
}) => {
  // Two registrations plus three visitors and live setting delivery.
  test.slow()
  await emulateSystemTheme(admin, 'light')
  await signUpAdmin(expect, admin)
  const contexts: BrowserContext[] = []
  try {
    await gotoPage(expect, admin, APPEARANCE_PATH, PAGE_READY)
    await expectAppearanceRows(expect, admin)
    const visitors: Page[] = []
    const loads = new Map<Page, number>()
    for (let index = 0; index < 3; index += 1) {
      const context = await browser.newContext({
        baseURL,
        ignoreHTTPSErrors: true,
        colorScheme: 'light',
      })
      contexts.push(context)
      const visitor = await context.newPage()
      visitors.push(visitor)
      loads.set(visitor, 0)
      visitor.on('load', () => {
        loads.set(visitor, (loads.get(visitor) ?? 0) + 1)
      })
      await gotoPage(expect, visitor, '/', PAGE_READY)
    }
    const [, person, lightGuest] = visitors
    await chooseHeaderTheme(expect, lightGuest, 'light')
    await signUp(expect, person)
    for (const visitor of visitors) {
      await expectThemeMode(expect, visitor, 'light')
      expect(
        await visitor.evaluate(
          (key) => localStorage.getItem(key),
          THEME_PICK_STORAGE_KEY,
        ),
      ).toBe(visitor === lightGuest ? 'light' : null)
    }
    const loadsBeforeChange = new Map(loads)

    await openAppearanceSetting(expect, admin, THEME_DEFAULT_KEY)
    await expect(
      admin.getByTestId('appearance-default-theme-system'),
    ).toBeChecked()
    await expect(
      admin.getByTestId('appearance-default-theme-save'),
    ).toBeDisabled()
    await admin.getByTestId('appearance-default-theme-dark').check()
    await saveAppearanceDefaultTheme(expect, admin, 'dark')
    await expectAppearanceRows(expect, admin, true, 'dark')
    for (const visitor of visitors) {
      await expectThemeMode(
        expect,
        visitor,
        visitor === lightGuest ? 'light' : 'dark',
      )
      await expectThemeHeader(
        expect,
        visitor,
        visitor === lightGuest ? 'light' : 'dark',
        visitor !== lightGuest,
      )
      expect(loads.get(visitor)).toBe(loadsBeforeChange.get(visitor))
    }
    expect(
      await lightGuest.evaluate(
        (key) => localStorage.getItem(key),
        THEME_PICK_STORAGE_KEY,
      ),
    ).toBe('light')
  } finally {
    try {
      await restoreSetting(admin, THEME_DEFAULT_KEY)
    } finally {
      await Promise.all(contexts.map((context) => context.close()))
    }
  }
})

test('Appearance switching restores guest and account Dark choices without reload', async ({
  browser,
  page: admin,
  baseURL,
}) => {
  // Two registrations, two observers and both live switching transitions.
  test.slow()
  await emulateSystemTheme(admin, 'light')
  await signUpAdmin(expect, admin)
  const contexts: BrowserContext[] = []
  try {
    await gotoPage(expect, admin, APPEARANCE_PATH, PAGE_READY)
    await expectAppearanceRows(expect, admin)
    const visitors: Page[] = []
    const loads = new Map<Page, number>()
    for (let index = 0; index < 2; index += 1) {
      const context = await browser.newContext({
        baseURL,
        ignoreHTTPSErrors: true,
        colorScheme: 'light',
      })
      contexts.push(context)
      const visitor = await context.newPage()
      visitors.push(visitor)
      loads.set(visitor, 0)
      visitor.on('load', () => {
        loads.set(visitor, (loads.get(visitor) ?? 0) + 1)
      })
      await gotoPage(expect, visitor, '/', PAGE_READY)
      await chooseHeaderTheme(expect, visitor, 'dark')
    }
    const [, person] = visitors
    // Registration carries the real guest control's Dark pick into the account.
    await signUp(expect, person)
    for (const visitor of visitors) {
      await expectThemeMode(expect, visitor, 'dark')
      await expect(visitor.getByTestId('nav-theme')).toBeVisible()
    }
    const loadsBeforeChange = new Map(loads)
    for (const enabled of [false, true]) {
      await openAppearanceSetting(expect, admin, THEME_SWITCHING_KEY)
      const switching = admin.getByTestId('appearance-switching-input')
      await expect(switching).toBeChecked({ checked: !enabled })
      await expect(
        admin.getByTestId('appearance-switching-save'),
      ).toBeDisabled()
      await switching.setChecked(enabled)
      await saveAppearanceSwitching(expect, admin, enabled)
      await expectAppearanceRows(expect, admin, enabled)
      for (const visitor of visitors) {
        await expectThemeMode(expect, visitor, enabled ? 'dark' : 'light')
        if (enabled) {
          await expectThemeHeader(expect, visitor, 'dark')
        } else {
          await expect(visitor.getByTestId('nav-theme')).toHaveCount(0)
        }
        expect(
          await visitor.evaluate(
            (key) => localStorage.getItem(key),
            THEME_PICK_STORAGE_KEY,
          ),
        ).toBe('dark')
        expect(loads.get(visitor)).toBe(loadsBeforeChange.get(visitor))
      }
    }
  } finally {
    try {
      await restoreSetting(admin, THEME_SWITCHING_KEY)
    } finally {
      await Promise.all(contexts.map((context) => context.close()))
    }
  }
})

test('both Appearance windows support keyboard, focus and discard', async ({
  page,
}) => {
  await emulateSystemTheme(page, 'light')
  await gotoPage(expect, page, '/', PAGE_READY)
  await chooseHeaderTheme(expect, page, 'light')
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, APPEARANCE_PATH, PAGE_READY)
  await expectThemeMode(expect, page, 'light')
  await expectAppearanceRows(expect, page)
  await dismissToasts(page)

  const pencil = shownByTestId(
    page,
    `appearance-setting-edit-${THEME_SWITCHING_KEY}`,
  )
  await expect(pencil).toContainText('Edit')
  await pencil.focus()
  await pencil.press('Enter')
  const dialog = page.getByTestId('modal')
  await expect(dialog).toBeVisible()
  await expect(dialog).toHaveAccessibleName('Edit · Theme switching')
  const switching = page.getByTestId('appearance-switching-input')
  await expect(switching).toBeFocused()
  await switching.press('Space')
  await expect(switching).not.toBeChecked()
  await expect(page.getByTestId('appearance-switching-save')).toBeEnabled()
  await switching.press('Escape')
  await expect(page.getByTestId('modal-confirm')).toContainText(
    'Discard changes?',
  )
  await page.getByTestId('modal-confirm-discard').click()
  await expect(dialog).toBeHidden()
  await expect(pencil).toBeFocused()
  await expect(appearanceSettingValue(page, THEME_SWITCHING_KEY)).toHaveText(
    'On',
  )

  const themePencil = shownByTestId(
    page,
    `appearance-setting-edit-${THEME_DEFAULT_KEY}`,
  )
  await themePencil.focus()
  await themePencil.press('Enter')
  await expect(dialog).toBeVisible()
  await expect(dialog).toHaveAccessibleName('Edit · Default theme')
  const system = page.getByTestId('appearance-default-theme-system')
  await expect(system).toBeChecked()
  await system.focus()
  await system.press('ArrowLeft')
  await expect(page.getByTestId('appearance-default-theme-dark')).toBeChecked()
  await expect(page.getByTestId('appearance-default-theme-save')).toBeEnabled()
  await page.keyboard.press('Escape')
  await expect(page.getByTestId('modal-confirm')).toContainText(
    'Discard changes?',
  )
  await page.getByTestId('modal-confirm-discard').click()
  await expect(dialog).toBeHidden()
  await expect(themePencil).toBeFocused()
  await expectAppearanceRows(expect, page)
  await expectThemeMode(expect, page, 'light')
})
