import { expect, test, type Page } from '@playwright/test'
import {
  dismissToasts,
  expectDeletedCountryCard,
  expectDeletedCountryCardReloaded,
  i18nLocaleRow,
  settleRemovedRow,
  shownByTestId,
  watchI18nLivePages,
  watchPageErrorFrames,
  clickSubmit,
  typeInto,
  gotoPage,
  PAGE_READY,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'

/** What an edit window says once its row went under it. */
const DELETED_NOTICE = 'Deleted elsewhere — your text stays to copy.'

/** Create this spec's isolated custom country through both add steps. */
async function addCountry(page: Page, countryCode: string): Promise<void> {
  await gotoPage(expect, page, '/hilos/i18n/countries', PAGE_READY)
  const open = page.getByTestId('hilos-table-main-action')
  await clickSubmit(expect, open)
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
  await clickSubmit(expect, submit)
  const confirm = page.getByTestId('i18n-country-confirm-submit')
  await clickSubmit(expect, confirm)
  await expect(confirm).toHaveCount(0)
  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    `Custom country ${countryCode} added and switched off for now.`,
  )
  await dismissToasts(page)
}

/** Delete the country from its own card's State, where the page stands. */
async function deleteFromCard(page: Page, countryCode: string): Promise<void> {
  const open = page.getByTestId('country-card-delete')
  await clickSubmit(expect, open)
  const confirm = page.getByTestId('i18n-country-delete-confirm')
  await clickSubmit(expect, confirm)
  await expect(confirm).toHaveCount(0)
  await expect(page).toHaveURL(/\/hilos\/i18n\/countries$/)
  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    `Country ${countryCode} deleted.`,
  )
}

/**
 * Close the window over the 404 through its dirty confirm; the 404 stays.
 *
 * @param page The viewer whose window holds a draft.
 * @param windowId The window's data-id.
 * @param cancelId Its Cancel's data-id.
 */
async function discardDraft(
  page: Page,
  windowId: string,
  cancelId: string,
): Promise<void> {
  await clickSubmit(expect, page.getByTestId(cancelId))
  const discard = page.getByTestId('modal-confirm-discard')
  await clickSubmit(expect, discard)
  await expect(discard).toHaveCount(0)
  await expect(page.getByTestId(windowId)).toHaveCount(0)
}

test('deletes a custom country from the list and updates a second subscribed card', async ({
  page,
  context,
}) => {
  await signUpAdmin(expect, page)
  await addCountry(page, 'qx')
  const search = page.getByTestId('hilos-table-search')
  await search.fill('')
  await search.pressSequentially('qx', { delay: 10 })
  const opener = shownByTestId(page, 'i18n-countries-delete-qx')
  await expect(opener).toBeVisible()
  await expect(opener).toHaveAccessibleName('Delete country QX')

  const other = await context.newPage()
  const frames = watchPageErrorFrames(other)
  await gotoPage(expect, other, '/hilos/i18n/countries/qx', PAGE_READY)
  await expect(other.getByTestId('country-card-code')).toHaveText('qx')
  await clickSubmit(expect, other.getByTestId('country-card-edit'))
  const symbol = other.getByTestId('i18n-country-edit-currency-symbol')
  await expect(symbol).toBeFocused()
  await typeInto(symbol, 'Q$')
  const live = await watchI18nLivePages(expect, [other])

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
  await clickSubmit(expect, confirm)
  await expect(confirm).toHaveCount(0)
  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    'Country qx deleted.',
  )
  await expect(page).toHaveURL(/\/hilos\/i18n\/countries$/)
  await expectDeletedCountryCard(expect, other, frames, 'qx')
  // The edit window stays above the 404 with the draft, and cannot save it.
  await expect(other.getByTestId('i18n-country-window')).toBeVisible()
  await expect(symbol).toHaveValue('Q$')
  await expect(other.getByTestId('i18n-country-notice')).toContainText(
    DELETED_NOTICE,
  )
  const save = other.getByTestId('i18n-country-save')
  await expect(save).toBeDisabled()
  await expect(save).toContainText('Deleted')
  await discardDraft(other, 'i18n-country-window', 'i18n-country-cancel')
  await expectDeletedCountryCard(expect, other, frames, 'qx')
  await live.unchanged()
  const loggedBeforeReload = frames.length
  await other.reload()
  await expectDeletedCountryCardReloaded(
    expect,
    other,
    frames,
    'qx',
    loggedBeforeReload,
  )
  await expect(other.getByTestId('i18n-country-window')).toHaveCount(0)

  // The ordinary table removal gate may leave Apply, then a placeholder or an empty window.
  await dismissToasts(page)
  await settleRemovedRow(expect, page, 'i18n-countries-delete-qx')
  await other.close()
})

