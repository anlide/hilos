import { mount } from '@vue/test-utils'
import {
  createSignal,
  HILOS_I18N_LANGUAGE_LOCALES_TABLE,
  HilosPages,
  LANGUAGE_CARD_DATA,
  ScopeManager,
  type ActionLifecycle,
  type HilosConnection,
  type HilosI18nLanguageCard,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import HilosI18nLanguageLocalesPage from './HilosI18nLanguageLocalesPage.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'

const russianCard: HilosI18nLanguageCard = {
  code: 'ru',
  nativeName: 'Русский',
  rtl: false,
  enabled: true,
  summary: {
    isDefault: false,
    isOwn: false,
    localeCount: 3,
    nameCount: 5,
    canDelete: true,
    deleteReason: null,
  },
}

/** The address of the locales page of Russian, a signal so a test can move it to another language. */
function localesRoute() {
  return createSignal<PageRouteMatch>({
    page: HilosPages.I18N_LANGUAGE_LOCALES,
    params: { languageCode: 'ru' },
    admin: true,
  })
}

function router(
  route: ReturnType<typeof localesRoute>,
  pageLoading = createSignal(false),
): HilosRouter {
  return {
    currentRoute: route,
    currentPath: createSignal('/hilos/i18n/languages/ru/locales'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading,
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: (page, params) => {
      const base = `/hilos/i18n/languages/${params?.languageCode}`
      if (page === HilosPages.I18N_LANGUAGE) return base
      if (page === HilosPages.I18N_LANGUAGE_NAMES) return `${base}/names`
      if (page === HilosPages.I18N_LANGUAGE_LOCALES) return `${base}/locales`
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
}

/** A connection stub that hands the locales window to the page and records the filters asked for. */
function makeConnection(): {
  connection: HilosConnection
  pushWindow: (rows: Record<string, unknown>[]) => void
  filters: Record<string, unknown>[]
} {
  const windowListeners: ((signal: { data: unknown }) => void)[] = []
  const filters: Record<string, unknown>[] = []
  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'tableWindow') {
        windowListeners.push(
          listener as unknown as (signal: { data: unknown }) => void,
        )
      }

      return () => {}
    },
    sendTableRendered(): void {},
    registerTableWindow(): void {},
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(
      _page: string,
      _tableKey: string,
      descriptor: { filter?: Record<string, unknown> },
    ): void {
      filters.push(descriptor.filter ?? {})
    },
  } as unknown as HilosConnection

  return {
    connection,
    pushWindow(rows: Record<string, unknown>[]): void {
      for (const listener of windowListeners) {
        listener({
          data: {
            page: HilosPages.I18N_LANGUAGE_LOCALES,
            tableKey: HILOS_I18N_LANGUAGE_LOCALES_TABLE,
            rows: rows.map((slot) => ({
              rowKey: String(slot.rowKey),
              slots: { locale: slot },
            })),
            totalCount: rows.length,
          },
        })
      }
    },
    filters,
  }
}

/** One pair as the backend puts it on the wire. */
function pair(
  rowKey: string,
  countryCode: string | null,
  countryName: string | null,
  enabled: boolean | null,
): Record<string, unknown> {
  return {
    rowKey,
    countryCode,
    countryName,
    localeCode: enabled === null ? null : rowKey,
    enabled,
  }
}

/** The locales page of Russian: its own locale on, Ukraine's off, and Andorra and Belarus without a locale. */
async function mountPage() {
  const { connection, pushWindow, filters } = makeConnection()
  const route = localesRoute()
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.I18N_LANGUAGE_LOCALES)
  scope.data.set(LANGUAGE_CARD_DATA, russianCard)
  const wrapper = mount(HilosI18nLanguageLocalesPage, {
    props: { context: { connection, scopes, actions: {} as ActionLifecycle } },
    global: {
      provide: { [hilosRouterKey as symbol]: router(route) },
      stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
    },
  })
  pushWindow([
    pair('ru', null, null, true),
    pair('ru-AD', 'ad', null, null),
    pair('ru-BY', 'by', 'Беларусь', null),
    pair('ru-UA', 'ua', 'Украина', false),
  ])
  await nextTick()

  return { wrapper, filters, route, scope }
}

