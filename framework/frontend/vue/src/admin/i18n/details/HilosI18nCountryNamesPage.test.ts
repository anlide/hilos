import { mount } from '@vue/test-utils'
import {
  COUNTRY_CARD_DATA,
  createSignal,
  HILOS_I18N_COUNTRY_NAMES_FILTER,
  HILOS_I18N_COUNTRY_NAMES_TABLE,
  HilosPages,
  ScopeManager,
  type HilosConnection,
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import HilosI18nCountryNamesPage from './HilosI18nCountryNamesPage.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'

const initialCard: HilosI18nCountryCard = {
  code: 'us',
  currencySymbol: '$',
  currencyCode: 'USD',
  enabled: false,
  summary: {
    name: 'United States',
    isOwn: false,
    defaultLocaleCode: null,
    canDelete: false,
    deleteReason: 'known',
  },
}

function makeRouter(pageLoading = createSignal(false)) {
  const currentRoute = createSignal<PageRouteMatch>({
    page: HilosPages.I18N_COUNTRY_NAMES,
    params: { countryCode: 'us' },
    admin: true,
  })
  const navigation: HilosRouter = {
    currentRoute,
    currentPath: createSignal('/hilos/i18n/countries/us/names'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading,
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: (page, params) => {
      const base = `/hilos/i18n/countries/${params?.countryCode}`
      if (page === HilosPages.I18N_COUNTRY) return base
      if (page === HilosPages.I18N_COUNTRY_NAMES) return `${base}/names`
      return undefined
    },
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  return { navigation, pageLoading, currentRoute }
}

function seededContext(initialRows: Array<Record<string, unknown>> = []): {
  context: HilosI18nCountryContext
  scopes: ScopeManager
  receivedFilters: Array<Record<string, unknown> | undefined>
} {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.I18N_COUNTRY_NAMES)
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const receivedFilters: Array<Record<string, unknown> | undefined> = []

  const serveWindow = (filter?: Record<string, unknown>): void => {
    if (filter !== undefined) {
      receivedFilters.push(filter)
    }
    const data = {
      page: HilosPages.I18N_COUNTRY_NAMES,
      tableKey: HILOS_I18N_COUNTRY_NAMES_TABLE,
      rows: initialRows.map((row) => ({
        rowKey: (row.code as string) ?? 'en',
        slots: { language: row },
      })),
      totalCount: initialRows.length,
      totalExact: true,
      firstAnchor: null,
      lastAnchor: null,
      offset: 0,
      limit: 10,
    }
    for (const listener of windowListeners) {
      listener({ data })
    }
  }

  const connection = {
    on(
      event: string,
      listener: (signal: { data: unknown }) => void,
    ): () => void {
      if (event === 'tableWindow') {
        windowListeners.add(listener)
        return () => windowListeners.delete(listener)
      }
      return () => {}
    },
    registerTableWindow(): void {
      serveWindow()
    },
    unregisterTableWindow(): void {},
    sendTableViewport(
      _page: string,
      _tableKey: string,
      descriptor: { filter?: Record<string, unknown> },
    ): boolean {
      serveWindow(descriptor.filter)
      return true
    },
    sendTableRendered(): boolean {
      return true
    },
    sendTableRowFocus(): boolean {
      return true
    },
  } as unknown as HilosConnection

  return {
    context: { connection, scopes },
    scopes,
    receivedFilters,
  }
}

function harness(
  options: {
    pageLoading?: boolean
    initialCardValue?: HilosI18nCountryCard | null
    rows?: Array<Record<string, unknown>>
  } = {},
) {
  const pageLoadingSignal = createSignal(options.pageLoading ?? false)
  const { navigation, currentRoute } = makeRouter(pageLoadingSignal)
  const { context, scopes, receivedFilters } = seededContext(options.rows ?? [])
  const scope = scopes.openPage(HilosPages.I18N_COUNTRY_NAMES)
  if (
    options.initialCardValue !== undefined &&
    options.initialCardValue !== null
  ) {
    scope.data.set(COUNTRY_CARD_DATA, options.initialCardValue)
  }
  const view = mount(HilosI18nCountryNamesPage, {
    props: { context },
    global: {
      provide: { [hilosRouterKey as symbol]: navigation },
      stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
    },
  })
  return {
    view,
    scope,
    pageLoadingSignal,
    receivedFilters,
    navigation,
    currentRoute,
  }
}

describe('HilosI18nCountryNamesPage', () => {
  it('shows a loading skeleton while the card is loading, with no header or table', async () => {
    const h = harness({ pageLoading: true })
    expect(h.view.find('[aria-label="Loading country"]').exists()).toBe(true)
    expect(h.view.find('[data-id="country-card-header"]').exists()).toBe(false)
    expect(h.view.find('[data-id="country-names"]').exists()).toBe(false)
  })

  it('renders the header, tabs, and the names table filtered by country=us', async () => {
    const h = harness({
      initialCardValue: initialCard,
      rows: [
        {
          code: 'en',
          nativeName: 'English',
          name: 'United States',
          corrections: [],
        },
      ],
    })
    await nextTick()

    expect(h.view.find('[data-id="country-card-title"]').text()).toBe(
      'United States',
    )
    expect(h.view.find('[data-id="country-card-code-badge"]').text()).toBe('us')

    const namesTab = h.view.find(
      '[data-id="country-card-tab-hilos_i18n_country_names"]',
    )
    expect(namesTab.attributes('aria-current')).toBe('page')
    expect(namesTab.attributes('href')).toBe('/hilos/i18n/countries/us/names')

    const mainTab = h.view.find(
      '[data-id="country-card-tab-hilos_i18n_country"]',
    )
    expect(mainTab.attributes('href')).toBe('/hilos/i18n/countries/us')
    expect(mainTab.attributes('aria-current')).toBeUndefined()

    const section = h.view.find('[data-id="country-names"]')
    expect(section.exists()).toBe(true)
    expect(section.find('table').exists()).toBe(true)
    expect(section.findAll('button')).toHaveLength(0)
    expect(section.findAll('input')).toHaveLength(0)
  })

  it('asks for the window of another country when the address moves to it', async () => {
    const h = harness({ initialCardValue: initialCard })
    await nextTick()

    h.currentRoute.set({
      page: HilosPages.I18N_COUNTRY_NAMES,
      params: { countryCode: 'gb' },
      admin: true,
    })
    await nextTick()

    expect(h.receivedFilters.at(-1)).toEqual({
      [HILOS_I18N_COUNTRY_NAMES_FILTER]: 'gb',
    })
  })

  it('updates the header title on live card changes', async () => {
    const h = harness({ initialCardValue: initialCard })
    await nextTick()
    expect(h.view.find('[data-id="country-card-title"]').text()).toBe(
      'United States',
    )

    h.scope.data.set(COUNTRY_CARD_DATA, {
      ...initialCard,
      summary: { ...initialCard.summary, name: 'USA' },
    })
    await nextTick()
    expect(h.view.find('[data-id="country-card-title"]').text()).toBe('USA')
  })

  it('shows unavailable alert with neither header nor table when card is cleared after deletion', async () => {
    const h = harness({ initialCardValue: initialCard })
    await nextTick()
    expect(h.view.find('[data-id="country-card-header"]').exists()).toBe(true)

    h.scope.data.set(COUNTRY_CARD_DATA, null)
    await nextTick()

    const alert = h.view.find('[data-id="country-names-unavailable"]')
    expect(alert.exists()).toBe(true)
    expect(alert.text()).toBe('Country details are no longer available.')
    expect(h.view.find('[data-id="country-card-header"]').exists()).toBe(false)
    expect(h.view.find('[data-id="country-names"]').exists()).toBe(false)
  })
})
