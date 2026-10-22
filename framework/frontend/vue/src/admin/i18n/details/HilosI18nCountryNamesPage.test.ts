import { mount, type VueWrapper } from '@vue/test-utils'
import {
  ActionError,
  createSignal,
  bindAdminAccess,
  createAppPageRouter,
  createHilosRouter,
  HilosPages,
  pageIdentity,
  PageSubscription,
  ScopeManager,
  type ActionResult,
  type ActionLifecycle,
  type HilosConnection,
  type HilosRouter,
  type PageRouteMatch,
  type PageSubscriptionError,
  type Scope,
} from '@hilos/core/base'
import {
  COUNTRY_CARD_DATA,
  HILOS_I18N_COUNTRY_NAMES_FILTER,
  HILOS_I18N_COUNTRY_NAMES_TABLE,
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
} from '@hilos/core/admin/i18n'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, markRaw, nextTick } from 'vue'

import HilosI18nCountryNamesPage from './HilosI18nCountryNamesPage.vue'
import HilosI18nCountryNameDelete from './HilosI18nCountryNameDelete.vue'
import HilosI18nCountryNameWindow from './HilosI18nCountryNameWindow.vue'
import HilosI18nNamesTable from './HilosI18nNamesTable.vue'
import ErrorPage from '../../../ErrorPage.vue'
import HilosView from '../../../HilosView.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'

const initialCard: HilosI18nCountryCard = {
  countryId: 102,
  code: 'us',
  currencySymbol: '$',
  currencyCode: 'USD',
  enabled: false,
  summary: {
    name: 'United States',
    isOwn: false,
    defaultLocaleId: null,
    defaultLocaleCode: null,
    defaultLocale: 'missing',
    localeCount: 0,
    nameCount: 0,
    locales: [],
    catalogDefaultLocaleCode: 'en-US',
    canDelete: false,
    deleteReason: 'known',
  },
}

/** The country owner as the catalog answers it: no icon, its own lead. */
const COUNTRY_OWNER = {
  page: HilosPages.I18N_COUNTRY,
  label: 'Country',
  lead: 'A single country: currency, default locale, and state.',
  icon: null,
}

/**
 * Answer the way the Names tab does: its own label, the country as the owner,
 * the chain through Countries ending at the country, not at Names, the
 * Overview · Names row and no child cards.
 *
 * @param scope The page scope the answer lands in.
 */
function answerAsNames(scope: Scope): void {
  scope.data.set('pageLabel', 'Country names')
  scope.data.set('pageHeadOwner', COUNTRY_OWNER)
  scope.data.set('pageBreadcrumb', [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.I18N, label: 'Internationalization' },
    { page: HilosPages.I18N_COUNTRIES, label: 'Countries' },
    { page: HilosPages.I18N_COUNTRY, label: 'Country' },
  ])
  scope.data.set('pageTabs', [
    { page: HilosPages.I18N_COUNTRY, label: 'Overview' },
    { page: HilosPages.I18N_COUNTRY_NAMES, label: 'Names' },
  ])
  scope.data.set('pageChildren', [])
}

/** The data-ids the manual country header carried; none of them stays. */
const RETIRED_HEADER = [
  'country-card-header',
  'country-card-title',
  'country-card-tabs',
  'country-card-code-badge',
  'country-card-own',
  'country-card-line',
]

/**
 * Whether anything of the manual country header is left on the page, its tab
 * links included.
 *
 * @param view The mounted page.
 */
function retiredHeaderLeft(view: VueWrapper): boolean {
  return (
    RETIRED_HEADER.some((id) => view.find(`[data-id="${id}"]`).exists()) ||
    view.find('[data-id^="country-card-tab-"]').exists()
  )
}

function makeRouter(
  scopes = new ScopeManager(),
  pageLoading = createSignal(false),
) {
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
    pageIdentity: pageIdentity(scopes),
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
        rowKey: String(row.inLanguageId),
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
    sendTableRowFocus: vi.fn(() => true),
  } as unknown as HilosConnection

  return {
    context: { connection, scopes, actions: {} as ActionLifecycle },
    scopes,
    receivedFilters,
  }
}

function harness(
  options: {
    pageLoading?: boolean
    initialCardValue?: HilosI18nCountryCard | null
    rows?: Array<Record<string, unknown>>
    realShell?: boolean
    viewer?: boolean
  } = {},
) {
  const pageLoadingSignal = createSignal(options.pageLoading ?? false)
  const { context, scopes, receivedFilters } = seededContext(options.rows ?? [])
  const { navigation, currentRoute } = makeRouter(scopes, pageLoadingSignal)
  const scope = scopes.openPage(HilosPages.I18N_COUNTRY_NAMES)
  if (options.realShell) {
    scopes.session.data.set('adminViewMode', options.viewer ?? false)
    releases.push(bindAdminAccess(scopes))
    answerAsNames(scope)
  }
  if (
    options.initialCardValue !== undefined &&
    options.initialCardValue !== null
  ) {
    scope.data.set(COUNTRY_CARD_DATA, options.initialCardValue)
  }
  const view = mount(HilosI18nCountryNamesPage, {
    props: { context: markRaw(context) },
    global: {
      provide: { [hilosRouterKey as symbol]: navigation },
      stubs: options.realShell
        ? {}
        : {
            HilosAdminPage: {
              template: '<main><slot /><slot name="modals" /></main>',
            },
          },
    },
  })
  mounted.push(view)
  return {
    view,
    scope,
    pageLoadingSignal,
    receivedFilters,
    navigation,
    currentRoute,
    scopes,
  }
}

