import { mount } from '@vue/test-utils'
import {
  COUNTRY_CARD_DATA,
  createSignal,
  HilosPages,
  ScopeManager,
  type HilosConnection,
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import HilosI18nCountryPage from './HilosI18nCountryPage.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'

const initialCard: HilosI18nCountryCard = {
  code: 'us',
  currencySymbol: '$',
  currencyCode: 'USD',
  enabled: false,
  summary: {
    name: 'United States',
    isOwn: false,
    defaultLocaleCode: null,
    canDelete: false,
    deleteReason: 'known',
  },
}

/** The route resolver gives unbuilt child pages no path. */
function router(childrenBuilt = false) {
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
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: (page, params) => {
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

function harness(childrenBuilt = false) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.I18N_COUNTRY)
  const context: HilosI18nCountryContext = {
    connection: {} as HilosConnection,
    scopes,
  }
  const { navigation, pageLoading } = router(childrenBuilt)
  const view = mount(HilosI18nCountryPage, {
    props: { context },
    global: {
      provide: { [hilosRouterKey as symbol]: navigation },
      stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
    },
  })
  return { scope, view, pageLoading }
}

describe('HilosI18nCountryPage', () => {
  it('waits for the first card, then shows its read-only fields and two tab names', async () => {
    const h = harness()
    expect(h.view.find('[data-id="country-card-header"]').exists()).toBe(false)
    expect(h.view.find('[aria-label="Loading country"]').exists()).toBe(true)

    h.scope.data.set(COUNTRY_CARD_DATA, initialCard)
    await nextTick()
    expect(h.view.find('[data-id="country-card-title"]').text()).toBe(
      'United States',
    )
    expect(h.view.find('[data-id="country-card-code-badge"]').text()).toBe('us')
    expect(h.view.find('[data-id="country-card-line"]').text()).toBe(
      'Currency $ USD · Default locale not chosen',
    )
    expect(h.view.find('[data-id="country-card-own"]').exists()).toBe(false)
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
    expect(
      h.view.find('[data-id="country-card-delete-verdict"]').text(),
    ).toContain('the built-in catalog knows this country')
    const tabs = h.view.find('[data-id="country-card-tabs"]')
    expect(tabs.text()).toContain('Main')
    expect(tabs.text()).toContain('Names')
    expect(tabs.findAll('a')).toHaveLength(1)
    expect(tabs.find('a').attributes('href')).toBe('/hilos/i18n/countries/us')
    expect(tabs.find('a').attributes('aria-current')).toBe('page')
    expect(h.view.findAll('button')).toHaveLength(0)
    expect(h.view.findAll('input')).toHaveLength(0)
    h.view.unmount()
  })

  it('shows the code as the title when the country has no name in the default language', async () => {
    const h = harness()
    h.scope.data.set(COUNTRY_CARD_DATA, {
      ...initialCard,
      code: 'qx',
      summary: {
        name: null,
        isOwn: true,
        defaultLocaleCode: null,
        canDelete: true,
        deleteReason: null,
      },
    })
    await nextTick()
    const title = h.view.find('[data-id="country-card-title"]')
    expect(title.text()).toBe('qx')
    expect(title.classes()).toContain('text-uppercase')
    expect(h.view.find('[data-id="country-card-code-badge"]').exists()).toBe(
      false,
    )
    expect(h.view.find('[data-id="country-card-own"]').text()).toBe(
      'Custom country',
    )
    expect(h.view.find('[data-id="country-card-delete-verdict"]').text()).toBe(
      'Can be deleted: nothing refers to it yet',
    )
    h.view.unmount()
  })

  it('reacts to a new page signal and clears a deleted card for an admin view viewer', async () => {
    const h = harness()
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
        defaultLocaleCode: 'en-US',
      },
    })
    await nextTick()
    expect(h.view.find('[data-id="country-card-title"]').text()).toBe('Renamed')
    expect(h.view.find('[data-id="country-card-line"]').text()).toBe(
      'Currency € EUR · Default locale en-US',
    )
    expect(h.view.find('[data-id="country-card-frozen"]').text()).toBe(
      'Country enabled — frozen',
    )
    expect(h.view.find('[data-id="country-card-enabled"]').text()).toBe(
      'Enabled',
    )
    expect(h.view.findAll('button')).toHaveLength(0)

    h.scope.data.set(COUNTRY_CARD_DATA, {
      ...initialCard,
      summary: {
        ...initialCard.summary,
        isOwn: true,
        deleteReason: 'locales',
      },
    })
    await nextTick()
    expect(h.view.find('[data-id="country-card-delete-verdict"]').text()).toBe(
      'Cannot delete: locales refer to this country',
    )
    h.scope.data.set(COUNTRY_CARD_DATA, {
      ...initialCard,
      summary: { ...initialCard.summary, isOwn: true, deleteReason: 'names' },
    })
    await nextTick()
    expect(h.view.find('[data-id="country-card-delete-verdict"]').text()).toBe(
      'Cannot delete: names refer to this country',
    )

    h.pageLoading.set(false)
    h.scope.data.set(COUNTRY_CARD_DATA, [])
    await nextTick()
    expect(h.view.find('[data-id="country-card-header"]').exists()).toBe(false)
    expect(h.view.find('[data-id="country-card-main"]').exists()).toBe(false)
    expect(h.view.find('[data-id="country-card-unavailable"]').text()).toBe(
      'Country details are no longer available.',
    )
    h.view.unmount()
  })

  it('uses the same country code when the names page becomes built', async () => {
    const h = harness(true)
    h.scope.data.set(COUNTRY_CARD_DATA, initialCard)
    await nextTick()
    const links = h.view.findAll('[data-id="country-card-tabs"] a')
    expect(links.map((link) => link.attributes('href'))).toEqual([
      '/hilos/i18n/countries/us',
      '/hilos/i18n/countries/us/names',
    ])
    h.view.unmount()
  })
})
