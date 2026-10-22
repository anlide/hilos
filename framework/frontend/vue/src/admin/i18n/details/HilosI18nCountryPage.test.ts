import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import {
  COUNTRY_CARD_DATA,
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
} from '@hilos/core/admin/i18n'
import {
  createSignal,
  bindAdminAccess,
  HilosPages,
  pageIdentity,
  ScopeManager,
  ActionError,
  hilosToasts,
  type ActionLifecycle,
  type ActionResult,
  type HilosConnection,
  type HilosRouter,
  type PageRouteMatch,
  type Scope,
} from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { markRaw, nextTick, ref } from 'vue'

import HilosI18nCountryPage from './HilosI18nCountryPage.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'
import { hilosAdminViewModeKey } from '../../../hilosAdminViewMode.js'

enableAutoUnmount(afterEach)
const releases: Array<() => void> = []
afterEach(() => {
  hilosToasts.clear()
  for (const release of releases.splice(0)) release()
})

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

/** The country owner as the catalog answers it: no icon, its own lead. */
const COUNTRY_OWNER = {
  page: HilosPages.I18N_COUNTRY,
  label: 'Country',
  lead: 'A single country: currency, default locale, and state.',
  icon: null,
}

/**
 * Answer the way either country tab does: its own label, the country as the
 * owner, the chain through Countries ending at the country, the Overview · Names
 * row and no child cards.
 *
 * @param scope The page scope the answer lands in.
 * @param label The subscribing page's own catalog label.
 */
function answerAsCountry(scope: Scope, label: string): void {
  scope.data.set('pageLabel', label)
  scope.data.set('pageHeadOwner', COUNTRY_OWNER)
  scope.data.set('pageBreadcrumb', [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.I18N, label: 'Internationalization' },
    { page: HilosPages.I18N_COUNTRIES, label: 'Countries' },
    { page: HilosPages.I18N_COUNTRY, label: 'Country' },
  ])
  scope.data.set('pageTabs', [
    { page: HilosPages.I18N_COUNTRY, label: 'Overview' },
    { page: HilosPages.I18N_COUNTRY_NAMES, label: 'Names' },
  ])
  scope.data.set('pageChildren', [])
}

/** The data-ids the manual country header carried; none of them stays. */
const RETIRED_HEADER = [
  'country-card-header',
  'country-card-title',
  'country-card-tabs',
  'country-card-code-badge',
  'country-card-own',
  'country-card-line',
]

/**
 * Whether anything of the manual country header is left on the page, its tab
 * links included.
 *
 * @param view The mounted page.
 */
function retiredHeaderLeft(view: VueWrapper): boolean {
  return (
    RETIRED_HEADER.some((id) => view.find(`[data-id="${id}"]`).exists()) ||
    view.find('[data-id^="country-card-tab-"]').exists()
  )
}

/** The route resolver gives unbuilt child pages no path. */
function router(scopes: ScopeManager, childrenBuilt = false) {
  const pageLoading = createSignal(true)
  const navigation: HilosRouter = {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.I18N_COUNTRY,
      params: { countryCode: 'us' },
      admin: true,
    }),
    currentPath: createSignal('/hilos/i18n/countries/us'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading,
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: (page, params) => {
      if (page === HilosPages.I18N_COUNTRIES) return '/hilos/i18n/countries'
      if (!childrenBuilt && page !== HilosPages.I18N_COUNTRY) return undefined
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
  return { navigation, pageLoading }
}

function harness(
  childrenBuilt = false,
  actions = {} as ActionLifecycle,
  viewer = false,
  options: {
    realShell?: boolean
    initialCardValue?: HilosI18nCountryCard
  } = {},
) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.I18N_COUNTRY)
  if (options.initialCardValue !== undefined) {
    scope.data.set(COUNTRY_CARD_DATA, options.initialCardValue)
  }
  const context: HilosI18nCountryContext = {
    connection: {} as HilosConnection,
    scopes,
    actions,
  }
  const { navigation, pageLoading } = router(scopes, childrenBuilt)
  if (options.realShell) {
    scopes.session.data.set('adminViewMode', viewer)
    releases.push(bindAdminAccess(scopes))
    answerAsCountry(scope, 'Country')
  }
  const view = mount(HilosI18nCountryPage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: {
      provide: {
        [hilosRouterKey as symbol]: navigation,
        [hilosAdminViewModeKey as symbol]: ref(viewer),
      },
      stubs: options.realShell
        ? {}
        : { HilosAdminPage: { template: '<main><slot /></main>' } },
    },
  })
  return { scope, scopes, context, view, pageLoading, navigation }
}

