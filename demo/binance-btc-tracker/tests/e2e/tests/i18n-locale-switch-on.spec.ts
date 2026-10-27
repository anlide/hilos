import { expect, test } from '@playwright/test'
import {
  shownByTestId,
  watchHeight,
  watchLeft,
  watchTop,
  i18nLocaleRow,
  clickSubmit,
  gotoPage,
  PAGE_READY,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'

test('switches on a known locale with a still window', async ({ page }) => {
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, '/hilos/i18n/languages/en/locales', PAGE_READY)

  const australianRow = i18nLocaleRow(page, 'en-AU')
  const add = australianRow.getByTestId('i18n-locales-add-en-AU')
  await clickSubmit(expect, add)

  const submit = page.getByTestId('i18n-locale-submit')
  await clickSubmit(expect, submit)
  await expect(submit).toHaveCount(0)
  await expect(
    page
      .getByTestId('hilos-toast-success')
      .filter({ hasText: 'Locale en-AU added.' }),
  ).toBeVisible()

  const edit = australianRow.getByTestId('i18n-locales-edit-en-AU')
  await clickSubmit(expect, edit)

  const confirm = page.getByTestId('i18n-locale-switch-on-confirm')
  await page.setViewportSize({ width: 1440, height: 1000 })
  const frame = page.getByTestId('modal').locator('.modal-content')
  const primary = page.getByTestId(/^i18n-locale-(submit|switch-on-confirm)$/)
  const frameTop = await watchTop(frame)
  const frameLeft = await watchLeft(frame)
  const frameHeight = await watchHeight(frame)
  const primaryTop = await watchTop(primary)
  const primaryHeight = await watchHeight(primary)
  const titleTop = await watchTop(page.getByTestId('i18n-locale-title'))
  const geometry = [
    frameTop,
    frameLeft,
    frameHeight,
    primaryTop,
    primaryHeight,
    titleTop,
  ]

  // The tallest confirmation includes the catalog note and an unsaved draft.
  const date = page.getByTestId('i18n-locale-field-date')
  const savedDate = await date.inputValue()
  await date.selectOption('YYYY-MM-DD')
  await expect(submit).toBeEnabled()

  const switchOn = page.getByTestId('i18n-locale-switch-on')
  await clickSubmit(expect, switchOn)
  await expect(page.getByTestId('i18n-locale-title')).toHaveText(
    'Switch on locale · English in Australia',
  )
  await expect(page.getByTestId('i18n-locale-switch-on-note')).toBeVisible()
  await expect(page.getByTestId('i18n-locale-switch-on-unsaved')).toBeVisible()
  await expect(confirm).toBeDisabled()
  for (const bookmark of geometry) await bookmark.unchanged()

  const back = page.getByTestId('i18n-locale-switch-on-back')
  await clickSubmit(expect, back)
  await expect(date).toBeVisible()
  await expect(date).toHaveValue('YYYY-MM-DD')
  for (const bookmark of geometry) await bookmark.unchanged()

  await date.selectOption(savedDate)
  await expect(submit).toBeDisabled()
  await clickSubmit(expect, switchOn)
  await expect(page.getByTestId('i18n-locale-title')).toHaveText(
    'Switch on locale · English in Australia',
  )
  await expect(page.getByTestId('i18n-locale-switch-on-note')).toBeVisible()
  await expect(page.getByTestId('i18n-locale-switch-on-unsaved')).toHaveCount(0)
  for (const bookmark of geometry) await bookmark.unchanged()

  await clickSubmit(expect, back)
  await expect(date).toBeVisible()
  await expect(submit).toBeDisabled()
  for (const bookmark of geometry) await bookmark.unchanged()

  await clickSubmit(expect, switchOn)
  await expect(confirm).toBeEnabled()

  await clickSubmit(expect, confirm)
  await expect(confirm).toHaveCount(0)
  await expect(
    page
      .getByTestId('hilos-toast-success')
      .filter({ hasText: 'Locale en-AU switched on.' }),
  ).toBeVisible()
  await expect(shownByTestId(page, 'i18n-locales-enabled-en-AU')).toBeChecked()
  await expect(shownByTestId(page, 'i18n-locales-view-en-AU')).toBeVisible()
  await expect(page.getByTestId('i18n-locales-edit-en-AU')).toHaveCount(0)
})
