import { expect, test as baseTest, type Page } from '@playwright/test'

import {
  dismissToasts,
  expectI18nName,
  i18nLocaleRow,
  i18nNameRow,
  readClusterBrowserStand,
  shownByTestId,
  watchClusterMasterUpgrades,
  watchI18nLivePages,
} from '../../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../../helpers/adminGrant.js'
import {
  CLUSTER_FOLLOWER,
  CLUSTER_LEADER,
  CLUSTER_MASTERS,
  clusterInspect,
  expectLeaderUnmoved,
  newMasterPage,
} from '../../helpers/cluster.js'
import {
  clearCountryNamesFixture,
  clearLanguageNamesFixture,
  deleteLanguage,
  deleteLocale,
  seedCountryNamesFixture,
  seedLanguageNamesFixture,
  switchCountryOff,
  switchLanguageOff,
  switchLocaleOn,
} from '../../helpers/i18n.js'
import { gotoPage, PAGE_READY } from '../../helpers/page.js'
import { clickSubmit, typeInto } from '../../helpers/session.js'

const stand = readClusterBrowserStand()
const test = baseTest.extend<{ masters: [Page, Page] }>({
  masters: async ({ browser }, use) => {
    const before = await clusterInspect(CLUSTER_FOLLOWER)
    await expectLeaderUnmoved(before)
    const otherMaster = CLUSTER_MASTERS.find(
      (node) => node !== CLUSTER_LEADER && node !== CLUSTER_FOLLOWER,
    )
    if (!otherMaster)
      throw new Error('The i18n browser proof needs two follower masters')
    const pages: Page[] = []
    const upgrades: Awaited<ReturnType<typeof watchClusterMasterUpgrades>>[] =
      []
    try {
      for (const node of [CLUSTER_FOLLOWER, otherMaster]) {
        const page = await newMasterPage(browser, node)
        pages.push(page)
        upgrades.push(
          await watchClusterMasterUpgrades(expect, page, stand, node),
        )
        await signUpAdmin(page)
      }
      expect(pages[0].context()).not.toBe(pages[1].context())
      for (const upgrade of upgrades) await upgrade.assert()
      await use([pages[0], pages[1]])
      for (const upgrade of upgrades) await upgrade.assert()
    } finally {
      try {
        for (const upgrade of upgrades) await upgrade.stop()
      } finally {
        for (const page of pages) await page.context().close()
        await expectLeaderUnmoved(before)
      }
    }
  },
})

// FIXME(F-016, 2026-10-18): parked on the owner's word (HOTFIX), without a diagnosis.
// All five tests red in 3 of the 6 full runs since 1268 (1269, 1271, 1277, all on seven
// lanes), each time green alone on the same sha (1270, 1272, 1278). Every failure is a
// browser sign-up after the daemon phase lost the leader and recreated s1: the code is
// accepted, the registration never completes. On 1274 and 1277 the auth throttle also went
// silent for 8-9 s and refused the first registration. Node journals:
// runs/<n>/steps/artifacts/binance-btc-tracker-cluster-e2e/nodes/ on nova-de.
test.fixme('cluster i18n: language nativeName reaches the other master in both directions', async ({
  masters: [first, second],
}) => {
  try {
    await switchLanguageOff('uk')
    for (const page of [first, second]) {
      await gotoPage(page, '/hilos/i18n/languages/uk', PAGE_READY)
      await expect(page.getByTestId('language-card-native-name')).toHaveText(
        'Українська',
      )
    }
    const live = await watchI18nLivePages(expect, [first, second])
    for (const [writer, value] of [
      [first, 'Українська on first master'],
      [second, 'Українська on second master'],
    ] as const) {
      await dismissToasts(writer)
      await clickSubmit(writer.getByTestId('language-card-edit'))
      await expect(writer.getByTestId('i18n-language-edit-code')).toHaveValue(
        'uk',
      )
      await typeInto(
        writer.getByTestId('i18n-language-edit-native-name'),
        value,
      )
      await clickSubmit(writer.getByTestId('i18n-language-edit-submit'))
      await expect(writer.getByTestId('i18n-language-edit-window')).toHaveCount(
        0,
      )
      for (const page of [first, second]) {
        await expect(page.getByTestId('language-card-native-name')).toHaveText(
          value,
        )
        await expect(page.getByTestId('language-card-code-tile')).toHaveText(
          'uk',
        )
      }
      await live.unchanged()
    }
  } finally {
    await deleteLanguage('uk')
  }
})

