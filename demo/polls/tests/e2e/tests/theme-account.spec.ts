// The account's theme in the Angular kit (HIL-1441): the header menu and the
// profile's Theme window both write the account, every session of that person
// follows the account frame and a stranger's does not, Use site default from
// either control returns to the default and follows it, a stale browser cannot
// adopt its old pick over a reset, and a never-chosen account still adopts the
// guest's first pick. The same scenarios as tasks' theme-account.spec.ts, on
// the Angular shell and profile, plus a sign-out that keeps the tab's theme.
import { expect, test, type Page } from '@playwright/test'

import {
  armSocketDrop,
  clearCustomSetting,
  countSentFrames,
  dropSocket,
  isSessionCookie,
  openFirstFrame,
  setCustomSetting,
  sidewaysOverflow,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit, login, logout, signUp } from '../helpers/session.js'

const PICK_KEY = 'hilos.theme.pick'
const DEFAULT_KEY = 'theme.default'
const DARK_FRAME = { theme: 'dark', background: 'rgb(33, 37, 41)' }
const POSITIONS = ['light', 'dark', 'system'] as const
const LABELS = { light: 'Light', dark: 'Dark', system: 'System' } as const
type Position = (typeof POSITIONS)[number]

/** Open the profile's live theme modal through its Change control. */
async function openProfileTheme(page: Page): Promise<void> {
  await clickSubmit(page.getByTestId('profile-theme-change'))
  await expect(page.getByTestId('profile-theme-modal')).toBeVisible()
}

/** Choose in the profile and settle on success and returned focus. */
async function chooseInProfile(
  page: Page,
  position: Position | null,
): Promise<void> {
  await openProfileTheme(page)
  await clickSubmit(
    page.getByTestId(
      position === null
        ? 'profile-theme-default'
        : `profile-theme-option-${position}`,
    ),
  )
  await expect(page.getByTestId('profile-theme-modal')).toBeHidden()
  await expect(page.getByTestId('profile-theme-change')).toBeFocused()
}

/** The header closes immediately; its enabled toggle says the reply settled. */
async function chooseInHeader(
  page: Page,
  position: Position | null,
): Promise<void> {
  const toggle = page.getByTestId('nav-theme')
  await clickSubmit(toggle)
  await expect(page.getByTestId('nav-theme-menu')).toBeVisible()
  await clickSubmit(
    page.getByTestId(
      position === null ? 'nav-theme-default' : `nav-theme-option-${position}`,
    ),
  )
  await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
  await expect(toggle).toBeEnabled()
  await expect(toggle).toBeFocused()
}

/** Check the account position on both surfaces separately from the worn mode. */
async function expectTheme(
  page: Page,
  position: Position,
  mode: 'light' | 'dark',
  isDefault = false,
): Promise<void> {
  await expect(page.getByTestId('profile-theme')).toHaveText(
    `${LABELS[position]}${isDefault ? ' · default' : ''}`,
  )
  await expectHeader(page, position, mode, isDefault)
}

/** Check the worn mode and the header's marks, with or without a profile on screen. */
async function expectHeader(
  page: Page,
  position: Position,
  mode: 'light' | 'dark',
  isDefault = false,
): Promise<void> {
  await expect(page.locator('html')).toHaveAttribute('data-bs-theme', mode)
  const toggle = page.getByTestId('nav-theme')
  await expect(toggle).toHaveAttribute('data-position', position)
  await expect(toggle).toHaveAccessibleName(`Theme: ${LABELS[position]}`)
  await clickSubmit(toggle)
  await expect(page.getByTestId('nav-theme-menu')).toBeVisible()
  for (const row of POSITIONS) {
    const option = page.getByTestId(`nav-theme-option-${row}`)
    await expect(option).toHaveAttribute(
      'aria-checked',
      String(row === position),
    )
    await expect(option.locator('.bi-check2')).toHaveCount(
      row === position ? 1 : 0,
    )
    await expect(option.locator('.badge')).toHaveCount(
      row === position && isDefault ? 1 : 0,
    )
  }
  const reset = page.getByTestId('nav-theme-default')
  await expect(reset).toBeVisible()
  await expect(reset).toHaveRole('menuitem')
  await expect(reset).toHaveText('Use site default')
  await expect(reset).not.toHaveAttribute('aria-checked')
  await expect(reset.locator('.bi-check2')).toHaveCount(0)
  await toggle.press('Escape')
  await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
}

