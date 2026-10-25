import { mount } from '@vue/test-utils'
import {
  createSignal,
  bindAdminAccess,
  HilosPages,
  ScopeManager,
  pageIdentity,
  type ActionLifecycle,
  type ActionResult,
  type HilosConnection,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'
import {
  HILOS_I18N_LANGUAGE_DELETE_ACTION,
  LANGUAGE_CARD_DATA,
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
} from '@hilos/core/admin/i18n'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { markRaw, nextTick, ref } from 'vue'

import HilosI18nLanguagePage from './HilosI18nLanguagePage.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'
import { hilosAdminViewModeKey } from '../../../hilosAdminViewMode.js'

const initialCard: HilosI18nLanguageCard = {
  languageId: 46,
  code: 'qx',
  nativeName: 'First name',
  rtl: false,
  enabled: true,
  summary: {
    isDefault: true,
    isOwn: false,
    localeCount: 2,
    enabledLocaleCount: 0,
    nameCount: 1,
    countrylessLocale: 'missing',
    canDelete: false,
    deleteReason: 'default',
  },
}

/** The route resolver gives unbuilt child pages no path. */
function router(scopes: ScopeManager, childrenBuilt = false): HilosRouter {
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
    pageIdentity: pageIdentity(scopes),
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
  answerAsLanguage(scopes)
  scopes.session.data.set('adminViewMode', viewMode)
  releases.push(bindAdminAccess(scopes))
  const context: HilosI18nLanguageContext = {
    connection: {} as HilosConnection,
    scopes,
    actions,
  }
  const navigation = {
    ...router(scopes, childrenBuilt),
    pageLoading: createSignal(true),
  }
  const view = mount(HilosI18nLanguagePage, {
    props: { context: markRaw(context) },
    global: {
      provide: {
        [hilosRouterKey as symbol]: navigation,
        [hilosAdminViewModeKey as symbol]: ref(viewMode),
      },
    },
  })
  return { scopes, scope, view, navigation }
}

/** The single reply carries the owner's identity, ordered tabs and no child cards.
 * @param scopes The current page scopes.
 */
function answerAsLanguage(scopes: ScopeManager): void {
  const scope = scopes.page()!
  scope.data.set('pageLabel', 'Language')
  scope.data.set('pageHeadOwner', {
    page: HilosPages.I18N_LANGUAGE,
    label: 'Language',
    lead: '',
    icon: null,
  })
  scope.data.set('pageBreadcrumb', [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.I18N, label: 'Internationalization' },
    { page: HilosPages.I18N_LANGUAGES, label: 'Languages' },
    { page: HilosPages.I18N_LANGUAGE, label: 'Language' },
  ])
  scope.data.set('pageTabs', [
    { page: HilosPages.I18N_LANGUAGE, label: 'Overview' },
    { page: HilosPages.I18N_LANGUAGE_NAMES, label: 'Names' },
    { page: HilosPages.I18N_LANGUAGE_LOCALES, label: 'Locales' },
  ])
  scope.data.set('pageChildren', [])
}