test('deletes a custom country from State and returns to the list after success', async ({
  page,
  context,
}) => {
  await signUpAdmin(expect, page)
  await addCountry(page, 'qy')
  await gotoPage(expect, page, '/hilos/i18n/countries/qy', PAGE_READY)
  const other = await context.newPage()
  const frames = watchPageErrorFrames(other)
  await gotoPage(expect, other, '/hilos/i18n/countries/qy', PAGE_READY)
  await expect(other.getByTestId('country-card-code')).toHaveText('qy')
  await deleteFromCard(page, 'qy')
  await expectDeletedCountryCard(expect, other, frames, 'qy')
  const search = page.getByTestId('hilos-table-search')
  await search.fill('')
  await search.pressSequentially('qy', { delay: 10 })
  await expect(
    page
      .getByTestId(/^hilos-table-row-/)
      .filter({ has: page.getByTestId('i18n-countries-link-qy') }),
  ).toHaveCount(0)
  await other.close()
})

test('deletes a custom country under a second Names tab and keeps its name draft above the 404', async ({
  page,
  context,
}) => {
  await signUpAdmin(expect, page)
  // No name and no locale: either would stop the deletion.
  await addCountry(page, 'qn')
  await gotoPage(expect, page, '/hilos/i18n/countries/qn', PAGE_READY)
  const other = await context.newPage()
  const frames = watchPageErrorFrames(other)
  await gotoPage(expect, other, '/hilos/i18n/countries/qn/names', PAGE_READY)
  await clickSubmit(expect, shownByTestId(other, 'i18n-country-name-add-en'))
  const input = other.getByTestId('i18n-country-name-input')
  await expect(input).toBeFocused()
  await typeInto(input, 'Draft name')
  const live = await watchI18nLivePages(expect, [other])

  await deleteFromCard(page, 'qn')
  await expectDeletedCountryCard(expect, other, frames, 'qn', 'names')
  // The add window stays above the 404 with the draft, and cannot save it.
  await expect(other.getByTestId('i18n-country-name-window')).toBeVisible()
  await expect(input).toHaveValue('Draft name')
  await expect(other.getByTestId('i18n-country-name-notice')).toContainText(
    DELETED_NOTICE,
  )
  const submit = other.getByTestId('i18n-country-name-submit')
  await expect(submit).toBeDisabled()
  await expect(submit).toContainText('Deleted')
  await discardDraft(
    other,
    'i18n-country-name-window',
    'i18n-country-name-cancel',
  )
  await expectDeletedCountryCard(expect, other, frames, 'qn', 'names')
  await live.unchanged()
  const loggedBeforeReload = frames.length
  await other.reload()
  await expectDeletedCountryCardReloaded(
    expect,
    other,
    frames,
    'qn',
    loggedBeforeReload,
    'names',
  )
  await expect(other.getByTestId('i18n-country-name-window')).toHaveCount(0)
  await other.close()
})

test('shows accessible blocked deletion for a custom country with a locale and a known country', async ({
  page,
}) => {
  await signUpAdmin(expect, page)
  await addCountry(page, 'qz')
  await gotoPage(expect, page, '/hilos/i18n/languages/en/locales', PAGE_READY)
  // The locales table shows every pair in one window and declares no search.
  await expect(i18nLocaleRow(page, 'en-QZ')).toBeVisible()
  const add = shownByTestId(page, 'i18n-locales-add-en-QZ')
  await clickSubmit(expect, add)
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
  await clickSubmit(expect, submit)
  await expect(submit).toHaveCount(0)
  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    'Locale en-QZ added.',
  )
  await dismissToasts(page)

  for (const [code, reason] of [
    ['qz', 'Cannot delete: locales refer to this country'],
    [
      'us',
      'Cannot delete: the built-in catalog knows this country and the reflow would bring it back; switch it off instead',
    ],
  ]) {
    await gotoPage(expect, page, '/hilos/i18n/countries', PAGE_READY)
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
    await expect(description).toBeVisible()
    await expect(basket).toHaveAttribute(
      'aria-describedby',
      (await description.getAttribute('id'))!,
    )
    await gotoPage(expect, page, `/hilos/i18n/countries/${code}`, PAGE_READY)
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
  }
})
