import type { Locator, Page } from '@playwright/test'

import { dismissToasts } from './toasts.js'

/** Prefix of each setting row's edit control in all three SDKs. */
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
 * Opens one setting's edit dialog and waits until its custom switch is ready.
 *
 * @param page Page carrying the settings table.
 * @param key Catalog key of the setting to edit.
 */
export async function openSettingEdit(page: Page, key: string): Promise<void> {
  await page.getByTestId(`${SETTING_EDIT_PREFIX}${key}`).click()
  await page.getByTestId(SETTING_CUSTOM).waitFor({ state: 'visible' })
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
  await page.getByTestId(SETTING_CUSTOM).check()

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

  const edit = page.getByTestId(`${SETTING_EDIT_PREFIX}${key}`)
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
