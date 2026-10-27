import { expect, test, type BrowserContext, type Page } from '@playwright/test'

import {
  login,
  logout,
  signUp,
  clearCustomSetting,
  THEME_DEFAULT_KEY,
  THEME_PICK_STORAGE_KEY,
  chooseHeaderTheme,
  chooseProfileTheme,
  emulateSystemTheme,
  expectProfileTheme,
  expectThemeHeader,
  expectThemeMode,
  isSessionCookie,
  openProfileTheme,
  setCustomSetting,
  clickSubmit,
  expectPageReady,
  gotoPage,
  PAGE_READY,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'

test('registration keeps the guest pick and independent sessions deliver choices in both directions', async ({
  browser,
  context,
  page,
  baseURL,
}) => {
  // Two registrations, a real second login and both controls across sessions.
  test.slow()
  const contexts: BrowserContext[] = []
  try {
    const secondContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors: true,
      colorScheme: 'dark',
      viewport: test.info().project.use.viewport,
    })
    contexts.push(secondContext)
    const strangerContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors: true,
      colorScheme: 'light',
      viewport: test.info().project.use.viewport,
    })
    contexts.push(strangerContext)
    await emulateSystemTheme(page, 'light')
    await gotoPage(expect, page, '/', PAGE_READY)
    await chooseHeaderTheme(expect, page, 'dark')
    const person = await signUp(expect, page)
    await gotoPage(expect, page, '/profile', PAGE_READY)
    await expectThemeMode(expect, page, 'dark')
    await expectThemeHeader(expect, page, 'dark')
    await expectProfileTheme(expect, page, 'dark')

    // A real login creates independent storage and a different session cookie.
    const second = await secondContext.newPage()
    await gotoPage(expect, second, '/profile')
    await login(expect, second, person.email)
    await expect(second.getByTestId('profile-name')).toHaveText(person.name)
    const firstSession = (await context.cookies()).find((cookie) =>
      isSessionCookie(cookie.name),
    )
    const secondSession = (await secondContext.cookies()).find((cookie) =>
      isSessionCookie(cookie.name),
    )
    expect(firstSession).toBeDefined()
    expect(secondSession).toBeDefined()
    expect(secondSession?.value).not.toBe(firstSession?.value)
    await expectThemeMode(expect, second, 'dark')
    await expectThemeHeader(expect, second, 'dark')
    await expectProfileTheme(expect, second, 'dark')

    const stranger = await strangerContext.newPage()
    await signUp(expect, stranger)
    await gotoPage(expect, stranger, '/profile', PAGE_READY)
    await chooseHeaderTheme(expect, stranger, 'light')

    // Profile to the other header, including an already open receiving window.
    await openProfileTheme(expect, second)
    await chooseProfileTheme(expect, page, 'light')
    await expectProfileTheme(expect, second, 'light')
    await clickSubmit(expect, second.getByTestId('profile-theme-close'))
    await expect(second.getByTestId('profile-theme-modal')).toBeHidden()
    await expect(second.getByTestId('profile-theme-change')).toBeFocused()
    for (const tab of [page, second]) {
      await expectThemeMode(expect, tab, 'light')
      await expectThemeHeader(expect, tab, 'light')
      await expectProfileTheme(expect, tab, 'light')
    }
    await emulateSystemTheme(second, 'light')
    await emulateSystemTheme(second, 'dark')
    await expectThemeMode(expect, second, 'light')

    // The reverse header write updates the profile while its window stays open.
    await openProfileTheme(expect, page)
    await chooseHeaderTheme(expect, second, 'dark')
    await expectProfileTheme(expect, page, 'dark')
    await clickSubmit(expect, page.getByTestId('profile-theme-close'))
    await expect(page.getByTestId('profile-theme-modal')).toBeHidden()
    await expect(page.getByTestId('profile-theme-change')).toBeFocused()
    for (const tab of [page, second]) {
      await expectThemeMode(expect, tab, 'dark')
      await expectThemeHeader(expect, tab, 'dark')
      await expectProfileTheme(expect, tab, 'dark')
    }
    await emulateSystemTheme(page, 'dark')
    await emulateSystemTheme(page, 'light')
    await expectThemeMode(expect, page, 'dark')
    await expectThemeMode(expect, stranger, 'light')
    await expectThemeHeader(expect, stranger, 'light')
    await expectProfileTheme(expect, stranger, 'light')

    // Swap the writers as well: the second profile reaches the first header.
    await chooseProfileTheme(expect, second, 'system')
    await expectThemeMode(expect, page, 'light')
    await expectThemeHeader(expect, page, 'system')
    await expectProfileTheme(expect, page, 'system')
    await expectThemeMode(expect, second, 'dark')
    await expectThemeHeader(expect, second, 'system')
    await expectProfileTheme(expect, second, 'system')
    await emulateSystemTheme(second, 'light')
    await expectThemeMode(expect, second, 'light')
    await emulateSystemTheme(second, 'dark')
    await expectThemeMode(expect, second, 'dark')
    await second.reload()
    await expectPageReady(expect, second)
    await expectThemeMode(expect, second, 'dark')
    await expectThemeHeader(expect, second, 'system')
    await expectProfileTheme(expect, second, 'system')
    const systemTab = await secondContext.newPage()
    await emulateSystemTheme(systemTab, 'light')
    await gotoPage(expect, systemTab, '/profile', PAGE_READY)
    await expectThemeMode(expect, systemTab, 'light')
    await expectThemeHeader(expect, systemTab, 'system')
    await expectProfileTheme(expect, systemTab, 'system')
    await systemTab.close()

    await chooseHeaderTheme(expect, page, 'light')
    for (const tab of [page, second]) {
      await expectThemeMode(expect, tab, 'light')
      await expectThemeHeader(expect, tab, 'light')
      await expectProfileTheme(expect, tab, 'light')
    }
    await chooseHeaderTheme(expect, second, 'dark')
    for (const tab of [page, second]) {
      await expectThemeMode(expect, tab, 'dark')
      await expectThemeHeader(expect, tab, 'dark')
      await expectProfileTheme(expect, tab, 'dark')
      expect(
        await tab.evaluate(
          (key) => localStorage.getItem(key),
          THEME_PICK_STORAGE_KEY,
        ),
      ).toBe('dark')
    }
    await page.reload()
    await expectPageReady(expect, page)
    await expectThemeMode(expect, page, 'dark')
    await expectThemeHeader(expect, page, 'dark')
    await expectProfileTheme(expect, page, 'dark')
    const newTab = await context.newPage()
    await emulateSystemTheme(newTab, 'light')
    await gotoPage(expect, newTab, '/profile', PAGE_READY)
    await expect(newTab.getByTestId('profile-name')).toHaveText(person.name)
    await expectThemeMode(expect, newTab, 'dark')
    await expectThemeHeader(expect, newTab, 'dark')
    await expectProfileTheme(expect, newTab, 'dark')
    await expectThemeMode(expect, stranger, 'light')
    await expectThemeHeader(expect, stranger, 'light')
    await expectProfileTheme(expect, stranger, 'light')

    // Keep the kit's sign-out proof after removing the duplicated adoption test.
    await logout(expect, second)
    await expectThemeMode(expect, second, 'dark')
    await expectThemeHeader(expect, second, 'dark')
    expect(
      await second.evaluate(
        (key) => localStorage.getItem(key),
        THEME_PICK_STORAGE_KEY,
      ),
    ).toBe('dark')
    await expectThemeMode(expect, page, 'dark')
    await expectThemeHeader(expect, page, 'dark')
    await expectProfileTheme(expect, page, 'dark')
  } finally {
    await Promise.all(contexts.map((context) => context.close()))
  }
})

