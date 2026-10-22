import { expect, test, type Page } from '@playwright/test'

import {
  dismissToasts,
  expectCountryPageHead,
  shownByTestId,
  watchI18nLivePages,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import {
  clearCountryNamesFixture,
  seedCountryNamesFixture,
} from '../helpers/i18n.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit, typeInto } from '../helpers/session.js'

// The live country name on the React shared head: the fixture country without a
// name in the default language, open on Names in one tab and on Overview in
// another of the same browser. Every committed name — added, renamed, saved
// empty and deleted — reaches both heads over the open sockets, and a reload of
// both says the same; the addresses stay on the code whatever the name.

const APP_NAME = 'Hilos Flowers'
const UNNAMED = 'Unnamed country'
const OVERVIEW = 'hilos_i18n_country'
const NAMES = 'hilos_i18n_country_names'
const OVERVIEW_PATH = '/hilos/i18n/countries/qj'
const NAMES_PATH = '/hilos/i18n/countries/qj/names'
const WINDOW = 'i18n-country-name-window'
const INPUT = 'i18n-country-name-input'
const SUBMIT = 'i18n-country-name-submit'
const DELETE_WINDOW = 'i18n-country-name-delete-window'
const DELETE_CONFIRM = 'i18n-country-name-delete-confirm'

/**
 * Write the default-language name from its modal and wait for the tracked
 * success.
 *
 * @param page The page on the country's Names tab.
 * @param control The name control that opens the modal: `add-en` or `edit-en`.
 * @param value The name to save, empty included.
 * @param message The success toast the save is confirmed by.
 */
async function writeName(
  page: Page,
  control: string,
  value: string,
  message: string,
): Promise<void> {
  await dismissToasts(page)
  await clickSubmit(shownByTestId(page, `i18n-country-name-${control}`))
  await expect(page.getByTestId(WINDOW)).toBeVisible()
  await expect(page.getByTestId(INPUT)).toBeFocused()
  await typeInto(page.getByTestId(INPUT), value)
  await clickSubmit(page.getByTestId(SUBMIT))
  await expect(page.getByTestId(WINDOW)).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-toast-success').filter({ hasText: message }),
  ).toBeVisible()
}

/**
 * Delete the default-language name through its confirmation and wait for the
 * tracked success.
 *
 * @param page The page on the country's Names tab.
 */
async function deleteName(page: Page): Promise<void> {
  await dismissToasts(page)
  await clickSubmit(shownByTestId(page, 'i18n-names-delete-en'))
  await expect(page.getByTestId(DELETE_WINDOW)).toBeVisible()
  await clickSubmit(page.getByTestId(DELETE_CONFIRM))
  await expect(page.getByTestId(DELETE_WINDOW)).toHaveCount(0)
  await expect(
    page
      .getByTestId('hilos-toast-success')
      .filter({ hasText: 'Name of qj in English (en) removed.' }),
  ).toBeVisible()
}

/**
 * Both tabs name the country alike in the h1, the last crumb and the browser
 * tab, and each stays on its own code-based address.
 *
 * @param names The page on the country's Names tab.
 * @param overview The page on the country's Overview tab.
 * @param name The name both heads show.
 */
async function expectBothHeads(
  names: Page,
  overview: Page,
  name: string,
): Promise<void> {
  for (const tab of [
    { page: names, path: NAMES_PATH, key: NAMES },
    { page: overview, path: OVERVIEW_PATH, key: OVERVIEW },
  ]) {
    await expect(tab.page).toHaveURL(new RegExp(`${tab.path}$`))
    await expectCountryPageHead(expect, tab.page, {
      code: 'qj',
      name,
      appName: APP_NAME,
      currentPage: tab.key,
    })
    await expect(tab.page.getByTestId('country-head-code')).toHaveText('qj')
  }
}

test('keeps one live country name on Names and Overview through add, rename, empty save and delete', async ({
  page,
  context,
}) => {
  await clearCountryNamesFixture()
  const overview = await context.newPage()
  try {
    await seedCountryNamesFixture()
    await grantAdminToSelf(page)
    await gotoPage(page, NAMES_PATH, PAGE_READY)
    await gotoPage(overview, OVERVIEW_PATH, PAGE_READY)
    await expect(shownByTestId(page, 'i18n-names-none-en')).toHaveText(
      'No name',
    )
    await expectBothHeads(page, overview, UNNAMED)

    for (const step of [
      {
        name: 'Country from UI',
        write: () =>
          writeName(
            page,
            'add-en',
            'Country from UI',
            'Name of Country from UI (qj) in English (en) added.',
          ),
      },
      {
        name: 'Renamed country',
        write: () =>
          writeName(
            page,
            'edit-en',
            'Renamed country',
            'Name of Renamed country (qj) in English (en) saved.',
          ),
      },
      {
        name: UNNAMED,
        write: async () => {
          await writeName(
            page,
            'edit-en',
            '',
            'Name of qj in English (en) saved.',
          )
          // A saved empty name is a name, not its absence.
          await expect(page.getByTestId('i18n-names-none-en')).toHaveCount(0)
        },
      },
      {
        name: UNNAMED,
        write: async () => {
          await deleteName(page)
          await expect(shownByTestId(page, 'i18n-names-none-en')).toHaveText(
            'No name',
          )
        },
      },
    ]) {
      const live = await watchI18nLivePages(expect, [page, overview])
      await step.write()
      await expectBothHeads(page, overview, step.name)
      await live.unchanged()

      for (const tab of [page, overview]) {
        await tab.reload()
        await expectPageReady(tab)
      }
      await expectBothHeads(page, overview, step.name)
    }
  } finally {
    await overview.close()
    await clearCountryNamesFixture()
  }
})
