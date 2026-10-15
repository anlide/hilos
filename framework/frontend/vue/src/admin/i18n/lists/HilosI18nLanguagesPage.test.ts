import { mount } from '@vue/test-utils'
import {
  BUILT_IN_CATALOG_DATA,
  BUILT_IN_LANGUAGES_DATA,
  createSignal,
  HILOS_I18N_LANGUAGES_TABLE,
  HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION,
  HILOS_I18N_LANGUAGE_DELETE_COPY,
  HilosPages,
  ScopeManager,
  type ActionLifecycle,
  type ActionResult,
  type HilosConnection,
  type HilosI18nLanguageContext,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'

import HilosI18nLanguagesPage from './HilosI18nLanguagesPage.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'
import { hilosAdminViewModeKey } from '../../../hilosAdminViewMode.js'

interface LanguageSlot {
  rowKey: string
  languageId: number
  code: string
  nativeName: string
  rtl: boolean
  enabled: boolean
  isDefault: boolean
  isOwn: boolean
  canDelete: boolean
  deleteReason: 'default' | 'locales' | 'names' | null
}

const ENGLISH: LanguageSlot = {
  languageId: 11,
  rowKey: '11',
  code: 'en',
  nativeName: 'English',
  rtl: false,
  enabled: true,
  isDefault: true,
  isOwn: false,
  canDelete: false,
  deleteReason: 'default',
}

const OWN: LanguageSlot = {
  languageId: 46,
  rowKey: '46',
  code: 'qx',
  nativeName: 'Qx own tongue',
  rtl: true,
  enabled: false,
  isDefault: false,
  isOwn: true,
  canDelete: true,
  deleteReason: null,
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

function context(
  rows: LanguageSlot[] = [ENGLISH, OWN],
  actions = {} as ActionLifecycle,
) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.I18N_LANGUAGES)
  const windowListeners = new Set<Listener>()
  const deltaListeners = new Set<Listener>()
  const focused: string[] = []
  const tableRows = rows.map((language) => ({
    rowKey: language.rowKey,
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
    sendTableRowFocus(_page: string, _table: string, code: string): boolean {
      focused.push(code)
      return true
    },
    on(event: string, listener: Listener): () => void {
      if (event === 'tableViewportDelta') {
        deltaListeners.add(listener)
        return () => {
          deltaListeners.delete(listener)
        }
      }
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
    actions,
  }

  const delta = (language: LanguageSlot, removed = false): void => {
    for (const listener of deltaListeners)
      listener({
        data: {
          page: HilosPages.I18N_LANGUAGES,
          tableKey: HILOS_I18N_LANGUAGES_TABLE,
          kind: removed ? 'row_removed' : 'row_updated',
          rowKey: language.rowKey,
          ...(removed
            ? { reason: 'deleted' }
            : { row: { rowKey: language.rowKey, slots: { language } } }),
        },
      })
  }
  return { context: ctx, scope, delta, focused }
}

describe('HilosI18nLanguagesPage', () => {
  it.each(['default', 'locales', 'names'] as const)(
    'shows the accessible %s refusal without hover',
    async (deleteReason) => {
      const c = context([{ ...OWN, canDelete: false, deleteReason }])
      const view = mount(HilosI18nLanguagesPage, {
        props: { context: c.context },
        global: {
          provide: { [hilosRouterKey as symbol]: router() },
          stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
        },
      })
      await nextTick()
      const opener = view.get('[data-id="i18n-languages-delete-qx"]')
      expect((opener.element as HTMLButtonElement).disabled).toBe(true)
      expect(opener.attributes('aria-label')).toBe(
        'Delete language Qx own tongue',
      )
      expect(opener.attributes('aria-describedby')).toBe(
        view
          .get('[data-id="i18n-languages-delete-reason-qx"]')
          .attributes('id'),
      )
      expect(
        view.get('[data-id="i18n-languages-delete-reason-qx"]').text(),
      ).toBe(HILOS_I18N_LANGUAGE_DELETE_COPY.reasons[deleteReason])
      await opener.trigger('click')
      expect(c.focused).toEqual([])
      expect(
        document.querySelector('[data-id="i18n-language-delete-confirm"]'),
      ).toBeNull()
      view.unmount()
    },
  )

  it('opens the fresh focused row and leaves the address and membership to their normal models on success', async () => {
    let settle: (result: ActionResult) => void = () => {}
    const done = new Promise<ActionResult>((resolve) => {
      settle = resolve
    })
    const dispatch = vi.fn(() => ({
      requestId: 'delete-row',
      loading: createSignal(false),
      done,
    }))
    const c = context([OWN], { dispatch } as unknown as ActionLifecycle)
    const navigation = router()
    const navigate = vi.spyOn(navigation, 'navigate')
    const view = mount(HilosI18nLanguagesPage, {
      props: { context: c.context },
      attachTo: document.body,
      global: {
        provide: { [hilosRouterKey as symbol]: navigation },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()
    const opener = view.get('[data-id="i18n-languages-delete-qx"]')
    c.delta({ ...OWN, nativeName: 'Fresh name' })
    await opener.trigger('click')
    await nextTick()
    expect(c.focused).toEqual(['46'])
    expect(document.body.textContent).toContain('Delete language · Fresh name')
    document
      .querySelector<HTMLButtonElement>(
        '[data-id="i18n-language-delete-confirm"]',
      )!
      .click()
    expect(dispatch).toHaveBeenCalledWith(
      HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION,
      { languageId: 46 },
    )
    expect(view.find('[data-id="hilos-table-row-46"]').exists()).toBe(true)
    c.delta(OWN, true)
    await nextTick()
    expect(
      document.querySelector('[data-id="i18n-language-delete-confirm"]'),
    ).not.toBeNull()
    expect(navigate).not.toHaveBeenCalled()
    settle({ message: 'Language deleted.' })
    await nextTick()
    await nextTick()
    await nextTick()
    expect(
      document.querySelector('[data-id="i18n-language-delete-confirm"]'),
    ).toBeNull()
    expect(c.focused).toEqual(['46', ''])
    expect(navigate).not.toHaveBeenCalled()
    view.unmount()
  })

  it('declines to open if a stale click reaches a row already removed', async () => {
    const c = context([OWN])
    const view = mount(HilosI18nLanguagesPage, {
      props: { context: c.context },
      global: {
        provide: { [hilosRouterKey as symbol]: router() },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()
    const opener = view.get('[data-id="i18n-languages-delete-qx"]')
    c.delta(OWN, true)
    await opener.trigger('click')
    await nextTick()
    expect(
      document.querySelector('[data-id="i18n-language-delete-confirm"]'),
    ).toBeNull()
    expect(c.focused).toEqual([])
    view.unmount()
  })

  it('lets an admin-view reader open a free language with confirmation disabled', async () => {
    const c = context([OWN])
    const view = mount(HilosI18nLanguagesPage, {
      props: { context: c.context },
      global: {
        provide: {
          [hilosRouterKey as symbol]: router(),
          [hilosAdminViewModeKey as symbol]: ref(true),
        },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()
    const opener = view.get('[data-id="i18n-languages-delete-qx"]')
    expect((opener.element as HTMLButtonElement).disabled).toBe(false)
    await opener.trigger('click')
    await nextTick()
    expect(
      document.querySelector<HTMLButtonElement>(
        '[data-id="i18n-language-delete-confirm"]',
      )!.disabled,
    ).toBe(true)
    view.unmount()
  })

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
    const eye = view.get('[data-id="i18n-languages-view-en"]')
    expect(eye.attributes('aria-label')).toBe('View language: nothing to edit')
    await eye.trigger('click')
    expect(
      document.querySelector('[data-id="i18n-language-edit-title"]')
        ?.textContent,
    ).toContain('Language · English')
    expect(
      document.querySelector('[data-id="i18n-language-edit-submit"]'),
    ).toBeNull()
    document
      .querySelector<HTMLButtonElement>('[data-id="i18n-language-edit-close"]')
      ?.click()
    await nextTick()
    const pencil = view.get('[data-id="i18n-languages-edit-qx"]')
    expect(pencil.attributes('aria-label')).toBe('Edit language')
    await pencil.trigger('click')
    expect(
      document.querySelector('[data-id="i18n-language-edit-title"]')
        ?.textContent,
    ).toContain('Edit language · Qx own tongue')
    expect(
      document
        .querySelector('[data-id="i18n-language-edit-plate"]')
        ?.getAttribute('data-kind'),
    ).toBe('own')
    view.unmount()
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
      "The framework's built-in catalog holds 3 ISO 639-1 codes with their own names and writing direction. A language from it is added by its code alone — the rest is filled in — and is refreshed whenever a framework update changes the catalog, but only while that language is switched off. A code that is not in the catalog can still be added, but keeping that language up is on you.",
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

  it('opens Add language from the table bar and reads the live page catalog', async () => {
    const c = context()
    const view = mount(HilosI18nLanguagesPage, {
      props: { context: c.context },
      attachTo: document.body,
      global: {
        provide: { [hilosRouterKey as symbol]: router() },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()
    const open = view.get('[data-id="hilos-table-main-action"]')
    expect(open.text()).toBe('Add language')
    expect((open.element as HTMLButtonElement).disabled).toBe(false)
    await open.trigger('click')
    const input = document.querySelector<HTMLInputElement>(
      '[data-id="i18n-language-add-code"]',
    )!
    input.value = 'fr'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()
    expect(
      (
        document.querySelector(
          '[data-id="i18n-language-add-submit"]',
        ) as HTMLButtonElement
      ).disabled,
    ).toBe(true)
    c.scope.data.set(BUILT_IN_LANGUAGES_DATA, [
      { code: 'fr', nativeName: 'Français', rtl: false },
    ])
    await nextTick()
    expect(
      document
        .querySelector('[data-id="i18n-language-add-plate"]')
        ?.getAttribute('data-kind'),
    ).toBe('known')
    input.value = 'f'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()
    input.value = 'fr'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()
    expect(
      (
        document.querySelector(
          '[data-id="i18n-language-add-native-name"]',
        ) as HTMLInputElement
      ).value,
    ).toBe('Français')
    expect(
      (
        document.querySelector(
          '[data-id="i18n-language-add-submit"]',
        ) as HTMLButtonElement
      ).disabled,
    ).toBe(false)
    view.unmount()
    expect(
      document.querySelector('[data-id="i18n-language-add-code"]'),
    ).toBeNull()
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
