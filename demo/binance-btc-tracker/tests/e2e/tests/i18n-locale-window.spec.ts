import { i18nLocaleRow } from '../../../../../framework/frontend/e2e/index.js'
import { expect, test } from '@playwright/test'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit } from '../helpers/session.js'

test('adds a known locale and edits its formats from the language table', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/languages/en/locales', PAGE_READY)

  const britishRow = i18nLocaleRow(page, 'en-GB')
  const add = britishRow.getByTestId('i18n-locales-add-en-GB')
  await clickSubmit(add)

  await expect(page.getByTestId('i18n-locale-title')).toHaveText(
    'Add locale · English in United Kingdom',
  )
  await expect(page.getByTestId('i18n-locale-field-date')).toHaveValue(
    'DD/MM/YYYY',
  )
  const submit = page.getByTestId('i18n-locale-submit')
  await clickSubmit(submit)
  await expect(submit).toHaveCount(0)
  await expect(
    page
      .getByTestId('hilos-toast-success')
      .filter({ hasText: 'Locale en-GB added.' }),
  ).toBeVisible()
  await expect(britishRow.getByTestId('i18n-locales-code-en-GB')).toHaveText(
    'en-GB',
  )
  await expect(
    britishRow.getByTestId('i18n-locales-enabled-en-GB'),
  ).not.toBeChecked()

  const edit = britishRow.getByTestId('i18n-locales-edit-en-GB')
  await clickSubmit(edit)
  await page.getByTestId('i18n-locale-field-date').selectOption('YYYY-MM-DD')
  const save = page.getByTestId('i18n-locale-submit')
  await clickSubmit(save)
  await expect(save).toHaveCount(0)
  await expect(
    page
      .getByTestId('hilos-toast-success')
      .filter({ hasText: 'Locale en-GB saved.' }),
  ).toBeVisible()

  await clickSubmit(edit)
  await expect(page.getByTestId('i18n-locale-field-date')).toHaveValue(
    'YYYY-MM-DD',
  )
})

test('opens a switched-on countryless locale only to view its formats', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/languages/en/locales', PAGE_READY)

  const view = i18nLocaleRow(page, 'en').getByTestId('i18n-locales-view-en')
  await clickSubmit(view)

  await expect(page.getByTestId('i18n-locale-title')).toHaveText(
    'Locale · English without a country',
  )
  for (const field of [
    'date',
    'time',
    'number',
    'phone',
    'address',
    'measurement',
    'collation',
  ]) {
    await expect(page.getByTestId(`i18n-locale-field-${field}`)).toBeDisabled()
  }
  await expect(page.getByTestId('i18n-locale-plate')).toContainText(
    'switched on',
  )
  await expect(page.getByTestId('i18n-locale-submit')).toHaveCount(0)
  await expect(page.getByTestId('i18n-locale-close')).toBeVisible()
})
