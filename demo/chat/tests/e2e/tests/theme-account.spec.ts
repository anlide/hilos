import { expect, test, type Page } from '@playwright/test'

import {
  isSessionCookie,
  openFirstFrame,
  sidewaysOverflow,
} from '../../../../../framework/frontend/e2e/index.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit, login, signUp } from '../helpers/session.js'

const PICK_KEY = 'hilos.theme.pick'
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
async function chooseInProfile(page: Page, position: Position): Promise<void> {
  await openProfileTheme(page)
  await clickSubmit(page.getByTestId(`profile-theme-option-${position}`))
  await expect(page.getByTestId('profile-theme-modal')).toBeHidden()
  await expect(page.getByTestId('profile-theme-change')).toBeFocused()
}

/** The header closes immediately; its enabled toggle says the reply settled. */
async function chooseInHeader(page: Page, position: Position): Promise<void> {
  const toggle = page.getByTestId('nav-theme')
  await clickSubmit(toggle)
  await expect(page.getByTestId('nav-theme-menu')).toBeVisible()
  await clickSubmit(page.getByTestId(`nav-theme-option-${position}`))
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
  await expect(page.locator('html')).toHaveAttribute('data-bs-theme', mode)
  await expect(page.getByTestId('profile-theme')).toHaveText(
    `${LABELS[position]}${isDefault ? ' · default' : ''}`,
  )
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
  await toggle.press('Escape')
  await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
}

test('independent account sessions synchronize profile and header choices and keep their first frame', async ({
  browser,
  context,
  page,
  baseURL,
}) => {
  // Two registrations, a separate login and both directions of socket delivery
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
    await secondContext.close()
    await strangerContext.close()
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
