import { mount } from '@vue/test-utils'
import {
  createSignal,
  HILOS_I18N_LANGUAGE_DELETE_ACTION,
  HilosPages,
  LANGUAGE_CARD_DATA,
  ScopeManager,
  type ActionLifecycle,
  type ActionResult,
  type HilosConnection,
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'

import HilosI18nLanguagePage from './HilosI18nLanguagePage.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'
import { hilosAdminViewModeKey } from '../../../hilosAdminViewMode.js'

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
    countrylessLocale: 'missing',
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
      if (page === HilosPages.I18N_LANGUAGES) return '/hilos/i18n/languages'
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

function harness(
  childrenBuilt = false,
  actions = {} as ActionLifecycle,
  viewMode = false,
) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.I18N_LANGUAGE)
  const context: HilosI18nLanguageContext = {
    connection: {} as HilosConnection,
    scopes,
    actions,
  }
  const navigation = {
    ...router(childrenBuilt),
    pageLoading: createSignal(true),
  }
  const view = mount(HilosI18nLanguagePage, {
    props: { context },
    global: {
      provide: {
        [hilosRouterKey as symbol]: navigation,
        [hilosAdminViewModeKey as symbol]: ref(viewMode),
      },
      stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
    },
  })
  return { scope, view, navigation }
}

describe('HilosI18nLanguagePage', () => {
  it.each(['default', 'locales', 'names'] as const)(
    'shows the %s delete reason beside the disabled card control',
    async (deleteReason) => {
      const h = harness()
      h.scope.data.set(LANGUAGE_CARD_DATA, {
        ...initialCard,
        summary: { ...initialCard.summary, canDelete: false, deleteReason },
      })
      await nextTick()
      const opener = h.view.get('[data-id="language-card-delete"]')
      expect((opener.element as HTMLButtonElement).disabled).toBe(true)
      expect(opener.attributes('aria-describedby')).toBe(
        'language-card-delete-reason',
      )
      expect(
        h.view.get('[data-id="language-card-delete-reason"]').text(),
      ).toMatch(/^Cannot delete: .+\.$/)
      h.view.unmount()
    },
  )

  it('lets a card viewer read the delete modal and keeps an idle vanished source there', async () => {
    const h = harness(false, {} as ActionLifecycle, true)
    h.scope.data.set(LANGUAGE_CARD_DATA, {
      ...initialCard,
      summary: { ...initialCard.summary, canDelete: true, deleteReason: null },
    })
    await nextTick()
    const opener = h.view.get('[data-id="language-card-delete"]')
    expect((opener.element as HTMLButtonElement).disabled).toBe(false)
    await opener.trigger('click')
    const confirm = document.querySelector<HTMLButtonElement>(
      '[data-id="i18n-language-delete-confirm"]',
    )!
    expect(confirm.disabled).toBe(true)
    h.scope.data.set(LANGUAGE_CARD_DATA, [])
    await nextTick()
    expect(document.body.textContent).toContain('Deleted elsewhere.')
    expect(
      document.querySelector('[data-id="i18n-language-delete-confirm"]'),
    ).toBe(confirm)
    h.view.unmount()
  })

  it('keeps deletion alive across source-null and redirects only after acknowledgement', async () => {
    let settle: (result: ActionResult) => void = () => {}
    const done = new Promise<ActionResult>((resolve) => {
      settle = resolve
    })
    const dispatch = vi.fn(() => ({
      requestId: 'delete-card',
      loading: createSignal(false),
      done,
    }))
    const h = harness(false, { dispatch } as unknown as ActionLifecycle)
    const navigate = vi.spyOn(h.navigation, 'navigate')
    h.navigation.pageLoading.set(false)
    h.scope.data.set(LANGUAGE_CARD_DATA, {
      ...initialCard,
      summary: {
        ...initialCard.summary,
        canDelete: true,
        deleteReason: null,
        isDefault: false,
      },
    })
    await nextTick()
    await h.view.get('[data-id="language-card-delete"]').trigger('click')
    document
      .querySelector<HTMLButtonElement>(
        '[data-id="i18n-language-delete-confirm"]',
      )!
      .click()
    expect(dispatch).toHaveBeenCalledWith(HILOS_I18N_LANGUAGE_DELETE_ACTION, {
      languageCode: 'qx',
    })
    h.scope.data.set(LANGUAGE_CARD_DATA, [])
    await nextTick()
    expect(
      document.querySelector('[data-id="i18n-language-delete-confirm"]'),
    ).not.toBeNull()
    expect(h.view.find('[data-id="language-card-unavailable"]').exists()).toBe(
      false,
    )
    expect(navigate).not.toHaveBeenCalled()
    settle({ message: 'Language deleted.' })
    await nextTick()
    await nextTick()
    await nextTick()
    expect(navigate).toHaveBeenCalledExactlyOnceWith('/hilos/i18n/languages')
    expect(
      document.querySelector('[data-id="i18n-language-delete-confirm"]'),
    ).toBeNull()
    h.view.unmount()
  })

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
    ).toHaveLength(2)
    expect(
      h.view
        .find('[data-id="language-card-switch-off"]')
        .attributes('disabled'),
    ).toBeDefined()
    expect(h.view.findAll('input')).toHaveLength(0)
    const main = h.view.get('[data-id="language-card-main"]')
    expect(main.get('[data-id="language-card-frozen"]').text()).toBe(
      'Language enabled — frozen',
    )
    expect(
      h.view
        .find(
          '[data-id="language-card-state"] [data-id="language-card-frozen"]',
        )
        .exists(),
    ).toBe(false)
    expect(
      main.get('[data-id="language-card-view"]').attributes('aria-label'),
    ).toBe('View language: nothing to edit')
    expect(main.find('[data-id="language-card-edit"]').exists()).toBe(false)
    await main.get('[data-id="language-card-view"]').trigger('click')
    expect(
      document.querySelector('[data-id="i18n-language-edit-title"]')
        ?.textContent,
    ).toContain('Language · First name')
    expect(
      document.querySelector('[data-id="i18n-language-edit-submit"]'),
    ).toBeNull()
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
        countrylessLocale: 'missing',
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
      'Delete language',
    )
    expect(h.view.find('[data-id="language-card-switch-off"]').exists()).toBe(
      false,
    )
    expect(
      h.view.findAll('[data-id="language-card-state"] button'),
    ).toHaveLength(2)
    expect(h.view.find('[data-id="language-card-switch-on"]').text()).toBe(
      'Switch on',
    )
    expect(h.view.find('[data-id="language-card-view"]').exists()).toBe(false)
    const edit = h.view.get('[data-id="language-card-edit"]')
    expect(edit.attributes('aria-label')).toBe('Edit language')
    await edit.trigger('click')
    expect(
      document.querySelector('[data-id="i18n-language-edit-title"]')
        ?.textContent,
    ).toContain('Edit language · Changed name')
    expect(
      document
        .querySelector('[data-id="i18n-language-edit-plate"]')
        ?.getAttribute('data-kind'),
    ).toBe('own')

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