// FIXME(F-016, 2026-10-18): parked with the first test of this file; the reason is above it.
test.fixme('cluster i18n: country currencySymbol reaches a card on another master', async ({
  masters: [writer, observer],
}) => {
  let original: { symbol: string; currency: string; locale: string } | undefined
  let originalLine = ''
  let changed = false
  const symbol = writer.getByTestId('i18n-country-edit-currency-symbol')
  const currency = writer.getByTestId('i18n-country-edit-currency-code')
  const locale = writer.getByTestId('i18n-country-edit-default-locale')
  try {
    await switchCountryOff('ie')
    await gotoPage(writer, '/hilos/i18n/countries', PAGE_READY)
    await typeInto(writer.getByTestId('hilos-table-search'), 'ie')
    await expect(shownByTestId(writer, 'i18n-countries-edit-ie')).toBeVisible()
    await gotoPage(observer, '/hilos/i18n/countries/ie', PAGE_READY)
    originalLine = (await observer.getByTestId('country-card-line').innerText())
      .replace(/\s+/g, ' ')
      .trim()
    const live = await watchI18nLivePages(expect, [writer, observer])
    await clickSubmit(shownByTestId(writer, 'i18n-countries-edit-ie'))
    original = {
      symbol: await symbol.inputValue(),
      currency: await currency.inputValue(),
      locale: await locale.inputValue(),
    }
    // An unchosen default must stay unchosen even if the editor suggests a locale.
    if (originalLine.endsWith('Default locale not chosen')) original.locale = ''
    await locale.selectOption(original.locale)
    const value = original.symbol === 'IE-live' ? 'IE-other' : 'IE-live'
    await typeInto(symbol, value)
    changed = true
    await clickSubmit(writer.getByTestId('i18n-country-save'))
    await expect(writer.getByTestId('i18n-country-window')).toHaveCount(0)
    await expect(observer.getByTestId('country-card-line')).toContainText(
      `Currency ${value} ${original.currency}`,
    )
    await live.unchanged()
  } finally {
    if (changed && original) {
      await dismissToasts(writer)
      if ((await writer.getByTestId('i18n-country-window').count()) === 0)
        await clickSubmit(shownByTestId(writer, 'i18n-countries-edit-ie'))
      await typeInto(symbol, original.symbol)
      await typeInto(currency, original.currency)
      await locale.selectOption(original.locale)
      await clickSubmit(writer.getByTestId('i18n-country-save'))
      await expect(writer.getByTestId('i18n-country-window')).toHaveCount(0)
      await expect(observer.getByTestId('country-card-line')).toHaveText(
        originalLine,
      )
    }
  }
})

// FIXME(F-016, 2026-10-18): parked with the first test of this file; the reason is above it.
test.fixme('cluster i18n: locale off and on reach the other master without reconnecting', async ({
  masters: [first, second],
}) => {
  try {
    await switchLanguageOff('pt')
    await switchLocaleOn('pt')
    for (const page of [first, second]) {
      await gotoPage(page, '/hilos/i18n/languages/pt/locales', PAGE_READY)
      await expect(
        i18nLocaleRow(page, 'pt').getByTestId('i18n-locales-enabled-pt'),
      ).toBeChecked()
    }
    const live = await watchI18nLivePages(expect, [first, second])
    await clickSubmit(shownByTestId(first, 'i18n-locales-view-pt'))
    await clickSubmit(first.getByTestId('i18n-locale-switch-off'))
    await clickSubmit(first.getByTestId('i18n-locale-switch-off-confirm'))
    await expect(first.getByTestId('i18n-locale-window')).toHaveCount(0)
    for (const page of [first, second]) {
      await expect(
        shownByTestId(page, 'i18n-locales-enabled-pt'),
      ).not.toBeChecked()
      await expect(shownByTestId(page, 'i18n-locales-edit-pt')).toBeVisible()
    }
    await live.unchanged()

    await clickSubmit(shownByTestId(second, 'i18n-locales-edit-pt'))
    await clickSubmit(second.getByTestId('i18n-locale-switch-on'))
    await clickSubmit(second.getByTestId('i18n-locale-switch-on-confirm'))
    await expect(second.getByTestId('i18n-locale-window')).toHaveCount(0)
    for (const page of [first, second])
      await expect(shownByTestId(page, 'i18n-locales-enabled-pt')).toBeChecked()
    await live.unchanged()
  } finally {
    try {
      // The owner command switches an enabled fixture off before deleting it.
      await deleteLocale('pt')
    } finally {
      await deleteLanguage('pt')
    }
  }
})

