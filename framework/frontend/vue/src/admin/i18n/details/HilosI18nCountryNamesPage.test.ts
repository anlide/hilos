import { mount, type VueWrapper } from '@vue/test-utils'
import {
  ActionError,
  createSignal,
  bindAdminAccess,
  HilosPages,
  ScopeManager,
  type ActionResult,
  type ActionLifecycle,
  type HilosConnection,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'
import {
  COUNTRY_CARD_DATA,
  HILOS_I18N_COUNTRY_NAMES_FILTER,
  HILOS_I18N_COUNTRY_NAMES_TABLE,
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
} from '@hilos/core/admin/i18n'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import HilosI18nCountryNamesPage from './HilosI18nCountryNamesPage.vue'
import HilosI18nCountryNameDelete from './HilosI18nCountryNameDelete.vue'
import HilosI18nCountryNameWindow from './HilosI18nCountryNameWindow.vue'
import HilosI18nNamesTable from './HilosI18nNamesTable.vue'
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

function makeRouter(pageLoading = createSignal(false)) {
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
    pageIdentity: createSignal(undefined),
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
  } = {},
) {
  const pageLoadingSignal = createSignal(options.pageLoading ?? false)
  const { navigation, currentRoute } = makeRouter(pageLoadingSignal)
  const { context, scopes, receivedFilters } = seededContext(options.rows ?? [])
  const scope = scopes.openPage(HilosPages.I18N_COUNTRY_NAMES)
  if (
    options.initialCardValue !== undefined &&
    options.initialCardValue !== null
  ) {
    scope.data.set(COUNTRY_CARD_DATA, options.initialCardValue)
  }
  const view = mount(HilosI18nCountryNamesPage, {
    props: { context },
    global: {
      provide: { [hilosRouterKey as symbol]: navigation },
      stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
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
  }
}

describe('HilosI18nCountryNamesPage', () => {
  it('shows a loading skeleton while the card is loading, with no header or table', async () => {
    const h = harness({ pageLoading: true })
    expect(h.view.find('[aria-label="Loading country"]').exists()).toBe(true)
    expect(h.view.find('[data-id="country-card-header"]').exists()).toBe(false)
    expect(h.view.find('[data-id="country-names"]').exists()).toBe(false)
  })

  it('renders the header, tabs, and the names table filtered by country=us', async () => {
    const h = harness({
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

    expect(h.view.find('[data-id="country-card-title"]').text()).toBe(
      'United States',
    )
    expect(h.view.find('[data-id="country-card-code-badge"]').text()).toBe('us')

    const namesTab = h.view.find(
      '[data-id="country-card-tab-hilos_i18n_country_names"]',
    )
    expect(namesTab.attributes('aria-current')).toBe('page')
    expect(namesTab.attributes('href')).toBe('/hilos/i18n/countries/us/names')

    const mainTab = h.view.find(
      '[data-id="country-card-tab-hilos_i18n_country"]',
    )
    expect(mainTab.attributes('href')).toBe('/hilos/i18n/countries/us')
    expect(mainTab.attributes('aria-current')).toBeUndefined()

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

  it('updates the header title on live card changes', async () => {
    const h = harness({ initialCardValue: initialCard })
    await nextTick()
    expect(h.view.find('[data-id="country-card-title"]').text()).toBe(
      'United States',
    )

    h.scope.data.set(COUNTRY_CARD_DATA, {
      ...initialCard,
      summary: { ...initialCard.summary, name: 'USA' },
    })
    await nextTick()
    expect(h.view.find('[data-id="country-card-title"]').text()).toBe('USA')
  })

  it('shows unavailable alert with neither header nor table when card is cleared after deletion', async () => {
    const h = harness({ initialCardValue: initialCard })
    await nextTick()
    expect(h.view.find('[data-id="country-card-header"]').exists()).toBe(true)

    h.scope.data.set(COUNTRY_CARD_DATA, null)
    await nextTick()

    const alert = h.view.find('[data-id="country-names-unavailable"]')
    expect(alert.exists()).toBe(true)
    expect(alert.text()).toBe('Country details are no longer available.')
    expect(h.view.find('[data-id="country-card-header"]').exists()).toBe(false)
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
  const router = { ...makeRouter().navigation, currentRoute: route }
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