test('both account controls reset independent sessions and follow later site defaults', async ({
  browser,
  context,
  page,
  baseURL,
}) => {
  // Three registrations, a separate login, resets and live settings delivery
  // exceed the single-client cap even on an idle stand.
  test.slow()
  const contexts: BrowserContext[] = []
  let admin: Page | undefined
  let adminReady = false
  try {
    const secondContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors: true,
      colorScheme: 'dark',
      viewport: test.info().project.use.viewport,
    })
    contexts.push(secondContext)
    const strangerContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors: true,
      colorScheme: 'light',
      viewport: test.info().project.use.viewport,
    })
    contexts.push(strangerContext)
    const adminContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors: true,
      colorScheme: 'light',
      viewport: test.info().project.use.viewport,
    })
    contexts.push(adminContext)
    admin = await adminContext.newPage()
    await emulateSystemTheme(page, 'light')
    const person = await signUp(expect, page)
    await gotoPage(expect, page, '/profile', PAGE_READY)

    // Login creates a separate session and storage; neither is copied from the
    // registered browser, so browser storage events cannot supply this proof.
    const second = await secondContext.newPage()
    await emulateSystemTheme(second, 'dark')
    await gotoPage(expect, second, '/profile')
    await login(expect, second, person.email)
    await expect(second.getByTestId('profile-name')).toHaveText(person.name)
    const firstSession = (await context.cookies()).find((cookie) =>
      isSessionCookie(cookie.name),
    )
    const secondSession = (await secondContext.cookies()).find((cookie) =>
      isSessionCookie(cookie.name),
    )
    expect(firstSession).toBeDefined()
    expect(secondSession).toBeDefined()
    expect(secondSession?.value).not.toBe(firstSession?.value)

    const stranger = await strangerContext.newPage()
    await emulateSystemTheme(stranger, 'light')
    await signUp(expect, stranger)
    await gotoPage(expect, stranger, '/profile', PAGE_READY)
    await chooseHeaderTheme(expect, stranger, 'light')

    await expectThemeMode(expect, page, 'light')
    await expectThemeHeader(expect, page, 'system', true)

    await expectProfileTheme(expect, page, 'system', true)
    await expectThemeMode(expect, second, 'dark')
    await expectThemeHeader(expect, second, 'system', true)
    await expectProfileTheme(expect, second, 'system', true)
    await openProfileTheme(expect, page)
    await expect(
      page.getByTestId('profile-theme-option-system'),
    ).toHaveAttribute('aria-current', 'true')
    await expect(
      page.getByTestId('profile-theme-option-system').locator('.badge'),
    ).toBeVisible()
    // Picking the marked default is a personal write, although the position
    // stays System. Its default badge must leave both independent sessions.
    await clickSubmit(expect, page.getByTestId('profile-theme-option-system'))
    await expect(page.getByTestId('profile-theme-modal')).toBeHidden()
    await expect(page.getByTestId('profile-theme-change')).toBeFocused()
    await expectThemeMode(expect, page, 'light')
    await expectThemeHeader(expect, page, 'system')
    await expectProfileTheme(expect, page, 'system')
    await expectThemeMode(expect, second, 'dark')
    await expectThemeHeader(expect, second, 'system')
    await expectProfileTheme(expect, second, 'system')

    await openProfileTheme(expect, second)
    await chooseProfileTheme(expect, page, 'dark')
    await expect(
      second.getByTestId('profile-theme-option-dark'),
    ).toHaveAttribute('aria-current', 'true')
    await expect(
      second.getByTestId('profile-theme-option-system'),
    ).not.toHaveAttribute('aria-current', 'true')
    await clickSubmit(expect, second.getByTestId('profile-theme-close'))
    await expect(second.getByTestId('profile-theme-modal')).toBeHidden()
    await expectThemeMode(expect, page, 'dark')
    await expectThemeHeader(expect, page, 'dark')
    await expectProfileTheme(expect, page, 'dark')
    await expectThemeMode(expect, second, 'dark')
    await expectThemeHeader(expect, second, 'dark')
    await expectProfileTheme(expect, second, 'dark')
    await expectThemeMode(expect, stranger, 'light')
    await expectThemeHeader(expect, stranger, 'light')
    await expectProfileTheme(expect, stranger, 'light')

    await signUpAdmin(expect, admin)
    await gotoPage(expect, admin, '/hilos/settings', PAGE_READY)
    const search = admin.getByTestId('hilos-table-search')
    await search.fill('')
    await search.pressSequentially(THEME_DEFAULT_KEY, { delay: 10 })
    await expect(
      admin.getByTestId(`hilos-table-row-${THEME_DEFAULT_KEY}`),
    ).toBeVisible()
    adminReady = true

    // Reset through the header while the other session has its modal open.
    await openProfileTheme(expect, second)
    await chooseHeaderTheme(expect, page, null)
    await expect(
      second.getByTestId('profile-theme-option-system'),
    ).toHaveAttribute('aria-current', 'true')
    await expect(
      second.getByTestId('profile-theme-option-system').locator('.badge'),
    ).toBeVisible()
    await expect(
      second.getByTestId('profile-theme-default'),
    ).not.toHaveAttribute('aria-current')
    await clickSubmit(expect, second.getByTestId('profile-theme-close'))
    await expect(second.getByTestId('profile-theme-modal')).toBeHidden()
    await expectThemeMode(expect, page, 'light')
    await expectThemeHeader(expect, page, 'system', true)
    await expectProfileTheme(expect, page, 'system', true)
    await expectThemeMode(expect, second, 'dark')
    await expectThemeHeader(expect, second, 'system', true)
    await expectProfileTheme(expect, second, 'system', true)
    expect(
      await page.evaluate(
        (key) => localStorage.getItem(key),
        THEME_PICK_STORAGE_KEY,
      ),
    ).toBeNull()
    expect(
      await second.evaluate(
        (key) => localStorage.getItem(key),
        THEME_PICK_STORAGE_KEY,
      ),
    ).toBeNull()
    await setCustomSetting(admin, THEME_DEFAULT_KEY, 'light')
    await expectThemeMode(expect, page, 'light')
    await expectThemeHeader(expect, page, 'light', true)
    await expectProfileTheme(expect, page, 'light', true)
    await expectThemeMode(expect, second, 'light')
    await expectThemeHeader(expect, second, 'light', true)
    await expectProfileTheme(expect, second, 'light', true)
    await expectThemeMode(expect, stranger, 'light')
    await expectThemeHeader(expect, stranger, 'light')
    await expectProfileTheme(expect, stranger, 'light')

    // Make Dark personal again, then reset from the independent profile.
    await chooseHeaderTheme(expect, page, 'dark')
    await expectThemeMode(expect, second, 'dark')
    await expectThemeHeader(expect, second, 'dark')
    await expectProfileTheme(expect, second, 'dark')
    await openProfileTheme(expect, page)
    await chooseProfileTheme(expect, second, null)
    await expect(
      page.getByTestId('profile-theme-option-light'),
    ).toHaveAttribute('aria-current', 'true')
    await expect(
      page.getByTestId('profile-theme-option-light').locator('.badge'),
    ).toBeVisible()
    await clickSubmit(expect, page.getByTestId('profile-theme-close'))
    await expect(page.getByTestId('profile-theme-modal')).toBeHidden()
    await expectThemeMode(expect, page, 'light')
    await expectThemeHeader(expect, page, 'light', true)
    await expectProfileTheme(expect, page, 'light', true)
    await expectThemeMode(expect, second, 'light')
    await expectThemeHeader(expect, second, 'light', true)
    await expectProfileTheme(expect, second, 'light', true)
    await setCustomSetting(admin, THEME_DEFAULT_KEY, 'dark')
    await expectThemeMode(expect, page, 'dark')
    await expectThemeHeader(expect, page, 'dark', true)
    await expectProfileTheme(expect, page, 'dark', true)
    await expectThemeMode(expect, second, 'dark')
    await expectThemeHeader(expect, second, 'dark', true)
    await expectProfileTheme(expect, second, 'dark', true)
    await expectThemeMode(expect, stranger, 'light')
    await expectThemeHeader(expect, stranger, 'light')
    await expectProfileTheme(expect, stranger, 'light')
    await chooseProfileTheme(expect, second, null)
    await expectThemeMode(expect, second, 'dark')
    await expectThemeHeader(expect, second, 'dark', true)
    await expectProfileTheme(expect, second, 'dark', true)
    expect(
      await page.evaluate(
        (key) => localStorage.getItem(key),
        THEME_PICK_STORAGE_KEY,
      ),
    ).toBeNull()
    expect(
      await second.evaluate(
        (key) => localStorage.getItem(key),
        THEME_PICK_STORAGE_KEY,
      ),
    ).toBeNull()
    await clearCustomSetting(admin, THEME_DEFAULT_KEY)
    await expectThemeMode(expect, page, 'light')
    await expectThemeHeader(expect, page, 'system', true)
    await expectProfileTheme(expect, page, 'system', true)
    await expectThemeMode(expect, second, 'dark')
    await expectThemeHeader(expect, second, 'system', true)
    await expectProfileTheme(expect, second, 'system', true)
  } finally {
    try {
      if (adminReady && admin)
        await clearCustomSetting(admin, THEME_DEFAULT_KEY)
    } finally {
      await Promise.all(contexts.map((context) => context.close()))
    }
  }
})

