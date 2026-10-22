import { expect, test, type Page } from '@playwright/test'

import { expectCountryPageHead } from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit } from '../helpers/session.js'

// The React country tabs on the shared page head: the built-in United States on
// Overview and Names, opened directly, reloaded, and switched in place from the
// pointer and the keyboard. The section above it has not moved to the shared
// head and keeps its own title and child cards.

const APP_NAME = 'Hilos Flowers'
const OVERVIEW = 'hilos_i18n_country'
const NAMES = 'hilos_i18n_country_names'
const OVERVIEW_PATH = '/hilos/i18n/countries/us'
const NAMES_PATH = '/hilos/i18n/countries/us/names'

/**
 * The United States head on either tab: the shared checks, then the labels of
 * the trail and the row and the country's own block, which carry no actions.
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
  await expect(page.getByTestId('hilos-breadcrumb')).toHaveAccessibleName(
    'Breadcrumb',
  )
  await expect(
    page.getByTestId('hilos-breadcrumb-hilos_i18n_countries'),
  ).toHaveAttribute('href', '/hilos/i18n/countries')

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

  const tabs = page.getByTestId('hilos-page-tabs')
  await expect(tabs).toHaveAccessibleName('Country pages')
  await expect(tabs.getByRole('link')).toHaveText(['Overview', 'Names'])
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
 * Count the documents the page loads from now on; a switch in place loads none.
 *
 * @param page The page to observe.
 */
function countDocuments(page: Page): () => number {
  let documents = 0
  page.on('domcontentloaded', () => {
    documents += 1
  })

  return () => documents
}

test('opens both country tabs directly under the shared head and keeps them through a reload', async ({
  page,
}) => {
  await grantAdminToSelf(page)

  for (const tab of [
    { path: OVERVIEW_PATH, key: OVERVIEW, body: 'country-card-main' },
    { path: NAMES_PATH, key: NAMES, body: 'country-names' },
  ]) {
    await gotoPage(page, tab.path, PAGE_READY)
    await expectCountryHead(page, tab.key)
    await expect(page.getByTestId(tab.body)).toBeVisible()

    await page.reload()
    await expectPageReady(page)
    await expect(page).toHaveURL(new RegExp(`${tab.path}$`))
    await expectCountryHead(page, tab.key)
    await expect(page.getByTestId(tab.body)).toBeVisible()
  }

  await gotoPage(page, '/hilos/i18n', PAGE_READY)
  await expect(page.getByTestId('hilos-page-head')).toHaveCount(0)
  await expect(page.getByTestId('hilos-admin-title')).toHaveText(
    'Internationalization',
  )
  await expect(page.getByTestId('hilos-admin-children')).toBeVisible()
  await expect(
    page.getByTestId('hilos-admin-child-hilos_i18n_countries'),
  ).toHaveAttribute('href', '/hilos/i18n/countries')
})

test('moves between Overview and Names in place from the pointer and the keyboard', async ({
  page,
}) => {
  await grantAdminToSelf(page)
  await gotoPage(page, OVERVIEW_PATH, PAGE_READY)
  await expectCountryHead(page, OVERVIEW)
  await expectSilentCrumbs(page)
  const tabs = page.getByTestId('hilos-page-tabs')
  const documents = countDocuments(page)

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
  expect(documents()).toBe(0)
})
