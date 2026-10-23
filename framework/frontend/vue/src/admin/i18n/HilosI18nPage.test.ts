import { enableAutoUnmount, mount } from '@vue/test-utils'
import {
  I18N_OVERVIEW_DATA,
  type HilosI18nOverview,
} from '@hilos/core/admin/i18n'
import {
  bindAdminAccess,
  createPageRouter,
  createSignal,
  HilosPages,
  pageIdentity,
  ScopeManager,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import HilosI18nPage from './HilosI18nPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

enableAutoUnmount(afterEach)
const releases: Array<() => void> = []
afterEach(() => {
  for (const release of releases.splice(0)) release()
})

const overview: HilosI18nOverview = {
  languages: {
    enabledCount: 4,
    totalCount: 7,
    defaultLanguageCode: 'en',
    defaultLanguageName: 'English',
  },
  countries: {
    enabledCount: 4,
    totalCount: 52,
    builtInCount: 51,
    ownCount: 1,
  },
}

function harness(viewer = false, unresolvedPage?: string) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.I18N)
  scopes.session.data.set('adminViewMode', viewer)
  if (!viewer) {
    const user = { type: 'users', id: '1' }
    scopes.session.entities.upsert(user, { admin: true })
    scopes.session.data.set('currentUser', user)
  }
  releases.push(bindAdminAccess(scopes))
  const paths = createPageRouter({
    [HilosPages.DASHBOARD]: { path: '/admin/{project}', admin: true },
    [HilosPages.I18N]: { path: '/admin/{project}/i18n', admin: true },
    [HilosPages.I18N_LANGUAGES]: {
      path: '/admin/{project}/languages',
      admin: true,
    },
    [HilosPages.I18N_COUNTRIES]: {
      path: '/admin/{project}/countries',
      admin: true,
    },
  })
  const navigation: HilosRouter = {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.I18N,
      params: { project: 'demo' },
      admin: true,
    }),
    currentPath: createSignal('/admin/demo/i18n'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: (page, params) =>
      page === unresolvedPage ? undefined : paths.path(page, params),
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: vi.fn(),
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  const answer = (): void => {
    scope.data.set('pageLabel', 'Internationalization')
    scope.data.set(
      'pageLead',
      'Languages, countries, locales, and translation screens.',
    )
    scope.data.set('pageHeadOwner', {
      page: HilosPages.I18N,
      label: 'Internationalization',
      lead: 'Languages, countries, locales, and translation screens.',
      icon: 'bi-translate',
    })
    scope.data.set('pageBreadcrumb', [
      { page: HilosPages.DASHBOARD, label: 'Hilos' },
      { page: HilosPages.I18N, label: 'Internationalization' },
    ])
    scope.data.set('pageTabs', [
      { page: HilosPages.I18N, label: 'Overview' },
      { page: HilosPages.I18N_LANGUAGES, label: 'Languages' },
      { page: HilosPages.I18N_COUNTRIES, label: 'Countries' },
    ])
    scope.data.set('pageChildren', [])
    scope.data.set(I18N_OVERVIEW_DATA, overview)
  }
  const view = mount(HilosI18nPage, {
    props: { context: { scopes } },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: navigation } },
  })
  return { scopes, scope, navigation, view, answer }
}