const releases: Array<() => void> = []
afterEach(() => {
  for (const release of releases.splice(0)) release()
})

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
      languageId: 46,
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
    expect(h.view.find('[data-id="language-head-tile"]').exists()).toBe(false)
    expect(h.view.find('[aria-label="Loading language"]').exists()).toBe(true)

    h.scope.data.set(LANGUAGE_CARD_DATA, initialCard)
    await nextTick()
    expect(h.view.find('[data-id="hilos-page-head-title"]').text()).toBe(
      'First name',
    )
    expect(
      h.view.get('[data-id="language-overview-locale-count"]').text(),
    ).toBe('2')
    expect(h.view.get('[data-id="language-overview-name-count"]').text()).toBe(
      '1',
    )
    expect(h.view.find('[data-id="language-card-counts"]').exists()).toBe(false)
    expect(h.view.find('[data-id="language-head-default"]').text()).toBe(
      'Default language',
    )
    expect(h.view.find('[data-id="language-head-own"]').exists()).toBe(false)
    expect(h.view.find('[data-id="language-card-frozen"]').text()).toBe(
      'Language enabled — frozen',
    )
    expect(
      h.view.find('[data-id="language-card-delete-verdict"]').text(),
    ).toContain('default language')
    expect(h.view.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)
    expect(
      h.view.get('[data-id="language-overview-name-count"]').element.tagName,
    ).toBe('SPAN')
    expect(
      h.view.get('[data-id="language-overview-locale-count"]').element.tagName,
    ).toBe('SPAN')
    expect(
      h.view.findAll('[data-id="language-card-state"] button'),
    ).toHaveLength(2)
    expect(
      h.view
        .find('[data-id="language-card-switch-off"]')
        .attributes('disabled'),
    ).toBeDefined()
    expect(h.view.findAll('input')).toHaveLength(1)
    expect(
      h.view.get('#language-card-rtl').attributes('disabled'),
    ).toBeDefined()
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
        enabledLocaleCount: 0,
        nameCount: 0,
        countrylessLocale: 'missing',
        canDelete: true,
        deleteReason: null,
      },
    })
    await nextTick()
    expect(h.view.find('[data-id="hilos-page-head-title"]').text()).toBe(
      'Changed name',
    )
    expect(
      (h.view.get('#language-card-rtl').element as HTMLInputElement).checked,
    ).toBe(true)
    expect(h.view.get('[data-id="language-head-direction"]').text()).toBe(
      'Right to left',
    )
    expect(h.view.get('[data-id="language-card-editable"]').text()).toBe(
      'Language switched off — edit freely',
    )
    expect(h.view.find('[data-id="language-card-frozen"]').exists()).toBe(false)
    expect(h.view.find('[data-id="language-head-own"]').text()).toBe(
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
    expect(h.view.find('[data-id="language-head-tile"]').exists()).toBe(false)
    expect(h.view.find('[data-id="language-card-main"]').exists()).toBe(false)
    h.view.unmount()
  })

  it.each(['missing', 'off', 'on'] as const)(
    'shows zero and nonzero tiles with countryless=%s, honoring catalog and route eligibility',
    async (countrylessLocale) => {
      const h = harness(true)
      h.scope.data.set(LANGUAGE_CARD_DATA, {
        ...initialCard,
        summary: {
          ...initialCard.summary,
          nameCount: 0,
          localeCount: 0,
          countrylessLocale,
        },
      })
      await nextTick()
      expect(
        h.view.get('[data-id="language-overview-name-count"]').text(),
      ).toBe('0')
      expect(
        h.view.get('[data-id="language-overview-locale-count"]').text(),
      ).toBe('0')
      expect(
        h.view.get('[data-id="language-overview-enabled-locale-count"]').text(),
      ).toBe('0')
      expect(
        h.view.get('[data-id="language-overview-locales"]').text(),
      ).toContain('None created.')
      expect(
        h.view.get('[data-id="language-overview-countryless-state"]').text(),
      ).toBe(
        { missing: 'Not created', off: 'Switched off', on: 'Switched on' }[
          countrylessLocale
        ],
      )
      expect(
        h.view
          .get('[data-id="language-overview-name-count"]')
          .attributes('href'),
      ).toBe('/hilos/i18n/languages/qx/names')
      expect(
        h.view
          .get('[data-id="language-overview-countryless-state"]')
          .attributes('href'),
      ).toBe('/hilos/i18n/languages/qx/locales')
      h.scope.data.set(LANGUAGE_CARD_DATA, {
        ...initialCard,
        summary: {
          ...initialCard.summary,
          nameCount: 7,
          localeCount: 4,
          enabledLocaleCount: 3,
          countrylessLocale,
        },
      })
      await nextTick()
      expect(
        h.view.get('[data-id="language-overview-name-count"]').text(),
      ).toBe('7')
      expect(
        h.view.get('[data-id="language-overview-locale-count"]').text(),
      ).toBe('4')
      expect(
        h.view.get('[data-id="language-overview-enabled-locale-count"]').text(),
      ).toBe('3')
      expect(
        h.view.get('[data-id="language-overview-locales"]').text(),
      ).not.toContain('None created.')
      h.scope.data.set('pageTabs', [
        { page: HilosPages.I18N_LANGUAGE, label: 'Overview' },
      ])
      await nextTick()
      for (const id of ['name-count', 'locale-count', 'countryless-state'])
        expect(
          h.view.get(`[data-id="language-overview-${id}"]`).element.tagName,
        ).toBe('SPAN')
      h.view.unmount()
    },
  )

  it('binds only permitted language scopes and releases its live name on unmount', async () => {
    const h = harness(true, {} as ActionLifecycle, true)
    h.scope.data.set(LANGUAGE_CARD_DATA, initialCard)
    await nextTick()
    expect(h.view.findAll('h1')).toHaveLength(1)
    expect(h.view.get('h1').text()).toBe('First name')
    expect(
      h.view.get('[data-id="hilos-breadcrumb"] [aria-current="page"]').text(),
    ).toBe('First name')
    expect(
      h.view.get('[data-id="language-head-default"]').attributes('title'),
    ).toBe('Default language')
    expect(
      h.view
        .get('[data-id="language-head-default"] i')
        .attributes('aria-hidden'),
    ).toBe('true')
    for (const id of [
      'language-card-header',
      'language-card-tabs',
      'language-card-counts',
      'hilos-admin-children',
    ])
      expect(h.view.find(`[data-id="${id}"]`).exists()).toBe(false)
    h.scope.data.set(LANGUAGE_CARD_DATA, {
      ...initialCard,
      nativeName: 'Renamed',
    })
    await nextTick()
    expect(h.view.get('h1').text()).toBe('Renamed')
    expect(
      h.view.get('[data-id="hilos-breadcrumb"] [aria-current="page"]').text(),
    ).toBe('Renamed')
    const foreign = h.scopes.openPage(HilosPages.I18N_COUNTRY)
    answerAsLanguage(h.scopes)
    foreign.data.set(LANGUAGE_CARD_DATA, {
      ...initialCard,
      nativeName: 'Foreign scope',
    })
    await nextTick()
    expect(h.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Language',
    )
    h.view.unmount()
    h.scopes.openPage(HilosPages.I18N_LANGUAGE)
    answerAsLanguage(h.scopes)
    h.scopes.page()!.data.set(LANGUAGE_CARD_DATA, initialCard)
    expect(h.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Language',
    )
  })

  it('uses the same language code when child routes become built', async () => {
    const h = harness(true)
    h.scope.data.set(LANGUAGE_CARD_DATA, initialCard)
    await nextTick()
    expect(
      h.view.get('[data-id="hilos-page-tabs"]').attributes('aria-label'),
    ).toBe('Language pages')
    expect(
      h.view
        .get('[data-id="hilos-page-tab-hilos_i18n_language"]')
        .attributes('aria-current'),
    ).toBe('page')
    const links = h.view.findAll('[data-id="hilos-page-tabs"] a')
    expect(links.map((link) => link.attributes('href'))).toEqual([
      '/hilos/i18n/languages/qx',
      '/hilos/i18n/languages/qx/names',
      '/hilos/i18n/languages/qx/locales',
    ])
    h.view.unmount()
  })
})
