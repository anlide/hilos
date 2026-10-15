import { expect, test, type Page } from '@playwright/test'
import {
  dismissToasts,
  shownByTestId,
  sidewaysOverflow,
  i18nLocaleRow,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'

/** Create this spec's isolated custom country through both add steps. */
async function addCountry(page: Page, countryCode: string): Promise<void> {
  await gotoPage(page, '/hilos/i18n/countries', PAGE_READY)
  const open = page.getByTestId('hilos-table-main-action')
  await open.scrollIntoViewIfNeeded()
  await expect(open).toBeVisible()
  await expect(open).toBeEnabled()
  await open.focus()
  await open.click()
  for (const [field, value] of [
    ['code', countryCode],
    ['currency-symbol', '¤'],
    ['currency-code', 'XQX'],
  ]) {
    const input = page.getByTestId(`i18n-country-add-${field}`)
    await input.fill('')
    await input.pressSequentially(value!, { delay: 10 })
  }
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
  const confirm = page.getByTestId('i18n-country-confirm-submit')
  await confirm.scrollIntoViewIfNeeded()
  await expect(confirm).toBeVisible()
  await expect(confirm).toBeEnabled()
  await confirm.focus()
  await confirm.click()
  await expect(confirm).toHaveCount(0)
  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    `Custom country ${countryCode} added and switched off for now.`,
  )
  await dismissToasts(page)
}

test('deletes a custom country from the list and updates a second subscribed card', async ({
  page,
  context,
}) => {
  await signUpAdmin(page)
  await addCountry(page, 'qx')
  const search = page.getByTestId('hilos-table-search')
  await search.fill('')
  await search.pressSequentially('qx', { delay: 10 })
  const opener = shownByTestId(page, 'i18n-countries-delete-qx')
  await expect(opener).toBeVisible()
  await expect(opener).toHaveAccessibleName('Delete country QX')

  const other = await context.newPage()
  await gotoPage(other, '/hilos/i18n/countries/qx', PAGE_READY)
  await expect(other.getByTestId('country-card-code')).toHaveText('qx')

  await opener.scrollIntoViewIfNeeded()
  await expect(opener).toBeEnabled()
  await opener.focus()
  await opener.click()
  const window = page.getByTestId('i18n-country-delete-window')
  await expect(window).toContainText('It will be removed entirely.')
  await expect(
    page.getByTestId('modal').filter({ has: window }).locator('.modal-title'),
  ).toHaveText('Delete country · QX')
  const confirm = page.getByTestId('i18n-country-delete-confirm')
  await confirm.scrollIntoViewIfNeeded()
  await expect(confirm).toBeVisible()
  await expect(confirm).toBeEnabled()
  await confirm.focus()
  await confirm.click()
  await expect(confirm).toHaveCount(0)
  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    'Country deleted.',
  )
  await expect(page).toHaveURL(/\/hilos\/i18n\/countries$/)
  await expect(other.getByTestId('country-card-unavailable')).toHaveText(
    'Country details are no longer available.',
  )
  await expect(other.getByTestId('country-card-main')).toHaveCount(0)

  // The ordinary table removal gate may leave Apply, then a placeholder or an empty window.
  const apply = page.getByTestId('hilos-table-apply')
  await expect
    .poll(
      async () =>
        (await apply.count()) > 0 ||
        (await page.getByTestId('i18n-countries-delete-qx').count()) === 0,
    )
    .toBe(true)
  if (await apply.isVisible()) {
    await dismissToasts(page)
    await apply.scrollIntoViewIfNeeded()
    await expect(apply).toBeVisible()
    await expect(apply).toBeEnabled()
    await apply.focus()
    await apply.click()
  }
  await expect(page.getByTestId('i18n-countries-delete-qx')).toHaveCount(0)
  await other.close()
})

