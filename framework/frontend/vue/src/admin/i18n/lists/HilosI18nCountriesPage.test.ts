import { enableAutoUnmount, mount } from '@vue/test-utils'
import {
  BUILT_IN_CATALOG_DATA,
  createSignal,
  HILOS_I18N_COUNTRIES_TABLE,
  HilosPages,
  ScopeManager,
  hilosToasts,
  type ActionLifecycle,
  type ActionResult,
  type HilosConnection,
  type HilosI18nCountryContext,
  type HilosI18nCountryLocaleChoice,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'

import HilosI18nCountriesPage from './HilosI18nCountriesPage.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'
import { hilosAdminViewModeKey } from '../../../hilosAdminViewMode.js'

enableAutoUnmount(afterEach)
afterEach(() => hilosToasts.clear())

interface CountrySlot {
  code: string
  name: string | null
  currencySymbol: string
  currencyCode: string
  defaultLocaleCode: string | null
  enabled: boolean
  isOwn: boolean
  locales: readonly HilosI18nCountryLocaleChoice[]
  catalogDefaultLocaleCode: string | null
  canDelete: boolean
  deleteReason: 'known' | 'locales' | 'names' | null
}

const UNITED_STATES: CountrySlot = {
  code: 'us',
  name: 'United States',
  currencySymbol: '$',
  currencyCode: 'USD',
  defaultLocaleCode: null,
  enabled: false,
  isOwn: false,
  locales: [],
  catalogDefaultLocaleCode: 'en-US',
  canDelete: false,
  deleteReason: 'known',
}

const OWN: CountrySlot = {
  code: 'qx',
  name: null,
  currencySymbol: '¤',
  currencyCode: 'XQX',
  defaultLocaleCode: 'en-QX',
  enabled: true,
  isOwn: true,
  locales: [],
  catalogDefaultLocaleCode: null,
  canDelete: true,
  deleteReason: null,
}

type Listener = (signal: { data: unknown }) => void

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.I18N_COUNTRIES,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/i18n/countries'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: (page, params) =>
      page === HilosPages.I18N_COUNTRY && params?.countryCode === 'us'
        ? '/hilos/i18n/countries/us'
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
  rows: CountrySlot[] = [UNITED_STATES, OWN],
  actions = {} as ActionLifecycle,
): HilosI18nCountryContext & {
  scope: ReturnType<ScopeManager['openPage']>
  focused: string[]
  delta(country: CountrySlot, removed?: boolean): void
} {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.I18N_COUNTRIES)
  const windowListeners = new Set<Listener>()
  const deltaListeners = new Set<Listener>()
  const focused: string[] = []
  const tableRows = rows.map((country) => ({
    rowKey: country.code,
    slots: { country },
  }))
  const serveWindow = (): void => {
    const data = {
      page: HilosPages.I18N_COUNTRIES,
      tableKey: HILOS_I18N_COUNTRIES_TABLE,
      rows: tableRows,
      totalCount: tableRows.length,
      totalExact: true,
      firstAnchor: null,
      lastAnchor: null,
      offset: 0,
      limit: 25,
    }
    for (const listener of windowListeners) {
      listener({ data })
    }
  }
  const connection = {
    registerTableWindow(tableKey: string): void {
      if (tableKey === HILOS_I18N_COUNTRIES_TABLE) {
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

  return {
    connection,
    scopes,
    scope,
    actions,
    focused,
    delta(country, removed = false) {
      for (const listener of deltaListeners)
        listener({
          data: {
            page: HilosPages.I18N_COUNTRIES,
            tableKey: HILOS_I18N_COUNTRIES_TABLE,
            kind: removed ? 'row_removed' : 'row_updated',
            rowKey: country.code,
            ...(removed
              ? { reason: 'deleted' }
              : { row: { rowKey: country.code, slots: { country } } }),
          },
        })
    },
  }
}

describe('HilosI18nCountriesPage', () => {
  it('links a built card, shows the code for an empty name, and draws the marks', async () => {
    const view = mount(HilosI18nCountriesPage, {
      props: { context: context() },
      global: {
        provide: { [hilosRouterKey as symbol]: router() },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()

    const known = view.get('[data-id="i18n-countries-link-us"]')
    expect(known.text()).toBe('us')
    expect(known.attributes('href') ?? known.attributes('to')).toContain(
      '/hilos/i18n/countries/us',
    )
    expect(known.classes()).toContain('link-secondary')
    expect(known.classes()).toContain('text-uppercase')
    expect(view.text()).toContain('United States')
    expect(view.find('[data-id="i18n-countries-own-us"]').exists()).toBe(false)
    expect(
      view.get('[data-id="i18n-countries-locale-none-us"]').text(),
    ).toContain('Not chosen')
    expect(view.text()).toContain('$')
    expect(view.text()).toContain('USD')
    expect(
      (
        view.get('[data-id="i18n-countries-enabled-us"]')
          .element as HTMLInputElement
      ).checked,
    ).toBe(false)
    expect(
      (
        view.get('[data-id="i18n-countries-enabled-us"]')
          .element as HTMLInputElement
      ).disabled,
    ).toBe(true)
    expect(view.html()).toContain('text-body-secondary')

    const own = view.get('[data-id="i18n-countries-link-qx"]')
    expect(own.element.tagName).toBe('SPAN')
    expect(own.classes()).not.toContain('link-secondary')
    expect(view.text()).toContain('QX')
    expect(view.get('[data-id="i18n-countries-own-qx"]').text()).toContain(
      'Custom country',
    )
    expect(
      view.get('[data-id="i18n-countries-own-qx"]').attributes('title'),
    ).toBe('Custom country')
    expect(
      view.find('[data-id="i18n-countries-locale-none-qx"]').exists(),
    ).toBe(false)
    expect(view.text()).toContain('en-QX')
    expect(
      (
        view.get('[data-id="i18n-countries-enabled-qx"]')
          .element as HTMLInputElement
      ).checked,
    ).toBe(true)
    expect(
      view.get('[data-id="i18n-countries-delete-qx"]').attributes('aria-label'),
    ).toBe('Delete country QX')
    expect(view.get('[data-id="hilos-table-main-action"]').text()).toBe(
      'Add country',
    )
  })

  it('renders the catalog callout with counts and omits it when data is absent', async () => {
    const c1 = context()
    c1.scope.data.set(BUILT_IN_CATALOG_DATA, {
      languageCount: 3,
      countryCount: 4,
    })
    const viewWithData = mount(HilosI18nCountriesPage, {
      props: { context: c1 },
      global: {
        provide: { [hilosRouterKey as symbol]: router() },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()

    const catalog = viewWithData.get('[data-id="i18n-countries-catalog"]')
    expect(catalog.get('h2').text()).toBe(
      'The system knows 3 languages and 4 countries on its own',
    )
    expect(catalog.get('p').text()).toBe(
      "The framework's built-in catalog holds 4 countries with their currency and their names in its 3 languages; each of them arrives on its own, switched off. Whenever a framework update changes the catalog, a country's currency is refreshed while that country is switched off, and its names wherever nobody has edited them. A code that is not in the catalog can still be added as a country of your own, but keeping it up is on you.",
    )

    const c2 = context()
    const viewWithoutData = mount(HilosI18nCountriesPage, {
      props: { context: c2 },
      global: {
        provide: { [hilosRouterKey as symbol]: router() },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()
    expect(
      viewWithoutData.find('[data-id="i18n-countries-catalog"]').exists(),
    ).toBe(false)
  })

  it('renders the legend row even when the countries table is empty', async () => {
    const c = context([])
    const view = mount(HilosI18nCountriesPage, {
      props: { context: c },
      global: {
        provide: { [hilosRouterKey as symbol]: router() },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()

    const legend = view.get('[data-id="i18n-countries-legend"]')
    expect(legend.get('h2').text()).toContain('What the marks mean')

    const ownRow = view.get('[data-id="i18n-countries-legend-own"]')
    expect(ownRow.get('dt').text()).toContain('Custom country')
    expect(ownRow.get('dd').text()).toBe(
      'its code is not in the built-in catalog: nothing refreshes it, keeping it up is yours',
    )
    expect(legend.findAll('dt')).toHaveLength(4)
    expect(legend.get('[data-id="i18n-countries-legend-view"] dd').text()).toBe(
      'the country is switched on and frozen: nobody changes a live row',
    )
    expect(legend.get('[data-id="i18n-countries-legend-edit"] dd').text()).toBe(
      'the country is switched off: edit it freely',
    )
    expect(
      legend.get('[data-id="i18n-countries-legend-deleteBlocked"]').text(),
    ).toContain('Deletion blocked')
  })

  it('opens pencil and eye windows for a viewer and follows the row switch live', async () => {
    const c = context()
    const view = mount(HilosI18nCountriesPage, {
      props: { context: c },
      attachTo: document.body,
      global: {
        provide: {
          [hilosRouterKey as symbol]: router(),
          [hilosAdminViewModeKey as symbol]: ref(true),
        },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()
    const pencil = view.get('[data-id="i18n-countries-edit-us"]')
    expect(pencil.attributes('aria-label')).toBe('Edit country')
    expect((pencil.element as HTMLButtonElement).disabled).toBe(false)
    expect(pencil.find('.bi-pencil').exists()).toBe(true)
    await pencil.trigger('click')
    expect(c.focused).toContain('us')
    expect(
      document.querySelector('[data-id="i18n-country-title"]')?.textContent,
    ).toContain('Edit country · United States')
    expect(
      document.querySelector<HTMLButtonElement>('[data-id="i18n-country-save"]')
        ?.disabled,
    ).toBe(true)
    document
      .querySelector<HTMLButtonElement>('[data-id="i18n-country-cancel"]')
      ?.click()
    await nextTick()
    const eye = view.get('[data-id="i18n-countries-view-qx"]')
    expect(eye.attributes('aria-label')).toBe('View country: nothing to edit')
    expect(eye.find('.bi-eye').exists()).toBe(true)
    await eye.trigger('click')
    expect(
      document.querySelector('[data-id="i18n-country-title"]')?.textContent,
    ).toContain('Country · QX')
    document
      .querySelector<HTMLButtonElement>('[data-id="i18n-country-close"]')
      ?.click()
    await nextTick()
    c.delta({ ...UNITED_STATES, enabled: true })
    await nextTick()
    expect(view.find('[data-id="i18n-countries-edit-us"]').exists()).toBe(false)
    expect(view.find('[data-id="i18n-countries-view-us"]').exists()).toBe(true)
  })

  it('opens the country creation form from the table main action', async () => {
    const c = context([])
    const view = mount(HilosI18nCountriesPage, {
      props: { context: c },
      global: {
        provide: { [hilosRouterKey as symbol]: router() },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()

    await view.get('[data-id="hilos-table-main-action"]').trigger('click')
    expect(
      document.querySelector('[data-id="i18n-country-add-code"]'),
    ).not.toBeNull()
    view.unmount()
  })

  it.each(['known', 'locales', 'names'] as const)(
    'explains a blocked %s basket to keyboard readers without sending or opening',
    async (deleteReason) => {
      const dispatch = vi.fn()
      const c = context([{ ...OWN, canDelete: false, deleteReason }], {
        dispatch,
      } as unknown as ActionLifecycle)
      const view = mount(HilosI18nCountriesPage, {
        props: { context: c },
        global: {
          provide: { [hilosRouterKey as symbol]: router() },
          stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
        },
      })
      await nextTick()
      const buttons = view.findAll('[data-id="i18n-countries-delete-qx"]')
      const ids = buttons.map((button) => button.attributes('aria-describedby'))
      expect(new Set(ids).size).toBe(buttons.length)
      for (const button of buttons) {
        expect(button.attributes('aria-disabled')).toBe('true')
        expect((button.element as HTMLButtonElement).disabled).toBe(false)
        const reason = view.find(
          `[id="${button.attributes('aria-describedby')}"]`,
        )
        expect(reason.text()).toContain(
          deleteReason === 'known'
            ? 'built-in catalog'
            : `${deleteReason} refer`,
        )
        await button.trigger('click')
      }
      expect(c.focused).toEqual([])
      expect(dispatch).not.toHaveBeenCalled()
      expect(
        document.querySelector('[data-id="i18n-country-delete-window"]'),
      ).toBeNull()
    },
  )

  it('opens the fresh focused row, survives its own deletion echo and releases only after success', async () => {
    let settle: (result: ActionResult) => void = () => {}
    const done = new Promise<ActionResult>((resolve) => {
      settle = resolve
    })
    const dispatch = vi.fn(() => ({
      requestId: 'delete',
      loading: createSignal(false),
      done,
    }))
    const c = context([OWN], { dispatch } as unknown as ActionLifecycle)
    const navigation = router()
    const navigate = vi.spyOn(navigation, 'navigate')
    const view = mount(HilosI18nCountriesPage, {
      props: { context: c },
      attachTo: document.body,
      global: {
        provide: { [hilosRouterKey as symbol]: navigation },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()
    const opener = view.get('[data-id="i18n-countries-delete-qx"]')
    c.delta({ ...OWN, name: 'Fresh name' })
    await opener.trigger('click')
    expect(c.focused).toEqual(['qx'])
    expect(document.body.textContent).toContain('Delete country · Fresh name')
    document
      .querySelector<HTMLButtonElement>(
        '[data-id="i18n-country-delete-confirm"]',
      )!
      .click()
    await nextTick()
    expect(dispatch).toHaveBeenCalledWith('hilos_i18n_country_row_delete', {
      countryCode: 'qx',
    })
    c.delta(OWN, true)
    await nextTick()
    expect(
      document.querySelector('[data-id="i18n-country-delete-window"]'),
    ).not.toBeNull()
    expect(document.body.textContent).not.toContain('Deleted elsewhere.')
    expect(c.focused).toEqual(['qx'])
    settle({ message: 'Country deleted.' })
    await nextTick()
    await nextTick()
    await nextTick()
    expect(
      document.querySelector('[data-id="i18n-country-delete-window"]'),
    ).toBeNull()
    expect(c.focused).toEqual(['qx', ''])
    expect(navigate).not.toHaveBeenCalled()
    expect(
      hilosToasts.toasts
        .get()
        .some((toast) => toast.message === 'Country deleted.'),
    ).toBe(true)
    view.unmount()
  })

  it('does not open a pending deleted or newly blocked row, and releases on cancel and disposal', async () => {
    const c = context([OWN])
    const view = mount(HilosI18nCountriesPage, {
      props: { context: c },
      attachTo: document.body,
      global: {
        provide: { [hilosRouterKey as symbol]: router() },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()
    const opener = view.get('[data-id="i18n-countries-delete-qx"]')
    c.delta({ ...OWN, canDelete: false, deleteReason: 'names' })
    await opener.trigger('click')
    expect(
      document.querySelector('[data-id="i18n-country-delete-window"]'),
    ).toBeNull()
    expect(c.focused).toEqual(['qx', ''])
    c.delta(OWN)
    await nextTick()
    await opener.trigger('click')
    document
      .querySelector<HTMLButtonElement>(
        '[data-id="i18n-country-delete-cancel"]',
      )!
      .click()
    await nextTick()
    await nextTick()
    expect(c.focused).toEqual(['qx', '', 'qx', ''])
    await opener.trigger('click')
    view.unmount()
    expect(c.focused).toEqual(['qx', '', 'qx', '', 'qx', ''])

    const c2 = context([OWN])
    const view2 = mount(HilosI18nCountriesPage, {
      props: { context: c2 },
      global: {
        provide: { [hilosRouterKey as symbol]: router() },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()
    c2.delta(OWN, true)
    await view2.get('[data-id="i18n-countries-delete-qx"]').trigger('click')
    expect(c2.focused).toEqual([])
    expect(
      document.querySelector('[data-id="i18n-country-delete-window"]'),
    ).toBeNull()
  })

  it('lets a viewer open a free country and cancel while confirmation is disabled', async () => {
    const dispatch = vi.fn()
    const c = context([OWN], { dispatch } as unknown as ActionLifecycle)
    const view = mount(HilosI18nCountriesPage, {
      props: { context: c },
      attachTo: document.body,
      global: {
        provide: {
          [hilosRouterKey as symbol]: router(),
          [hilosAdminViewModeKey as symbol]: ref(true),
        },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    await nextTick()
    await view.get('[data-id="i18n-countries-delete-qx"]').trigger('click')
    const confirm = document.querySelector<HTMLButtonElement>(
      '[data-id="i18n-country-delete-confirm"]',
    )!
    expect(confirm.disabled).toBe(true)
    confirm.click()
    expect(dispatch).not.toHaveBeenCalled()
    document
      .querySelector<HTMLButtonElement>(
        '[data-id="i18n-country-delete-cancel"]',
      )!
      .click()
    await nextTick()
    await nextTick()
    expect(c.focused).toEqual(['qx', ''])
  })
})
