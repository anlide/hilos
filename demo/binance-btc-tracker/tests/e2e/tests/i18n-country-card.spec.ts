import { expect, test, type Page } from '@playwright/test'
import {
  countSentFrames,
  expectCountryPageHead,
  clickSubmit,
  expectDocumentUnchanged,
  expectPageReady,
  gotoPage,
  markDocument,
  PAGE_READY,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'

const APP_NAME = 'BTC Tracker'
const OVERVIEW = 'hilos_i18n_country'
const NAMES = 'hilos_i18n_country_names'
const OVERVIEW_PATH = '/hilos/i18n/countries/us'
const NAMES_PATH = '/hilos/i18n/countries/us/names'

/**
 * The United States head on either tab: the shared checks, then the country's
 * own block, which carries no action.
 *
 * @param page A page on the country's Overview or Names tab.
 * @param current The page key of the tab on screen.
 */
async function expectCountryHead(page: Page, current: string): Promise<void> {
  await expectCountryPageHead(expect, page, {
    code: 'us',
    name: 'United States',
    appName: APP_NAME,
    currentPage: current,
  })

  const block = page.getByTestId('hilos-page-head-block')
  await expect(block.getByTestId('country-head-tile')).toHaveText('us')
  await expect(block.getByTestId('country-head-code')).toHaveText('us')
  await expect(block.getByTestId('country-head-own')).toHaveCount(0)
  await expect(block.getByTestId('country-head-description')).toHaveText(
    'Currency $ USD · Default locale not chosen',
  )
  await expect(page.getByTestId('hilos-page-head-action')).toHaveCount(0)
}

test('opens a reflowed country card directly with read-only details under the shared head', async ({
  page,
}) => {
  const deleteRequests = countSentFrames(page, 'hilos_i18n_country_delete')
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, OVERVIEW_PATH, PAGE_READY)

  await expectCountryHead(page, OVERVIEW)
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

  await gotoPage(expect, page, NAMES_PATH, PAGE_READY)
  await expectCountryHead(page, NAMES)
  await expect(page).toHaveURL(new RegExp(`${NAMES_PATH}$`))
  await expect(page.getByTestId('country-names')).toBeVisible()

  await gotoPage(expect, page, '/hilos/i18n/countries', PAGE_READY)
  await gotoPage(expect, page, '/hilos/i18n', PAGE_READY)
  expect(deleteRequests()).toBe(0)
})

test('moves between Overview and Names in place on the shared head row and keeps it through a reload', async ({
  page,
}) => {
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, OVERVIEW_PATH, PAGE_READY)
  await expectCountryHead(page, OVERVIEW)
  const tabs = page.getByTestId('hilos-page-tabs')
  const overview = tabs.getByTestId(`hilos-page-tab-${OVERVIEW}`)
  const names = tabs.getByTestId(`hilos-page-tab-${NAMES}`)

  // A tab is a native link the router takes over: no new document on a switch.
  await markDocument(page)
  await clickSubmit(expect, names)
  await expect(page).toHaveURL(new RegExp(`${NAMES_PATH}$`))
  await expectPageReady(expect, page)
  await expectCountryHead(page, NAMES)
  await expect(page.getByTestId('country-names')).toBeVisible()
  await expect(page.getByTestId('country-card-main')).toHaveCount(0)
  await expectDocumentUnchanged(expect, page)

  // The way back from the keyboard, as a native link answers Enter.
  await overview.focus()
  await overview.press('Enter')
  await expect(page).toHaveURL(new RegExp(`${OVERVIEW_PATH}$`))
  await expectPageReady(expect, page)
  await expectCountryHead(page, OVERVIEW)
  await expect(page.getByTestId('country-card-main')).toBeVisible()
  await expect(page.getByTestId('country-names')).toHaveCount(0)
  await expectDocumentUnchanged(expect, page)

  await page.reload()
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(new RegExp(`${OVERVIEW_PATH}$`))
  await expectCountryHead(page, OVERVIEW)
  await expect(page.getByTestId('country-card-main')).toBeVisible()

  await clickSubmit(expect, names)
  await expect(page).toHaveURL(new RegExp(`${NAMES_PATH}$`))
  await expectPageReady(expect, page)
  await expectCountryHead(page, NAMES)
  await page.reload()
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(new RegExp(`${NAMES_PATH}$`))
  await expectCountryHead(page, NAMES)
  await expect(page.getByTestId('country-names')).toBeVisible()
})
