import { expect, test } from '@playwright/test'
import {
  shownByTestId,
  watchHeight,
  watchLeft,
  watchTop,
  i18nLocaleRow,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit } from '../helpers/session.js'

test('switches on a known locale with a still window on wide and narrow screens', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/languages/en/locales', PAGE_READY)

  const australianRow = i18nLocaleRow(page, 'en-AU')
  const add = australianRow.getByTestId('i18n-locales-add-en-AU')
  await clickSubmit(add)

  const submit = page.getByTestId('i18n-locale-submit')
  await clickSubmit(submit)
  await expect(submit).toHaveCount(0)
  await expect(
    page
      .getByTestId('hilos-toast-success')
      .filter({ hasText: 'Locale added.' }),
  ).toBeVisible()

  const edit = australianRow.getByTestId('i18n-locales-edit-en-AU')
  await clickSubmit(edit)

  const confirm = page.getByTestId('i18n-locale-switch-on-confirm')
  for (const viewport of [
    { width: 1440, height: 1000 },
    { width: 375, height: 812 },
  ]) {
    await page.setViewportSize(viewport)
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
    await clickSubmit(switchOn)
    await expect(page.getByTestId('i18n-locale-title')).toHaveText(
      'Switch on locale · English in Australia',
    )
    await expect(page.getByTestId('i18n-locale-switch-on-note')).toBeVisible()
    await expect(
      page.getByTestId('i18n-locale-switch-on-unsaved'),
    ).toBeVisible()
    await expect(confirm).toBeDisabled()
    for (const bookmark of geometry) await bookmark.unchanged()

    const back = page.getByTestId('i18n-locale-switch-on-back')
    await clickSubmit(back)
    await expect(date).toBeVisible()
    await expect(date).toHaveValue('YYYY-MM-DD')
    for (const bookmark of geometry) await bookmark.unchanged()

    await date.selectOption(savedDate)
    await expect(submit).toBeDisabled()
    await clickSubmit(switchOn)
    await expect(page.getByTestId('i18n-locale-title')).toHaveText(
      'Switch on locale · English in Australia',
    )
    await expect(page.getByTestId('i18n-locale-switch-on-note')).toBeVisible()
    await expect(page.getByTestId('i18n-locale-switch-on-unsaved')).toHaveCount(
      0,
    )
    for (const bookmark of geometry) await bookmark.unchanged()

    await clickSubmit(back)
    await expect(date).toBeVisible()
    await expect(submit).toBeDisabled()
    for (const bookmark of geometry) await bookmark.unchanged()
  }

  const switchOn = page.getByTestId('i18n-locale-switch-on')
  await clickSubmit(switchOn)
  await expect(confirm).toBeEnabled()

  await clickSubmit(confirm)
  await expect(confirm).toHaveCount(0)
  await expect(
    page
      .getByTestId('hilos-toast-success')
      .filter({ hasText: 'Locale switched on.' }),
  ).toBeVisible()
  await expect(shownByTestId(page, 'i18n-locales-enabled-en-AU')).toBeChecked()
  await expect(shownByTestId(page, 'i18n-locales-view-en-AU')).toBeVisible()
  await expect(page.getByTestId('i18n-locales-edit-en-AU')).toHaveCount(0)
})
