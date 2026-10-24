import type { Expect, Locator, Page } from '@playwright/test'

import { openFirstFrame } from './firstFrame.js'

// The theme a page wears comes from the account's pick once signed in, and before
// that from the browser's own (docs/agents/architecture/theme.md). A spec of
// the theme picks it through the header menu every kit draws; a spec that only
// needs its pages in one theme may instead set the browser's pick before the
// page loads, and let registration carry it into the account.

/**
 * The storage key of the browser's theme pick, copied verbatim from
 * THEME_PICK_STORAGE_KEY in core/src/theme/themeStored.ts. The e2e package builds
 * against no framework source, so the literal is kept beside the step that
 * writes it.
 */
export const THEME_PICK_STORAGE_KEY = 'hilos.theme.pick'

/** The complete settings pair remembered by the browser for its first frame. */
export const THEME_SETTINGS_STORAGE_KEY = 'hilos.theme.settings'

/** Independent test vocabulary: never derive expected values from the core rule. */
export const THEME_TEST_POSITIONS = ['light', 'dark', 'system'] as const
export type ThemeTestPosition = (typeof THEME_TEST_POSITIONS)[number]
export type ThemeTestPick = ThemeTestPosition | null
export const THEME_TEST_LABELS = {
  light: 'Light',
  dark: 'Dark',
  system: 'System',
} as const
export const THEME_TEST_ICONS = {
  light: 'bi-sun',
  dark: 'bi-moon-stars',
  system: 'bi-circle-half',
} as const
export const LIGHT_FRAME = { theme: 'light', background: 'rgb(255, 255, 255)' }
export const DARK_FRAME = { theme: 'dark', background: 'rgb(33, 37, 41)' }

/** Set only this tab's system preference, leaving its personal pick intact. */
export async function emulateSystemTheme(
  page: Page,
  mode: 'light' | 'dark',
): Promise<void> {
  await page.emulateMedia({ colorScheme: mode })
}

/** Drive a theme control through its actionable states. */
async function clickControl(expect: Expect, control: Locator): Promise<void> {
  await control.scrollIntoViewIfNeeded()
  await expect(control).toBeVisible()
  await expect(control).toBeEnabled()
  await control.focus()
  await control.click()
}

/** Choose in the header and settle on the reply, closed menu and returned focus. */
export async function chooseHeaderTheme(
  expect: Expect,
  page: Page,
  pick: ThemeTestPick,
): Promise<void> {
  const toggle = page.getByTestId('nav-theme')
  await clickControl(expect, toggle)
  await expect(page.getByTestId('nav-theme-menu')).toBeVisible()
  await clickControl(
    expect,
    page.getByTestId(
      pick === null ? 'nav-theme-default' : `nav-theme-option-${pick}`,
    ),
  )
  await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
  await expect(toggle).toBeEnabled()
  await expect(toggle).toBeFocused()
}

/** Open the profile's live theme window through Change. */
export async function openProfileTheme(
  expect: Expect,
  page: Page,
): Promise<void> {
  await clickControl(expect, page.getByTestId('profile-theme-change'))
  await expect(page.getByTestId('profile-theme-modal')).toBeVisible()
  await expect(page.getByTestId('modal')).toHaveAccessibleName('Theme')
}

/** Choose in the profile and wait for success to close the window and return focus. */
export async function chooseProfileTheme(
  expect: Expect,
  page: Page,
  pick: ThemeTestPick,
): Promise<void> {
  await openProfileTheme(expect, page)
  await clickControl(
    expect,
    page.getByTestId(
      pick === null ? 'profile-theme-default' : `profile-theme-option-${pick}`,
    ),
  )
  await expect(page.getByTestId('profile-theme-modal')).toBeHidden()
  await expect(page.getByTestId('profile-theme-change')).toBeEnabled()
  await expect(page.getByTestId('profile-theme-change')).toBeFocused()
}

/** Assert the effective palette independently of the displayed position and default. */
export async function expectThemeMode(
  expect: Expect,
  page: Page,
  mode: 'light' | 'dark',
): Promise<void> {
  await expect(page.locator('html')).toHaveAttribute('data-bs-theme', mode)
}

