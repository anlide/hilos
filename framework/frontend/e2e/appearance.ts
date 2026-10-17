import type { Expect, Locator, Page } from '@playwright/test'

import { shownByTestId, TABLE } from './table.js'
import { THEME_TEST_LABELS, type ThemeTestPosition } from './theme.js'
import { dismissToasts } from './toasts.js'

export const APPEARANCE_PATH = '/hilos/appearance'
export const THEME_SWITCHING_KEY = 'theme.switching_enabled'
export const THEME_DEFAULT_KEY = 'theme.default'

/** Read a setting in the visible table or card projection. */
export function appearanceSettingValue(page: Page, key: string): Locator {
  return shownByTestId(page, `appearance-setting-value-${key}`)
}

/** Check the two typed rows, their catalog defaults, editor count and table name. */
export async function expectAppearanceRows(
  expect: Expect,
  page: Page,
  switchingEnabled = true,
  defaultTheme: ThemeTestPosition = 'system',
): Promise<void> {
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Appearance')
  await expect(appearanceSettingValue(page, THEME_SWITCHING_KEY)).toHaveText(
    switchingEnabled ? 'On' : 'Off',
  )
  await expect(appearanceSettingValue(page, THEME_DEFAULT_KEY)).toHaveText(
    THEME_TEST_LABELS[defaultTheme],
  )
  await expect(
    shownByTestId(page, `appearance-setting-default-${THEME_SWITCHING_KEY}`),
  ).toHaveText('On')
  await expect(
    shownByTestId(page, `appearance-setting-default-${THEME_DEFAULT_KEY}`),
  ).toHaveText('System')
  await expect(shownByTestId(page, /^appearance-setting-value-/)).toHaveCount(2)
  await expect(shownByTestId(page, /^appearance-setting-edit-/)).toHaveCount(2)
  const table = page.getByTestId(TABLE)
  await expect(
    table.getByRole('table').or(table.getByRole('list')),
  ).toHaveAccessibleName('Appearance')
}

/** Open one of Appearance's two editors and check the live dialog's name. */
export async function openAppearanceSetting(
  expect: Expect,
  page: Page,
  key: typeof THEME_SWITCHING_KEY | typeof THEME_DEFAULT_KEY,
): Promise<void> {
  await dismissToasts(page)
  const edit = shownByTestId(page, `appearance-setting-edit-${key}`)
  await edit.scrollIntoViewIfNeeded()
  await expect(edit).toBeVisible()
  await expect(edit).toBeEnabled()
  await edit.focus()
  await edit.click()
  await expect(page.getByTestId('modal')).toBeVisible()
  await expect(page.getByTestId('modal')).toHaveAccessibleName(
    key === THEME_SWITCHING_KEY
      ? 'Edit · Theme switching'
      : 'Edit · Default theme',
  )
}

/** Save the open switching editor and wait for its reply, row and returned focus. */
export async function saveAppearanceSwitching(
  expect: Expect,
  page: Page,
  enabled: boolean,
): Promise<void> {
  await saveAppearanceWindow(
    expect,
    page,
    'appearance-switching-save',
    'Theme switching saved.',
    THEME_SWITCHING_KEY,
  )
  await expect(appearanceSettingValue(page, THEME_SWITCHING_KEY)).toHaveText(
    enabled ? 'On' : 'Off',
  )
}

/** Save the open default-theme editor and wait for its reply, row and returned focus. */
export async function saveAppearanceDefaultTheme(
  expect: Expect,
  page: Page,
  position: ThemeTestPosition,
): Promise<void> {
  await saveAppearanceWindow(
    expect,
    page,
    'appearance-default-theme-save',
    'Default theme saved.',
    THEME_DEFAULT_KEY,
  )
  await expect(appearanceSettingValue(page, THEME_DEFAULT_KEY)).toHaveText(
    THEME_TEST_LABELS[position],
  )
}

/** Drive Save through its actionable states and settle before reading its result. */
async function saveAppearanceWindow(
  expect: Expect,
  page: Page,
  saveId: string,
  sentence: string,
  key: string,
): Promise<void> {
  const save = page.getByTestId(saveId)
  await save.scrollIntoViewIfNeeded()
  await expect(save).toBeVisible()
  await expect(save).toBeEnabled()
  await save.focus()
  await save.click()
  await expect(page.getByTestId('modal')).toBeHidden()
  await expect(
    page.getByTestId('hilos-toasts').getByText(sentence),
  ).toBeVisible()
  await expect(
    shownByTestId(page, `appearance-setting-edit-${key}`),
  ).toBeFocused()
}
