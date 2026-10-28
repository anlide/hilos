import { expect, test as base, type Page } from '@playwright/test'
import {
  cancelI18nEditor,
  clickSubmit,
  countSentFrames,
  dismissToasts,
  expectBuiltInCountryCard,
  expectCountryDeleteDescription,
  expectCountryPageHead,
  expectDeletedCountryCard,
  expectDeletedCountryCardReloaded,
  expectI18nPageHead,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  i18nCountryUpdates,
  PAGE_READY,
  PAGE_REFUSED,
  settleRemovedRow,
  shownByTestId,
  typeInto,
  watchClientDocument,
  watchDeliveryFrames,
  watchI18nLivePages,
  watchPageErrorFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  clearLocale,
  switchCountryOff,
} from '../../../../../framework/frontend/scripts/i18n.mjs'
import {
  i18nFixtures,
  type I18nFixtureScope,
} from '../../../../../framework/frontend/scripts/i18nFixtures.mjs'

const test = base.extend<{ i18n: I18nFixtureScope }>({ i18n: i18nFixtures })

const APP_NAME = 'Hilos Tasks'
const OVERVIEW = 'hilos_i18n_country'
const NAMES = 'hilos_i18n_country_names'
const OVERVIEW_PATH = '/hilos/i18n/countries/us'
const NAMES_PATH = '/hilos/i18n/countries/us/names'
const DELETED_NOTICE = 'Deleted elsewhere — your text stays to copy.'

/**
 * Read the React country breadcrumb as assistive technology does, with each
 * linked section and the country name but no announced slash divider.
 *
 * @param page A page on either tab of a country.
 */
