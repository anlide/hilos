import { expect, test as base } from '@playwright/test'
import {
  cancelI18nEditor,
  clickSubmit,
  countSentFrames,
  dismissToasts,
  expectCountryDeleteDescription,
  expectCountryPageHead,
  expectDeletedCountryCard,
  expectDeletedCountryCardReloaded,
  expectDocumentUnchanged,
  expectI18nPageHead,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  i18nCountryUpdates,
  markDocument,
  PAGE_READY,
  PAGE_REFUSED,
  settleRemovedRow,
  shownByTestId,
  typeInto,
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

test('country.life', async ({ page, i18n }, testInfo) => {
  // Table navigation, both creation decisions and the edit/switch/delete cycle share one administrator.
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

  await test.step('Read, page and search the countries catalog and capture the default language prerequisite', async () => {
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
      appName: 'BTC Tracker',
      tabsLabel: 'Internationalization pages',
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
  })

  await test.step('Open the known country directly and follow Overview and Names by pointer, keyboard, Back and F5', async () => {
    await gotoPage(expect, page, '/hilos/i18n/countries/us', PAGE_READY)
    await expectCountryPageHead(expect, page, {
      code: 'us',
      name: 'United States',
      currentPage: 'hilos_i18n_country',
      appName: 'BTC Tracker',
    })
    await expect(page.getByTestId('country-head-description')).toHaveText(
      'Currency $ USD · Default locale not chosen',
    )
    await expect(page.getByTestId('country-head-own')).toHaveCount(0)
    await expect(page.getByTestId('country-card-currency')).toHaveText('$ USD')
    await expect(page.getByTestId('country-card-default-locale')).toHaveText(
      'not chosen',
    )
    await expect(page.getByTestId('country-card-enabled')).toHaveText(
      'Disabled',
    )
    await expect(page.getByTestId('country-card-edit')).toBeVisible()
    const remove = page.getByTestId('country-card-delete')
    await expect(remove).toHaveAccessibleName('Delete country')
    await expect(remove).toHaveAttribute('aria-disabled', 'true')
    await expect(remove).toHaveAttribute(
      'aria-describedby',
      'country-card-delete-reason',
    )
    await expect(remove).toHaveAccessibleDescription(
      'Cannot delete: the built-in catalog knows this country and the reflow would bring it back; switch it off instead',
    )
    await remove.focus()
    await remove.press('Enter')
    await remove.press('Space')
    await expect(page.getByTestId('i18n-country-delete-window')).toHaveCount(0)
    expect(deletes()).toBe(0)
    await markDocument(page)
    await clickSubmit(
      expect,
      page.getByTestId('hilos-page-tab-hilos_i18n_country_names'),
    )
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(/\/countries\/us\/names$/)
    await expectCountryPageHead(expect, page, {
      code: 'us',
      name: 'United States',
      currentPage: 'hilos_i18n_country_names',
      appName: 'BTC Tracker',
    })
    await expect(page.getByTestId('country-names')).toBeVisible()
    await expectDocumentUnchanged(expect, page)
    const overview = page.getByTestId('hilos-page-tab-hilos_i18n_country')
    await overview.focus()
    await overview.press('Enter')
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(/\/countries\/us$/)
    await expect(page.getByTestId('country-card-main')).toBeVisible()
    await expectDocumentUnchanged(expect, page)
    for (const [path, currentPage] of [
      ['/hilos/i18n/countries/us', 'hilos_i18n_country'],
      ['/hilos/i18n/countries/us/names', 'hilos_i18n_country_names'],
    ]) {
      await gotoPage(expect, page, path!, PAGE_READY)
      await page.reload()
      await expectPageReady(expect, page)
      await expectCountryPageHead(expect, page, {
        code: 'us',
        name: 'United States',
        currentPage: currentPage!,
        appName: 'BTC Tracker',
      })
    }
    await clickSubmit(
      expect,
      page.getByTestId('hilos-page-tab-hilos_i18n_country'),
    )
    await expectPageReady(expect, page)
    await page.goBack()
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(/\/countries\/us\/names$/)
    await expect(
      page.getByTestId('hilos-page-tab-hilos_i18n_country_names'),
    ).toHaveAttribute('aria-current', 'page')
    expect(writes()).toHaveLength(0)
  })

  await test.step('Reject a duplicate known country and cancel then confirm custom upkeep', async () => {
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
    // Keep the refused form and turn it into the custom request, then cancel its upkeep decision.
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
    const before = adds()
    await clickSubmit(expect, page.getByTestId('i18n-country-confirm-cancel'))
    await expect(page.getByTestId('i18n-country-confirm')).toHaveCount(0)
    expect(adds()).toBe(before)
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
    await expect(shownByTestId(page, 'i18n-countries-own-gg')).toBeVisible()
    await expect(
      shownByTestId(page, 'i18n-countries-enabled-gg'),
    ).not.toBeChecked()
  })

  await test.step('Edit the custom currency, reject incomplete fields and read its frozen view', async () => {
    await gotoPage(expect, page, '/hilos/i18n/countries/gg', PAGE_READY)
    await expect(page.getByTestId('country-head-own')).toBeVisible()
    await expect(page.getByTestId('hilos-page-head-title')).toHaveText(
      'Unnamed country',
    )
    await clickSubmit(expect, page.getByTestId('country-card-edit'))
    await expect(page.getByTestId('i18n-country-edit-code')).toBeDisabled()
    await expect(page.getByTestId('i18n-country-edit-code')).toHaveValue('gg')
    await expect(
      page.getByTestId('i18n-country-edit-default-locale'),
    ).toHaveValue('')
    const currency = page.getByTestId('i18n-country-edit-currency-code')
    const symbol = page.getByTestId('i18n-country-edit-currency-symbol')
    await typeInto(symbol, '')
    await expect(page.getByTestId('i18n-country-save')).toBeDisabled()
    await typeInto(symbol, 'G£')
    await typeInto(currency, 'GB')
    await expect(page.getByTestId('i18n-country-save')).toBeDisabled()
    await currency.press('Enter')
    expect(writes()).toHaveLength(0)
    await typeInto(currency, 'GBP')
    await clickSubmit(expect, page.getByTestId('i18n-country-save'))
    await expect(page.getByTestId('i18n-country-window')).toHaveCount(0)
    await expect(page.getByTestId('country-card-currency')).toHaveText('G£ GBP')
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
    await expect(page.getByTestId('i18n-country-save')).toHaveCount(0)
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
    await expect(
      page.getByTestId('country-card-switch-off-confirm'),
    ).toHaveCount(0)
    await expect(page.getByTestId('country-card-enabled')).toHaveText(
      'Disabled',
    )
    await expect(page.getByTestId('country-card-frozen')).toHaveCount(0)
    await expect(page.getByTestId('country-card-switch-on')).toBeVisible()
  })

  await test.step('Save the built-in default-locale suggestion without changing the borrowed currency', async () => {
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
    i18n.defer(() => switchCountryOff('ie'))
    const locale = await i18n.locale({ languageId: englishId, countryId })
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
    await clickSubmit(expect, page.getByTestId('i18n-country-save'))
    await expect(page.getByTestId('i18n-country-window')).toHaveCount(0)
    await expect(row).toContainText('en-IE')
    expect(writes()[1]).toEqual({
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
    await expect(
      page.getByTestId('country-card-switch-on-confirm'),
    ).toHaveCount(0)
    await expect(page.getByTestId('country-card-enabled')).toHaveText('Enabled')
    await clickSubmit(expect, page.getByTestId('country-card-switch-off'))
    await clickSubmit(
      expect,
      page.getByTestId('country-card-switch-off-confirm'),
    )
    await expect(
      page.getByTestId('country-card-switch-off-confirm'),
    ).toHaveCount(0)
    await expect(page.getByTestId('country-card-enabled')).toHaveText(
      'Disabled',
    )
    await clearLocale(locale.code)
    await expect(page.getByTestId('country-card-default-locale')).toHaveText(
      'not chosen',
    )
    await expect(page.getByTestId('country-card-currency')).toHaveText('€ EUR')
  })

  await test.step('Resolve the current blocked-delete description after a real table redraw and guard keyboard writes', async () => {
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
    ]) {
      await gotoPage(expect, page, '/hilos/i18n/countries', PAGE_READY)
      const search = page.getByTestId('hilos-table-search')
      await typeInto(search, code!)
      const remove = shownByTestId(page, `i18n-countries-delete-${code}`)
      const description = shownByTestId(
        page,
        `i18n-countries-delete-reason-${code}`,
      )
      await expect(remove).toHaveAttribute('aria-disabled', 'true')
      await expect(description).toHaveText(reason!)
      await expectCountryDeleteDescription(expect, page, code!)
      await remove.focus()
      await remove.press('Enter')
      await expect(page.getByTestId('i18n-country-delete-window')).toHaveCount(
        0,
      )
      const previousId = (await description.getAttribute('id'))!
      await typeInto(search, 'no-such-country-redraw')
      await expect(
        page.getByTestId(`i18n-countries-delete-${code}`),
      ).toHaveCount(0)
      await typeInto(search, code!)
      await expect(description).toBeVisible()
      const currentId = await description.getAttribute('id')
      expect(currentId).not.toBe(previousId)
      await expect(description).toHaveText(reason!)
      await expectCountryDeleteDescription(expect, page, code!)
      await testInfo.attach(`country-${code}-redraw`, {
        body: JSON.stringify({
          previousId,
          currentId,
          describedBy: await remove.getAttribute('aria-describedby'),
        }),
        contentType: 'application/json',
      })
      await gotoPage(expect, page, `/hilos/i18n/countries/${code}`, PAGE_READY)
      await expect(
        page.getByTestId('country-card-delete-verdict'),
      ).toContainText(reason!)
      await expect(page.getByTestId('country-card-delete')).toHaveAttribute(
        'aria-disabled',
        'true',
      )
      await page.getByTestId('country-card-delete').focus()
      await page.getByTestId('country-card-delete').press('Enter')
      await expect(page.getByTestId('i18n-country-delete-window')).toHaveCount(
        0,
      )
    }
    expect(deletes()).toBe(0)
    await clearLocale(locale.code)
  })

  await test.step('Cancel and confirm custom deletion from State, then remove the companion from the list', async () => {
    await gotoPage(expect, page, '/hilos/i18n/countries/gg', PAGE_READY)
    await clickSubmit(expect, page.getByTestId('country-card-delete'))
    await expect(page.getByTestId('i18n-country-delete-window')).toContainText(
      'It will be removed entirely.',
    )
    await clickSubmit(expect, page.getByTestId('i18n-country-delete-cancel'))
    await expect(page.getByTestId('i18n-country-delete-window')).toHaveCount(0)
    await expect(page.getByTestId('country-card-code')).toHaveText('gg')
    expect(deletes()).toBe(0)
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
  // Both deletion entry places notify open Overview/Names drafts, then cold subscriptions prove the final 404.
  test.slow()
  await i18n.country({ code: 'qn', currencySymbol: '¤', currencyCode: 'XQN' })
  await i18n.country({ code: 'qo', currencySymbol: '¤', currencyCode: 'XQO' })
  await grantAdminToSelf(expect, page)
  for (const [code, entry] of [
    ['qn', 'list'],
    ['qo', 'card'],
  ]) {
    await test.step(`Delete from ${entry} while Overview and Names retain copyable drafts`, async () => {
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
      const input = names.getByTestId('i18n-country-name-input')
      await expect(input).toBeFocused()
      await typeInto(input, 'Draft name')
      const live = await watchI18nLivePages(expect, [card, names])
      if (entry === 'list') {
        await gotoPage(expect, page, '/hilos/i18n/countries', PAGE_READY)
        await typeInto(page.getByTestId('hilos-table-search'), code!)
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
        `Delete country · ${code!.toUpperCase()}`,
      )
      await clickSubmit(expect, page.getByTestId('i18n-country-delete-confirm'))
      await expect(page.getByTestId('i18n-country-delete-window')).toHaveCount(
        0,
      )
      await expect(page).toHaveURL(/\/hilos\/i18n\/countries$/)
      await expect(page.getByTestId('hilos-toast-success')).toContainText(
        `Country ${code} deleted.`,
      )
      await expectDeletedCountryCard(expect, card, cardFrames, code!)
      await expectDeletedCountryCard(expect, names, nameFrames, code!, 'names')
      await expect(card.getByTestId('i18n-country-window')).toBeVisible()
      await expect(names.getByTestId('i18n-country-name-window')).toBeVisible()
      await expect(symbol).toHaveValue('Q$')
      await expect(input).toHaveValue('Draft name')
      for (const [observer, prefix, saveId] of [
        [card, 'i18n-country', 'i18n-country-save'],
        [names, 'i18n-country-name', 'i18n-country-name-submit'],
      ] as const) {
        await expect(observer.getByTestId(`${prefix}-notice`)).toContainText(
          'Deleted elsewhere — your text stays to copy.',
        )
        await expect(observer.getByTestId(saveId)).toBeDisabled()
        await expect(observer.getByTestId(saveId)).toContainText('Deleted')
        await observer.getByTestId('modal').press('Enter')
      }
      expect(cardWrites()).toBe(0)
      expect(nameWrites()).toBe(0)
      await live.unchanged()
      // Existing dirty confirmation may still appear; this test does not prescribe its removal.
      for (const [observer, prefix] of [
        [card, 'i18n-country'],
        [names, 'i18n-country-name'],
      ] as const) {
        await cancelI18nEditor(
          expect,
          observer,
          `${prefix}-window`,
          `${prefix}-cancel`,
        )
      }
      await expectDeletedCountryCard(expect, card, cardFrames, code!)
      await expectDeletedCountryCard(expect, names, nameFrames, code!, 'names')
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
          code!,
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
          code!,
          beforeDirect,
          tab,
        )
      }
      expect(cardWrites()).toBe(0)
      expect(nameWrites()).toBe(0)
      await dismissToasts(page)
      if (entry === 'list')
        await settleRemovedRow(expect, page, `i18n-countries-delete-${code}`)
      await typeInto(page.getByTestId('hilos-table-search'), code!)
      await gotoPage(expect, page, '/hilos/i18n/countries', PAGE_READY)
      await typeInto(page.getByTestId('hilos-table-search'), code!)
      await expect(page.getByTestId(`i18n-countries-link-${code}`)).toHaveCount(
        0,
      )
    })
  }
})
