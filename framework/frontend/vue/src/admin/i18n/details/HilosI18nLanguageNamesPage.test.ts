import { mount, type VueWrapper } from '@vue/test-utils'
import {
  createSignal,
  bindAdminAccess,
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
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import HilosI18nLanguageNamesPage from './HilosI18nLanguageNamesPage.vue'
import HilosI18nLanguageNameDelete from './HilosI18nLanguageNameDelete.vue'
import HilosI18nNamesTable from './HilosI18nNamesTable.vue'
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
    countrylessLocale: 'missing',
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
    sendTableRowFocus: vi.fn(),
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
  const scope = scopes.openPage(HilosPages.I18N_LANGUAGE_NAMES)
  scope.data.set(LANGUAGE_CARD_DATA, testCard)
  const context = makeContext(scopes)
  const route = createSignal<PageRouteMatch>({
    page: HilosPages.I18N_LANGUAGE_NAMES,
    params: { languageCode: 'qx' },
    admin: true,
  })
  const router = { ...makeRouter(), currentRoute: route }
  const wrapper = mount(HilosI18nLanguageNamesPage, {
    props: { context },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })
  mounted.push(wrapper)
  const controller = wrapper
    .getComponent(HilosI18nNamesTable)
    .props('controller')
  const slot = {
    code: 'qw',
    nativeName: 'Writing',
    name: 'Original',
    corrections: [
      {
        localeCode: 'qw-GB',
        countryCode: 'gb',
        countryName: 'UK',
        name: 'Correction',
      },
      { localeCode: 'qw-US', countryCode: 'us', countryName: 'US', name: '' },
      {
        localeCode: 'qw-CA',
        countryCode: 'ca',
        countryName: 'Canada',
        name: null,
      },
    ],
  }
  controller.ingestWindow(
    [
      { rowKey: 'qw', slots: { language: slot } },
      {
        rowKey: 'fr',
        slots: {
          language: {
            code: 'fr',
            nativeName: 'French',
            name: null,
            corrections: [],
          },
        },
      },
      {
        rowKey: 'es',
        slots: {
          language: {
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
  const modal = () => wrapper.getComponent(HilosI18nLanguageNameDelete)
  return { wrapper, scope, context, route, controller, slot, modal }
}

async function settlePage(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}

describe('language name deletion from the page', () => {
  it('owns both slots, includes saved empty names, and omits absent values', async () => {
    const s = deletionPage()
    s.controller.expandRow('qw', true)
    await settlePage()
    for (const key of ['qw', 'qw-GB', 'qw-US', 'es']) {
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
    for (const key of ['fr', 'qw-CA'])
      expect(
        s.wrapper.find(`[data-id="i18n-names-delete-${key}"]`).exists(),
      ).toBe(false)
    expect(
      s.wrapper
        .get('tr [data-id="i18n-names-delete-qw-US"]')
        .attributes('aria-label'),
    ).toBe('Remove locale correction qw-US')
  })

  it('awaits focus, freezes fresh values, follows source updates and releases on close', async () => {
    const s = deletionPage()
    await settlePage()
    const focus = vi.spyOn(s.controller, 'focusRow')
    const button = s.wrapper.get('tr [data-id="i18n-names-delete-qw"]')
    ;(button.element as HTMLButtonElement).click()
    expect(focus).toHaveBeenCalledWith('qw')
    expect(s.modal().props('modelValue')).toBe(false)
    // The fresh focus can change between the click and the awaited continuation.
    s.controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'qw',
      row: { rowKey: 'qw', slots: { language: { ...s.slot, name: 'Fresh' } } },
    })
    await settlePage()
    expect(s.modal().props('target')).toMatchObject({
      languageCode: 'qx',
      inLanguageCode: 'qw',
      expectedName: 'Fresh',
      kind: 'base',
    })
    s.controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'qw',
      row: { rowKey: 'qw', slots: { language: { ...s.slot, name: 'Newer' } } },
    })
    await settlePage()
    expect(s.modal().props('target')?.expectedName).toBe('Fresh')
    expect(s.modal().props('source')?.name).toBe('Newer')
    document
      .querySelector<HTMLButtonElement>(
        '[data-id="i18n-language-name-delete-cancel"]',
      )!
      .click()
    await settlePage()
    expect(s.controller.focusedRow.get()).toBeUndefined()
    expect(s.context.connection.sendTableRowFocus).toHaveBeenLastCalledWith(
      HilosPages.I18N_LANGUAGE_NAMES,
      'hilosI18nLanguageNames',
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
    s.controller.expandRow('qw', true)
    await settlePage()
    await s.wrapper
      .get('tr [data-id="i18n-names-delete-qw-US"]')
      .trigger('click')
    await settlePage()
    expect(s.modal().props('target')).toMatchObject({
      kind: 'override',
      localeCode: 'qw-US',
      expectedName: '',
    })
  })

  it.each([false, true])(
    'closes on a route change, including while focus is pending (%s)',
    async (pending) => {
      const s = deletionPage()
      await settlePage()
      ;(
        s.wrapper.get('tr [data-id="i18n-names-delete-qw"]')
          .element as HTMLButtonElement
      ).click()
      if (!pending) {
        await settlePage()
        expect(s.modal().props('modelValue')).toBe(true)
      }
      s.route.set({
        page: HilosPages.I18N_LANGUAGE_NAMES,
        params: { languageCode: 'en' },
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
    await s.wrapper.get('tr [data-id="i18n-names-delete-qw"]').trigger('click')
    await settlePage()
    s.scope.data.set(LANGUAGE_CARD_DATA, [])
    await settlePage()
    expect(s.wrapper.findComponent(HilosI18nNamesTable).exists()).toBe(false)
    expect(s.modal().props('modelValue')).toBe(true)
    expect(
      document.querySelector('[data-id="i18n-language-name-delete-notice"]')
        ?.textContent,
    ).toContain('The name no longer exists.')
    expect(
      document.querySelector<HTMLButtonElement>(
        '[data-id="i18n-language-name-delete-confirm"]',
      )?.disabled,
    ).toBe(true)
  })

  it('disables every trash for the viewer through the admin shell', async () => {
    const s = deletionPage(true)
    s.controller.expandRow('qw', true)
    await settlePage()
    for (const button of s.wrapper.findAll('[data-id^="i18n-names-delete-"]')) {
      expect((button.element as HTMLButtonElement).disabled).toBe(true)
      expect(button.attributes('aria-describedby')).toBeTruthy()
    }
    expect(s.modal().props('modelValue')).toBe(false)
  })
})