describe('HilosI18nCountryPage', () => {
  it('waits for the first card, then shows the country block, its read-only fields and the Overview · Names row', async () => {
    const h = harness(true, {} as ActionLifecycle, false, { realShell: true })
    expect(h.view.find('[data-id="country-head-tile"]').exists()).toBe(false)
    expect(h.view.find('[data-id="country-head-description"]').exists()).toBe(
      false,
    )
    expect(h.view.find('[aria-label="Loading country"]').exists()).toBe(true)

    h.scope.data.set(COUNTRY_CARD_DATA, {
      ...initialCard,
      summary: { ...initialCard.summary, nameCount: 7, localeCount: 5 },
    })
    await nextTick()
    const headings = h.view.findAll('h1')
    expect(headings).toHaveLength(1)
    expect(headings[0].attributes('data-id')).toBe('hilos-page-head-title')
    expect(headings[0].text()).toBe('United States')
    expect(headings[0].text()).toBe(
      h.navigation.pageIdentity.get()?.pageHeadOwner?.label,
    )
    const block = h.view.get('[data-id="hilos-page-head-block"]')
    const tile = block.get('[data-id="country-head-tile"]')
    expect(tile.text()).toBe('us')
    expect(tile.classes()).toEqual(
      expect.arrayContaining(['text-uppercase', 'rounded-3']),
    )
    expect(tile.attributes('aria-hidden')).toBe('true')
    expect(tile.find('img').exists()).toBe(false)
    const code = block.get('[data-id="country-head-code"]')
    expect(code.text()).toBe('us')
    expect(code.classes()).toContain('text-uppercase')
    expect(block.get('[data-id="country-head-description"]').text()).toBe(
      'Currency $ USD · Default locale not chosen',
    )
    expect(block.find('[data-id="country-head-own"]').exists()).toBe(false)
    // The counters stay out of the head: Overview owns them.
    expect(block.text()).not.toMatch(/[0-9]/)
    expect(retiredHeaderLeft(h.view)).toBe(false)
    expect(h.view.find('[data-id="hilos-admin-children"]').exists()).toBe(false)
    const tabs = h.view.get('[data-id="hilos-page-tabs"]')
    expect(tabs.attributes('aria-label')).toBe('Country pages')
    const links = tabs.findAll('a')
    expect(links.map((link) => link.text())).toEqual(['Overview', 'Names'])
    expect(links.map((link) => link.attributes('href'))).toEqual([
      '/hilos/i18n/countries/us',
      '/hilos/i18n/countries/us/names',
    ])
    expect(links[0].attributes('aria-current')).toBe('page')
    expect(links[1].attributes('aria-current')).toBeUndefined()
    expect(h.view.find('[data-id="country-card-code"]').text()).toBe('us')
    expect(h.view.find('[data-id="country-card-currency"]').text()).toBe(
      '$ USD',
    )
    expect(h.view.find('[data-id="country-card-default-locale"]').text()).toBe(
      'not chosen',
    )
    expect(h.view.find('[data-id="country-card-frozen"]').exists()).toBe(false)
    expect(h.view.find('[data-id="country-card-enabled"]').text()).toBe(
      'Disabled',
    )
    expect(h.view.find('#country-card-delete-reason').text()).toContain(
      'the built-in catalog knows this country',
    )
    expect(h.view.findAll('button')).toHaveLength(3)
    expect(
      h.view.get('[data-id="country-card-edit"]').attributes('aria-label'),
    ).toBe('Edit country')
    expect(h.view.find('[data-id="country-card-switch-on"]').exists()).toBe(
      true,
    )
    expect(h.view.findAll('input')).toHaveLength(0)
    h.view.unmount()
  })

  it('opens edit and view from Main, including for an admin-view reader', async () => {
    const h = harness(false, {} as ActionLifecycle, true)
    h.scope.data.set(COUNTRY_CARD_DATA, initialCard)
    await nextTick()
    const edit = h.view.get('[data-id="country-card-edit"]')
    expect((edit.element as HTMLButtonElement).disabled).toBe(false)
    await edit.trigger('click')
    expect(
      document.querySelector('[data-id="i18n-country-title"]')?.textContent,
    ).toContain('Edit country · United States')
    document
      .querySelector<HTMLButtonElement>('[data-id="i18n-country-cancel"]')
      ?.click()
    await nextTick()
    h.scope.data.set(COUNTRY_CARD_DATA, { ...initialCard, enabled: true })
    await nextTick()
    const eye = h.view.get('[data-id="country-card-view"]')
    expect(eye.attributes('title')).toBe('View country: nothing to edit')
    expect(h.view.find('[data-id="country-card-frozen"]').exists()).toBe(true)
    await eye.trigger('click')
    expect(
      document.querySelector('[data-id="i18n-country-title"]')?.textContent,
    ).toContain('Country · United States')
    expect(document.querySelector('[data-id="i18n-country-save"]')).toBeNull()
    document
      .querySelector<HTMLButtonElement>('[data-id="i18n-country-close"]')
      ?.click()
    await nextTick()
    expect(document.querySelector('[data-id="i18n-country-window"]')).toBeNull()
  })

  it('shows Unnamed country, a separate code and the custom mark when no default-language name exists', async () => {
    const h = harness(true, {} as ActionLifecycle, false, { realShell: true })
    h.scope.data.set(COUNTRY_CARD_DATA, {
      ...initialCard,
      code: 'qx',
      summary: {
        name: null,
        isOwn: true,
        defaultLocaleId: null,
        defaultLocaleCode: null,
        defaultLocale: 'missing',
        localeCount: 0,
        nameCount: 0,
        locales: [],
        catalogDefaultLocaleCode: null,
        canDelete: true,
        deleteReason: null,
      },
    })
    await nextTick()
    const title = h.view.get('[data-id="hilos-page-head-title"]')
    expect(title.text()).toBe('Unnamed country')
    expect(title.classes()).not.toContain('text-uppercase')
    expect(h.view.get('[data-id="country-head-tile"]').text()).toBe('qx')
    expect(h.view.get('[data-id="country-head-code"]').text()).toBe('qx')
    const own = h.view.get(
      '[data-id="hilos-page-head-block"] [data-id="country-head-own"]',
    )
    expect(own.classes()).toEqual(
      expect.arrayContaining([
        'badge',
        'text-bg-warning-subtle',
        'text-warning-emphasis',
      ]),
    )
    expect(own.get('i').classes()).toContain('bi-question-circle')
    expect(own.get('i').attributes('aria-hidden')).toBe('true')
    expect(own.get('.visually-hidden').text()).toBe(
      'Custom country. This country is absent from the catalog and source.',
    )
    expect(own.attributes('title')).toBe(
      'This country is absent from the catalog and source.',
    )
    expect(h.view.find('#country-card-delete-reason').text()).toBe(
      'Can be deleted: nothing refers to it yet',
    )
    h.view.unmount()
  })

  it.each([
    ['Visible country', 'Visible country', false],
    [null, 'Unnamed country', true],
    ['', 'Unnamed country', true],
  ] as const)(
    'binds %j as %j before the real shell renders (viewer %s)',
    async (name, expected, viewer) => {
      const h = harness(true, {} as ActionLifecycle, viewer, {
        realShell: true,
        initialCardValue: {
          ...initialCard,
          summary: { ...initialCard.summary, name },
        },
      })
      const crumb = () =>
        h.view.get('[data-id="hilos-breadcrumb"] li:last-child').text()
      expect(crumb()).toBe(expected)
      expect(h.view.findAll('h1')).toHaveLength(1)
      expect(h.view.get('[data-id="hilos-page-head-title"]').text()).toBe(
        expected,
      )
      expect(h.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe(
        expected,
      )
      expect(h.view.get('[data-id="country-head-code"]').text()).toBe('us')
      expect(
        h.view
          .get('[data-id="hilos-page-tab-hilos_i18n_country_names"]')
          .attributes('href'),
      ).toBe('/hilos/i18n/countries/us/names')
      for (const liveName of ['Renamed', null, '']) {
        const display =
          liveName === null || liveName === '' ? 'Unnamed country' : liveName
        h.scope.data.set(COUNTRY_CARD_DATA, {
          ...initialCard,
          summary: { ...initialCard.summary, name: liveName },
        })
        await nextTick()
        expect(crumb()).toBe(display)
        expect(h.view.get('[data-id="hilos-page-head-title"]').text()).toBe(
          display,
        )
        expect(h.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe(
          display,
        )
      }
      h.scope.data.set(COUNTRY_CARD_DATA, null)
      h.scopes.session.data.set(COUNTRY_CARD_DATA, initialCard)
      h.scopes.user.data.set(COUNTRY_CARD_DATA, initialCard)
      await nextTick()
      expect(crumb()).toBe('Country')
      expect(h.view.get('[data-id="hilos-page-head-title"]').text()).toBe(
        'Country',
      )
      expect(h.view.find('[data-id="country-head-tile"]').exists()).toBe(false)
      expect(h.view.find('[data-id="country-head-code"]').exists()).toBe(false)
      h.view.unmount()
    },
  )

  it('follows new scopes, ignores other pages, and releases only its own registration', async () => {
    const h = harness(true, {} as ActionLifecycle, false, {
      realShell: true,
      initialCardValue: initialCard,
    })
    const identity = h.navigation.pageIdentity
    expect(identity.get()?.pageHeadOwner?.label).toBe('United States')
    h.scopes.dropPage()
    expect(identity.get()).toBeUndefined()
    const next = h.scopes.openPage(HilosPages.I18N_COUNTRY)
    answerAsCountry(next, 'Country')
    expect(identity.get()?.pageHeadOwner?.label).toBe('Country')
    next.data.set(COUNTRY_CARD_DATA, {
      ...initialCard,
      countryId: 140,
      code: 'ie',
      summary: { ...initialCard.summary, name: 'Ireland' },
    })
    expect(identity.get()?.pageHeadOwner?.label).toBe('Ireland')
    await nextTick()
    expect(h.view.get('[data-id="hilos-page-head-title"]').text()).toBe(
      'Ireland',
    )
    expect(h.view.get('[data-id="country-head-code"]').text()).toBe('ie')
    h.scope.data.set(COUNTRY_CARD_DATA, initialCard)
    expect(identity.get()?.pageHeadOwner?.label).toBe('Ireland')
    const replacement = mount(HilosI18nCountryPage, {
      props: { context: h.context },
      global: {
        provide: { [hilosRouterKey as symbol]: h.navigation },
        stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
      },
    })
    h.view.unmount()
    expect(identity.get()?.pageHeadOwner?.label).toBe('Ireland')
    const unrelated = h.scopes.openPage(HilosPages.SETTINGS)
    unrelated.data.set('pageLabel', 'Settings')
    unrelated.data.set('pageBreadcrumb', [
      { page: HilosPages.I18N_COUNTRY, label: 'Country' },
    ])
    unrelated.data.set(COUNTRY_CARD_DATA, initialCard)
    expect(identity.get()?.breadcrumb[0].label).toBe('Country')
    const current = h.scopes.openPage(HilosPages.I18N_COUNTRY_NAMES)
    answerAsCountry(current, 'Country names')
    current.data.set(COUNTRY_CARD_DATA, initialCard)
    expect(identity.get()?.pageHeadOwner?.label).toBe('United States')
    replacement.unmount()
    expect(identity.get()?.pageHeadOwner?.label).toBe('Country')
    await nextTick()
  })

  it('reacts to a new page signal and clears a deleted card for an admin view viewer', async () => {
    const h = harness(true, {} as ActionLifecycle, false, { realShell: true })
    h.scope.data.set(COUNTRY_CARD_DATA, initialCard)
    await nextTick()
    h.scope.data.set(COUNTRY_CARD_DATA, {
      ...initialCard,
      currencySymbol: '€',
      currencyCode: 'EUR',
      enabled: true,
      summary: {
        ...initialCard.summary,
        name: 'Renamed',
        defaultLocaleId: 11102,
        defaultLocaleCode: 'en-US',
      },
    })
    await nextTick()
    expect(h.view.get('[data-id="hilos-page-head-title"]').text()).toBe(
      'Renamed',
    )
    expect(h.view.get('[data-id="country-head-description"]').text()).toBe(
      'Currency € EUR · Default locale en-US',
    )
    expect(h.view.find('[data-id="country-card-frozen"]').text()).toBe(
      'Country enabled — frozen',
    )
    expect(h.view.find('[data-id="country-card-enabled"]').text()).toBe(
      'Enabled',
    )
    expect(
      h.view.find('[data-id="country-card-state"]').findAll('button'),
    ).toHaveLength(2)
    expect(h.view.find('[data-id="country-card-switch-off"]').exists()).toBe(
      true,
    )

    h.scope.data.set(COUNTRY_CARD_DATA, {
      ...initialCard,
      summary: {
        ...initialCard.summary,
        isOwn: true,
        deleteReason: 'locales',
      },
    })
    await nextTick()
    expect(h.view.find('#country-card-delete-reason').text()).toBe(
      'Cannot delete: locales refer to this country',
    )
    expect(h.view.find('[data-id="country-head-own"]').exists()).toBe(true)
    h.scope.data.set(COUNTRY_CARD_DATA, {
      ...initialCard,
      summary: { ...initialCard.summary, isOwn: true, deleteReason: 'names' },
    })
    await nextTick()
    expect(h.view.find('#country-card-delete-reason').text()).toBe(
      'Cannot delete: names refer to this country',
    )

    h.pageLoading.set(false)
    h.scope.data.set(COUNTRY_CARD_DATA, [])
    await nextTick()
    expect(h.view.find('[data-id="country-head-tile"]').exists()).toBe(false)
    expect(h.view.find('[data-id="country-head-own"]').exists()).toBe(false)
    expect(h.view.find('[data-id="country-head-description"]').exists()).toBe(
      false,
    )
    expect(h.view.find('[data-id="country-card-main"]').exists()).toBe(false)
    expect(h.view.find('[data-id="country-card-unavailable"]').text()).toBe(
      'Country details are no longer available.',
    )
    h.view.unmount()
  })

  it('hides the row but keeps the block while the names page has no address', async () => {
    const h = harness(false, {} as ActionLifecycle, false, { realShell: true })
    h.scope.data.set(COUNTRY_CARD_DATA, initialCard)
    await nextTick()
    expect(h.view.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)
    expect(h.view.get('[data-id="hilos-page-head-title"]').text()).toBe(
      'United States',
    )
    expect(h.view.get('[data-id="country-head-code"]').text()).toBe('us')
    expect(retiredHeaderLeft(h.view)).toBe(false)
    h.view.unmount()
  })

  it('keeps the modal and handle when its own card disappears and navigates only after success', async () => {
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
    h.pageLoading.set(false)
    h.scope.data.set(COUNTRY_CARD_DATA, {
      ...initialCard,
      countryId: 147,
      code: 'qy',
      summary: {
        ...initialCard.summary,
        name: null,
        isOwn: true,
        canDelete: true,
        deleteReason: null,
      },
    })
    await nextTick()
    await h.view.get('[data-id="country-card-delete"]').trigger('click')
    expect(document.body.textContent).toContain('Delete country · QY')
    document
      .querySelector<HTMLButtonElement>(
        '[data-id="i18n-country-delete-confirm"]',
      )!
      .click()
    await nextTick()
    expect(dispatch).toHaveBeenCalledWith('hilos_i18n_country_delete', {
      countryId: 147,
    })
    h.scope.data.set(COUNTRY_CARD_DATA, [])
    await nextTick()
    expect(h.view.find('[data-id="country-card-unavailable"]').exists()).toBe(
      true,
    )
    expect(
      document.querySelector('[data-id="i18n-country-delete-window"]'),
    ).not.toBeNull()
    expect(document.body.textContent).not.toContain('Deleted elsewhere.')
    expect(navigate).not.toHaveBeenCalled()
    settle({ message: 'Country deleted.' })
    await nextTick()
    await nextTick()
    await nextTick()
    expect(
      document.querySelector('[data-id="i18n-country-delete-window"]'),
    ).toBeNull()
    expect(navigate).toHaveBeenCalledExactlyOnceWith('/hilos/i18n/countries')
  })

  it('keeps a refused card deletion open without navigating, including a late hint', async () => {
    let refuse: (error: ActionError) => void = () => {}
    const done = new Promise<ActionResult>((_resolve, reject) => {
      refuse = reject
    })
    const dispatch = vi.fn(() => ({
      requestId: 'refused',
      loading: createSignal(false),
      done,
    }))
    const h = harness(false, { dispatch } as unknown as ActionLifecycle)
    const navigate = vi.spyOn(h.navigation, 'navigate')
    const own = {
      ...initialCard,
      countryId: 147,
      code: 'qy',
      summary: { ...initialCard.summary, canDelete: true, deleteReason: null },
    }
    h.scope.data.set(COUNTRY_CARD_DATA, own)
    await nextTick()
    await h.view.get('[data-id="country-card-delete"]').trigger('click')
    h.scope.data.set(COUNTRY_CARD_DATA, {
      ...own,
      summary: { ...own.summary, canDelete: false, deleteReason: 'locales' },
    })
    await nextTick()
    const confirm = document.querySelector<HTMLButtonElement>(
      '[data-id="i18n-country-delete-confirm"]',
    )!
    expect(confirm.disabled).toBe(false)
    confirm.click()
    refuse(
      new ActionError(
        'hilos_i18n_country_delete',
        'fail',
        'Cannot delete: locales refer to this country',
      ),
    )
    await nextTick()
    await nextTick()
    await nextTick()
    expect(
      document.querySelector('[data-id="i18n-country-delete-error"]')
        ?.textContent,
    ).toContain('locales refer')
    expect(
      document.querySelector('[data-id="i18n-country-delete-window"]'),
    ).not.toBeNull()
    expect(navigate).not.toHaveBeenCalled()
  })

  it('opens a free card for a viewer and blocks an idle dialog after a foreign deletion', async () => {
    const dispatch = vi.fn()
    const h = harness(false, { dispatch } as unknown as ActionLifecycle, true)
    h.pageLoading.set(false)
    h.scope.data.set(COUNTRY_CARD_DATA, {
      ...initialCard,
      countryId: 147,
      code: 'qy',
      summary: { ...initialCard.summary, canDelete: true, deleteReason: null },
    })
    await nextTick()
    await h.view.get('[data-id="country-card-delete"]').trigger('click')
    const confirm = document.querySelector<HTMLButtonElement>(
      '[data-id="i18n-country-delete-confirm"]',
    )!
    expect(confirm.disabled).toBe(true)
    confirm.click()
    expect(dispatch).not.toHaveBeenCalled()
    h.scope.data.set(COUNTRY_CARD_DATA, [])
    await nextTick()
    expect(document.body.textContent).toContain('Deleted elsewhere.')
    expect(
      document.querySelector<HTMLButtonElement>(
        '[data-id="i18n-country-delete-cancel"]',
      )!.disabled,
    ).toBe(false)
    document
      .querySelector<HTMLButtonElement>(
        '[data-id="i18n-country-delete-cancel"]',
      )!
      .click()
    await nextTick()
    await nextTick()
    expect(
      document.querySelector('[data-id="i18n-country-delete-window"]'),
    ).toBeNull()
  })
})
