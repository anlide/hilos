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

import HilosI18nLanguagePage from './HilosI18nLanguagePage.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'

const initialCard: HilosI18nLanguageCard = {
  code: 'qx',
  nativeName: 'First name',
  rtl: false,
  enabled: true,
  summary: {
    isDefault: true,
    isOwn: false,
    localeCount: 2,
    nameCount: 1,
    canDelete: false,
    deleteReason: 'default',
  },
}

/** The route resolver gives unbuilt child pages no path. */
function router(childrenBuilt = false): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.I18N_LANGUAGE,
      params: { languageCode: 'qx' },
      admin: true,
    }),
    currentPath: createSignal('/hilos/i18n/languages/qx'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(true),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: (page, params) => {
      if (!childrenBuilt && page !== HilosPages.I18N_LANGUAGE) return undefined
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

function harness(childrenBuilt = false) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.I18N_LANGUAGE)
  const context: HilosI18nLanguageContext = {
    connection: {} as HilosConnection,
    scopes,
    actions: {} as ActionLifecycle,
  }
  const navigation = router(childrenBuilt)
  const view = mount(HilosI18nLanguagePage, {
    props: { context },
    global: {
      provide: { [hilosRouterKey as symbol]: navigation },
      stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
    },
  })
  return { scope, view, navigation }
}

describe('HilosI18nLanguagePage', () => {
  it('waits for the first card, then shows its read-only fields and three tab names', async () => {
    const h = harness()
    expect(h.view.find('[data-id="language-card-header"]').exists()).toBe(false)
    expect(h.view.find('[aria-label="Loading language"]').exists()).toBe(true)

    h.scope.data.set(LANGUAGE_CARD_DATA, initialCard)
    await nextTick()
    expect(h.view.find('[data-id="language-card-native-name"]').text()).toBe(
      'First name',
    )
    expect(h.view.find('[data-id="language-card-counts"]').text()).toContain(
      'Locales: 2 · Names: 1',
    )
    expect(h.view.find('[data-id="language-card-default"]').text()).toBe(
      'Default language',
    )
    expect(h.view.find('[data-id="language-card-own"]').exists()).toBe(false)
    expect(h.view.find('[data-id="language-card-frozen"]').text()).toBe(
      'Language enabled — frozen',
    )
    expect(
      h.view.find('[data-id="language-card-delete-verdict"]').text(),
    ).toContain('default language')
    expect(h.view.find('[data-id="language-card-tabs"]').text()).toContain(
      'Main',
    )
    expect(h.view.find('[data-id="language-card-tabs"]').text()).toContain(
      'Names',
    )
    expect(h.view.find('[data-id="language-card-tabs"]').text()).toContain(
      'Locales',
    )
    expect(h.view.findAll('[data-id="language-card-tabs"] a')).toHaveLength(1)
    expect(
      h.view.find('[data-id="language-card-tabs"] a').attributes('href'),
    ).toBe('/hilos/i18n/languages/qx')
    expect(
      h.view
        .find('[data-id="language-card-tabs"] a')
        .attributes('aria-current'),
    ).toBe('page')
    expect(
      h.view.findAll('[data-id="language-card-state"] button'),
    ).toHaveLength(1)
    expect(
      h.view
        .find('[data-id="language-card-switch-off"]')
        .attributes('disabled'),
    ).toBeDefined()
    expect(h.view.findAll('input')).toHaveLength(0)
    h.view.unmount()
  })

  it('reacts to a new page signal and clears a deleted card for an admin view viewer', async () => {
    const h = harness()
    h.scope.data.set(LANGUAGE_CARD_DATA, initialCard)
    await nextTick()
    h.scope.data.set(LANGUAGE_CARD_DATA, {
      ...initialCard,
      nativeName: 'Changed name',
      rtl: true,
      enabled: false,
      summary: {
        isDefault: false,
        isOwn: true,
        localeCount: 0,
        nameCount: 0,
        canDelete: true,
        deleteReason: null,
      },
    })
    await nextTick()
    expect(h.view.find('[data-id="language-card-native-name"]').text()).toBe(
      'Changed name',
    )
    expect(h.view.find('[data-id="language-card-direction"]').text()).toBe(
      'Right to left',
    )
    expect(h.view.find('[data-id="language-card-frozen"]').exists()).toBe(false)
    expect(h.view.find('[data-id="language-card-own"]').text()).toBe(
      'Custom language',
    )
    expect(h.view.find('[data-id="language-card-delete-verdict"]').text()).toBe(
      'Can be deleted',
    )
    expect(h.view.find('[data-id="language-card-switch-off"]').exists()).toBe(
      false,
    )

    h.scope.data.set(LANGUAGE_CARD_DATA, [])
    await nextTick()
    expect(h.view.find('[data-id="language-card-header"]').exists()).toBe(false)
    expect(h.view.find('[data-id="language-card-main"]').exists()).toBe(false)
    h.view.unmount()
  })

  it('uses the same language code when child routes become built', async () => {
    const h = harness(true)
    h.scope.data.set(LANGUAGE_CARD_DATA, initialCard)
    await nextTick()
    const links = h.view.findAll('[data-id="language-card-tabs"] a')
    expect(links.map((link) => link.attributes('href'))).toEqual([
      '/hilos/i18n/languages/qx',
      '/hilos/i18n/languages/qx/names',
      '/hilos/i18n/languages/qx/locales',
    ])
    h.view.unmount()
  })
})
