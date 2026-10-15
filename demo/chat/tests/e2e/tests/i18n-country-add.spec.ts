import { expect, test } from '@playwright/test'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'

test('adds a custom country after confirming its upkeep', async ({ page }) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/countries', PAGE_READY)

  const open = page.getByTestId('hilos-table-main-action')
  await open.scrollIntoViewIfNeeded()
  await expect(open).toBeVisible()
  await expect(open).toBeEnabled()
  await open.focus()
  await open.click()

  const code = page.getByTestId('i18n-country-add-code')
  await code.fill('')
  await code.pressSequentially('gg', { delay: 10 })
  const symbol = page.getByTestId('i18n-country-add-currency-symbol')
  await symbol.fill('')
  await symbol.pressSequentially('£', { delay: 10 })
  const currency = page.getByTestId('i18n-country-add-currency-code')
  await currency.fill('')
  await currency.pressSequentially('GBP', { delay: 10 })

  await expect(page.getByTestId('i18n-country-add-plate')).toHaveAttribute(
    'data-kind',
    'own',
  )
  const submit = page.getByTestId('i18n-country-add-submit')
  await submit.scrollIntoViewIfNeeded()
  await expect(submit).toBeVisible()
  await expect(submit).toBeEnabled()
  await submit.focus()
  await submit.click()

  const confirmation = page.getByTestId('i18n-country-confirm')
  await expect(confirmation).toBeVisible()
  const confirmDialog = page.getByTestId('modal').filter({ has: confirmation })
  await expect(confirmDialog.locator('.modal-title')).toHaveText(
    'The system does not know this country',
  )
  await expect(page.getByTestId('i18n-country-confirm-code')).toHaveText('gg')
  const confirm = page.getByTestId('i18n-country-confirm-submit')
  await confirm.scrollIntoViewIfNeeded()
  await expect(confirm).toBeVisible()
  await expect(confirm).toBeEnabled()
  await confirm.focus()
  await confirm.click()
  await expect(confirm).toHaveCount(0)

  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    'Custom country gg added and switched off for now.',
  )
  const search = page.getByTestId('hilos-table-search')
  await search.fill('')
  await search.pressSequentially('gg', { delay: 10 })
  const row = page
    .getByTestId(/^hilos-table-row-/)
    .filter({ has: page.getByTestId('i18n-countries-link-gg') })
  await expect(row).toBeVisible()
  await expect(row.getByTestId('i18n-countries-own-gg')).toBeVisible()
  await expect(row.getByTestId('i18n-countries-enabled-gg')).not.toBeChecked()
})

test('refuses an already listed catalog country', async ({ page }) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/countries', PAGE_READY)

  const open = page.getByTestId('hilos-table-main-action')
  await open.scrollIntoViewIfNeeded()
  await expect(open).toBeVisible()
  await expect(open).toBeEnabled()
  await open.focus()
  await open.click()

  const code = page.getByTestId('i18n-country-add-code')
  await code.fill('')
  await code.pressSequentially('us', { delay: 10 })
  const symbol = page.getByTestId('i18n-country-add-currency-symbol')
  await symbol.fill('')
  await symbol.pressSequentially('$', { delay: 10 })
  const currency = page.getByTestId('i18n-country-add-currency-code')
  await currency.fill('')
  await currency.pressSequentially('USD', { delay: 10 })

  await expect(page.getByTestId('i18n-country-add-plate')).toHaveAttribute(
    'data-kind',
    'known',
  )
  const submit = page.getByTestId('i18n-country-add-submit')
  await submit.scrollIntoViewIfNeeded()
  await expect(submit).toBeVisible()
  await expect(submit).toBeEnabled()
  await submit.focus()
  await submit.click()
  await expect(page.getByTestId('i18n-country-add-error')).toContainText(
    "Country 'us' already exists.",
  )
  await expect(submit).toBeVisible()
})