describe('HilosI18nLanguageLocalesPage', () => {
  it('shows a loading skeleton while the card is loading, with no header or table cells', async () => {
    const { connection } = makeConnection()
    const route = localesRoute()
    const scopes = new ScopeManager()
    scopes.openPage(HilosPages.I18N_LANGUAGE_LOCALES)
    const pageLoading = createSignal(true)
    const wrapper = mount(HilosI18nLanguageLocalesPage, {
      props: {
        context: { connection, scopes, actions: {} as ActionLifecycle },
      },
      global: {
        provide: { [hilosRouterKey as symbol]: router(route, pageLoading) },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()

    expect(wrapper.find('[aria-label="Loading language"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="language-card-header"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id^="i18n-locales-"]').exists()).toBe(false)
  })

  it('renders the header and tabs above the locales table once the card arrives', async () => {
    const { wrapper } = await mountPage()

    expect(wrapper.find('[data-id="language-card-native-name"]').text()).toBe(
      'Русский',
    )
    expect(wrapper.find('[data-id="language-card-counts"]').text()).toBe(
      'Locales: 3 · Names: 5',
    )

    const localesTab = wrapper.find(
      '[data-id="language-card-tab-hilos_i18n_language_locales"]',
    )
    expect(localesTab.attributes('aria-current')).toBe('page')
    expect(localesTab.attributes('href')).toBe(
      '/hilos/i18n/languages/ru/locales',
    )

    const mainTab = wrapper.find(
      '[data-id="language-card-tab-hilos_i18n_language"]',
    )
    expect(mainTab.attributes('href')).toBe('/hilos/i18n/languages/ru')

    expect(wrapper.find('table').exists()).toBe(true)
    expect(wrapper.findAll('button')).toHaveLength(0)

    const inputs = wrapper.findAll('input')
    for (const input of inputs) {
      expect(input.attributes('type')).toBe('checkbox')
      expect(input.attributes('disabled')).toBeDefined()
    }
  })

  it('says language details are no longer available when the card is cleared after deletion', async () => {
    const { wrapper, scope } = await mountPage()

    scope.data.set(LANGUAGE_CARD_DATA, [])
    await nextTick()

    expect(wrapper.find('[data-id="language-card-unavailable"]').text()).toBe(
      'Language details are no longer available.',
    )
    expect(wrapper.find('[data-id="language-card-header"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('table').exists()).toBe(false)
  })

  it('asks for the window of another language when the address moves to it', async () => {
    const { filters, route } = await mountPage()

    route.set({
      page: HilosPages.I18N_LANGUAGE_LOCALES,
      params: { languageCode: 'en' },
      admin: true,
    })
    await nextTick()

    expect(filters.at(-1)).toEqual({ language: 'en' })
  })

  it('says "— no country" on the row of the language alone', async () => {
    const { wrapper } = await mountPage()

    expect(wrapper.find('[data-id="i18n-locales-country-ru"]').text()).toBe(
      '— no country',
    )
    expect(wrapper.find('[data-id="i18n-locales-code-ru"]').text()).toBe('ru')
  })

  it('shows a country by its code in capitals and its name, or by its code alone', async () => {
    const { wrapper } = await mountPage()
    const belarus = wrapper.find('[data-id="i18n-locales-country-ru-BY"]')
    const andorra = wrapper.find('[data-id="i18n-locales-country-ru-AD"]')

    expect(belarus.find('.text-uppercase').text()).toBe('by')
    expect(belarus.text()).toContain('Беларусь')
    expect(andorra.find('.text-uppercase').text()).toBe('ad')
    expect(andorra.text()).toBe('ad')
  })

  it('says "No locale" where the pair has none, with no tick', async () => {
    const { wrapper } = await mountPage()

    expect(wrapper.find('[data-id="i18n-locales-none-ru-AD"]').text()).toBe(
      'No locale',
    )
    expect(wrapper.find('[data-id="i18n-locales-code-ru-AD"]').exists()).toBe(
      false,
    )
    expect(
      wrapper.find('[data-id="i18n-locales-enabled-ru-AD"]').exists(),
    ).toBe(false)
  })

  it('shows the switch as a disabled tick named "Enabled"', async () => {
    const { wrapper } = await mountPage()
    const own = wrapper.find('[data-id="i18n-locales-enabled-ru"]')
    const ukraine = wrapper.find('[data-id="i18n-locales-enabled-ru-UA"]')

    expect(own.attributes('disabled')).toBeDefined()
    expect(own.attributes('aria-label')).toBe('Enabled')
    expect(own.attributes('title')).toBeUndefined()
    expect((own.element as HTMLInputElement).checked).toBe(true)
    expect(ukraine.attributes('disabled')).toBeDefined()
    expect((ukraine.element as HTMLInputElement).checked).toBe(false)
  })

  it('mutes the text of a switched-off locale with the secondary color, never with opacity', async () => {
    const { wrapper } = await mountPage()
    const country = wrapper.find('[data-id="i18n-locales-country-ru-UA"]')
    const code = wrapper.find('[data-id="i18n-locales-code-ru-UA"]')

    expect(country.classes()).toContain('text-body-secondary')
    expect(code.classes()).toContain('text-body-secondary')
    expect(wrapper.find('.opacity-50').exists()).toBe(false)
  })

  it('leaves a switched-on locale and a pair without one unmuted', async () => {
    const { wrapper } = await mountPage()

    expect(
      wrapper.find('[data-id="i18n-locales-code-ru"]').classes(),
    ).not.toContain('text-body-secondary')
    expect(
      wrapper.find('[data-id="i18n-locales-country-ru-BY"]').classes(),
    ).not.toContain('text-body-secondary')
  })
})