test('independent account sessions synchronize profile and header choices and keep their first frame', async ({
  browser,
  context,
  page,
  baseURL,
}) => {
  // Three registrations, a separate login, resets and live settings delivery
  // exceed the single-client cap even on an idle stand.
  test.slow()
  const secondContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
  })
  const strangerContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
  })
  const adminContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
  })
  const admin = await adminContext.newPage()
  let adminReady = false
  try {
    await page.emulateMedia({ colorScheme: 'light' })
    const person = await signUp(page)
    await gotoPage(page, '/profile', PAGE_READY)

    // Login creates a separate session and storage; neither is copied from the
    // registered browser, so browser storage events cannot supply this proof.
    const second = await secondContext.newPage()
    await second.emulateMedia({ colorScheme: 'dark' })
    await gotoPage(second, '/profile')
    await login(second, person.email)
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
    await stranger.emulateMedia({ colorScheme: 'light' })
    await signUp(stranger)
    await gotoPage(stranger, '/profile', PAGE_READY)
    await chooseInHeader(stranger, 'light')

    await expectTheme(page, 'system', 'light', true)
    await expectTheme(second, 'system', 'dark', true)
    await openProfileTheme(page)
    await expect(
      page.getByTestId('profile-theme-option-system'),
    ).toHaveAttribute('aria-current', 'true')
    await expect(
      page.getByTestId('profile-theme-option-system').locator('.badge'),
    ).toBeVisible()
    // Picking the marked default is a personal write, although the position
    // stays System. Its default badge must leave both independent sessions.
    await clickSubmit(page.getByTestId('profile-theme-option-system'))
    await expect(page.getByTestId('profile-theme-modal')).toBeHidden()
    await expect(page.getByTestId('profile-theme-change')).toBeFocused()
    await expectTheme(page, 'system', 'light')
    await expectTheme(second, 'system', 'dark')

    await openProfileTheme(second)
    await chooseInProfile(page, 'dark')
    await expect(
      second.getByTestId('profile-theme-option-dark'),
    ).toHaveAttribute('aria-current', 'true')
    await expect(
      second.getByTestId('profile-theme-option-system'),
    ).not.toHaveAttribute('aria-current', 'true')
    await clickSubmit(second.getByTestId('profile-theme-close'))
    await expect(second.getByTestId('profile-theme-modal')).toBeHidden()
    await expectTheme(page, 'dark', 'dark')
    await expectTheme(second, 'dark', 'dark')
    await expectTheme(stranger, 'light', 'light')

    await signUpAdmin(admin)
    await gotoPage(admin, '/hilos/settings', PAGE_READY)
    const search = admin.getByTestId('hilos-table-search')
    await search.fill('')
    await search.pressSequentially(DEFAULT_KEY, { delay: 10 })
    await expect(
      admin.getByTestId(`hilos-table-row-${DEFAULT_KEY}`),
    ).toBeVisible()
    adminReady = true

    // Reset through the header while the other session has its modal open.
    await openProfileTheme(second)
    await chooseInHeader(page, null)
    await expect(
      second.getByTestId('profile-theme-option-system'),
    ).toHaveAttribute('aria-current', 'true')
    await expect(
      second.getByTestId('profile-theme-option-system').locator('.badge'),
    ).toBeVisible()
    await expect(
      second.getByTestId('profile-theme-default'),
    ).not.toHaveAttribute('aria-current')
    await clickSubmit(second.getByTestId('profile-theme-close'))
    await expect(second.getByTestId('profile-theme-modal')).toBeHidden()
    await expectTheme(page, 'system', 'light', true)
    await expectTheme(second, 'system', 'dark', true)
    expect(
      await page.evaluate((key) => localStorage.getItem(key), PICK_KEY),
    ).toBeNull()
    expect(
      await second.evaluate((key) => localStorage.getItem(key), PICK_KEY),
    ).toBeNull()
    await setCustomSetting(admin, DEFAULT_KEY, 'light')
    await expectTheme(page, 'light', 'light', true)
    await expectTheme(second, 'light', 'light', true)
    await expectTheme(stranger, 'light', 'light')

    // Make Dark personal again, then reset from the independent profile.
    await chooseInHeader(page, 'dark')
    await expectTheme(second, 'dark', 'dark')
    await openProfileTheme(page)
    await chooseInProfile(second, null)
    await expect(
      page.getByTestId('profile-theme-option-light'),
    ).toHaveAttribute('aria-current', 'true')
    await expect(
      page.getByTestId('profile-theme-option-light').locator('.badge'),
    ).toBeVisible()
    await clickSubmit(page.getByTestId('profile-theme-close'))
    await expect(page.getByTestId('profile-theme-modal')).toBeHidden()
    await expectTheme(page, 'light', 'light', true)
    await expectTheme(second, 'light', 'light', true)
    await setCustomSetting(admin, DEFAULT_KEY, 'dark')
    await expectTheme(page, 'dark', 'dark', true)
    await expectTheme(second, 'dark', 'dark', true)
    await expectTheme(stranger, 'light', 'light')
    await chooseInProfile(second, null)
    await expectTheme(second, 'dark', 'dark', true)
    expect(
      await page.evaluate((key) => localStorage.getItem(key), PICK_KEY),
    ).toBeNull()
    expect(
      await second.evaluate((key) => localStorage.getItem(key), PICK_KEY),
    ).toBeNull()
    await clearCustomSetting(admin, DEFAULT_KEY)
    await expectTheme(page, 'system', 'light', true)
    await expectTheme(second, 'system', 'dark', true)

    await chooseInHeader(second, 'light')
    await expectTheme(second, 'light', 'light')
    await expectTheme(page, 'light', 'light')
    await expectTheme(stranger, 'light', 'light')

    await chooseInProfile(page, 'system')
    await expectTheme(page, 'system', 'light')
    await expectTheme(second, 'system', 'dark')
    await second.emulateMedia({ colorScheme: 'light' })
    await expectTheme(second, 'system', 'light')
    await second.emulateMedia({ colorScheme: 'dark' })
    await expectTheme(second, 'system', 'dark')
    await second.reload()
    await expectPageReady(second)
    await expectTheme(second, 'system', 'dark')
    const systemTab = await secondContext.newPage()
    await systemTab.emulateMedia({ colorScheme: 'light' })
    await gotoPage(systemTab, '/profile', PAGE_READY)
    await expectTheme(systemTab, 'system', 'light')
    await systemTab.close()

    await chooseInHeader(second, 'dark')
    await expectTheme(page, 'dark', 'dark')
    await expectTheme(second, 'dark', 'dark')
    expect(
      await page.evaluate((key) => localStorage.getItem(key), PICK_KEY),
    ).toBe('dark')
    expect(
      await second.evaluate((key) => localStorage.getItem(key), PICK_KEY),
    ).toBe('dark')
    await page.reload()
    await expectPageReady(page)
    await expectTheme(page, 'dark', 'dark')

    const newTab = await context.newPage()
    await newTab.emulateMedia({ colorScheme: 'light' })
    await gotoPage(newTab, '/profile', PAGE_READY)
    await expect(newTab.getByTestId('profile-name')).toHaveText(person.name)
    await expectTheme(newTab, 'dark', 'dark')
    // Both browsers already received the account frame. This checks their
    // cached head decision with external JS blocked, not a first-ever visit.
    expect(await openFirstFrame(page, '/profile')).toEqual(DARK_FRAME)
    expect(await openFirstFrame(second, '/profile')).toEqual(DARK_FRAME)
    await expectTheme(stranger, 'light', 'light')
  } finally {
    try {
      if (adminReady) await clearCustomSetting(admin, DEFAULT_KEY)
    } finally {
      await adminContext.close()
      await secondContext.close()
      await strangerContext.close()
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
  try {
    await page.emulateMedia({ colorScheme: 'light' })
    const person = await signUp(page)
    await gotoPage(page, '/profile', PAGE_READY)
    await chooseInHeader(page, 'dark')
    const second = await secondContext.newPage()
    await armSocketDrop(second)
    const adoptions = countSentFrames(second, 'hilos_theme_adopt')
    let accountTheme: unknown
    second.on('websocket', (socket) => {
      socket.on('framereceived', ({ payload }) => {
        if (typeof payload !== 'string') return
        const frame = JSON.parse(payload) as {
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
        const user = frame.data?.entities?.currentUser
        if (frame.type === 'handshake_response' && user) {
          accountTheme = {
            themePick: user.themePick,
            themePickInitialized: user.themePickInitialized,
          }
        }
      })
    })
    await gotoPage(second, '/profile')
    await login(second, person.email)
    await expectTheme(second, 'dark', 'dark')
    await secondContext.setOffline(true)
    await dropSocket(second)
    await expect(second.getByTestId('conn-state')).not.toHaveText('connected')
    await chooseInHeader(page, null)
    await expectTheme(page, 'system', 'light', true)
    expect(
      await second.evaluate((key) => localStorage.getItem(key), PICK_KEY),
    ).toBe('dark')

    const reconnected = second.waitForEvent('websocket')
    await secondContext.setOffline(false)
    await reconnected
    await expect(second.getByTestId('conn-state')).toHaveText('connected')
    await expectPageReady(second)
    await expect
      .poll(() => accountTheme)
      .toEqual({ themePick: null, themePickInitialized: true })
    await expectTheme(second, 'system', 'light', true)
    expect(
      await second.evaluate((key) => localStorage.getItem(key), PICK_KEY),
    ).toBeNull()
    expect(adoptions()).toBe(0)
    await expectTheme(page, 'system', 'light', true)
    await second.reload()
    await expectPageReady(second)
    await expectTheme(second, 'system', 'light', true)
    await expectTheme(page, 'system', 'light', true)
    expect(adoptions()).toBe(0)
  } finally {
    await secondContext.close()
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
    await page.emulateMedia({ colorScheme: 'light' })
    const person = await signUp(page)
    await gotoPage(page, '/profile', PAGE_READY)
    await expectTheme(page, 'system', 'light', true)
    const guest = await guestContext.newPage()
    await guest.emulateMedia({ colorScheme: 'light' })
    const adoptions = countSentFrames(guest, 'hilos_theme_adopt')
    await gotoPage(guest, '/')
    await chooseInHeader(guest, 'dark')
    await gotoPage(guest, '/profile')
    await login(guest, person.email)
    await expectTheme(guest, 'dark', 'dark')
    await expectTheme(page, 'dark', 'dark')
    expect(adoptions()).toBe(1)

    // Sign-out keeps the tab's choice and its browser copy: the guest the tab
    // falls back to wears the same theme, and the account stays as it was.
    await logout(guest)
    await expectHeader(guest, 'dark', 'dark')
    expect(
      await guest.evaluate((key) => localStorage.getItem(key), PICK_KEY),
    ).toBe('dark')
    await expectTheme(page, 'dark', 'dark')
  } finally {
    await guestContext.close()
  }
})

test('profile modal and header work by keyboard at 375px and return focus', async ({
  page,
}) => {
  await page.setViewportSize({ width: 375, height: 812 })
  await page.emulateMedia({ colorScheme: 'light' })
  await signUp(page)
  await gotoPage(page, '/profile', PAGE_READY)
  await expectTheme(page, 'system', 'light', true)
  expect(await sidewaysOverflow(page)).toEqual([0, 0])

  const change = page.getByTestId('profile-theme-change')
  await change.focus()
  await expect(change).toBeVisible()
  await expect(change).toBeEnabled()
  await change.press('Enter')
  await expect(page.getByTestId('profile-theme-modal')).toBeVisible()
  const dialog = page.getByTestId('modal')
  await expect(dialog).toBeFocused()
  await expect(dialog).toHaveAccessibleName('Theme')
  expect(await sidewaysOverflow(page)).toEqual([0, 0])
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
  await expectTheme(page, 'dark', 'dark')

  await openProfileTheme(page)
  await dialog.press('Escape')
  await expect(page.getByTestId('profile-theme-modal')).toBeHidden()
  await expect(change).toBeFocused()
  await expectTheme(page, 'dark', 'dark')
  await openProfileTheme(page)
  await clickSubmit(page.getByTestId('profile-theme-close'))
  await expect(page.getByTestId('profile-theme-modal')).toBeHidden()
  await expect(change).toBeFocused()
  await expectTheme(page, 'dark', 'dark')

  const toggle = page.getByTestId('nav-theme')
  await toggle.focus()
  await toggle.press('ArrowDown')
  await expect(page.getByTestId('nav-theme-menu')).toBeVisible()
  const light = page.getByTestId('nav-theme-option-light')
  await expect(light).toBeFocused()
  expect(await sidewaysOverflow(page)).toEqual([0, 0])
  await light.press('End')
  const reset = page.getByTestId('nav-theme-default')
  await expect(reset).toBeFocused()
  await expect(reset).toBeEnabled()
  await reset.press('Enter')
  await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
  await expect(toggle).toBeEnabled()
  await expect(toggle).toBeFocused()
  await expectTheme(page, 'system', 'light', true)
  expect(await sidewaysOverflow(page)).toEqual([0, 0])

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
  expect(await sidewaysOverflow(page)).toEqual([0, 0])
  await profileReset.press('Enter')
  await expect(page.getByTestId('profile-theme-modal')).toBeHidden()
  await expect(change).toBeFocused()
  await expectTheme(page, 'system', 'light', true)

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
  await expectTheme(page, 'system', 'light')
  expect(await sidewaysOverflow(page)).toEqual([0, 0])
})
