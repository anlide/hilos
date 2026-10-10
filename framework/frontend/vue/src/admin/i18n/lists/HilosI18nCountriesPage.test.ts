import { mount } from '@vue/test-utils'
import {
  BUILT_IN_CATALOG_DATA,
  createSignal,
  HILOS_I18N_COUNTRIES_TABLE,
  HilosPages,
  ScopeManager,
  type HilosConnection,
  type HilosI18nCountryContext,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import HilosI18nCountriesPage from './HilosI18nCountriesPage.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'

interface CountrySlot {
  code: string
  name: string | null
  currencySymbol: string
  currencyCode: string
  defaultLocaleCode: string | null
  enabled: boolean
  isOwn: boolean
}

const UNITED_STATES: CountrySlot = {
  code: 'us',
  name: 'United States',
  currencySymbol: '$',
  currencyCode: 'USD',
  defaultLocaleCode: null,
  enabled: false,
  isOwn: false,
}

const OWN: CountrySlot = {
  code: 'qx',
  name: null,
  currencySymbol: '¤',
  currencyCode: 'XQX',
  defaultLocaleCode: 'en-QX',
  enabled: true,
  isOwn: true,
}

type Listener = (signal: { data: unknown }) => void

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.I18N_COUNTRIES,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/i18n/countries'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: (page, params) =>
      page === HilosPages.I18N_COUNTRY && params?.countryCode === 'us'
        ? '/hilos/i18n/countries/us'
        : undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

function context(
  rows: CountrySlot[] = [UNITED_STATES, OWN],
): HilosI18nCountryContext & {
  scope: ReturnType<ScopeManager['openPage']>
} {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.I18N_COUNTRIES)
  const windowListeners = new Set<Listener>()
  const tableRows = rows.map((country) => ({
    rowKey: country.code,
    slots: { country },
  }))
  const serveWindow = (): void => {
    const data = {
      page: HilosPages.I18N_COUNTRIES,
      tableKey: HILOS_I18N_COUNTRIES_TABLE,
      rows: tableRows,
      totalCount: tableRows.length,
      totalExact: true,
      firstAnchor: null,
      lastAnchor: null,
      offset: 0,
      limit: 25,
    }
    for (const listener of windowListeners) {
      listener({ data })
    }
  }
  const connection = {
    registerTableWindow(tableKey: string): void {
      if (tableKey === HILOS_I18N_COUNTRIES_TABLE) {
        serveWindow()
      }
    },
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(): boolean {
      return true
    },
    sendTableRendered(): boolean {
      return true
    },
    on(event: string, listener: Listener): () => void {
      if (event === 'tableWindow') {
        windowListeners.add(listener)

        return () => windowListeners.delete(listener)
      }

      return () => {}
    },
  } as unknown as HilosConnection

  return {
    connection,
    scopes,
    scope,
  }
}

describe('HilosI18nCountriesPage', () => {
  it('links a built card, shows the code for an empty name, and draws the marks', async () => {
    const view = mount(HilosI18nCountriesPage, {
      props: { context: context() },
      global: {
        provide: { [hilosRouterKey as symbol]: router() },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()

    const known = view.get('[data-id="i18n-countries-link-us"]')
    expect(known.text()).toBe('us')
    expect(known.attributes('href') ?? known.attributes('to')).toContain(
      '/hilos/i18n/countries/us',
    )
    expect(known.classes()).toContain('link-secondary')
    expect(known.classes()).toContain('text-uppercase')
    expect(view.text()).toContain('United States')
    expect(view.find('[data-id="i18n-countries-own-us"]').exists()).toBe(false)
    expect(
      view.get('[data-id="i18n-countries-locale-none-us"]').text(),
    ).toContain('Not chosen')
    expect(view.text()).toContain('$')
    expect(view.text()).toContain('USD')
    expect(
      (
        view.get('[data-id="i18n-countries-enabled-us"]')
          .element as HTMLInputElement
      ).checked,
    ).toBe(false)
    expect(
      (
        view.get('[data-id="i18n-countries-enabled-us"]')
          .element as HTMLInputElement
      ).disabled,
    ).toBe(true)
    expect(view.html()).toContain('text-body-secondary')

    const own = view.get('[data-id="i18n-countries-link-qx"]')
    expect(own.element.tagName).toBe('SPAN')
    expect(own.classes()).not.toContain('link-secondary')
    expect(view.text()).toContain('QX')
    expect(view.get('[data-id="i18n-countries-own-qx"]').text()).toContain(
      'Custom country',
    )
    expect(
      view.get('[data-id="i18n-countries-own-qx"]').attributes('title'),
    ).toBe('Custom country')
    expect(
      view.find('[data-id="i18n-countries-locale-none-qx"]').exists(),
    ).toBe(false)
    expect(view.text()).toContain('en-QX')
    expect(
      (
        view.get('[data-id="i18n-countries-enabled-qx"]')
          .element as HTMLInputElement
      ).checked,
    ).toBe(true)
    expect(view.find('[data-id^="i18n-countries-"] button').exists()).toBe(
      false,
    )
    expect(view.text()).not.toContain('Add country')
  })

  it('renders the catalog callout with counts and omits it when data is absent', async () => {
    const c1 = context()
    c1.scope.data.set(BUILT_IN_CATALOG_DATA, {
      languageCount: 3,
      countryCount: 4,
    })
    const viewWithData = mount(HilosI18nCountriesPage, {
      props: { context: c1 },
      global: {
        provide: { [hilosRouterKey as symbol]: router() },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()

    const catalog = viewWithData.get('[data-id="i18n-countries-catalog"]')
    expect(catalog.get('h2').text()).toBe(
      'The system knows 3 languages and 4 countries on its own',
    )
    expect(catalog.get('p').text()).toBe(
      "The framework's built-in catalog holds 4 countries with their currency and their names in its 3 languages; each of them arrives on its own, switched off. Whenever a framework update changes the catalog, a country's currency is refreshed while that country is switched off, and its names wherever nobody has edited them.",
    )

    const c2 = context()
    const viewWithoutData = mount(HilosI18nCountriesPage, {
      props: { context: c2 },
      global: {
        provide: { [hilosRouterKey as symbol]: router() },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()
    expect(
      viewWithoutData.find('[data-id="i18n-countries-catalog"]').exists(),
    ).toBe(false)
  })

  it('renders the legend row even when the countries table is empty', async () => {
    const c = context([])
    const view = mount(HilosI18nCountriesPage, {
      props: { context: c },
      global: {
        provide: { [hilosRouterKey as symbol]: router() },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()

    const legend = view.get('[data-id="i18n-countries-legend"]')
    expect(legend.get('h2').text()).toContain('What the marks mean')

    const ownRow = view.get('[data-id="i18n-countries-legend-own"]')
    expect(ownRow.get('dt').text()).toContain('Custom country')
    expect(ownRow.get('dd').text()).toBe(
      'its code is not in the built-in catalog: nothing refreshes it, keeping it up is yours',
    )
  })
})