test('deletes a custom country from State and returns to the list after success', async ({
  page,
}) => {
  await signUpAdmin(page)
  await addCountry(page, 'qy')
  await gotoPage(page, '/hilos/i18n/countries/qy', PAGE_READY)
  const open = page.getByTestId('country-card-delete')
  await open.scrollIntoViewIfNeeded()
  await expect(open).toBeVisible()
  await expect(open).toBeEnabled()
  await open.focus()
  await open.click()
  const confirm = page.getByTestId('i18n-country-delete-confirm')
  await confirm.scrollIntoViewIfNeeded()
  await expect(confirm).toBeVisible()
  await expect(confirm).toBeEnabled()
  await confirm.focus()
  await confirm.click()
  await expect(confirm).toHaveCount(0)
  await expect(page).toHaveURL(/\/hilos\/i18n\/countries$/)
  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    'Country deleted.',
  )
  const search = page.getByTestId('hilos-table-search')
  await search.fill('')
  await search.pressSequentially('qy', { delay: 10 })
  await expect(
    page
      .getByTestId(/^hilos-table-row-/)
      .filter({ has: page.getByTestId('i18n-countries-link-qy') }),
  ).toHaveCount(0)
})

test('shows accessible blocked deletion for a custom country with a locale and a known country', async ({
  page,
}) => {
  await signUpAdmin(page)
  await addCountry(page, 'qz')
  await gotoPage(page, '/hilos/i18n/languages/en/locales', PAGE_READY)
  // The locales table shows every pair in one window and declares no search.
  await expect(i18nLocaleRow(page, 'en-QZ')).toBeVisible()
  const add = shownByTestId(page, 'i18n-locales-add-en-QZ')
  await add.scrollIntoViewIfNeeded()
  await expect(add).toBeVisible()
  await expect(add).toBeEnabled()
  await add.focus()
  await add.click()
  for (const [field, value] of [
    ['date', 'YYYY-MM-DD'],
    ['time', 'HH:mm:ss'],
    ['number', '1,000.00'],
    ['phone', '+X (XXX) XXX-XXXX'],
    ['address', 'Street, House, City, Index'],
    ['measurement', 'metric'],
    ['collation', 'und'],
  ]) {
    await page.getByTestId(`i18n-locale-field-${field}`).selectOption(value!)
  }
  const submit = page.getByTestId('i18n-locale-submit')
  await submit.scrollIntoViewIfNeeded()
  await expect(submit).toBeVisible()
  await expect(submit).toBeEnabled()
  await submit.focus()
  await submit.click()
  await expect(submit).toHaveCount(0)
  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    'Locale added.',
  )
  await dismissToasts(page)

  for (const [code, reason] of [
    ['qz', 'Cannot delete: locales refer to this country'],
    [
      'us',
      'Cannot delete: the built-in catalog knows this country and the reflow would bring it back; switch it off instead',
    ],
  ]) {
    await gotoPage(page, '/hilos/i18n/countries', PAGE_READY)
    const countrySearch = page.getByTestId('hilos-table-search')
    await countrySearch.fill('')
    await countrySearch.pressSequentially(code!, { delay: 10 })
    const basket = shownByTestId(page, `i18n-countries-delete-${code}`)
    const description = shownByTestId(
      page,
      `i18n-countries-delete-reason-${code}`,
    )
    await expect(basket).toHaveAttribute('aria-disabled', 'true')
    await expect(description).toHaveText(reason!)
    await expect(basket).toHaveAttribute(
      'aria-describedby',
      (await description.getAttribute('id'))!,
    )
    await basket.focus()
    await basket.press('Enter')
    await expect(page.getByTestId('i18n-country-delete-window')).toHaveCount(0)
    await page.setViewportSize({ width: 375, height: 812 })
    await expect(description).toBeVisible()
    await expect(basket).toHaveAttribute(
      'aria-describedby',
      (await description.getAttribute('id'))!,
    )
    await expect.poll(() => sidewaysOverflow(page)).toEqual([0, 0])
    await gotoPage(page, `/hilos/i18n/countries/${code}`, PAGE_READY)
    await expect(page.getByTestId('country-card-delete-verdict')).toContainText(
      reason!,
    )
    await expect(page.getByTestId('country-card-delete')).toHaveAttribute(
      'aria-disabled',
      'true',
    )
    await page.getByTestId('country-card-delete').focus()
    await page.getByTestId('country-card-delete').press('Enter')
    await expect(page.getByTestId('i18n-country-delete-window')).toHaveCount(0)
    await page.setViewportSize({ width: 1280, height: 720 })
  }
})
