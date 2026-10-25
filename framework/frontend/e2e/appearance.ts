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

/** Check the one heading, the two typed rows, their catalog defaults, editor count and table name. */
export async function expectAppearanceRows(
  expect: Expect,
  page: Page,
  switchingEnabled = true,
  defaultTheme: ThemeTestPosition = 'system',
): Promise<void> {
  const heading = page
    .getByTestId('hilos-admin-title')
    .or(page.getByTestId('hilos-page-head-title'))
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveText('Appearance')
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

/**
 * The shared Appearance head on a ready screen: one real head and one h1,
 * the palette mark, the catalog lead, the Hilos trail with the last crumb as
 * text, no tab row and no child cards, and the table directly under the head.
 * The browser title stays with the spec.
 *
 * @param expect The spec's expect.
 * @param page A ready Appearance page.
 */
export async function expectAppearancePageHead(
  expect: Expect,
  page: Page,
): Promise<void> {
  const head = page.getByTestId('hilos-page-head')
  await expect(head).toHaveCount(1)
  await expect(head).toBeVisible()
  await expect(page.getByTestId('hilos-page-head-skeleton')).toHaveCount(0)
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveAttribute('data-id', 'hilos-page-head-title')
  await expect(heading).toHaveText('Appearance')
  await expect(head.getByRole('heading', { level: 1 })).toHaveCount(1)

  const block = head.getByTestId('hilos-page-head-block')
  const circle = block.locator('.rounded-circle')
  await expect(circle).toHaveCount(1)
  await expect(circle).toHaveAttribute('aria-hidden', 'true')
  await expect(circle.locator('i')).toHaveCount(1)
  await expect(circle.locator('i')).toHaveClass(/\bbi-palette\b/)
  await expect(block.locator('p')).toHaveCount(1)
  await expect(block.locator('p')).toHaveText(
    'Theme switching and the default theme.',
  )
  await expect(
    page.getByText(
      'Theme switching and the default theme for this installation.',
    ),
  ).toHaveCount(0)

  const crumbs = head.getByTestId('hilos-breadcrumb')
  await expect(crumbs).toHaveCount(1)
  await expect(crumbs).toHaveRole('navigation')
  await expect(crumbs).toHaveAccessibleName('Breadcrumb')
  await expect
    .poll(() =>
      crumbs.getByRole('listitem').evaluateAll((items) =>
        items.map((item) => {
          const words = item.cloneNode(true) as Element
          for (const hidden of words.querySelectorAll('[aria-hidden="true"]'))
            hidden.remove()
          return words.textContent?.trim()
        }),
      ),
    )
    .toEqual(['Hilos', 'Appearance'])
  await expect(crumbs.getByTestId('hilos-breadcrumb-hilos')).toHaveAttribute(
    'href',
    '/hilos',
  )
  const lastCrumb = crumbs.locator('[aria-current]')
  await expect(lastCrumb).toHaveCount(1)
  await expect(lastCrumb).toHaveAttribute('aria-current', 'page')
  await expect(lastCrumb.locator('a')).toHaveCount(0)
  await expect(lastCrumb.locator('xpath=following-sibling::*')).toHaveCount(0)

  await expect(page.getByTestId('hilos-page-tabs')).toHaveCount(0)
  await expect(page.getByTestId('hilos-admin-children')).toHaveCount(0)
  await expect(page.getByTestId('hilos-admin-title')).toHaveCount(0)
  await expect(head.locator('xpath=following-sibling::*[1]')).toHaveAttribute(
    'data-id',
    'hilos-viewport-table',
  )
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