describe('HilosI18nPage', () => {
  it.each([false, true])(
    'draws the two linked tiles under one shared section head (viewer=%s)',
    async (viewer) => {
      const { view, navigation, answer } = harness(viewer)
      answer()
      await nextTick()

      expect(view.findAll('h1').map((heading) => heading.text())).toEqual([
        'Internationalization',
      ])
      expect(view.get('[data-id="hilos-page-head-block"] p').text()).toBe(
        'Languages, countries, locales, and translation screens.',
      )
      expect(view.find('i.bi-translate').exists()).toBe(true)
      const crumbs = view.get('nav[aria-label="Breadcrumb"]')
      expect(crumbs.findAll('li').map((crumb) => crumb.text())).toEqual([
        'Hilos',
        'Internationalization',
      ])
      expect(crumbs.get('a').attributes('href')).toBe('/admin/demo')
      expect(crumbs.findAll('a')).toHaveLength(1)
      const tabs = view.get('nav[aria-label="Internationalization pages"]')
      expect(tabs.findAll('a').map((link) => link.text())).toEqual([
        'Overview',
        'Languages',
        'Countries',
      ])
      expect(tabs.get('[aria-current="page"]').text()).toBe('Overview')
      expect(view.find('[data-id="hilos-admin-children"]').exists()).toBe(false)
      expect(view.find('[data-id="hilos-admin-empty"]').exists()).toBe(false)

      const body = view.get('[data-id="i18n-overview"]')
      expect(body.get('.row').classes()).toEqual([
        'row',
        'row-cols-2',
        'row-cols-md-3',
        'row-cols-lg-5',
        'g-3',
        'mb-4',
      ])
      expect(body.findAll('.border.rounded-3.p-3.h-100')).toHaveLength(2)
      expect(body.findAll('a')).toHaveLength(2)
      expect(body.findAll('button, table')).toHaveLength(0)
      for (const [kind, tally, description, href] of [
        [
          'languages',
          '4 of 7',
          'Enabled · default English',
          '/admin/demo/languages',
        ],
        [
          'countries',
          '4 of 52',
          'Enabled · 51 built-in, 1 custom',
          '/admin/demo/countries',
        ],
      ]) {
        const tile = body.get(`[data-id="i18n-overview-${kind}"]`)
        expect(tile.get('.small:last-child').text()).toBe(description)
        const link = tile.get(`[data-id="i18n-overview-${kind}-count"]`)
        expect(link.element.tagName).toBe('A')
        expect(link.text()).toBe(tally)
        expect(link.attributes('href')).toBe(href)
        expect(link.attributes('aria-label')).toBe(
          `${kind === 'languages' ? 'Languages' : 'Countries'}: ${tally} enabled`,
        )
        const anchor = link.element as HTMLAnchorElement
        expect(link.attributes('tabindex')).toBeUndefined()
        anchor.focus()
        expect(document.activeElement).toBe(link.element)
        await link.trigger('click', { button: 0 })
        expect(navigation.navigate).toHaveBeenLastCalledWith(href)
      }
    },
  )

  it('waits for complete data without guessed counts or count links', async () => {
    const { view, scope, answer } = harness()
    expect(view.find('[data-id="hilos-page-head-skeleton"]').exists()).toBe(
      true,
    )
    const body = view.get('[data-id="i18n-overview"]')
    expect(body.find('[data-id="hilos-skeleton"]').exists()).toBe(true)
    expect(body.findAll('a')).toHaveLength(0)
    expect(body.text()).not.toContain('0 of 0')

    answer()
    scope.data.set(I18N_OVERVIEW_DATA, { languages: overview.languages })
    await nextTick()
    expect(body.find('[data-id="hilos-skeleton"]').exists()).toBe(true)
    expect(body.findAll('a')).toHaveLength(0)

    scope.data.set(I18N_OVERVIEW_DATA, overview)
    await nextTick()
    expect(body.find('[data-id="hilos-skeleton"]').exists()).toBe(false)
    expect(body.findAll('a').map((link) => link.text())).toEqual([
      '4 of 7',
      '4 of 52',
    ])
  })

  it('replaces both tallies and their descriptions in one reactive update', async () => {
    const { view, scope, answer } = harness()
    answer()
    await nextTick()
    const body = view.get('[data-id="i18n-overview"]')
    scope.data.set(I18N_OVERVIEW_DATA, {
      languages: {
        enabledCount: 5,
        totalCount: 8,
        defaultLanguageCode: 'fr',
        defaultLanguageName: 'Français',
      },
      countries: {
        enabledCount: 6,
        totalCount: 54,
        builtInCount: 52,
        ownCount: 2,
      },
    })
    expect(body.findAll('a').map((link) => link.text())).toEqual([
      '4 of 7',
      '4 of 52',
    ])
    await nextTick()
    expect(body.findAll('a').map((link) => link.text())).toEqual([
      '5 of 8',
      '6 of 54',
    ])
    expect(
      body
        .get('[data-id="i18n-overview-languages-count"]')
        .attributes('aria-label'),
    ).toBe('Languages: 5 of 8 enabled')
    expect(
      body
        .get('[data-id="i18n-overview-countries-count"]')
        .attributes('aria-label'),
    ).toBe('Countries: 6 of 54 enabled')
    expect(
      body.get('[data-id="i18n-overview-languages"] .small:last-child').text(),
    ).toBe('Enabled · default Français')
    expect(
      body.get('[data-id="i18n-overview-countries"] .small:last-child').text(),
    ).toBe('Enabled · 52 built-in, 2 custom')
  })

  it.each([
    ['languages', HilosPages.I18N_LANGUAGES],
    ['countries', HilosPages.I18N_COUNTRIES],
  ])(
    'omits the %s tile and tab when its route is unresolved',
    async (kind, page) => {
      const { view, answer } = harness(false, page)
      answer()
      await nextTick()
      expect(view.find(`[data-id="i18n-overview-${kind}"]`).exists()).toBe(
        false,
      )
      expect(view.find(`[data-id="hilos-page-tab-${page}"]`).exists()).toBe(
        false,
      )
      expect(view.get('[data-id="i18n-overview"]').findAll('a')).toHaveLength(1)
    },
  )

  it('uses the server tab eligibility even when both routes resolve', async () => {
    const { view, scope, answer } = harness()
    answer()
    scope.data.set('pageTabs', [
      { page: HilosPages.I18N, label: 'Overview' },
      { page: HilosPages.I18N_COUNTRIES, label: 'Countries' },
    ])
    await nextTick()
    expect(view.find('[data-id="i18n-overview-languages"]').exists()).toBe(
      false,
    )
    expect(view.find('[data-id="i18n-overview-countries"]').exists()).toBe(true)
  })

  it('clears the tiles when the current page scope changes', async () => {
    const { view, scopes, answer } = harness()
    answer()
    await nextTick()
    scopes.openPage(HilosPages.I18N_COUNTRIES)
    await nextTick()
    const body = view.get('[data-id="i18n-overview"]')
    expect(body.find('[data-id="hilos-skeleton"]').exists()).toBe(true)
    expect(body.findAll('a')).toHaveLength(0)
    expect(body.text()).not.toContain('4 of')
  })
})
