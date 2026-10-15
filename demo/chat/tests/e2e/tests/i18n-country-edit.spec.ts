import { i18nLocaleRow } from '../../../../../framework/frontend/e2e/index.js'
import { expect, test } from '@playwright/test'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { switchCountryOn } from '../helpers/i18n.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'

test('edits a switched-off country and saves its suggested default locale', async ({
  page,
}) => {
  const writes: Array<{
    countryId: number
    currencySymbol: string
    currencyCode: string
    defaultLocaleId: number | null
  }> = []
  page.on('websocket', (socket) =>
    socket.on('framesent', ({ payload }) => {
      const frame = JSON.parse(payload.toString())
      if (
        frame.type === 'action' &&
        frame.action === 'hilos_i18n_country_row_update'
      )
        writes.push(frame.data)
    }),
  )
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/languages/en/locales', PAGE_READY)

  const add = i18nLocaleRow(page, 'en-IE').getByTestId('i18n-locales-add-en-IE')
  await add.scrollIntoViewIfNeeded()
  await expect(add).toBeVisible()
  await expect(add).toBeEnabled()
  await add.focus()
  await add.click()

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

  await gotoPage(page, '/hilos/i18n/countries', PAGE_READY)
  const search = page.getByTestId('hilos-table-search')
  await search.fill('')
  await search.pressSequentially('ie', { delay: 10 })
  const row = page
    .getByTestId(/^hilos-table-row-/)
    .filter({ has: page.getByTestId('i18n-countries-link-ie') })
  const edit = row.getByTestId('i18n-countries-edit-ie')
  await edit.scrollIntoViewIfNeeded()
  await expect(edit).toBeVisible()
  await expect(edit).toBeEnabled()
  await edit.focus()
  await edit.click()

  await expect(page.getByTestId('i18n-country-title')).toHaveText(
    'Edit country · Ireland',
  )
  const defaultLocale = page.getByTestId('i18n-country-edit-default-locale')
  await expect(defaultLocale).toHaveValue(/^[1-9][0-9]*$/)
  await expect(defaultLocale.locator('option:checked')).toHaveText(
    'en-IE — switched off',
  )
  const selectedLocaleId = Number(await defaultLocale.inputValue())
  await expect(
    page.getByTestId('i18n-country-edit-default-locale-hint'),
  ).toContainText('Suggested by the built-in catalog — save to keep it.')

  const currency = page.getByTestId('i18n-country-edit-currency-code')
  await currency.fill('')
  await currency.pressSequentially('EU', { delay: 10 })
  const save = page.getByTestId('i18n-country-save')
  await expect(save).toBeDisabled()
  await currency.pressSequentially('R', { delay: 10 })
  const symbol = page.getByTestId('i18n-country-edit-currency-symbol')
  await symbol.fill('')
  await symbol.pressSequentially('IE€', { delay: 10 })

  await save.scrollIntoViewIfNeeded()
  await expect(save).toBeVisible()
  await expect(save).toBeEnabled()
  await save.focus()
  await save.click()
  await expect(page.getByTestId('i18n-country-window')).toHaveCount(0)
  await expect(
    page
      .getByTestId('hilos-toast-success')
      .filter({ hasText: 'Country saved.' }),
  ).toBeVisible()
  await expect(row).toContainText('IE€')
  await expect(row).toContainText('EUR')
  await expect(row).toContainText('en-IE')
  const countryId = Number(
    (await row.getAttribute('data-id'))!.replace('hilos-table-row-', ''),
  )
  expect(Number.isInteger(countryId)).toBe(true)
  expect(countryId).toBeGreaterThan(0)
  expect(writes).toEqual([
    {
      countryId,
      currencySymbol: 'IE€',
      currencyCode: 'EUR',
      defaultLocaleId: selectedLocaleId,
    },
  ])
})

test('opens a switched-on country from its card only to view its fields', async ({
  page,
}) => {
  await signUpAdmin(page)
  await switchCountryOn('nz')
  await gotoPage(page, '/hilos/i18n/countries/nz', PAGE_READY)

  const view = page.getByTestId('country-card-view')
  await view.scrollIntoViewIfNeeded()
  await expect(view).toBeVisible()
  await expect(view).toBeEnabled()
  await view.focus()
  await view.click()

  await expect(page.getByTestId('i18n-country-title')).toHaveText(
    'Country · New Zealand',
  )
  for (const field of [
    'currency-symbol',
    'currency-code',
    'default-locale',
    'code',
  ]) {
    await expect(page.getByTestId(`i18n-country-edit-${field}`)).toBeDisabled()
  }
  await expect(page.getByTestId('i18n-country-plate')).toContainText(
    'switched on',
  )
  await expect(page.getByTestId('i18n-country-save')).toHaveCount(0)

  const close = page.getByTestId('i18n-country-close')
  await close.scrollIntoViewIfNeeded()
  await expect(close).toBeVisible()
  await expect(close).toBeEnabled()
  await close.focus()
  await close.click()
  await expect(page.getByTestId('i18n-country-window')).toHaveCount(0)
})