/** Check the header's name, icon, three radio rows and always available reset. */
export async function expectThemeHeader(
  expect: Expect,
  page: Page,
  position: ThemeTestPosition,
  isDefault = false,
): Promise<void> {
  const toggle = page.getByTestId('nav-theme')
  await expectThemeHeaderPresentation(expect, page, position)
  await clickControl(expect, toggle)
  await expect(page.getByTestId('nav-theme-menu')).toBeVisible()
  for (const row of THEME_TEST_POSITIONS) {
    const option = page.getByTestId(`nav-theme-option-${row}`)
    await expect(option).toHaveRole('menuitemradio')
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
    if (row === position) {
      await expect(option).toHaveClass(/\bactive\b/)
    } else {
      await expect(option).not.toHaveClass(/\bactive\b/)
    }
  }
  const reset = page.getByTestId('nav-theme-default')
  await expect(reset).toBeVisible()
  await expect(reset).toBeEnabled()
  await expect(reset).toHaveRole('menuitem')
  await expect(reset).toHaveText('Use site default')
  await expect(reset).not.toHaveAttribute('aria-checked')
  await expect(reset.locator('.bi-check2')).toHaveCount(0)
  await toggle.press('Escape')
  await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
  await expect(toggle).toBeFocused()
}

/** Check the header while an account theme choice is unavailable. */
export async function expectUnavailableThemeHeader(
  expect: Expect,
  page: Page,
  position: ThemeTestPosition,
): Promise<void> {
  const toggle = page.getByTestId('nav-theme')
  await expectThemeHeaderPresentation(expect, page, position)
  await expect(toggle).toBeDisabled()
  await expect(toggle).toHaveAttribute(
    'aria-describedby',
    'hilos-connection-status-text',
  )
  await expect(page.locator('#hilos-connection-status-text')).toHaveCount(1)
  await expect(page.getByTestId('nav-theme-menu')).toBeHidden()
}

async function expectThemeHeaderPresentation(
  expect: Expect,
  page: Page,
  position: ThemeTestPosition,
): Promise<void> {
  const toggle = page.getByTestId('nav-theme')
  await expect(toggle).toHaveAttribute('data-position', position)
  await expect(toggle).toHaveAccessibleName(
    `Theme: ${THEME_TEST_LABELS[position]}`,
  )
  await expect(toggle.locator('i')).toHaveClass(
    `bi ${THEME_TEST_ICONS[position]}`,
  )
}

/** Check the profile line and live window, preserving a window the caller opened. */
export async function expectProfileTheme(
  expect: Expect,
  page: Page,
  position: ThemeTestPosition,
  isDefault = false,
): Promise<void> {
  await expect(page.getByTestId('profile-theme')).toHaveText(
    `${THEME_TEST_LABELS[position]}${isDefault ? ' · default' : ''}`,
  )
  const alreadyOpen = await page.getByTestId('profile-theme-modal').isVisible()
  if (!alreadyOpen) await openProfileTheme(expect, page)
  for (const row of THEME_TEST_POSITIONS) {
    const option = page.getByTestId(`profile-theme-option-${row}`)
    if (row === position) {
      await expect(option).toHaveAttribute('aria-current', 'true')
      await expect(option).toHaveClass(/\bactive\b/)
    } else {
      await expect(option).not.toHaveAttribute('aria-current', 'true')
      await expect(option).not.toHaveClass(/\bactive\b/)
    }
    await expect(option.locator('.badge')).toHaveCount(
      row === position && isDefault ? 1 : 0,
    )
  }
  const reset = page.getByTestId('profile-theme-default')
  await expect(reset).toBeVisible()
  await expect(reset).toBeEnabled()
  await expect(reset).toHaveText('Use site default')
  await expect(reset).not.toHaveAttribute('aria-current')
  await expect(reset.locator('.bi-check2')).toHaveCount(0)
  if (!alreadyOpen) {
    await clickControl(expect, page.getByTestId('profile-theme-close'))
    await expect(page.getByTestId('profile-theme-modal')).toBeHidden()
    await expect(page.getByTestId('profile-theme-change')).toBeFocused()
  }
}

/** Probe both the head attribute and painted body with external application JS blocked. */
export async function expectThemeFirstFrame(
  expect: Expect,
  page: Page,
  path: string,
  mode: 'light' | 'dark',
): Promise<void> {
  expect(await openFirstFrame(page, path)).toEqual(
    mode === 'dark' ? DARK_FRAME : LIGHT_FRAME,
  )
}