describe('HilosI18nCountryNamesPage', () => {
  it('shows a loading skeleton while the card is loading, with no country block or table', async () => {
    const h = harness({ pageLoading: true, realShell: true })
    expect(h.view.find('[aria-label="Loading country"]').exists()).toBe(true)
    expect(h.view.find('[data-id="country-head-tile"]').exists()).toBe(false)
    expect(h.view.find('[data-id="country-head-code"]').exists()).toBe(false)
    expect(h.view.find('[data-id="country-head-description"]').exists()).toBe(
      false,
    )
    expect(h.view.find('[data-id="country-names"]').exists()).toBe(false)
  })

  it('renders the country block, the Overview · Names row, and the names table filtered by country=us', async () => {
    const h = harness({
      realShell: true,
      initialCardValue: initialCard,
      rows: [
        {
          inLanguageId: 11,
          nameId: 301,
          rowKey: '11',
          code: 'en',
          nativeName: 'English',
          name: 'United States',
          corrections: [],
        },
      ],
    })
    await nextTick()

    const headings = h.view.findAll('h1')
    expect(headings).toHaveLength(1)
    expect(headings[0].attributes('data-id')).toBe('hilos-page-head-title')
    expect(headings[0].text()).toBe('United States')
    expect(headings[0].text()).toBe(
      h.navigation.pageIdentity.get()?.pageHeadOwner?.label,
    )
    const block = h.view.get('[data-id="hilos-page-head-block"]')
    const tile = block.get('[data-id="country-head-tile"]')
    expect(tile.text()).toBe('us')
    expect(tile.classes()).toContain('text-uppercase')
    expect(tile.attributes('aria-hidden')).toBe('true')
    expect(block.get('[data-id="country-head-code"]').text()).toBe('us')
    expect(block.get('[data-id="country-head-description"]').text()).toBe(
      'Currency $ USD · Default locale not chosen',
    )
    expect(block.find('[data-id="country-head-own"]').exists()).toBe(false)
    expect(retiredHeaderLeft(h.view)).toBe(false)
    expect(h.view.find('[data-id="hilos-admin-children"]').exists()).toBe(false)

    const tabs = h.view.get('[data-id="hilos-page-tabs"]')
    expect(tabs.attributes('aria-label')).toBe('Country pages')
    expect(tabs.findAll('a').map((link) => link.text())).toEqual([
      'Overview',
      'Names',
    ])
    const namesTab = h.view.get(
      '[data-id="hilos-page-tab-hilos_i18n_country_names"]',
    )
    expect(namesTab.attributes('aria-current')).toBe('page')
    expect(namesTab.attributes('href')).toBe('/hilos/i18n/countries/us/names')

    const overviewTab = h.view.get(
      '[data-id="hilos-page-tab-hilos_i18n_country"]',
    )
    expect(overviewTab.attributes('href')).toBe('/hilos/i18n/countries/us')
    expect(overviewTab.attributes('aria-current')).toBeUndefined()
    // The chain ends at the country, not at Names.
    expect(
      h.view.get('[data-id="hilos-breadcrumb"] li:last-child').text(),
    ).toBe('United States')

    const section = h.view.find('[data-id="country-names"]')
    expect(section.exists()).toBe(true)
    expect(section.find('table').exists()).toBe(true)
    expect(section.find('tr [data-id="i18n-names-delete-en"]').exists()).toBe(
      true,
    )
    expect(section.findAll('input, select, textarea, form')).toHaveLength(0)
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

    h.scope.data.set(COUNTRY_CARD_DATA, {
      ...initialCard,
      countryId: 101,
      code: 'gb',
    })
    await nextTick()
    expect(h.receivedFilters.at(-1)).toEqual({
      [HILOS_I18N_COUNTRY_NAMES_FILTER]: 101,
    })
  })

  it('updates the country block on live card changes', async () => {
    const h = harness({ realShell: true, initialCardValue: initialCard })
    await nextTick()
    expect(h.view.get('[data-id="hilos-page-head-title"]').text()).toBe(
      'United States',
    )

    h.scope.data.set(COUNTRY_CARD_DATA, {
      ...initialCard,
      currencySymbol: '€',
      currencyCode: 'EUR',
      summary: {
        ...initialCard.summary,
        name: 'USA',
        isOwn: true,
        defaultLocaleCode: 'en-US',
      },
    })
    await nextTick()
    expect(h.view.get('[data-id="hilos-page-head-title"]').text()).toBe('USA')
    expect(h.view.get('[data-id="country-head-description"]').text()).toBe(
      'Currency € EUR · Default locale en-US',
    )
    const own = h.view.get(
      '[data-id="hilos-page-head-block"] [data-id="country-head-own"]',
    )
    expect(own.classes()).toEqual(
      expect.arrayContaining([
        'badge',
        'text-bg-warning-subtle',
        'text-warning-emphasis',
      ]),
    )
    expect(own.get('i').classes()).toContain('bi-question-circle')
    expect(own.get('i').attributes('aria-hidden')).toBe('true')
    expect(own.get('.visually-hidden').text()).toBe(
      'Custom country. This country is absent from the catalog and source.',
    )
    expect(own.attributes('title')).toBe(
      'This country is absent from the catalog and source.',
    )
  })

  it.each([
    ['Visible country', 'Visible country', false],
    [null, 'Unnamed country', true],
    ['', 'Unnamed country', true],
  ] as const)(
    'binds %j as %j before the Names shell renders (viewer %s)',
    async (name, expected, viewer) => {
      const h = harness({
        realShell: true,
        viewer,
        initialCardValue: {
          ...initialCard,
          summary: { ...initialCard.summary, name },
        },
      })
      const crumb = () =>
        h.view.get('[data-id="hilos-breadcrumb"] li:last-child').text()
      expect(crumb()).toBe(expected)
      expect(h.view.findAll('h1')).toHaveLength(1)
      expect(h.view.get('[data-id="hilos-page-head-title"]').text()).toBe(
        expected,
      )
      expect(h.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe(
        expected,
      )
      expect(h.navigation.pageIdentity.get()?.label).toBe('Country names')
      expect(h.view.get('[data-id="country-head-code"]').text()).toBe('us')
      expect(
        h.view
          .get('[data-id="hilos-page-tab-hilos_i18n_country"]')
          .attributes('href'),
      ).toBe('/hilos/i18n/countries/us')
      for (const liveName of ['Renamed', null, '']) {
        const display =
          liveName === null || liveName === '' ? 'Unnamed country' : liveName
        h.scope.data.set(COUNTRY_CARD_DATA, {
          ...initialCard,
          summary: { ...initialCard.summary, name: liveName },
        })
        await nextTick()
        expect(crumb()).toBe(display)
        expect(h.view.get('[data-id="hilos-page-head-title"]').text()).toBe(
          display,
        )
        expect(h.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe(
          display,
        )
      }
    },
  )

  it('has no placeholder name without a card and follows a new country until unmount', async () => {
    const h = harness({ realShell: true, pageLoading: true })
    const identity = h.navigation.pageIdentity
    expect(identity.get()?.pageHeadOwner?.label).toBe('Country')
    expect(h.view.find('[data-id="country-head-tile"]').exists()).toBe(false)
    h.scope.data.set(COUNTRY_CARD_DATA, initialCard)
    expect(identity.get()?.pageHeadOwner?.label).toBe('United States')
    h.scopes.dropPage()
    expect(identity.get()).toBeUndefined()
    h.currentRoute.set({
      page: HilosPages.I18N_COUNTRY_NAMES,
      params: { countryCode: 'ie' },
      admin: true,
    })
    const next = h.scopes.openPage(HilosPages.I18N_COUNTRY_NAMES)
    answerAsNames(next)
    expect(identity.get()?.pageHeadOwner?.label).toBe('Country')
    next.data.set(COUNTRY_CARD_DATA, {
      ...initialCard,
      countryId: 140,
      code: 'ie',
      summary: { ...initialCard.summary, name: 'Ireland' },
    })
    await nextTick()
    expect(h.view.get('[data-id="hilos-page-head-title"]').text()).toBe(
      'Ireland',
    )
    expect(h.view.get('[data-id="country-head-code"]').text()).toBe('ie')
    expect(
      h.view
        .get('[data-id="hilos-page-tab-hilos_i18n_country"]')
        .attributes('href'),
    ).toBe('/hilos/i18n/countries/ie')
    expect(identity.get()?.pageHeadOwner?.label).toBe('Ireland')
    h.scope.data.set(COUNTRY_CARD_DATA, initialCard)
    expect(identity.get()?.pageHeadOwner?.label).toBe('Ireland')
    const unrelated = h.scopes.openPage(HilosPages.SETTINGS)
    unrelated.data.set('pageLabel', 'Settings')
    unrelated.data.set('pageBreadcrumb', [
      { page: HilosPages.I18N_COUNTRY, label: 'Country' },
    ])
    unrelated.data.set(COUNTRY_CARD_DATA, initialCard)
    expect(identity.get()?.breadcrumb[0].label).toBe('Country')
    const current = h.scopes.openPage(HilosPages.I18N_COUNTRY_NAMES)
    answerAsNames(current)
    current.data.set(COUNTRY_CARD_DATA, initialCard)
    expect(identity.get()?.pageHeadOwner?.label).toBe('United States')
    h.view.unmount()
    expect(identity.get()?.pageHeadOwner?.label).toBe('Country')
    current.data.set(COUNTRY_CARD_DATA, {
      ...initialCard,
      summary: { ...initialCard.summary, name: 'After unmount' },
    })
    expect(identity.get()?.pageHeadOwner?.label).toBe('Country')
    mounted.splice(mounted.indexOf(h.view), 1)
  })

  it('shows unavailable alert with neither country block nor table when card is cleared after deletion', async () => {
    const h = harness({ realShell: true, initialCardValue: initialCard })
    await nextTick()
    expect(h.view.find('[data-id="country-head-tile"]').exists()).toBe(true)

    h.scope.data.set(COUNTRY_CARD_DATA, null)
    await nextTick()

    const alert = h.view.find('[data-id="country-names-unavailable"]')
    expect(alert.exists()).toBe(true)
    expect(alert.text()).toBe('Country details are no longer available.')
    expect(h.view.find('[data-id="country-head-tile"]').exists()).toBe(false)
    expect(h.view.find('[data-id="country-head-code"]').exists()).toBe(false)
    expect(h.view.find('[data-id="country-head-description"]').exists()).toBe(
      false,
    )
    expect(h.view.get('[data-id="hilos-page-head-title"]').text()).toBe(
      'Country',
    )
    expect(h.view.find('[data-id="country-names"]').exists()).toBe(false)
  })
})

const mounted: VueWrapper[] = []
const releases: Array<() => void> = []
afterEach(() => {
  for (const view of mounted.splice(0)) view.unmount()
  for (const release of releases.splice(0)) release()
})

function deletionPage(viewer = false) {
  const scopes = new ScopeManager()
  scopes.session.data.set('adminViewMode', viewer)
  releases.push(bindAdminAccess(scopes))
  const scope = scopes.openPage(HilosPages.I18N_COUNTRY_NAMES)
  scope.data.set(COUNTRY_CARD_DATA, initialCard)
  const context = { ...seededContext().context, scopes }
  const route = createSignal<PageRouteMatch>({
    page: HilosPages.I18N_COUNTRY_NAMES,
    params: { countryCode: 'us' },
    admin: true,
  })
  const router = { ...makeRouter(scopes).navigation, currentRoute: route }
  const wrapper = mount(HilosI18nCountryNamesPage, {
    props: { context },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })
  mounted.push(wrapper)
  const controller = wrapper
    .getComponent(HilosI18nNamesTable)
    .props('controller')
  const slot = {
    inLanguageId: 51,
    nameId: 301,
    rowKey: '51',
    code: 'qk',
    nativeName: 'Writing',
    name: 'Original',
    corrections: [
      {
        localeId: 501,
        nameId: 401,
        countryId: 101,
        localeCode: 'qk-GB',
        countryCode: 'gb',
        countryName: 'UK',
        name: 'Correction',
      },
      {
        localeId: 502,
        nameId: 402,
        countryId: 102,
        localeCode: 'qk-US',
        countryCode: 'us',
        countryName: 'US',
        name: '',
      },
      {
        localeId: 503,
        nameId: null,
        countryId: 103,
        localeCode: 'qk-CA',
        countryCode: 'ca',
        countryName: 'Canada',
        name: null,
      },
    ],
  }
  controller.ingestWindow(
    [
      { rowKey: '51', slots: { language: slot } },
      {
        rowKey: '13',
        slots: {
          language: {
            inLanguageId: 13,
            nameId: null,
            rowKey: '13',
            code: 'fr',
            nativeName: 'French',
            name: null,
            corrections: [],
          },
        },
      },
      {
        rowKey: '15',
        slots: {
          language: {
            inLanguageId: 15,
            nameId: 301,
            rowKey: '15',
            code: 'es',
            nativeName: 'Spanish',
            name: '',
            corrections: [],
          },
        },
      },
    ],
    3,
    true,
    null,
    null,
    100,
  )
  const modal = () => wrapper.getComponent(HilosI18nCountryNameDelete)
  return { wrapper, scope, context, route, controller, slot, modal }
}

async function settlePage(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}

function holdWrite(context: HilosI18nCountryContext) {
  let settle: (result: ActionResult<never>) => void = () => {}
  let refuse: (reason: ActionError) => void = () => {}
  const done = new Promise<ActionResult<never>>((resolve, reject) => {
    settle = resolve
    refuse = reject
  })
  const dispatch = vi.fn(() => ({
    requestId: 'held',
    loading: createSignal(false),
    done,
  }))
  context.actions.dispatch = dispatch
  return { dispatch, settle, refuse }
}

describe('country name deletion from the page', () => {
  it('owns both slots, includes saved empty names, and omits absent values', async () => {
    const s = deletionPage()
    s.controller.expandRow('51', true)
    await settlePage()
    for (const key of ['qk', 'qk-GB', 'qk-US', 'es']) {
      expect(
        s.wrapper.find(`tr [data-id="i18n-names-delete-${key}"]`).exists(),
      ).toBe(true)
      expect(
        s.wrapper
          .find(
            `[data-id="hilos-table-cards"] [data-id="i18n-names-delete-${key}"]`,
          )
          .exists(),
      ).toBe(true)
    }
    for (const key of ['fr', 'qk-CA'])
      expect(
        s.wrapper.find(`[data-id="i18n-names-delete-${key}"]`).exists(),
      ).toBe(false)
    expect(
      s.wrapper
        .get('tr [data-id="i18n-names-delete-qk-US"]')
        .attributes('aria-label'),
    ).toBe('Remove locale correction qk-US')
  })

  it('awaits focus, freezes fresh values, follows source updates and releases on close', async () => {
    const s = deletionPage()
    await settlePage()
    const focus = vi.spyOn(s.controller, 'focusRow')
    const button = s.wrapper.get('tr [data-id="i18n-names-delete-qk"]')
    ;(button.element as HTMLButtonElement).click()
    expect(focus).toHaveBeenCalledWith('51')
    expect(s.modal().props('modelValue')).toBe(false)
    // The fresh focus can change between the click and the awaited continuation.
    s.controller.ingestDelta({
      kind: 'row_updated',
      rowKey: '51',
      row: { rowKey: '51', slots: { language: { ...s.slot, name: 'Fresh' } } },
    })
    await settlePage()
    expect(
      document.querySelectorAll('[data-id="i18n-country-name-delete-window"]'),
    ).toHaveLength(1)
    expect(s.modal().props('target')).toMatchObject({
      inLanguageId: 51,
      nameId: 301,
      countryId: 102,
      countryCode: 'us',
      inLanguageCode: 'qk',
      expectedName: 'Fresh',
      kind: 'base',
    })
    s.controller.ingestDelta({
      kind: 'row_updated',
      rowKey: '51',
      row: { rowKey: '51', slots: { language: { ...s.slot, name: 'Newer' } } },
    })
    await settlePage()
    expect(s.modal().props('target')?.expectedName).toBe('Fresh')
    expect(s.modal().props('source')?.name).toBe('Newer')
    document
      .querySelector<HTMLButtonElement>(
        '[data-id="i18n-country-name-delete-cancel"]',
      )!
      .click()
    await settlePage()
    expect(s.controller.focusedRow.get()).toBeUndefined()
    expect(s.context.connection.sendTableRowFocus).toHaveBeenLastCalledWith(
      HilosPages.I18N_COUNTRY_NAMES,
      'hilosI18nCountryNames',
      '',
    )
    await button.trigger('click')
    await settlePage()
    expect(s.modal().props('target')?.expectedName).toBe('Newer')
    s.wrapper.unmount()
    mounted.splice(mounted.indexOf(s.wrapper), 1)
    expect(s.controller.focusedRow.get()).toBeUndefined()
  })

  it('takes the empty correction from the fresh focused row', async () => {
    const s = deletionPage()
    s.controller.expandRow('51', true)
    await settlePage()
    await s.wrapper
      .get('tr [data-id="i18n-names-delete-qk-US"]')
      .trigger('click')
    await settlePage()
    expect(s.modal().props('target')).toMatchObject({
      kind: 'override',
      localeId: 502,
      nameId: 402,
      localeCode: 'qk-US',
      expectedName: '',
    })
  })

  it.each([false, true])(
    'closes on a route change, including while focus is pending (%s)',
    async (pending) => {
      const s = deletionPage()
      await settlePage()
      ;(
        s.wrapper.get('tr [data-id="i18n-names-delete-qk"]')
          .element as HTMLButtonElement
      ).click()
      if (!pending) {
        await settlePage()
        expect(s.modal().props('modelValue')).toBe(true)
      }
      s.route.set({
        page: HilosPages.I18N_COUNTRY_NAMES,
        params: { countryCode: 'en' },
        admin: true,
      })
      await settlePage()
      expect(s.modal().props('modelValue')).toBe(false)
      expect(s.controller.focusedRow.get()).toBeUndefined()
    },
  )

  it('keeps the explanation when the subject card disappears', async () => {
    const s = deletionPage()
    await settlePage()
    await s.wrapper.get('tr [data-id="i18n-names-delete-qk"]').trigger('click')
    await settlePage()
    s.scope.data.set(COUNTRY_CARD_DATA, [])
    await settlePage()
    expect(s.wrapper.findComponent(HilosI18nNamesTable).exists()).toBe(false)
    expect(s.modal().props('modelValue')).toBe(true)
    expect(
      document.querySelector('[data-id="i18n-country-name-delete-notice"]')
        ?.textContent,
    ).toContain('The name no longer exists.')
    expect(
      document.querySelector<HTMLButtonElement>(
        '[data-id="i18n-country-name-delete-confirm"]',
      )?.disabled,
    ).toBe(true)
  })

  it('disables every trash for the viewer through the admin shell', async () => {
    const s = deletionPage(true)
    s.controller.expandRow('51', true)
    await settlePage()
    for (const button of s.wrapper.findAll('[data-id^="i18n-names-delete-"]')) {
      expect((button.element as HTMLButtonElement).disabled).toBe(true)
      expect(button.attributes('aria-describedby')).toBeTruthy()
    }
    expect(s.modal().props('modelValue')).toBe(false)
  })
})

describe('country name add/edit from the page', () => {
  it('draws plus/pencil by null in both base and correction slots, preserving the trash', async () => {
    const s = deletionPage()
    s.controller.expandRow('51', true)
    await settlePage()
    for (const surface of ['table', '[data-id="hilos-table-cards"]']) {
      for (const suffix of [
        'edit-qk',
        'edit-es',
        'add-fr',
        'locale-edit-qk-GB',
        'locale-edit-qk-US',
        'locale-add-qk-CA',
      ]) {
        expect(
          s.wrapper
            .find(`${surface} [data-id="i18n-country-name-${suffix}"]`)
            .exists(),
        ).toBe(true)
      }
      for (const key of ['qk', 'es', 'qk-GB', 'qk-US']) {
        expect(
          s.wrapper
            .find(`${surface} [data-id="i18n-names-delete-${key}"]`)
            .exists(),
        ).toBe(true)
      }
    }
    expect(
      s.wrapper
        .get('tr [data-id="i18n-country-name-edit-qk"]')
        .attributes('aria-label'),
    ).toBe('Edit name · United States in Writing')
    expect(
      s.wrapper
        .get('tr [data-id="i18n-country-name-locale-add-qk-CA"]')
        .attributes('aria-label'),
    ).toBe('Add locale correction · United States in Writing · qk-CA')
    // One actions column, the inherited HIL-1490 slots.
    expect(
      s.wrapper.findAll('th').filter((cell) => cell.text() === 'Actions'),
    ).toHaveLength(1)
  })

  it('opens corrections under an empty base and takes their parent focus', async () => {
    const s = deletionPage()
    s.controller.ingestDelta({
      kind: 'row_updated',
      rowKey: '51',
      row: { rowKey: '51', slots: { language: { ...s.slot, name: '' } } },
    })
    s.controller.expandRow('51', true)
    await settlePage()
    await s.wrapper
      .get('tr [data-id="i18n-country-name-locale-add-qk-CA"]')
      .trigger('click')
    await settlePage()
    const window = s.wrapper
      .getComponent(HilosI18nCountryNameWindow)
      .props('window')
    expect(window.mode.get()).toBe('add')
    expect(window.target.get()).toMatchObject({
      inLanguageCode: 'qk',
      localeCode: 'qk-CA',
    })
    expect(s.controller.focusedRow.get()?.name).toBe('')
  })

  it('keeps both components mounted while switching their shared focus', async () => {
    const s = deletionPage()
    await settlePage()
    const component = s.wrapper.getComponent(HilosI18nCountryNameWindow)
    const window = component.props('window')
    const deletion = s.modal().vm.$
    await s.wrapper.get('tr [data-id="i18n-names-delete-qk"]').trigger('click')
    await settlePage()
    expect(s.modal().props('modelValue')).toBe(true)
    await s.wrapper
      .get('tr [data-id="i18n-country-name-edit-es"]')
      .trigger('click')
    await settlePage()
    expect(s.modal().props('modelValue')).toBe(false)
    expect(window.opened.get()).toBe(true)
    expect(s.controller.focusedRow.get()?.code).toBe('es')
    expect(s.modal().vm.$).toBe(deletion)
    await s.wrapper.get('tr [data-id="i18n-names-delete-qk"]').trigger('click')
    await settlePage()
    expect(window.opened.get()).toBe(false)
    expect(s.modal().props('modelValue')).toBe(true)
    expect(s.controller.focusedRow.get()?.code).toBe('qk')
    expect(s.wrapper.getComponent(HilosI18nCountryNameWindow).vm.$).toBe(
      component.vm.$,
    )
  })

  it('cancels a pending delete opening without releasing the newly opened edit', async () => {
    const s = deletionPage()
    await settlePage()
    ;(
      s.wrapper.get('tr [data-id="i18n-names-delete-qk"]')
        .element as HTMLButtonElement
    ).click()
    ;(
      s.wrapper.get('tr [data-id="i18n-country-name-edit-es"]')
        .element as HTMLButtonElement
    ).click()
    await settlePage()
    expect(s.modal().props('modelValue')).toBe(false)
    expect(
      s.wrapper
        .getComponent(HilosI18nCountryNameWindow)
        .props('window')
        .opened.get(),
    ).toBe(true)
    expect(s.controller.focusedRow.get()?.code).toBe('es')
  })

  it('keeps a draft when card=null, then releases it on a route change and on unmount', async () => {
    const s = deletionPage()
    await settlePage()
    await s.wrapper
      .get('tr [data-id="i18n-country-name-edit-qk"]')
      .trigger('click')
    const window = s.wrapper
      .getComponent(HilosI18nCountryNameWindow)
      .props('window')
    window.patchForm({ name: 'Copy me' })
    s.scope.data.set(COUNTRY_CARD_DATA, null)
    await settlePage()
    expect(window.opened.get()).toBe(true)
    expect(window.form.get().name).toBe('Copy me')
    expect(window.saveLabel.get()).toBe('Deleted')
    expect(
      document.querySelector<HTMLInputElement>(
        '[data-id="i18n-country-name-input"]',
      )?.disabled,
    ).toBe(false)
    s.route.set({
      page: HilosPages.I18N_COUNTRY_NAMES,
      params: { countryCode: 'qz' },
      admin: true,
    })
    await settlePage()
    expect(window.opened.get()).toBe(false)
    expect(s.controller.focusedRow.get()).toBeUndefined()
    s.scope.data.set(COUNTRY_CARD_DATA, { ...initialCard, code: 'qz' })
    s.controller.ingestWindow(
      [{ rowKey: '51', slots: { language: s.slot } }],
      1,
      true,
      null,
      null,
      100,
    )
    await settlePage()
    await s.wrapper
      .get('tr [data-id="i18n-country-name-edit-qk"]')
      .trigger('click')
    expect(window.target.get()?.countryCode).toBe('qz')
    expect(s.controller.focusedRow.get()?.code).toBe('qk')
    s.wrapper.unmount()
    mounted.splice(mounted.indexOf(s.wrapper), 1)
    expect(window.opened.get()).toBe(false)
    expect(s.controller.focusedRow.get()).toBeUndefined()
  })

  it('lets the viewer open a form through either slot while its submit stays locked', async () => {
    const s = deletionPage(true)
    s.controller.expandRow('51', true)
    await settlePage()
    for (const suffix of [
      'add-fr',
      'edit-es',
      'locale-add-qk-CA',
      'locale-edit-qk-US',
    ]) {
      const button = s.wrapper.get(`tr [data-id="i18n-country-name-${suffix}"]`)
      expect((button.element as HTMLButtonElement).disabled).toBe(false)
      await button.trigger('click')
      await settlePage()
      const window = s.wrapper
        .getComponent(HilosI18nCountryNameWindow)
        .props('window')
      window.patchForm({ name: 'Viewer draft' })
      await settlePage()
      const submit = document.querySelector<HTMLButtonElement>(
        '[data-id="i18n-country-name-submit"]',
      )!
      expect(submit.disabled).toBe(true)
      expect(submit.getAttribute('aria-describedby')).toBeTruthy()
      window.close()
      await settlePage()
    }
  })
})

describe('country name windows sharing focus during tracked writes', () => {
  it.each(['edit', 'delete'] as const)(
    'keeps the busy %s window and its focus when another opening is requested',
    async (kind) => {
      const s = deletionPage()
      const write = holdWrite(s.context)
      await settlePage()
      const window = s.wrapper
        .getComponent(HilosI18nCountryNameWindow)
        .props('window')
      const opener =
        kind === 'edit' ? 'i18n-country-name-edit-qk' : 'i18n-names-delete-qk'
      await s.wrapper.get(`tr [data-id="${opener}"]`).trigger('click')
      await settlePage()
      if (kind === 'edit') window.patchForm({ name: 'My draft' })
      await settlePage()
      document
        .querySelector<HTMLButtonElement>(
          `[data-id="i18n-country-name-${kind === 'edit' ? 'submit' : 'delete-confirm'}"]`,
        )!
        .click()
      expect(write.dispatch).toHaveBeenCalledTimes(1)
      // Try both openings before Vue has had a render turn to disable anything.
      ;(
        s.wrapper.get('tr [data-id="i18n-country-name-edit-es"]')
          .element as HTMLButtonElement
      ).click()
      ;(
        s.wrapper.get('tr [data-id="i18n-names-delete-es"]')
          .element as HTMLButtonElement
      ).click()
      await settlePage()
      expect(window.opened.get()).toBe(kind === 'edit')
      expect(s.modal().props('modelValue')).toBe(kind === 'delete')
      expect(s.controller.focusedRow.get()?.code).toBe('qk')
      write.refuse(new ActionError('write', 'fail', 'Try again.'))
      await settlePage()
      await s.wrapper
        .get('tr [data-id="i18n-country-name-edit-es"]')
        .trigger('click')
      await settlePage()
      expect(s.modal().props('modelValue')).toBe(false)
      expect(window.target.get()?.inLanguageCode).toBe('es')
      expect(s.controller.focusedRow.get()?.code).toBe('es')
    },
  )

  it.each(['success', 'refusal'] as const)(
    'ignores a previous country delete %s after the new country opens an edit',
    async (outcome) => {
      const s = deletionPage()
      const write = holdWrite(s.context)
      await settlePage()
      await s.wrapper
        .get('tr [data-id="i18n-names-delete-qk"]')
        .trigger('click')
      await settlePage()
      document
        .querySelector<HTMLButtonElement>(
          '[data-id="i18n-country-name-delete-confirm"]',
        )!
        .click()
      s.route.set({
        page: HilosPages.I18N_COUNTRY_NAMES,
        params: { countryCode: 'gb' },
        admin: true,
      })
      s.scope.data.set(COUNTRY_CARD_DATA, { ...initialCard, code: 'gb' })
      s.controller.ingestWindow(
        [{ rowKey: '51', slots: { language: s.slot } }],
        1,
        true,
        null,
        null,
        100,
      )
      await settlePage()
      await s.wrapper
        .get('tr [data-id="i18n-country-name-edit-qk"]')
        .trigger('click')
      const window = s.wrapper
        .getComponent(HilosI18nCountryNameWindow)
        .props('window')
      window.patchForm({ name: 'New country draft' })
      if (outcome === 'success') write.settle({})
      else write.refuse(new ActionError('old', 'fail', 'Old refusal'))
      await settlePage()
      expect(window.opened.get()).toBe(true)
      expect(window.form.get().name).toBe('New country draft')
      expect(s.controller.focusedRow.get()?.code).toBe('qk')
      expect(
        document.querySelector('[data-id="i18n-country-name-error"]')
          ?.textContent,
      ).not.toContain('Old refusal')
    },
  )

  it('cancels an awaited delete opening on unmount', async () => {
    const s = deletionPage()
    await settlePage()
    ;(
      s.wrapper.get('tr [data-id="i18n-names-delete-qk"]')
        .element as HTMLButtonElement
    ).click()
    s.wrapper.unmount()
    mounted.splice(mounted.indexOf(s.wrapper), 1)
    await settlePage()
    expect(s.controller.focusedRow.get()).toBeUndefined()
    expect(
      document.querySelector('[data-id="i18n-country-name-delete-window"]'),
    ).toBeNull()
  })
})

/** The ordinary 404 of a country names page whose country does not exist. */
const NAMES_NOT_FOUND: PageSubscriptionError = {
  page: HilosPages.I18N_COUNTRY_NAMES,
  httpCode: 404,
  errorCode: 'not_found',
  title: 'Page Not Found',
  message: 'The page you are looking for does not exist.',
}

/** The live 404 a viewer gets when the country is deleted under its names. */
const NAMES_DELETED: PageSubscriptionError = {
  ...NAMES_NOT_FOUND,
  transitionMessage: 'It was deleted just now.',
}

/**
 * A writing language with no name yet. Names refer to their country and stop
 * its deletion, so a country that can be deleted has none, and the window open
 * under its deletion is the one its plus opens.
 */
const WRITING_ROW = {
  inLanguageId: 51,
  nameId: null,
  rowKey: '51',
  code: 'qk',
  nativeName: 'Writing',
  name: null,
  corrections: [],
}

/**
 * The names page as an app mounts it: the routed outlet over the real navigator
 * and page subscription. A refusal then reaches the page the way the server's
 * does — the error is set and the page scope emptied under it (HIL-1600) — so
 * the name window loses its card because the scope went, not because a test
 * cleared it.
 */
function liveNamesPage() {
  const scopes = new ScopeManager()
  scopes.session.data.set('adminViewMode', false)
  releases.push(bindAdminAccess(scopes))
  const subscription = new PageSubscription(
    {
      state: 'connected',
      reconnectDragging: false,
      send: () => true,
      on: () => () => {},
      forgetPageFrames: () => {},
      tableWindowDescriptors: () => ({}),
    },
    scopes,
  )
  let path = '/hilos/i18n/countries/us/names'
  const navigation = createHilosRouter(createAppPageRouter({}), subscription, {
    pathname: () => path,
    pushState: (next) => {
      path = next
    },
    replaceState: (next) => {
      path = next
    },
    onPopState: () => () => {},
  })
  navigation.start()
  releases.push(navigation.stop)
  const dispatch = vi.fn()
  const context: HilosI18nCountryContext = {
    ...seededContext().context,
    scopes,
    actions: { dispatch } as unknown as ActionLifecycle,
  }
  const pageMounts = vi.fn()
  const Names = markRaw(
    defineComponent(() => {
      pageMounts()
      return () => h(HilosI18nCountryNamesPage, { context: markRaw(context) })
    }),
  )
  const view = mount(HilosView, {
    props: { pages: { [HilosPages.I18N_COUNTRY_NAMES]: Names } },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: navigation } },
  })
  mounted.push(view)

  return {
    view,
    subscription,
    navigation,
    dispatch,
    pageMounts,
    /** Answer the subscription the way the names page does, its table with it. */
    answer: async () => {
      answerAsNames(scopes.page()!)
      subscription.ingestPageResponse(HilosPages.I18N_COUNTRY_NAMES, {
        data: { [COUNTRY_CARD_DATA]: initialCard },
      })
      await settlePage()
      view
        .getComponent(HilosI18nNamesTable)
        .props('controller')
        .ingestWindow(
          [{ rowKey: '51', slots: { language: WRITING_ROW } }],
          1,
          true,
          null,
          null,
          100,
        )
      await settlePage()
    },
  }
}

