import { mount } from '@vue/test-utils'
import {
  createSignal,
  HilosPages,
  LANGUAGE_CARD_DATA,
  ScopeManager,
  type ActionLifecycle,
  type HilosConnection,
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import HilosI18nLanguageNamesPage from './HilosI18nLanguageNamesPage.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'

const testCard: HilosI18nLanguageCard = {
  code: 'qx',
  nativeName: 'Test language',
  rtl: false,
  enabled: true,
  summary: {
    isDefault: false,
    isOwn: true,
    localeCount: 2,
    nameCount: 4,
    canDelete: true,
    deleteReason: null,
  },
}

function makeRouter(pageLoading = createSignal(false)): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.I18N_LANGUAGE_NAMES,
      params: { languageCode: 'qx' },
      admin: true,
    }),
    currentPath: createSignal('/hilos/i18n/languages/qx/names'),
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

function makeContext(scopes: ScopeManager): HilosI18nLanguageContext {
  const listeners: Array<(frame: never) => void> = []
  const connection = {
    on(event: string, listener: (frame: never) => void): () => void {
      if (event === 'tableWindow') listeners.push(listener)
      return () => {}
    },
    registerTableWindow() {},
    unregisterTableWindow() {},
    sendTableViewport() {},
    sendTableRendered() {},
    sendTableRowFocus() {},
  } as unknown as HilosConnection

  return {
    connection,
    scopes,
    actions: {} as ActionLifecycle,
  }
}

describe('HilosI18nLanguageNamesPage', () => {
  it('shows a loading skeleton while the card is loading, with no header or table', async () => {
    const scopes = new ScopeManager()
    scopes.openPage(HilosPages.I18N_LANGUAGE_NAMES)
    const pageLoading = createSignal(true)
    const wrapper = mount(HilosI18nLanguageNamesPage, {
      props: { context: makeContext(scopes) },
      global: {
        provide: { [hilosRouterKey as symbol]: makeRouter(pageLoading) },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()

    expect(wrapper.find('[aria-label="Loading language"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="language-card-header"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('table').exists()).toBe(false)
  })

  it('renders the header and tabs above the names table once the card arrives', async () => {
    const scopes = new ScopeManager()
    const scope = scopes.openPage(HilosPages.I18N_LANGUAGE_NAMES)
    scope.data.set(LANGUAGE_CARD_DATA, testCard)
    const wrapper = mount(HilosI18nLanguageNamesPage, {
      props: { context: makeContext(scopes) },
      global: {
        provide: { [hilosRouterKey as symbol]: makeRouter() },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()

    expect(wrapper.find('[data-id="language-card-native-name"]').text()).toBe(
      'Test language',
    )
    expect(wrapper.find('[data-id="language-card-counts"]').text()).toBe(
      'Locales: 2 · Names: 4',
    )

    const namesTab = wrapper.find(
      '[data-id="language-card-tab-hilos_i18n_language_names"]',
    )
    expect(namesTab.attributes('aria-current')).toBe('page')
    expect(namesTab.attributes('href')).toBe('/hilos/i18n/languages/qx/names')

    const mainTab = wrapper.find(
      '[data-id="language-card-tab-hilos_i18n_language"]',
    )
    expect(mainTab.attributes('href')).toBe('/hilos/i18n/languages/qx')

    expect(wrapper.find('table').exists()).toBe(true)
    expect(wrapper.findAll('button')).toHaveLength(0)
    expect(wrapper.findAll('input')).toHaveLength(0)
  })

  it('says language details are no longer available when the card is cleared after deletion', async () => {
    const scopes = new ScopeManager()
    const scope = scopes.openPage(HilosPages.I18N_LANGUAGE_NAMES)
    scope.data.set(LANGUAGE_CARD_DATA, testCard)
    const wrapper = mount(HilosI18nLanguageNamesPage, {
      props: { context: makeContext(scopes) },
      global: {
        provide: { [hilosRouterKey as symbol]: makeRouter() },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()

    expect(wrapper.find('[data-id="language-card-header"]').exists()).toBe(true)

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
})
