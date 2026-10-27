import type { Expect, Locator, Page } from '@playwright/test'

import { shownByTestId } from './table.js'
import { dismissToasts } from './toasts.js'

/**
 * Prefix of each setting row's edit control in all three SDKs. The control lives in a
 * cell, and a table draws its cells twice — once in the row and once in the
 * card — so it is always looked up through the copy on screen.
 */
const SETTING_EDIT_PREFIX = 'hilos-settings-edit-'

/** `data-id` of the switch between a catalog default and a custom value. */
const SETTING_CUSTOM = 'hilos-settings-edit-custom'

/** `data-id` of the text or checkbox control holding the custom value. */
const SETTING_VALUE = 'hilos-settings-edit-value'

/** `data-id` of the setting dialog's submit control. */
const SETTING_SAVE = 'hilos-settings-edit-save'

/** Accessible name of an edit control whose row still uses its catalog default. */
const SET_CUSTOM_VALUE_LABEL = 'Set custom value'

/**
 * Checks the Settings section head and the table named by its single heading.
 * Navigation and the scenario stay with the calling spec.
 *
 * @param expect The spec's expect.
 * @param page A page showing the Settings catalog.
 */
export async function expectSettingsPageHead(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expect(page.getByTestId('hilos-page-head')).toBeVisible()
  await expect(page.getByTestId('hilos-page-head-skeleton')).toHaveCount(0)
  await expect(page.getByTestId('hilos-admin-title')).toHaveCount(0)
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveAttribute('data-id', 'hilos-page-head-title')
  await expect(heading).toHaveText('Settings')

  const block = page.getByTestId('hilos-page-head-block')
  await expect(block.locator('.bi-sliders')).toBeVisible()
  await expect(block.locator('p')).toHaveText(
    'Application setting catalog: catalog defaults, overrides, and references.',
  )

  const crumbs = page.getByTestId('hilos-breadcrumb')
  await expect(crumbs).toHaveAccessibleName('Breadcrumb')
  await expect(crumbs.getByRole('listitem')).toHaveCount(2)
  const root = crumbs.getByTestId('hilos-breadcrumb-hilos')
  await expect(root).toHaveText('Hilos')
  await expect(root).toHaveAttribute('href', '/hilos')
  await expect(crumbs.getByRole('link')).toHaveCount(1)
  const current = crumbs.locator('[aria-current]')
  await expect(current).toHaveCount(1)
  await expect(current).toHaveAttribute('aria-current', 'page')
  await expect(current.locator('a')).toHaveCount(0)
  // React and Angular draw a decorative divider inside the current crumb.
  await expect
    .poll(() =>
      current.evaluate((crumb) => {
        const words = crumb.cloneNode(true) as Element
        for (const hidden of words.querySelectorAll('[aria-hidden="true"]'))
          hidden.remove()
        return words.textContent?.trim()
      }),
    )
    .toBe('Settings')
  await expect(current.locator('xpath=following-sibling::*')).toHaveCount(0)

  await expect(page.getByTestId('hilos-page-tabs')).toHaveCount(0)
  await expect(page.getByTestId('hilos-admin-children')).toHaveCount(0)
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(page.getByRole('table')).toHaveAccessibleName('Settings')
}

/**
 * Opens one setting's edit dialog and waits until its controls are ready.
 *
 * @param page Page carrying the settings table.
 * @param key Catalog key of the setting to edit.
 */
export async function openSettingEdit(page: Page, key: string): Promise<void> {
  await shownByTestId(page, `${SETTING_EDIT_PREFIX}${key}`).click()
  await page
    .getByTestId(SETTING_CUSTOM)
    .or(page.getByTestId(SETTING_VALUE))
    .first()
    .waitFor({ state: 'visible' })
}

/**
 * Opens one setting, enables its custom value and writes a draft.
 *
 * @param page Page carrying the settings table.
 * @param key Catalog key of the setting to edit.
 * @param value Custom value to draft.
 * @returns The value control for assertions about the unsaved draft.
 */
export async function draftCustomSetting(
  page: Page,
  key: string,
  value: string | boolean,
): Promise<Locator> {
  await openSettingEdit(page, key)
  const custom = page.getByTestId(SETTING_CUSTOM)
  if (await custom.isVisible()) {
    await custom.check()
  }

  const valueControl = page.getByTestId(SETTING_VALUE)
  if (typeof value === 'boolean') {
    if (value) {
      await valueControl.check()
    } else {
      await valueControl.uncheck()
    }
  } else {
    await valueControl.fill('')
    await valueControl.pressSequentially(value, { delay: 10 })
  }

  return valueControl
}

/**
 * Saves one custom setting and settles when its dialog closes.
 *
 * @param page Page carrying the settings table.
 * @param key Catalog key of the setting to edit.
 * @param value Custom value to save.
 */
export async function setCustomSetting(
  page: Page,
  key: string,
  value: string | boolean,
): Promise<void> {
  await draftCustomSetting(page, key, value)
  await submitSetting(page)
}

/**
 * Clears one custom setting, doing nothing when it already uses its default.
 *
 * @param page Page carrying the settings table.
 * @param key Catalog key of the setting to clear.
 */
export async function clearCustomSetting(
  page: Page,
  key: string,
): Promise<void> {
  await dismissToasts(page)

  const edit = shownByTestId(page, `${SETTING_EDIT_PREFIX}${key}`)
  if ((await edit.getAttribute('aria-label')) === SET_CUSTOM_VALUE_LABEL) {
    return
  }

  await openSettingEdit(page, key)
  await page.getByTestId(SETTING_CUSTOM).uncheck()
  await submitSetting(page)
}

/** Drives the save control through its actionable states and waits for settle. */
async function submitSetting(page: Page): Promise<void> {
  const save = page.getByTestId(SETTING_SAVE)
  await save.scrollIntoViewIfNeeded()
  await save.waitFor({ state: 'visible' })
  await save.focus()
  await save.click()
  await save.waitFor({ state: 'detached' })
}