/**
 * Open the base name's add window from its plus in the table and type a draft
 * into it.
 *
 * @param live The answered page.
 */
async function draftName(live: ReturnType<typeof liveNamesPage>) {
  const opener = live.view.get('tr [data-id="i18n-country-name-add-qk"]')
  ;(opener.element as HTMLElement).focus()
  await opener.trigger('click')
  await settlePage()
  const input = document.querySelector<HTMLInputElement>(
    '[data-id="i18n-country-name-input"]',
  )!
  expect(document.activeElement).toBe(input)
  input.value = 'Draft name'
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await settlePage()

  return { opener: opener.element as HTMLElement, input }
}

describe('country names under a country deleted elsewhere', () => {
  afterEach(() => {
    document.body.classList.remove('modal-open')
  })

  it('keeps the name window and its draft above the one 404, refuses Save, and returns focus home from the table opener', async () => {
    const live = liveNamesPage()
    await live.answer()
    const { opener, input } = await draftName(live)

    live.subscription.handleSubscriptionError(NAMES_DELETED)
    await settlePage()

    expect(live.pageMounts).toHaveBeenCalledTimes(1)
    expect(document.querySelector('[data-id="i18n-country-name-input"]')).toBe(
      input,
    )
    expect(input.value).toBe('Draft name')
    expect(input.disabled).toBe(false)
    expect(document.activeElement).toBe(input)
    expect(
      document.querySelector('[data-id="i18n-country-name-notice"]')
        ?.textContent,
    ).toContain('Deleted elsewhere — your text stays to copy.')
    const submit = document.querySelector<HTMLButtonElement>(
      '[data-id="i18n-country-name-submit"]',
    )!
    expect(submit.disabled).toBe(true)
    expect(submit.textContent).toBe('Deleted')
    const shell = live.view.get('[data-id="hilos-admin-page"]')
    expect(live.view.findAllComponents(ErrorPage)).toHaveLength(1)
    expect(shell.get('[data-id="page-error-transition-message"]').text()).toBe(
      'It was deleted just now.',
    )
    for (const gone of [
      'hilos-page-head',
      'hilos-breadcrumb',
      'hilos-page-tabs',
      'country-names',
      'country-names-unavailable',
    ]) {
      expect(live.view.find(`[data-id="${gone}"]`).exists()).toBe(false)
    }
    expect(live.view.find('table').exists()).toBe(false)
    expect(opener.isConnected).toBe(false)
    const headings = live.view.findAll('h1')
    expect(headings).toHaveLength(1)
    expect(headings[0].text()).toBe('404')

    input
      .closest('form')!
      .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    submit.click()
    await settlePage()
    expect(live.dispatch).not.toHaveBeenCalled()

    document
      .querySelector<HTMLButtonElement>('[data-id="i18n-country-name-cancel"]')!
      .click()
    await settlePage()
    expect(document.querySelector('[data-id="i18n-country-name-input"]')).toBe(
      input,
    )
    document
      .querySelector<HTMLButtonElement>('[data-id="modal-confirm-discard"]')!
      .click()
    await settlePage()

    expect(
      document.querySelector('[data-id="i18n-country-name-window"]'),
    ).toBeNull()
    expect(live.view.findAllComponents(ErrorPage)).toHaveLength(1)
    expect(
      live.view.find('[data-id="page-error-transition-message"]').exists(),
    ).toBe(true)
    expect(document.activeElement).toBe(
      live.view.get('[data-id="page-error-home"]').element,
    )
    expect(live.dispatch).not.toHaveBeenCalled()
  })

  it('draws the same 404 in place of the head and the table with no window open', async () => {
    const live = liveNamesPage()
    await live.answer()

    live.subscription.handleSubscriptionError(NAMES_DELETED)
    await settlePage()

    const shell = live.view.get('[data-id="hilos-admin-page"]')
    expect(live.view.findAllComponents(ErrorPage)).toHaveLength(1)
    expect(shell.get('[data-id="page-error-transition-message"]').text()).toBe(
      'It was deleted just now.',
    )
    expect(live.view.find('[data-id="hilos-page-head"]').exists()).toBe(false)
    expect(live.view.find('table').exists()).toBe(false)
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(
      live.view.get('[data-id="hilos-page-state"]').attributes('data-state'),
    ).toBe('error')
  })

  it('keeps the window and its draft through a later 404 that brings no line', async () => {
    const live = liveNamesPage()
    await live.answer()
    const { input } = await draftName(live)
    live.subscription.handleSubscriptionError(NAMES_DELETED)
    await settlePage()

    live.subscription.handleSubscriptionError(NAMES_NOT_FOUND)
    await settlePage()

    expect(
      live.view.find('[data-id="page-error-transition-message"]').exists(),
    ).toBe(false)
    expect(live.view.findAllComponents(ErrorPage)).toHaveLength(1)
    expect(
      live.view
        .get('[data-id="hilos-admin-page"]')
        .find('[data-id="page-error"]')
        .exists(),
    ).toBe(true)
    expect(document.querySelector('[data-id="i18n-country-name-input"]')).toBe(
      input,
    )
    expect(input.value).toBe('Draft name')
    expect(live.pageMounts).toHaveBeenCalledTimes(1)
  })

  it.each([
    {
      name: "the server's 403",
      leave: (live: ReturnType<typeof liveNamesPage>) =>
        live.subscription.handleSubscriptionError({
          ...NAMES_NOT_FOUND,
          httpCode: 403,
          errorCode: 'forbidden',
          title: 'Forbidden',
          message: 'Server refusal words',
        }),
      code: '403',
    },
    {
      name: 'the access the client saw go',
      leave: (live: ReturnType<typeof liveNamesPage>) =>
        live.navigation.denyCurrentPage(),
      code: '403',
    },
    {
      name: 'leaving for another country',
      leave: (live: ReturnType<typeof liveNamesPage>) =>
        live.navigation.navigate('/hilos/i18n/countries/fr/names'),
      code: null,
    },
  ])(
    'takes the window and its draft away on $name after the 404',
    async ({ leave, code }) => {
      const live = liveNamesPage()
      await live.answer()
      await draftName(live)
      live.subscription.handleSubscriptionError(NAMES_DELETED)
      await settlePage()

      leave(live)
      await settlePage()

      expect(
        document.querySelector('[data-id="i18n-country-name-window"]'),
      ).toBeNull()
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      expect(live.view.find('[data-id="hilos-admin-page"]').exists()).toBe(
        false,
      )
      if (code === null) {
        expect(live.view.find('[data-id="page-error"]').exists()).toBe(false)
      } else {
        expect(live.view.findAllComponents(ErrorPage)).toHaveLength(1)
        expect(
          live.view.get('[data-id="page-error"]').attributes('data-error-code'),
        ).toBe(code)
      }
    },
  )

  it('never mounts the names page for a 404 that answers its first subscribe', async () => {
    const live = liveNamesPage()

    live.subscription.handleSubscriptionError(NAMES_NOT_FOUND)
    await settlePage()

    expect(live.pageMounts).not.toHaveBeenCalled()
    expect(live.view.find('[data-id="hilos-admin-page"]').exists()).toBe(false)
    expect(live.view.findAllComponents(ErrorPage)).toHaveLength(1)
  })
})
