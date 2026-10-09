import { mount } from '@vue/test-utils'
import {
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

function context(): HilosI18nLanguageContext {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.I18N_LANGUAGES)
  const windowListeners = new Set<Listener>()
  const rows = [ENGLISH, OWN].map((language) => ({
    rowKey: language.code,
    slots: { language },
  }))
  const serveWindow = (): void => {
    const data = {
      page: HilosPages.I18N_LANGUAGES,
      tableKey: HILOS_I18N_LANGUAGES_TABLE,
      rows,
      totalCount: rows.length,
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

  return {
    connection,
    scopes,
    actions: {} as ActionLifecycle,
  }
}

describe('HilosI18nLanguagesPage', () => {
  it('links the code, marks default and custom, and ticks rtl and enabled', async () => {
    const view = mount(HilosI18nLanguagesPage, {
      props: { context: context() },
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
})
