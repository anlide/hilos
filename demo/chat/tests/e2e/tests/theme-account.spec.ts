import { expect, test, type BrowserContext, type Page } from '@playwright/test'

import {
  login,
  armSocketDrop,
  clearCustomSetting,
  THEME_DEFAULT_KEY,
  THEME_PICK_STORAGE_KEY,
  chooseHeaderTheme,
  emulateSystemTheme,
  expectThemeHeader,
  expectThemeMode,
  THEME_SWITCHING_KEY,
  chooseProfileTheme,
  expectProfileTheme,
  expectThemeFirstFrame,
  openProfileTheme,
  countSentFrames,
  dropSocket,
  isSessionCookie,
  setCustomSetting,
  watchSocketFrames,
  type SocketFrame,
  clickSubmit,
  expectPageReady,
  gotoPage,
  PAGE_READY,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUp } from '../helpers/session.js'

test('independent account sessions synchronize profile and header choices and keep their first frame', async ({
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
    })
    contexts.push(secondContext)
    const strangerContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors: true,
      colorScheme: 'light',
    })
    contexts.push(strangerContext)
    const adminContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors: true,
      colorScheme: 'light',
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

    await signUpAdmin(expect, admin, { entryId: 'message-signin' })
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

    await chooseHeaderTheme(expect, second, 'light')
    await expectThemeMode(expect, second, 'light')
    await expectThemeHeader(expect, second, 'light')
    await expectProfileTheme(expect, second, 'light')
    await expectThemeMode(expect, page, 'light')
    await expectThemeHeader(expect, page, 'light')
    await expectProfileTheme(expect, page, 'light')
    await expectThemeMode(expect, stranger, 'light')
    await expectThemeHeader(expect, stranger, 'light')
    await expectProfileTheme(expect, stranger, 'light')

    await chooseProfileTheme(expect, page, 'system')
    await expectThemeMode(expect, page, 'light')
    await expectThemeHeader(expect, page, 'system')
    await expectProfileTheme(expect, page, 'system')
    await expectThemeMode(expect, second, 'dark')
    await expectThemeHeader(expect, second, 'system')
    await expectProfileTheme(expect, second, 'system')
    await emulateSystemTheme(second, 'light')
    await expectThemeMode(expect, second, 'light')
    await expectThemeHeader(expect, second, 'system')
    await expectProfileTheme(expect, second, 'system')
    await emulateSystemTheme(second, 'dark')
    await expectThemeMode(expect, second, 'dark')
    await expectThemeHeader(expect, second, 'system')
    await expectProfileTheme(expect, second, 'system')
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

    await chooseHeaderTheme(expect, second, 'dark')
    await expectThemeMode(expect, page, 'dark')
    await expectThemeHeader(expect, page, 'dark')
    await expectProfileTheme(expect, page, 'dark')
    await expectThemeMode(expect, second, 'dark')
    await expectThemeHeader(expect, second, 'dark')
    await expectProfileTheme(expect, second, 'dark')
    expect(
      await page.evaluate(
        (key) => localStorage.getItem(key),
        THEME_PICK_STORAGE_KEY,
      ),
    ).toBe('dark')
    expect(
      await second.evaluate(
        (key) => localStorage.getItem(key),
        THEME_PICK_STORAGE_KEY,
      ),
    ).toBe('dark')
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
    // Both browsers already received the account frame. This checks their
    // cached head decision with external JS blocked, not a first-ever visit.
    await expectThemeFirstFrame(expect, page, '/profile', 'dark')
    await expectThemeFirstFrame(expect, second, '/profile', 'dark')
    await expectThemeMode(expect, stranger, 'light')
    await expectThemeHeader(expect, stranger, 'light')
    await expectProfileTheme(expect, stranger, 'light')
  } finally {
    try {
      if (adminReady && admin)
        await clearCustomSetting(admin, THEME_DEFAULT_KEY)
    } finally {
      await Promise.all(contexts.map((context) => context.close()))
    }
  }
})

