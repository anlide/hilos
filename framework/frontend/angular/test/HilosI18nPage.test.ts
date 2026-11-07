import { TestBed } from '@angular/core/testing'
import {
  I18N_OVERVIEW_DATA,
  type HilosI18nOverview,
} from '@hilos/core/admin/i18n'
import {
  bindAdminAccess,
  createAppPageRouter,
  createSignal,
  HilosPages,
  pageIdentity,
  ScopeManager,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosI18nPage } from '../src/admin/i18n/HilosI18nPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const releases: Array<() => void> = []
afterEach(() => {
  for (const release of releases.splice(0)) release()
  document.body.innerHTML = ''
  TestBed.resetTestingModule()
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
  const routes = createAppPageRouter({})
  const navigation: HilosRouter = {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.I18N,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/i18n'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: (page, params) =>
      page === unresolvedPage ? undefined : routes.path(page, params),
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

  TestBed.configureTestingModule({
    imports: [HilosI18nPage],
    providers: [{ provide: HILOS_ROUTER, useValue: navigation }],
  })
  const fixture = TestBed.createComponent(HilosI18nPage)
  fixture.componentRef.setInput('context', { scopes })
  fixture.detectChanges()
  document.body.append(fixture.nativeElement as HTMLElement)

  return { scopes, scope, navigation, fixture, answer }
}

function body(): HTMLElement {
  return document.querySelector('[data-id="i18n-overview"]')!
}

function countText(kind: string): string {
  return (
    body()
      .querySelector(`[data-id="i18n-overview-${kind}-count"]`)
      ?.textContent?.replace(/\s+/g, ' ')
      .trim() ?? ''
  )
}

describe('HilosI18nPage', () => {
  it.each([false, true])(
    'draws the two linked tiles under one shared section head (viewer=%s)',
    (viewer) => {
      const { navigation, answer, fixture } = harness(viewer)
      answer()
      fixture.detectChanges()

      const headings = [...document.querySelectorAll('h1')]
      expect(headings.map((heading) => heading.textContent?.trim())).toEqual([
        'Internationalization',
      ])
      expect(
        document.querySelector('[data-id="hilos-page-head-block"] p')
          ?.textContent,
      ).toBe('Languages, countries, locales, and translation screens.')
      const crumbs = document.querySelector('nav[aria-label="Breadcrumb"]')!
      expect(crumbs.querySelectorAll('li')).toHaveLength(2)
      expect(crumbs.querySelector('[aria-current="page"]')).not.toBeNull()
      expect(crumbs.querySelector('[aria-current="page"] a')).toBeNull()
      expect(crumbs.querySelector('a')?.getAttribute('href')).toBe('/hilos')
      expect(crumbs.querySelectorAll('a')).toHaveLength(1)
      const tabs = document.querySelector(
        'nav[aria-label="Internationalization pages"]',
      )!
      expect(
        [...tabs.querySelectorAll('a')].map((link) => link.textContent?.trim()),
      ).toEqual(['Overview', 'Languages', 'Countries'])
      expect(
        tabs.querySelector('[aria-current="page"]')?.textContent?.trim(),
      ).toBe('Overview')
      expect(
        document.querySelector('[data-id="hilos-admin-children"]'),
      ).toBeNull()
      expect(document.querySelector('[data-id="hilos-admin-empty"]')).toBeNull()

      const region = body()
      expect(region.querySelectorAll('a')).toHaveLength(2)
      expect(region.querySelectorAll('button, table')).toHaveLength(0)
      for (const [kind, tally, description, href, name] of [
        [
          'languages',
          '4 of 7',
          'Enabled · default English',
          '/hilos/i18n/languages',
          'Languages: 4 of 7 enabled',
        ],
        [
          'countries',
          '4 of 52',
          'Enabled · 51 built-in, 1 custom',
          '/hilos/i18n/countries',
          'Countries: 4 of 52 enabled',
        ],
      ] as const) {
        const tile = region.querySelector(`[data-id="i18n-overview-${kind}"]`)
        expect(tile?.textContent).toContain(description)
        const link = tile?.querySelector(
          `[data-id="i18n-overview-${kind}-count"]`,
        )
        expect(link?.tagName).toBe('A')
        expect(countText(kind)).toBe(tally)
        expect(link?.getAttribute('href')).toBe(href)
        expect(link?.getAttribute('aria-label')).toBe(name)
        expect(link?.getAttribute('tabindex')).toBeNull()
        ;(link as HTMLAnchorElement).focus()
        expect(document.activeElement).toBe(link)
        link?.dispatchEvent(
          new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
            button: 0,
          }),
        )
        expect(navigation.navigate).toHaveBeenLastCalledWith(href)
      }
    },
  )

  it('waits for complete data without guessed counts or count links', () => {
    const { scope, answer, fixture } = harness()
    expect(
      document.querySelector('[data-id="hilos-page-head-skeleton"]'),
    ).not.toBeNull()
    const region = body()
    expect(region.querySelector('[data-id="hilos-skeleton"]')).not.toBeNull()
    expect(region.querySelectorAll('a')).toHaveLength(0)
    expect(region.textContent).not.toContain('0 of 0')

    answer()
    scope.data.set(I18N_OVERVIEW_DATA, { languages: overview.languages })
    fixture.detectChanges()
    expect(region.querySelector('[data-id="hilos-skeleton"]')).not.toBeNull()
    expect(region.querySelectorAll('a')).toHaveLength(0)

    scope.data.set(I18N_OVERVIEW_DATA, overview)
    fixture.detectChanges()
    expect(region.querySelector('[data-id="hilos-skeleton"]')).toBeNull()
    expect(
      [...region.querySelectorAll('a')].map((link) =>
        link.textContent?.replace(/\s+/g, ' ').trim(),
      ),
    ).toEqual(['4 of 7', '4 of 52'])
  })

  it('replaces both tallies and their descriptions in one reactive update', () => {
    const { scope, answer, fixture } = harness()
    answer()
    fixture.detectChanges()
    const region = body()
    expect(
      [...region.querySelectorAll('a')].map((link) =>
        link.textContent?.replace(/\s+/g, ' ').trim(),
      ),
    ).toEqual(['4 of 7', '4 of 52'])
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
    fixture.detectChanges()
    expect(
      [...region.querySelectorAll('a')].map((link) =>
        link.textContent?.replace(/\s+/g, ' ').trim(),
      ),
    ).toEqual(['5 of 8', '6 of 54'])
    expect(
      region
        .querySelector('[data-id="i18n-overview-languages-count"]')
        ?.getAttribute('aria-label'),
    ).toBe('Languages: 5 of 8 enabled')
    expect(
      region
        .querySelector('[data-id="i18n-overview-countries-count"]')
        ?.getAttribute('aria-label'),
    ).toBe('Countries: 6 of 54 enabled')
    expect(
      region.querySelector('[data-id="i18n-overview-languages"]')?.textContent,
    ).toContain('Enabled · default Français')
    expect(
      region.querySelector('[data-id="i18n-overview-countries"]')?.textContent,
    ).toContain('Enabled · 52 built-in, 2 custom')
  })

  it.each([
    ['languages', HilosPages.I18N_LANGUAGES],
    ['countries', HilosPages.I18N_COUNTRIES],
  ])('omits the %s tile and tab when its route is unresolved', (kind, page) => {
    const { answer, fixture } = harness(false, page)
    answer()
    fixture.detectChanges()
    expect(
      document.querySelector(`[data-id="i18n-overview-${kind}"]`),
    ).toBeNull()
    expect(
      document.querySelector(`[data-id="hilos-page-tab-${page}"]`),
    ).toBeNull()
    expect(body().querySelectorAll('a')).toHaveLength(1)
  })

  it('uses the server tab eligibility even when both routes resolve', () => {
    const { scope, answer, fixture } = harness()
    answer()
    scope.data.set('pageTabs', [
      { page: HilosPages.I18N, label: 'Overview' },
      { page: HilosPages.I18N_COUNTRIES, label: 'Countries' },
    ])
    fixture.detectChanges()
    expect(
      document.querySelector('[data-id="i18n-overview-languages"]'),
    ).toBeNull()
    expect(
      document.querySelector('[data-id="i18n-overview-countries"]'),
    ).not.toBeNull()
  })

  it('clears the tiles when the current page scope changes', () => {
    const { scopes, answer, fixture } = harness()
    answer()
    fixture.detectChanges()
    scopes.openPage(HilosPages.I18N_COUNTRIES)
    fixture.detectChanges()
    const region = body()
    expect(region.querySelector('[data-id="hilos-skeleton"]')).not.toBeNull()
    expect(region.querySelectorAll('a')).toHaveLength(0)
    expect(region.textContent).not.toContain('4 of')
  })
})
