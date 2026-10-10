import { expect, test } from '@playwright/test'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'

test('adds a known locale and edits its formats from the language table', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/languages/en/locales', PAGE_READY)

  const britishRow = page.getByTestId('hilos-table-row-en-GB')
  const add = britishRow.getByTestId('i18n-locales-add-en-GB')
  await add.scrollIntoViewIfNeeded()
  await expect(add).toBeVisible()
  await expect(add).toBeEnabled()
  await add.focus()
  await add.click()

  await expect(page.getByTestId('i18n-locale-title')).toHaveText(
    'Add locale · English in United Kingdom',
  )
  await expect(page.getByTestId('i18n-locale-field-date')).toHaveValue(
    'DD/MM/YYYY',
  )
  const submit = page.getByTestId('i18n-locale-submit')
  await submit.scrollIntoViewIfNeeded()
  await expect(submit).toBeVisible()
  await expect(submit).toBeEnabled()
  await submit.focus()
  await submit.click()
  await expect(submit).toHaveCount(0)
  await expect(
    page
      .getByTestId('hilos-toast-success')
      .filter({ hasText: 'Locale added.' }),
  ).toBeVisible()
  await expect(britishRow.getByTestId('i18n-locales-code-en-GB')).toHaveText(
    'en-GB',
  )
  await expect(
    britishRow.getByTestId('i18n-locales-enabled-en-GB'),
  ).not.toBeChecked()

  const edit = britishRow.getByTestId('i18n-locales-edit-en-GB')
  await edit.scrollIntoViewIfNeeded()
  await expect(edit).toBeVisible()
  await expect(edit).toBeEnabled()
  await edit.focus()
  await edit.click()
  await page.getByTestId('i18n-locale-field-date').selectOption('YYYY-MM-DD')
  const save = page.getByTestId('i18n-locale-submit')
  await save.scrollIntoViewIfNeeded()
  await expect(save).toBeVisible()
  await expect(save).toBeEnabled()
  await save.focus()
  await save.click()
  await expect(save).toHaveCount(0)
  await expect(
    page
      .getByTestId('hilos-toast-success')
      .filter({ hasText: 'Locale saved.' }),
  ).toBeVisible()

  await edit.scrollIntoViewIfNeeded()
  await expect(edit).toBeVisible()
  await expect(edit).toBeEnabled()
  await edit.focus()
  await edit.click()
  await expect(page.getByTestId('i18n-locale-field-date')).toHaveValue(
    'YYYY-MM-DD',
  )
})

test('opens a switched-on countryless locale only to view its formats', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/languages/en/locales', PAGE_READY)

  const view = page
    .getByTestId('hilos-table-row-en')
    .getByTestId('i18n-locales-view-en')
  await view.scrollIntoViewIfNeeded()
  await expect(view).toBeVisible()
  await expect(view).toBeEnabled()
  await view.focus()
  await view.click()

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
