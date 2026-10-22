import { expect, test } from '@playwright/test'
import { countSentFrames } from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'

test('opens a reflowed country card directly with read-only details and staged tabs', async ({
  page,
}) => {
  const deleteRequests = countSentFrames(page, 'hilos_i18n_country_delete')
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/countries/us', PAGE_READY)

  await expect(page.getByTestId('country-card-title')).toHaveText(
    'United States',
  )
  await expect(
    page.getByTestId('hilos-breadcrumb').locator('[aria-current="page"]'),
  ).toHaveText('United States')
  await expect(page).toHaveTitle('United States · BTC Tracker')
  await expect(page.getByTestId('country-card-code-badge')).toHaveText('us')
  await expect(page.getByTestId('country-card-own')).toHaveCount(0)
  await expect(page.getByTestId('country-card-line')).toHaveText(
    'Currency $ USD · Default locale not chosen',
  )
  await expect(page.getByTestId('country-card-code')).toHaveText('us')
  await expect(page.getByTestId('country-card-currency')).toHaveText('$ USD')
  await expect(page.getByTestId('country-card-default-locale')).toHaveText(
    'not chosen',
  )
  await expect(page.getByTestId('country-card-enabled')).toHaveText('Disabled')
  await expect(page.getByTestId('country-card-delete-verdict')).toContainText(
    'the built-in catalog knows this country',
  )
  await expect(page.getByTestId('country-card-frozen')).toHaveCount(0)

  const tabs = page.getByTestId('country-card-tabs')
  await expect(tabs).toContainText('Main')
  await expect(tabs).toContainText('Names')
  await expect(tabs.locator('a')).toHaveCount(2)
  await expect(
    page.getByTestId('country-card-tab-hilos_i18n_country'),
  ).toHaveAttribute('href', '/hilos/i18n/countries/us')
  await expect(
    page.getByTestId('country-card-tab-hilos_i18n_country'),
  ).toHaveAttribute('aria-current', 'page')
  await expect(
    page.getByTestId('country-card-tab-hilos_i18n_country_names'),
  ).toHaveAttribute('href', '/hilos/i18n/countries/us/names')
  await expect(
    page.getByTestId('country-card-main').locator('button, input'),
  ).toHaveCount(1)
  await expect(page.getByTestId('country-card-edit')).toBeVisible()
  await expect(
    page.getByTestId('country-card-state').locator('button, input'),
  ).toHaveCount(2)
  await expect(page.getByTestId('country-card-switch-on')).toBeVisible()

  const deleteCountry = page.getByTestId('country-card-delete')
  const deleteReason =
    'Cannot delete: the built-in catalog knows this country and the reflow would bring it back; switch it off instead'
  await expect(deleteCountry).toBeVisible()
  await expect(deleteCountry).toHaveAccessibleName('Delete country')
  await expect(deleteCountry).toHaveAttribute('aria-disabled', 'true')
  await expect(deleteCountry).toHaveClass(/text-body-secondary/)
  await expect(deleteCountry).toHaveAttribute(
    'aria-describedby',
    'country-card-delete-reason',
  )
  await expect(deleteCountry).toHaveAccessibleDescription(deleteReason)
  await deleteCountry.scrollIntoViewIfNeeded()
  await deleteCountry.focus()
  await deleteCountry.press('Enter')
  await deleteCountry.press('Space')
  await expect(page.getByTestId('i18n-country-delete-window')).toHaveCount(0)
  await expect(page.getByTestId('country-card-code')).toHaveText('us')

  await gotoPage(page, '/hilos/i18n/countries/us/names', PAGE_READY)
  await expect(page.getByTestId('country-card-title')).toHaveText(
    'United States',
  )
  await expect(
    page.getByTestId('hilos-breadcrumb').locator('[aria-current="page"]'),
  ).toHaveText('United States')
  await expect(page).toHaveTitle('United States · BTC Tracker')
  await expect(page).toHaveURL(/\/hilos\/i18n\/countries\/us\/names$/)
  await expect(
    page.getByTestId('country-card-tab-hilos_i18n_country_names'),
  ).toHaveAttribute('aria-current', 'page')

  await gotoPage(page, '/hilos/i18n/countries', PAGE_READY)
  await gotoPage(page, '/hilos/i18n', PAGE_READY)
  expect(deleteRequests()).toBe(0)
})