test('a stale Dark browser reconnects after reset without adopting it again', async ({
  browser,
  page,
  baseURL,
}) => {
  const secondContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
  })
  const frames: SocketFrame[] = []
  try {
    await emulateSystemTheme(page, 'light')
    const person = await signUp(expect, page)
    await gotoPage(expect, page, '/profile', PAGE_READY)
    await chooseHeaderTheme(expect, page, 'dark')
    const second = await secondContext.newPage()
    await armSocketDrop(second)
    const adoptions = countSentFrames(second, 'hilos_theme_adopt')
    let accountTheme: unknown
    watchSocketFrames(second, (observation) => {
      frames.push(observation)
      const frame = observation.wire as
        | {
            type?: string
            data?: {
              entities?: {
                currentUser?: {
                  themePick: unknown
                  themePickInitialized: unknown
                } | null
              }
            }
          }
        | undefined
        | null
      const user = frame?.data?.entities?.currentUser
      if (frame?.type === 'handshake_response') {
        accountTheme = {
          themePick: user?.themePick,
          themePickInitialized: user?.themePickInitialized,
        }
      }
    })
    await gotoPage(expect, second, '/profile')
    await login(expect, second, person.email)
    await expectThemeMode(expect, second, 'dark')
    await expectThemeHeader(expect, second, 'dark')
    await expectProfileTheme(expect, second, 'dark')
    await secondContext.setOffline(true)
    await dropSocket(second)
    await expect(second.getByTestId('conn-state')).not.toHaveText('connected')
    await chooseHeaderTheme(expect, page, null)
    await expectThemeMode(expect, page, 'light')
    await expectThemeHeader(expect, page, 'system', true)
    await expectProfileTheme(expect, page, 'system', true)
    expect(
      await second.evaluate(
        (key) => localStorage.getItem(key),
        THEME_PICK_STORAGE_KEY,
      ),
    ).toBe('dark')

    const reconnected = second.waitForEvent('websocket')
    await secondContext.setOffline(false)
    await reconnected
    await expect(second.getByTestId('conn-state')).toHaveText('connected')
    await expectPageReady(expect, second)
    await expect
      .poll(() => accountTheme)
      .toEqual({ themePick: null, themePickInitialized: true })
    await expectThemeMode(expect, second, 'light')
    await expectThemeHeader(expect, second, 'system', true)
    await expectProfileTheme(expect, second, 'system', true)
    expect(
      await second.evaluate(
        (key) => localStorage.getItem(key),
        THEME_PICK_STORAGE_KEY,
      ),
    ).toBeNull()
    expect(adoptions()).toBe(0)
    await expectThemeMode(expect, page, 'light')
    await expectThemeHeader(expect, page, 'system', true)
    await expectProfileTheme(expect, page, 'system', true)
    await second.reload()
    await expectPageReady(expect, second)
    await expectThemeMode(expect, second, 'light')
    await expectThemeHeader(expect, second, 'system', true)
    await expectProfileTheme(expect, second, 'system', true)
    await expectThemeMode(expect, page, 'light')
    await expectThemeHeader(expect, page, 'system', true)
    await expectProfileTheme(expect, page, 'system', true)
    expect(adoptions()).toBe(0)
  } finally {
    await secondContext.close()
    await test.info().attach('theme-reconnect-frames', {
      body: JSON.stringify(frames),
      contentType: 'application/json',
    })
  }
})

test('a never-chosen account still adopts a guest Dark choice on first login', async ({
  browser,
  page,
  baseURL,
}) => {
  const guestContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
  })
  try {
    await emulateSystemTheme(page, 'light')
    const person = await signUp(expect, page)
    await gotoPage(expect, page, '/profile', PAGE_READY)
    await expectThemeMode(expect, page, 'light')
    await expectThemeHeader(expect, page, 'system', true)
    await expectProfileTheme(expect, page, 'system', true)
    const guest = await guestContext.newPage()
    await emulateSystemTheme(guest, 'light')
    const adoptions = countSentFrames(guest, 'hilos_theme_adopt')
    await gotoPage(expect, guest, '/')
    await chooseHeaderTheme(expect, guest, 'dark')
    await gotoPage(expect, guest, '/profile')
    await login(expect, guest, person.email)
    await expectThemeMode(expect, guest, 'dark')
    await expectThemeHeader(expect, guest, 'dark')
    await expectProfileTheme(expect, guest, 'dark')
    await expectThemeMode(expect, page, 'dark')
    await expectThemeHeader(expect, page, 'dark')
    await expectProfileTheme(expect, page, 'dark')
    expect(adoptions()).toBe(1)
  } finally {
    await guestContext.close()
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

test('registration keeps the guest pick and switching Off closes the open profile window', async ({
  browser,
  page,
  baseURL,
}) => {
  // Two registrations and two live setting writes exceed the single-client cap.
  test.slow()
  const adminContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
  })
  let admin: Page | undefined
  let adminReady = false
  try {
    await emulateSystemTheme(page, 'light')
    await gotoPage(expect, page, '/', PAGE_READY)
    await chooseHeaderTheme(expect, page, 'dark')
    await signUp(expect, page)
    await gotoPage(expect, page, '/profile', PAGE_READY)
    await expectThemeMode(expect, page, 'dark')
    await expectThemeHeader(expect, page, 'dark')
    await expectProfileTheme(expect, page, 'dark')

    admin = await adminContext.newPage()
    await signUpAdmin(expect, admin, { entryId: 'message-signin' })
    await gotoPage(expect, admin, '/hilos/settings', PAGE_READY)
    const search = admin.getByTestId('hilos-table-search')
    await search.fill('')
    await search.pressSequentially(THEME_SWITCHING_KEY, { delay: 10 })
    await expect(
      admin.getByTestId(`hilos-table-row-${THEME_SWITCHING_KEY}`),
    ).toBeVisible()
    adminReady = true
    let loads = 0
    page.on('load', () => {
      loads += 1
    })
    await openProfileTheme(expect, page)
    await setCustomSetting(admin, THEME_SWITCHING_KEY, false)
    await expect(page.getByTestId('profile-theme-modal')).toBeHidden()
    await expect(page.getByTestId('profile-theme')).toHaveCount(0)
    await expect(page.getByTestId('profile-theme-change')).toHaveCount(0)
    await expect(page.getByTestId('nav-theme')).toHaveCount(0)
    await expectThemeMode(expect, page, 'light')
    expect(
      await page.evaluate(
        (key) => localStorage.getItem(key),
        THEME_PICK_STORAGE_KEY,
      ),
    ).toBe('dark')

    await clearCustomSetting(admin, THEME_SWITCHING_KEY)
    await expectThemeMode(expect, page, 'dark')
    await expectThemeHeader(expect, page, 'dark')
    await expectProfileTheme(expect, page, 'dark')
    expect(loads).toBe(0)
  } finally {
    try {
      if (adminReady && admin)
        await clearCustomSetting(admin, THEME_SWITCHING_KEY)
    } finally {
      await adminContext.close()
    }
  }
})