async function expectCountryBreadcrumb(page: Page): Promise<void> {
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

test('country.life', async ({ page, i18n }) => {
  // One administrator owns list navigation, both add decisions, edits and both delete entry places.
  test.slow()
  await i18n.ownCountry('gg')
  const companion = await i18n.country({
    code: 'qr',
    currencySymbol: '¤',
    currencyCode: 'XQR',
  })
  const frames = watchDeliveryFrames(page)
  i18n.defer(async () => frames.stop())
  const writes = () => i18nCountryUpdates(frames)
  const deletes = countSentFrames(page, 'hilos_i18n_country_delete')
  const adds = countSentFrames(page, 'hilos_i18n_country_add')
  await grantAdminToSelf(expect, page)
  let englishId = 0
  let ggCountryId = 0

  await test.step('Read the reflowed list, page it, search and return through the section tab', async () => {
    await gotoPage(expect, page, '/hilos/i18n/languages', PAGE_READY)
    const english = shownByTestId(page, /^hilos-table-row-/).filter({
      has: page.getByTestId('i18n-languages-link-en'),
    })
    englishId = Number(
      (await english.getAttribute('data-id'))!.replace('hilos-table-row-', ''),
    )
    expect(englishId).toBeGreaterThan(0)
    await gotoPage(expect, page, '/hilos/i18n', PAGE_READY)
    await clickSubmit(
      expect,
      page.getByTestId('hilos-page-tab-hilos_i18n_countries'),
    )
    await expectPageReady(expect, page)
    await expectI18nPageHead(expect, page, {
      currentPage: 'hilos_i18n_countries',
      appName: APP_NAME,
    })
    await expect(page.getByTestId('i18n-countries-catalog')).toContainText(
      /The system knows \d+ languages and \d+ countries on its own/,
    )
    await expect(page.getByTestId('i18n-countries-legend-own')).toBeVisible()
    await expect(page.getByTestId('hilos-table-count')).toHaveText(
      /^\s*1 – 25 of \d+\s*$/,
    )
    await clickSubmit(expect, page.getByTestId('hilos-table-page-2'))
    await expect(page.getByTestId('hilos-table-count')).toHaveText(
      /^\s*26 – 50 of \d+\s*$/,
    )
    await expect(page.getByTestId('hilos-table-page-2')).toHaveAttribute(
      'aria-current',
      'page',
    )
    const search = page.getByTestId('hilos-table-search')
    await typeInto(search, 'united')
    const row = shownByTestId(page, /^hilos-table-row-/).filter({
      has: page.getByTestId('i18n-countries-link-us'),
    })
    await expect(row.getByTestId('i18n-countries-link-us')).toHaveAttribute(
      'href',
      '/hilos/i18n/countries/us',
    )
    await expect(row).toContainText('United States')
    await expect(row).toContainText('$')
    await expect(row).toContainText('USD')
    await expect(
      row.getByTestId('i18n-countries-locale-none-us'),
    ).toContainText('Not chosen')
    await expect(row.getByTestId('i18n-countries-enabled-us')).not.toBeChecked()
    await typeInto(search, 'zzzz-absent')
    await expect(
      page.locator('table').getByTestId('hilos-table-no-matches'),
    ).toContainText('Nothing found')
    await expect(row).toHaveCount(0)
    await expect(page.getByTestId('i18n-countries-legend')).toBeVisible()
    await gotoPage(expect, page, '/hilos/i18n', PAGE_READY)
    await clickSubmit(
      expect,
      page.getByTestId('hilos-page-tab-hilos_i18n_countries'),
    )
    await expectPageReady(expect, page)
    await expectI18nPageHead(expect, page, {
      currentPage: 'hilos_i18n_countries',
      appName: APP_NAME,
    })
  })

  await test.step('Open both built-in country tabs directly, switch by pointer and Enter, and preserve the document', async () => {
    const deletesBefore = deletes()
    const tabs = [
      { path: OVERVIEW_PATH, key: OVERVIEW, body: 'country-card-main' },
      { path: NAMES_PATH, key: NAMES, body: 'country-names' },
    ] as const
    for (const tab of tabs) {
      await gotoPage(expect, page, tab.path, PAGE_READY)
      await expectCountryPageHead(expect, page, {
        code: 'us',
        name: 'United States',
        currentPage: tab.key,
        appName: APP_NAME,
      })
      await expectCountryBreadcrumb(page)
      await expect(page.getByTestId(tab.body)).toBeVisible()
      if (tab.key === OVERVIEW)
        await expectBuiltInCountryCard(expect, page, deletes)
      await page.reload()
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(new RegExp(`${tab.path}$`))
      await expectCountryPageHead(expect, page, {
        code: 'us',
        name: 'United States',
        currentPage: tab.key,
        appName: APP_NAME,
      })
      await expectCountryBreadcrumb(page)
    }

    await gotoPage(expect, page, OVERVIEW_PATH, PAGE_READY)
    const document = await watchClientDocument(page)
    try {
      await clickSubmit(expect, page.getByTestId(`hilos-page-tab-${NAMES}`))
      await expect(page).toHaveURL(new RegExp(`${NAMES_PATH}$`))
      await expectPageReady(expect, page)
      await expectCountryPageHead(expect, page, {
        code: 'us',
        name: 'United States',
        currentPage: NAMES,
        appName: APP_NAME,
      })
      await expectCountryBreadcrumb(page)
      await expect(page.getByTestId('country-names')).toBeVisible()
      await document.unchanged()

      const overview = page.getByTestId(`hilos-page-tab-${OVERVIEW}`)
      await overview.focus()
      await overview.press('Enter')
      await expect(page).toHaveURL(new RegExp(`${OVERVIEW_PATH}$`))
      await expectPageReady(expect, page)
      await expectCountryBreadcrumb(page)
      await expect(page.getByTestId('country-card-main')).toBeVisible()
      await document.unchanged()
      await page.goBack()
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(new RegExp(`${NAMES_PATH}$`))
      await expect(page.getByTestId(`hilos-page-tab-${NAMES}`)).toHaveAttribute(
        'aria-current',
        'page',
      )
      await document.unchanged()
    } finally {
      await document.dispose()
    }
    expect(deletes()).toBe(deletesBefore)
  })

  await test.step('Switch on the known country with no default locale, read its frozen fields and return it to off', async () => {
    // Canada starts off with no default locale; restore that borrowed row even after a failed assertion.
    i18n.defer(() => switchCountryOff('ca'))
    await gotoPage(expect, page, '/hilos/i18n/countries/ca', PAGE_READY)
    await expect(page.getByTestId('country-card-enabled')).toHaveText(
      'Disabled',
    )
    await expect(page.getByTestId('country-card-default-locale')).toHaveText(
      'not chosen',
    )
    await clickSubmit(expect, page.getByTestId('country-card-switch-on'))
    await expect(page.getByTestId('country-card-switch-on-note')).toContainText(
      'No default locale is chosen',
    )
    await clickSubmit(
      expect,
      page.getByTestId('country-card-switch-on-confirm'),
    )
    await expect(page.getByTestId('country-card-enabled')).toHaveText('Enabled')
    await expect(page.getByTestId('country-card-frozen')).toBeVisible()
    await clickSubmit(expect, page.getByTestId('country-card-view'))
    for (const field of [
      'currency-symbol',
      'currency-code',
      'default-locale',
      'code',
    ])
      await expect(
        page.getByTestId(`i18n-country-edit-${field}`),
      ).toBeDisabled()
    await expect(page.getByTestId('i18n-country-save')).toHaveCount(0)
    await clickSubmit(expect, page.getByTestId('i18n-country-close'))
    await clickSubmit(expect, page.getByTestId('country-card-switch-off'))
    await clickSubmit(
      expect,
      page.getByTestId('country-card-switch-off-confirm'),
    )
    await expect(page.getByTestId('country-card-enabled')).toHaveText(
      'Disabled',
    )
    await expect(page.getByTestId('country-card-frozen')).toHaveCount(0)
  })

  await test.step('Refuse a known duplicate, cancel upkeep, and confirm the custom country', async () => {
    await gotoPage(expect, page, '/hilos/i18n/countries', PAGE_READY)
    await clickSubmit(expect, page.getByTestId('hilos-table-main-action'))
    await typeInto(page.getByTestId('i18n-country-add-code'), 'us')
    await typeInto(page.getByTestId('i18n-country-add-currency-symbol'), '$')
    await typeInto(page.getByTestId('i18n-country-add-currency-code'), 'USD')
    await expect(page.getByTestId('i18n-country-add-plate')).toHaveAttribute(
      'data-kind',
      'known',
    )
    await clickSubmit(expect, page.getByTestId('i18n-country-add-submit'))
    await expect(page.getByTestId('i18n-country-add-error')).toContainText(
      "Country 'us' already exists.",
    )
    await expect(page.getByTestId('i18n-country-add-submit')).toBeEnabled()

    await typeInto(page.getByTestId('i18n-country-add-code'), 'gg')
    await typeInto(page.getByTestId('i18n-country-add-currency-symbol'), '£')
    await typeInto(page.getByTestId('i18n-country-add-currency-code'), 'GBP')
    await expect(page.getByTestId('i18n-country-add-plate')).toHaveAttribute(
      'data-kind',
      'own',
    )
    await clickSubmit(expect, page.getByTestId('i18n-country-add-submit'))
    await expect(page.getByTestId('modal')).toHaveAccessibleName(
      'The system does not know this country',
    )
    await expect(page.getByTestId('i18n-country-confirm-code')).toHaveText('gg')
    const beforeCancel = adds()
    await clickSubmit(expect, page.getByTestId('i18n-country-confirm-cancel'))
    await expect(page.getByTestId('i18n-country-confirm')).toHaveCount(0)
    expect(adds()).toBe(beforeCancel)

    await clickSubmit(expect, page.getByTestId('hilos-table-main-action'))
    for (const [field, value] of [
      ['code', 'gg'],
      ['currency-symbol', '£'],
      ['currency-code', 'GBP'],
    ])
      await typeInto(page.getByTestId(`i18n-country-add-${field}`), value!)
    await clickSubmit(expect, page.getByTestId('i18n-country-add-submit'))
    await clickSubmit(expect, page.getByTestId('i18n-country-confirm-submit'))
    await expect(page.getByTestId('i18n-country-confirm')).toHaveCount(0)
    await expect(page.getByTestId('hilos-toast-success')).toContainText(
      'Custom country gg added and switched off for now.',
    )
    await typeInto(page.getByTestId('hilos-table-search'), 'gg')
    const row = shownByTestId(page, /^hilos-table-row-/).filter({
      has: page.getByTestId('i18n-countries-link-gg'),
    })
    await expect(row).toBeVisible()
    ggCountryId = Number(
      (await row.getAttribute('data-id'))!.replace('hilos-table-row-', ''),
    )
    expect(ggCountryId).toBeGreaterThan(0)
    await expect(row.getByTestId('i18n-countries-own-gg')).toBeVisible()
    await expect(row.getByTestId('i18n-countries-enabled-gg')).not.toBeChecked()
  })

  await test.step('Edit custom currency with invalid-field guards, then read its frozen and switch states', async () => {
    await gotoPage(expect, page, '/hilos/i18n/countries/gg', PAGE_READY)
    await expect(page.getByTestId('country-head-own')).toBeVisible()
    await clickSubmit(expect, page.getByTestId('country-card-edit'))
    await expect(page.getByTestId('i18n-country-edit-code')).toBeDisabled()
    await expect(page.getByTestId('i18n-country-edit-code')).toHaveValue('gg')
    await expect(
      page.getByTestId('i18n-country-edit-default-locale'),
    ).toHaveValue('')
    const currency = page.getByTestId('i18n-country-edit-currency-code')
    const symbol = page.getByTestId('i18n-country-edit-currency-symbol')
    const save = page.getByTestId('i18n-country-save')
    await typeInto(symbol, '')
    await expect(save).toBeDisabled()
    await typeInto(symbol, 'G£')
    await typeInto(currency, 'GB')
    await expect(save).toBeDisabled()
    const beforeInvalidEnter = writes().length
    await currency.press('Enter')
    expect(writes()).toHaveLength(beforeInvalidEnter)
    await typeInto(currency, 'GBP')
    await clickSubmit(expect, save)
    await expect(page.getByTestId('i18n-country-window')).toHaveCount(0)
    await expect(page.getByTestId('country-card-currency')).toHaveText('G£ GBP')
    expect(writes().at(-1)).toEqual({
      countryId: ggCountryId,
      currencySymbol: 'G£',
      currencyCode: 'GBP',
      defaultLocaleId: null,
    })

    await clickSubmit(expect, page.getByTestId('country-card-switch-on'))
    await expect(page.getByTestId('country-card-switch-on-note')).toContainText(
      'No default locale is chosen',
    )
    await clickSubmit(
      expect,
      page.getByTestId('country-card-switch-on-confirm'),
    )
    await expect(
      page.getByTestId('country-card-switch-on-confirm'),
    ).toHaveCount(0)
    await expect(page.getByTestId('country-card-enabled')).toHaveText('Enabled')
    await expect(page.getByTestId('country-card-frozen')).toBeVisible()
    await clickSubmit(expect, page.getByTestId('country-card-view'))
    await expect(page.getByTestId('i18n-country-title')).toHaveText(
      'Country · GG',
    )
    for (const field of [
      'currency-symbol',
      'currency-code',
      'default-locale',
      'code',
    ])
      await expect(
        page.getByTestId(`i18n-country-edit-${field}`),
      ).toBeDisabled()
    await expect(symbol).toHaveValue('G£')
    await expect(page.getByTestId('i18n-country-plate')).toContainText(
      'switched on',
    )
    await expect(save).toHaveCount(0)
    await clickSubmit(expect, page.getByTestId('i18n-country-close'))
    await expect(page.getByTestId('i18n-country-window')).toHaveCount(0)
    await clickSubmit(expect, page.getByTestId('country-card-switch-off'))
    await expect(
      page.getByTestId('country-card-switch-off-locale-count'),
    ).toHaveText('0')
    await expect(
      page.getByTestId('country-card-switch-off-name-count'),
    ).toHaveText('0')
    await clickSubmit(
      expect,
      page.getByTestId('country-card-switch-off-confirm'),
    )
    await expect(page.getByTestId('country-card-enabled')).toHaveText(
      'Disabled',
    )
    await expect(page.getByTestId('country-card-frozen')).toHaveCount(0)
    await expect(page.getByTestId('country-card-switch-on')).toBeVisible()
  })

  await test.step('Save the built-in default-locale suggestion while preserving its currency', async () => {
    await gotoPage(expect, page, '/hilos/i18n/countries', PAGE_READY)
    await typeInto(page.getByTestId('hilos-table-search'), 'ie')
    const row = shownByTestId(page, /^hilos-table-row-/).filter({
      has: page.getByTestId('i18n-countries-link-ie'),
    })
    await expect(row.getByTestId('i18n-countries-enabled-ie')).not.toBeChecked()
    await expect(
      row.getByTestId('i18n-countries-locale-none-ie'),
    ).toContainText('Not chosen')
    const countryId = Number(
      (await row.getAttribute('data-id'))!.replace('hilos-table-row-', ''),
    )
    expect(countryId).toBeGreaterThan(0)
    const locale = await i18n.locale({
      languageId: englishId,
      countryId,
    })
    // The borrowed catalog row returns to its known off/null baseline before this fixture is cleared.
    i18n.defer(() => switchCountryOff('ie'))
    await dismissToasts(page)
    await clickSubmit(expect, row.getByTestId('i18n-countries-edit-ie'))
    await expect(page.getByTestId('i18n-country-title')).toHaveText(
      'Edit country · Ireland',
    )
    const selected = page.getByTestId('i18n-country-edit-default-locale')
    await expect(selected).toHaveValue(String(locale.id))
    await expect(selected.locator('option:checked')).toHaveText(
      'en-IE — switched off',
    )
    await expect(
      page.getByTestId('i18n-country-edit-default-locale-hint'),
    ).toContainText('Suggested by the built-in catalog — save to keep it.')
    await expect(
      page.getByTestId('i18n-country-edit-currency-symbol'),
    ).toHaveValue('€')
    await expect(
      page.getByTestId('i18n-country-edit-currency-code'),
    ).toHaveValue('EUR')
    const ieSave = page.getByTestId('i18n-country-save')
    await clickSubmit(expect, ieSave)
    await expect(page.getByTestId('i18n-country-window')).toHaveCount(0)
    await expect(row).toContainText('en-IE')
    expect(writes().at(-1)).toEqual({
      countryId,
      currencySymbol: '€',
      currencyCode: 'EUR',
      defaultLocaleId: locale.id,
    })
    await gotoPage(expect, page, '/hilos/i18n/countries/ie', PAGE_READY)
    await clickSubmit(expect, page.getByTestId('country-card-switch-on'))
    await clickSubmit(
      expect,
      page.getByTestId('country-card-switch-on-confirm'),
    )
    await expect(page.getByTestId('country-card-enabled')).toHaveText('Enabled')
    await clickSubmit(expect, page.getByTestId('country-card-switch-off'))
    await expect(
      page.getByTestId('country-card-switch-off-counts'),
    ).toBeVisible()
    await expect(
      page.getByTestId('country-card-switch-off-locale-count'),
    ).toHaveText(/^\d+$/)
    await expect(
      page.getByTestId('country-card-switch-off-name-count'),
    ).toHaveText(/^\d+$/)
    await clickSubmit(
      expect,
      page.getByTestId('country-card-switch-off-confirm'),
    )
    await expect(page.getByTestId('country-card-enabled')).toHaveText(
      'Disabled',
    )
    await clearLocale(locale.code)
    await expect(page.getByTestId('country-card-currency')).toHaveText('€ EUR')
    await expect(page.getByTestId('country-card-default-locale')).toHaveText(
      'not chosen',
    )
  })

  await test.step('Refuse locale-referred and built-in deletion with their own descriptions and no keyboard write', async () => {
    const locale = await i18n.locale({
      languageId: englishId,
      countryId: companion.id,
    })
    for (const [code, reason] of [
      ['qr', 'Cannot delete: locales refer to this country'],
      [
        'us',
        'Cannot delete: the built-in catalog knows this country and the reflow would bring it back; switch it off instead',
      ],
    ] as const) {
      await gotoPage(expect, page, '/hilos/i18n/countries', PAGE_READY)
      const search = page.getByTestId('hilos-table-search')
      await typeInto(search, code)
      const remove = shownByTestId(page, `i18n-countries-delete-${code}`)
      const description = shownByTestId(
        page,
        `i18n-countries-delete-reason-${code}`,
      )
      await expect(remove).toHaveAttribute('aria-disabled', 'true')
      await expect(description).toHaveText(reason)
      await expectCountryDeleteDescription(expect, page, code)
      await remove.focus()
      await expect(remove).toBeFocused()
      await remove.press('Enter')
      await expect(page.getByTestId('i18n-country-delete-window')).toHaveCount(
        0,
      )
      await expectCountryDeleteDescription(expect, page, code)
      await gotoPage(expect, page, `/hilos/i18n/countries/${code}`, PAGE_READY)
      await expect(
        page.getByTestId('country-card-delete-verdict'),
      ).toContainText(reason)
      const cardRemove = page.getByTestId('country-card-delete')
      await expect(cardRemove).toHaveAttribute('aria-disabled', 'true')
      await cardRemove.focus()
      await expect(cardRemove).toBeFocused()
      await cardRemove.press('Enter')
      await expect(page.getByTestId('i18n-country-delete-window')).toHaveCount(
        0,
      )
    }
    expect(deletes()).toBe(0)
    await clearLocale(locale.code)
    await expect(page.getByTestId('country-card-currency')).toHaveText('$ USD')
    await expect(page.getByTestId('country-card-default-locale')).toHaveText(
      'not chosen',
    )
    await expect(page.getByTestId('country-card-enabled')).toHaveText(
      'Disabled',
    )
    await expect(page.getByTestId('country-card-delete')).toHaveAttribute(
      'aria-disabled',
      'true',
    )
  })

  await test.step('Cancel and confirm State deletion, then remove the companion from the list', async () => {
    await gotoPage(expect, page, '/hilos/i18n/countries/gg', PAGE_READY)
    await clickSubmit(expect, page.getByTestId('country-card-delete'))
    await expect(page.getByTestId('i18n-country-delete-window')).toContainText(
      'It will be removed entirely.',
    )
    await clickSubmit(expect, page.getByTestId('i18n-country-delete-cancel'))
    await expect(page.getByTestId('i18n-country-delete-window')).toHaveCount(0)
    await expect(page.getByTestId('country-card-code')).toHaveText('gg')
    const beforeConfirm = deletes()
    expect(beforeConfirm).toBe(0)
    await clickSubmit(expect, page.getByTestId('country-card-delete'))
    await clickSubmit(expect, page.getByTestId('i18n-country-delete-confirm'))
    await expect(page.getByTestId('i18n-country-delete-window')).toHaveCount(0)
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(/\/hilos\/i18n\/countries$/)
    await expect(page.getByTestId('hilos-toast-success')).toContainText(
      'Country gg deleted.',
    )
    await typeInto(page.getByTestId('hilos-table-search'), 'gg')
    await expect(page.getByTestId('i18n-countries-link-gg')).toHaveCount(0)
    await typeInto(page.getByTestId('hilos-table-search'), 'qr')
    await dismissToasts(page)
    const remove = shownByTestId(page, 'i18n-countries-delete-qr')
    await expect(remove).toHaveAccessibleName('Delete country QR')
    await clickSubmit(expect, remove)
    await clickSubmit(expect, page.getByTestId('i18n-country-delete-confirm'))
    await expect(page.getByTestId('i18n-country-delete-window')).toHaveCount(0)
    await expect(page.getByTestId('hilos-toast-success')).toContainText(
      'Country qr deleted.',
    )
    await settleRemovedRow(expect, page, 'i18n-countries-delete-qr')
  })
})

test('country.live-deletion', async ({ page, context, i18n }) => {
  // Each entry point removes a different country while both tabs keep copyable drafts open.
  test.slow()
  await i18n.country({ code: 'qn', currencySymbol: '¤', currencyCode: 'XQN' })
  await i18n.country({ code: 'qo', currencySymbol: '¤', currencyCode: 'XQO' })
  await grantAdminToSelf(expect, page)

  for (const [code, entry] of [
    ['qn', 'list'],
    ['qo', 'card'],
  ] as const) {
    await test.step(`Delete from ${entry} while Overview and Names retain their drafts`, async () => {
      const card = await context.newPage()
      i18n.defer(() => card.close())
      const names = await context.newPage()
      i18n.defer(() => names.close())
      const cardFrames = watchPageErrorFrames(card)
      const nameFrames = watchPageErrorFrames(names)
      const cardWrites = countSentFrames(card, 'hilos_i18n_country_update')
      const nameWrites = countSentFrames(names, 'hilos_i18n_country_name_add')
      await gotoPage(expect, card, `/hilos/i18n/countries/${code}`, PAGE_READY)
      await gotoPage(
        expect,
        names,
        `/hilos/i18n/countries/${code}/names`,
        PAGE_READY,
      )
      await clickSubmit(expect, card.getByTestId('country-card-edit'))
      const symbol = card.getByTestId('i18n-country-edit-currency-symbol')
      await expect(symbol).toBeFocused()
      await typeInto(symbol, 'Q$')
      await clickSubmit(
        expect,
        shownByTestId(names, 'i18n-country-name-add-en'),
      )
      const name = names.getByTestId('i18n-country-name-input')
      await expect(name).toBeFocused()
      await typeInto(name, 'Draft name')
      const live = await watchI18nLivePages(expect, [card, names])

      if (entry === 'list') {
        await gotoPage(expect, page, '/hilos/i18n/countries', PAGE_READY)
        await typeInto(page.getByTestId('hilos-table-search'), code)
        await clickSubmit(
          expect,
          shownByTestId(page, `i18n-countries-delete-${code}`),
        )
      } else {
        await gotoPage(
          expect,
          page,
          `/hilos/i18n/countries/${code}`,
          PAGE_READY,
        )
        await clickSubmit(expect, page.getByTestId('country-card-delete'))
      }
      await expect(
        page.getByTestId('i18n-country-delete-window'),
      ).toContainText('It will be removed entirely.')
      await expect(page.getByTestId('modal')).toHaveAccessibleName(
        `Delete country · ${code.toUpperCase()}`,
      )
      await clickSubmit(expect, page.getByTestId('i18n-country-delete-confirm'))
      await expect(page.getByTestId('i18n-country-delete-window')).toHaveCount(
        0,
      )
      await expect(page).toHaveURL(/\/hilos\/i18n\/countries$/)
      await expect(page.getByTestId('hilos-toast-success')).toContainText(
        `Country ${code} deleted.`,
      )
      await expectDeletedCountryCard(expect, card, cardFrames, code)
      await expectDeletedCountryCard(expect, names, nameFrames, code, 'names')
      await expect(card.getByTestId('i18n-country-window')).toBeVisible()
      await expect(names.getByTestId('i18n-country-name-window')).toBeVisible()
      await expect(symbol).toHaveValue('Q$')
      await expect(name).toHaveValue('Draft name')
      for (const [observer, prefix, saveId] of [
        [card, 'i18n-country', 'i18n-country-save'],
        [names, 'i18n-country-name', 'i18n-country-name-submit'],
      ] as const) {
        await expect(observer.getByTestId(`${prefix}-notice`)).toContainText(
          DELETED_NOTICE,
        )
        await expect(observer.getByTestId(saveId)).toBeDisabled()
        await expect(observer.getByTestId(saveId)).toContainText('Deleted')
        await observer.getByTestId('modal').press('Enter')
      }
      expect(cardWrites()).toBe(0)
      expect(nameWrites()).toBe(0)
      await live.unchanged()

      // HIL-1813 may add a discard prompt; handle it if it is present without prescribing it.
      await cancelI18nEditor(
        expect,
        card,
        'i18n-country-window',
        'i18n-country-cancel',
      )
      await cancelI18nEditor(
        expect,
        names,
        'i18n-country-name-window',
        'i18n-country-name-cancel',
      )
      await expectDeletedCountryCard(expect, card, cardFrames, code)
      await expectDeletedCountryCard(expect, names, nameFrames, code, 'names')
      await live.unchanged()

      for (const [observer, frames, tab] of [
        [card, cardFrames, 'overview'],
        [names, nameFrames, 'names'],
      ] as const) {
        const beforeReload = frames.length
        await observer.reload()
        await expectDeletedCountryCardReloaded(
          expect,
          observer,
          frames,
          code,
          beforeReload,
          tab,
        )
        const beforeDirect = frames.length
        await gotoPage(
          expect,
          observer,
          `/hilos/i18n/countries/${code}${tab === 'names' ? '/names' : ''}`,
          PAGE_REFUSED,
        )
        await expectDeletedCountryCardReloaded(
          expect,
          observer,
          frames,
          code,
          beforeDirect,
          tab,
        )
      }
      expect(cardWrites()).toBe(0)
      expect(nameWrites()).toBe(0)
      await expect(page).toHaveURL(/\/hilos\/i18n\/countries$/)
      if (entry === 'list')
        await settleRemovedRow(expect, page, `i18n-countries-delete-${code}`)
      await dismissToasts(page)
      await typeInto(page.getByTestId('hilos-table-search'), code)
      await expect(page.getByTestId(`i18n-countries-link-${code}`)).toHaveCount(
        0,
      )
    })
  }
})
