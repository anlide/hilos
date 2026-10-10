import { mount } from '@vue/test-utils'
import {
  BUILT_IN_CATALOG_DATA,
  createSignal,
  HILOS_I18N_LANGUAGES_TABLE,
  HilosPages,
  ScopeManager,
  type ActionLifecycle,
  type HilosConnection,
  type HilosI18nLanguageContext,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import HilosI18nLanguagesPage from './HilosI18nLanguagesPage.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'

interface LanguageSlot {
  code: string
  nativeName: string
  rtl: boolean
  enabled: boolean
  isDefault: boolean
  isOwn: boolean
}

const ENGLISH: LanguageSlot = {
  code: 'en',
  nativeName: 'English',
  rtl: false,
  enabled: true,
  isDefault: true,
  isOwn: false,
}

const OWN: LanguageSlot = {
  code: 'qx',
  nativeName: 'Qx own tongue',
  rtl: true,
  enabled: false,
  isDefault: false,
  isOwn: true,
}

type Listener = (signal: { data: unknown }) => void

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.I18N_LANGUAGES,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/i18n/languages'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: (page, params) =>
      page === HilosPages.I18N_LANGUAGE
        ? `/hilos/i18n/languages/${params?.languageCode}`
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

function context(rows: LanguageSlot[] = [ENGLISH, OWN]) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.I18N_LANGUAGES)
  const windowListeners = new Set<Listener>()
  const tableRows = rows.map((language) => ({
    rowKey: language.code,
    slots: { language },
  }))
  const serveWindow = (): void => {
    const data = {
      page: HilosPages.I18N_LANGUAGES,
      tableKey: HILOS_I18N_LANGUAGES_TABLE,
      rows: tableRows,
      totalCount: tableRows.length,
      totalExact: true,
      firstAnchor: null,
      lastAnchor: null,
      offset: 0,
      limit: 100,
    }
    for (const listener of windowListeners) {
      listener({ data })
    }
  }
  const connection = {
    registerTableWindow(tableKey: string): void {
      if (tableKey === HILOS_I18N_LANGUAGES_TABLE) {
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

  const ctx: HilosI18nLanguageContext = {
    connection,
    scopes,
    actions: {} as ActionLifecycle,
  }

  return { context: ctx, scope }
}

describe('HilosI18nLanguagesPage', () => {
  it('links the code, marks default and custom, and ticks rtl and enabled', async () => {
    const view = mount(HilosI18nLanguagesPage, {
      props: { context: context().context },
      global: {
        provide: { [hilosRouterKey as symbol]: router() },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()

    const english = view.get('[data-id="i18n-languages-link-en"]')
    expect(english.text()).toBe('en')
    expect(english.attributes('href') ?? english.attributes('to')).toContain(
      '/hilos/i18n/languages/en',
    )
    expect(english.classes()).not.toContain('link-secondary')
    expect(view.get('[data-id="i18n-languages-default-en"]').text()).toContain(
      'Default language',
    )
    expect(view.find('[data-id="i18n-languages-own-en"]').exists()).toBe(false)
    expect(
      (
        view.get('[data-id="i18n-languages-rtl-en"]')
          .element as HTMLInputElement
      ).checked,
    ).toBe(false)
    expect(
      (
        view.get('[data-id="i18n-languages-enabled-en"]')
          .element as HTMLInputElement
      ).checked,
    ).toBe(true)

    const own = view.get('[data-id="i18n-languages-link-qx"]')
    expect(own.classes()).toContain('link-secondary')
    expect(own.classes()).toContain('text-uppercase')
    expect(view.find('[data-id="i18n-languages-default-qx"]').exists()).toBe(
      false,
    )
    expect(view.get('[data-id="i18n-languages-own-qx"]').text()).toContain(
      'Custom language',
    )
    expect(
      view.get('[data-id="i18n-languages-own-qx"]').attributes('title'),
    ).toBe('Custom language')
    expect(
      (
        view.get('[data-id="i18n-languages-rtl-qx"]')
          .element as HTMLInputElement
      ).checked,
    ).toBe(true)
    expect(
      (
        view.get('[data-id="i18n-languages-enabled-qx"]')
          .element as HTMLInputElement
      ).disabled,
    ).toBe(true)
    expect(view.text()).toContain('Qx own tongue')
    expect(view.html()).toContain('text-body-secondary')
  })

  it('renders the catalog callout with counts and omits it when data is absent', async () => {
    const c1 = context()
    c1.scope.data.set(BUILT_IN_CATALOG_DATA, {
      languageCount: 3,
      countryCount: 4,
    })
    const viewWithData = mount(HilosI18nLanguagesPage, {
      props: { context: c1.context },
      global: {
        provide: { [hilosRouterKey as symbol]: router() },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()

    const catalog = viewWithData.get('[data-id="i18n-languages-catalog"]')
    expect(catalog.get('h2').text()).toBe(
      'The system knows 3 languages and 4 countries on its own',
    )
    expect(catalog.get('p').text()).toBe(
      "The framework's built-in catalog holds 3 ISO 639-1 codes with their own names and writing direction. A language from it is refreshed whenever a framework update changes the catalog — and only while that language is switched off.",
    )

    const c2 = context()
    const viewWithoutData = mount(HilosI18nLanguagesPage, {
      props: { context: c2.context },
      global: {
        provide: { [hilosRouterKey as symbol]: router() },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()
    expect(
      viewWithoutData.find('[data-id="i18n-languages-catalog"]').exists(),
    ).toBe(false)
  })

  it('renders the legend rows even when the languages table is empty', async () => {
    const c = context([])
    const view = mount(HilosI18nLanguagesPage, {
      props: { context: c.context },
      global: {
        provide: { [hilosRouterKey as symbol]: router() },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()

    const legend = view.get('[data-id="i18n-languages-legend"]')
    expect(legend.get('h2').text()).toContain('What the marks mean')

    const defaultRow = view.get('[data-id="i18n-languages-legend-default"]')
    expect(defaultRow.get('dt').text()).toContain('Default language')
    expect(defaultRow.get('dd').text()).toBe(
      'the language from the environment; it can be neither switched off nor deleted',
    )

    const ownRow = view.get('[data-id="i18n-languages-legend-own"]')
    expect(ownRow.get('dt').text()).toContain('Custom language')
    expect(ownRow.get('dd').text()).toBe(
      'its code is not in the built-in catalog: nothing refreshes it, keeping it up is yours',
    )
  })
})