test('profile and header work by keyboard and return focus', async ({
  page,
}) => {
  await signUp(expect, page)
  await gotoPage(expect, page, '/profile', PAGE_READY)
  await expectThemeMode(expect, page, 'light')
  await expectThemeHeader(expect, page, 'system', true)
  await expectProfileTheme(expect, page, 'system', true)

  const change = page.getByTestId('profile-theme-change')
  await change.focus()
  await expect(change).toBeVisible()
  await expect(change).toBeEnabled()
  await change.press('Enter')
  await expect(page.getByTestId('profile-theme-modal')).toBeVisible()
  const dialog = page.getByTestId('modal')
  await expect(dialog).toBeFocused()
  await expect(dialog).toHaveAccessibleName('Theme')
  await dialog.press('Tab')
  await expect(page.getByTestId('modal-close')).toBeFocused()
  await page.getByTestId('modal-close').press('Tab')
  await expect(page.getByTestId('profile-theme-option-light')).toBeFocused()
  await page.getByTestId('profile-theme-option-light').press('Tab')
  const dark = page.getByTestId('profile-theme-option-dark')
  await expect(dark).toBeFocused()
  await expect(dark).toBeEnabled()
  await dark.press('Enter')
  await expect(page.getByTestId('profile-theme-modal')).toBeHidden()
  await expect(change).toBeFocused()
  await expectThemeMode(expect, page, 'dark')
  await expectThemeHeader(expect, page, 'dark')
  await expectProfileTheme(expect, page, 'dark')

  await openProfileTheme(expect, page)
  await dialog.press('Escape')
  await expect(page.getByTestId('profile-theme-modal')).toBeHidden()
  await expect(change).toBeFocused()
  await expectThemeMode(expect, page, 'dark')
  await expectThemeHeader(expect, page, 'dark')
  await expectProfileTheme(expect, page, 'dark')
  await openProfileTheme(expect, page)
  await clickSubmit(expect, page.getByTestId('profile-theme-close'))
  await expect(page.getByTestId('profile-theme-modal')).toBeHidden()
  await expect(change).toBeFocused()
  await expectThemeMode(expect, page, 'dark')
  await expectThemeHeader(expect, page, 'dark')
  await expectProfileTheme(expect, page, 'dark')

  const toggle = page.getByTestId('nav-theme')
  await toggle.focus()
  await toggle.press('ArrowDown')
  await expect(page.getByTestId('nav-theme-menu')).toBeVisible()
  const light = page.getByTestId('nav-theme-option-light')
  await expect(light).toBeFocused()
  await light.press('End')
  const reset = page.getByTestId('nav-theme-default')
  await expect(reset).toBeFocused()
  await expect(reset).toBeEnabled()
  await reset.press('Enter')
  await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
  await expect(toggle).toBeEnabled()
  await expect(toggle).toBeFocused()
  await expectThemeMode(expect, page, 'light')
  await expectThemeHeader(expect, page, 'system', true)
  await expectProfileTheme(expect, page, 'system', true)

  await change.press('Enter')
  await expect(page.getByTestId('profile-theme-modal')).toBeVisible()
  await expect(dialog).toBeFocused()
  const profileReset = page.getByTestId('profile-theme-default')
  await dialog.press('Tab')
  await page.getByTestId('modal-close').press('Tab')
  await page.getByTestId('profile-theme-option-light').press('Tab')
  await dark.press('Tab')
  await page.getByTestId('profile-theme-option-system').press('Tab')
  await expect(profileReset).toBeFocused()
  await expect(profileReset).toBeVisible()
  await expect(profileReset).toBeEnabled()
  await profileReset.press('Enter')
  await expect(page.getByTestId('profile-theme-modal')).toBeHidden()
  await expect(change).toBeFocused()
  await expectThemeMode(expect, page, 'light')
  await expectThemeHeader(expect, page, 'system', true)
  await expectProfileTheme(expect, page, 'system', true)

  await toggle.press('ArrowUp')
  await expect(reset).toBeFocused()
  await reset.press('ArrowUp')
  const system = page.getByTestId('nav-theme-option-system')
  await expect(system).toBeFocused()
  await expect(system).toBeEnabled()
  await system.press('Enter')
  await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
  await expect(toggle).toBeEnabled()
  await expect(toggle).toBeFocused()
  await expectThemeMode(expect, page, 'light')
  await expectThemeHeader(expect, page, 'system')
  await expectProfileTheme(expect, page, 'system')
})