// FIXME(F-016, 2026-10-18): parked with the first test of this file; the reason is above it.
test.fixme('cluster i18n: language base and correction are created and edited across masters', async ({
  masters: [first, second],
}) => {
  await clearLanguageNamesFixture()
  try {
    await seedLanguageNamesFixture()
    const keys: (string | null)[] = []
    for (const page of [first, second]) {
      await gotoPage(page, '/hilos/i18n/languages/qw/names', PAGE_READY)
      await expect(shownByTestId(page, 'i18n-names-none-qv')).toHaveText(
        'No name',
      )
      keys.push(
        await i18nNameRow(page, 'language', 'qv').getAttribute('data-id'),
      )
    }
    expect(keys[0]).not.toBeNull()
    expect(keys[1]).toBe(keys[0])
    const live = await watchI18nLivePages(expect, [first, second])
    for (const [writer, control, key, value] of [
      [first, 'add-qv', 'qv', 'Base from first master'],
      [first, 'locale-add-qv-GB', 'qv-GB', 'Correction from first master'],
      [second, 'edit-qv', 'qv', 'Base from second master'],
      [second, 'locale-edit-qv-GB', 'qv-GB', 'Correction from second master'],
    ] as const) {
      await dismissToasts(writer)
      await clickSubmit(shownByTestId(writer, `i18n-language-name-${control}`))
      await typeInto(writer.getByTestId('i18n-language-name-input'), value)
      await clickSubmit(writer.getByTestId('i18n-language-name-submit'))
      await expect(writer.getByTestId('i18n-language-name-window')).toHaveCount(
        0,
      )
      for (const page of [first, second]) {
        await expectI18nName(expect, page, 'language', key, value)
        await expect(i18nNameRow(page, 'language', 'qv')).toHaveAttribute(
          'data-id',
          keys[0]!,
        )
        if (control === 'add-qv') {
          await clickSubmit(
            i18nNameRow(page, 'language', 'qv').getByTestId(
              /^hilos-table-expand-/,
            ),
          )
          await expect(
            shownByTestId(page, 'i18n-names-inherit-qv-GB'),
          ).toBeVisible()
        }
      }
      await live.unchanged()
    }
  } finally {
    await clearLanguageNamesFixture()
  }
})

// FIXME(F-016, 2026-10-18): parked with the first test of this file; the reason is above it.
test.fixme('cluster i18n: country base and correction edits reach both masters', async ({
  masters: [first, second],
}) => {
  await clearCountryNamesFixture()
  try {
    await seedCountryNamesFixture()
    const keys: (string | null)[] = []
    for (const page of [first, second]) {
      await gotoPage(page, '/hilos/i18n/countries/qj/names', PAGE_READY)
      await expectI18nName(expect, page, 'country', 'qk', 'Fixture base')
      keys.push(
        await i18nNameRow(page, 'country', 'qk').getAttribute('data-id'),
      )
      await clickSubmit(
        i18nNameRow(page, 'country', 'qk').getByTestId(/^hilos-table-expand-/),
      )
      await expect(
        shownByTestId(page, 'i18n-country-name-locale-edit-qk-GB'),
      ).toBeVisible()
    }
    expect(keys[0]).not.toBeNull()
    expect(keys[1]).toBe(keys[0])
    const live = await watchI18nLivePages(expect, [first, second])
    for (const [writer, control, key, value] of [
      [first, 'edit-qk', 'qk', 'Country base from first master'],
      [
        first,
        'locale-edit-qk-GB',
        'qk-GB',
        'Country correction from first master',
      ],
      [second, 'edit-qk', 'qk', 'Country base from second master'],
      [
        second,
        'locale-edit-qk-GB',
        'qk-GB',
        'Country correction from second master',
      ],
    ] as const) {
      await dismissToasts(writer)
      await clickSubmit(shownByTestId(writer, `i18n-country-name-${control}`))
      await typeInto(writer.getByTestId('i18n-country-name-input'), value)
      await clickSubmit(writer.getByTestId('i18n-country-name-submit'))
      await expect(writer.getByTestId('i18n-country-name-window')).toHaveCount(
        0,
      )
      for (const page of [first, second]) {
        await expectI18nName(expect, page, 'country', key, value)
        await expect(i18nNameRow(page, 'country', 'qk')).toHaveAttribute(
          'data-id',
          keys[0]!,
        )
      }
      await live.unchanged()
    }
  } finally {
    await clearCountryNamesFixture()
  }
})
