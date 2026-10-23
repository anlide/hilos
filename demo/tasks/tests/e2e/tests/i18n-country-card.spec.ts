import { expect, test, type Page } from '@playwright/test'
import {
  countSentFrames,
  expectCountryPageHead,
  expectI18nPageHead,
  watchClientDocument,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit } from '../helpers/session.js'

// The React country tabs on the shared page head: the built-in United States on
// Overview and Names, opened directly, reloaded, and switched in place from the
// pointer and the keyboard. Overview reads the card's own details and a
// deletion the built-in catalog blocks. The section above the country keeps
// Internationalization as its shared head, with Overview, Languages and Countries.

const APP_NAME = 'Hilos Tasks'
const OVERVIEW = 'hilos_i18n_country'
const NAMES = 'hilos_i18n_country_names'
const OVERVIEW_PATH = '/hilos/i18n/countries/us'
const NAMES_PATH = '/hilos/i18n/countries/us/names'

/**
 * The United States head on either tab: the shared checks, then the country's
 * own block, which carries no action.
 *
 * @param page A page on the country's Overview or Names tab.
 * @param currentPage The page key of the tab on screen.
 */
async function expectCountryHead(
  page: Page,
  currentPage: string,
): Promise<void> {
  await expectCountryPageHead(expect, page, {
    code: 'us',
    name: 'United States',
    appName: APP_NAME,
    currentPage,
  })

  const block = page.getByTestId('hilos-page-head-block')
  await expect(block.getByTestId('country-head-tile')).toHaveText('us')
  await expect(block.getByTestId('country-head-tile')).toHaveAttribute(
    'aria-hidden',
    'true',
  )
  await expect(block.getByTestId('country-head-code')).toHaveText('us')
  await expect(block.getByTestId('country-head-own')).toHaveCount(0)
  await expect(block.getByTestId('country-head-description')).toHaveText(
    'Currency $ USD · Default locale not chosen',
  )
  await expect(page.getByTestId('hilos-page-head-action')).toHaveCount(0)
}

/**
 * The trail as a screen reader meets it: the three section links and the
 * country, with no slash spoken between them — neither the span drawn in its
 * place nor the stock Bootstrap divider the trail switches off.
 *
 * @param page A page on the country's Overview or Names tab.
 */
async function expectSilentCrumbs(page: Page): Promise<void> {
  await expect(page.getByTestId('hilos-breadcrumb')).toMatchAriaSnapshot(`
    - navigation "Breadcrumb":
      - /children: deep-equal
      - list:
        - listitem:
          - link "Hilos":
            - /url: /hilos
        - listitem:
          - link "Internationalization":
            - /url: /hilos/i18n
        - listitem:
          - link "Countries":
            - /url: /hilos/i18n/countries
        - listitem: United States
  `)
}

/**
 * The card's body on Overview: the read-only details of a built-in country, the
 * one Edit control of the main section and the two of State, and a deletion the
 * built-in catalog blocks — told accessibly, so that Enter and Space on the
 * disabled control open no window and the card stays.
 *
 * @param page A page on the country's Overview tab.
 */
async function expectReadOnlyCard(page: Page): Promise<void> {
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
}

test('opens both country tabs directly under the shared head, reads the card and keeps them through a reload', async ({
  page,
}) => {
  const deleteRequests = countSentFrames(page, 'hilos_i18n_country_delete')
  await signUpAdmin(page)

  for (const tab of [
    { path: OVERVIEW_PATH, key: OVERVIEW, body: 'country-card-main' },
    { path: NAMES_PATH, key: NAMES, body: 'country-names' },
  ]) {
    await gotoPage(page, tab.path, PAGE_READY)
    await expectCountryHead(page, tab.key)
    await expect(page.getByTestId(tab.body)).toBeVisible()
    if (tab.key === OVERVIEW) await expectReadOnlyCard(page)

    await page.reload()
    await expectPageReady(page)
    await expect(page).toHaveURL(new RegExp(`${tab.path}$`))
    await expectCountryHead(page, tab.key)
    await expect(page.getByTestId(tab.body)).toBeVisible()
  }

  // The entity keeps its own row; the containing section has three page links.
  await gotoPage(page, '/hilos/i18n/countries', PAGE_READY)
  await expectI18nPageHead(expect, page, {
    currentPage: 'hilos_i18n_countries',
    appName: APP_NAME,
  })
  await gotoPage(page, '/hilos/i18n', PAGE_READY)
  await expectI18nPageHead(expect, page, {
    currentPage: 'hilos_i18n',
    appName: APP_NAME,
  })
  expect(deleteRequests()).toBe(0)
})

test('moves between Overview and Names in place from the pointer and the keyboard', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, OVERVIEW_PATH, PAGE_READY)
  await expectCountryHead(page, OVERVIEW)
  await expectSilentCrumbs(page)
  const tabs = page.getByTestId('hilos-page-tabs')
  const clientDocument = await watchClientDocument(page)
  try {
    // A tab is a native link the router takes over: no new document on a switch.
    await clickSubmit(tabs.getByTestId(`hilos-page-tab-${NAMES}`))
    await expect(page).toHaveURL(new RegExp(`${NAMES_PATH}$`))
    await expectPageReady(page)
    await expectCountryHead(page, NAMES)
    await expectSilentCrumbs(page)
    await expect(page.getByTestId('country-names')).toBeVisible()
    await expect(page.getByTestId('country-card-main')).toHaveCount(0)

    // The way back from the keyboard, as a native link answers Enter.
    const overview = tabs.getByTestId(`hilos-page-tab-${OVERVIEW}`)
    await overview.focus()
    await overview.press('Enter')
    await expect(page).toHaveURL(new RegExp(`${OVERVIEW_PATH}$`))
    await expectPageReady(page)
    await expectCountryHead(page, OVERVIEW)
    await expect(page.getByTestId('country-card-main')).toBeVisible()
    await expect(page.getByTestId('country-names')).toHaveCount(0)
    await clientDocument.unchanged()
  } finally {
    await clientDocument.dispose()
  }
})
