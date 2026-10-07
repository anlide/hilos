import { describe, expect, it } from 'vitest'
import { resolveHilosPath } from '../../src/routing/hilosAdmin.js'
import { createPageRouter } from '../../src/routing/PageRouter.js'
import {
  HilosPages,
  HILOS_ROUTE_DECLARATIONS,
  HILOS_PAGE_ROUTES,
  HILOS_FOOTER_LINKS,
} from '../../src/routing/hilosPages.js'

describe('HILOS_ROUTE_DECLARATIONS', () => {
  it('addresses all five i18n detail pages by code', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS, {
      fallback: HilosPages.DASHBOARD,
    })
    const addresses = [
      [
        HilosPages.I18N_LANGUAGE,
        'hilos_i18n_language',
        '/hilos/i18n/languages/{languageCode}',
        { languageCode: 'en' },
        '/hilos/i18n/languages/en',
      ],
      [
        HilosPages.I18N_LANGUAGE_NAMES,
        'hilos_i18n_language_names',
        '/hilos/i18n/languages/{languageCode}/names',
        { languageCode: 'en' },
        '/hilos/i18n/languages/en/names',
      ],
      [
        HilosPages.I18N_LANGUAGE_LOCALES,
        'hilos_i18n_language_locales',
        '/hilos/i18n/languages/{languageCode}/locales',
        { languageCode: 'en' },
        '/hilos/i18n/languages/en/locales',
      ],
      [
        HilosPages.I18N_COUNTRY,
        'hilos_i18n_country',
        '/hilos/i18n/countries/{countryCode}',
        { countryCode: 'pl' },
        '/hilos/i18n/countries/pl',
      ],
      [
        HilosPages.I18N_COUNTRY_NAMES,
        'hilos_i18n_country_names',
        '/hilos/i18n/countries/{countryCode}/names',
        { countryCode: 'pl' },
        '/hilos/i18n/countries/pl/names',
      ],
    ] as const

    for (const [page, key, template, params, path] of addresses) {
      expect(page).toBe(key)
      expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({
        path: template,
        admin: true,
      })
      expect(resolveHilosPath(page, params)).toBe(path)
      expect(router.match(path)).toEqual({ page, params, admin: true })
    }

    expect(router.match('/hilos/i18n/languages').page).toBe(
      HilosPages.I18N_LANGUAGES,
    )
    expect(router.match('/hilos/i18n/countries').page).toBe(
      HilosPages.I18N_COUNTRIES,
    )
  })

  it('routes Daemon children through a required node segment', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS, {
      fallback: HilosPages.DASHBOARD,
    })
    const children = [
      [HilosPages.DAEMON_WORKERS, 'workers'],
      [HilosPages.DAEMON_AGENTS, 'agents'],
      [HilosPages.DAEMON_CRON, 'cron'],
      [HilosPages.DAEMON_WEBSOCKETS, 'websockets'],
      [HilosPages.DAEMON_HTTP_SERVER, 'http'],
      [HilosPages.DAEMON_ENV, 'env'],
    ] as const

    expect(router.match('/hilos/daemon').page).toBe(HilosPages.DAEMON)
    for (const [page, tail] of children) {
      const path = `/hilos/daemon/standalone/${tail}`
      expect(resolveHilosPath(page, { nodeId: 'standalone' })).toBe(path)
      expect(router.match(path)).toEqual({
        page,
        params: { nodeId: 'standalone' },
        admin: true,
      })
      expect(router.match(`/hilos/daemon/${tail}`).page).toBe(
        HilosPages.DASHBOARD,
      )
    }
    expect(router.match('/hilos/daemon/env-mismatch')).toEqual({
      page: HilosPages.DAEMON_ENV_MISMATCH,
      params: {},
      admin: true,
    })
    expect(router.match('/hilos/daemon/http/old-server').page).toBe(
      HilosPages.DASHBOARD,
    )
  })

  it('declares a route for every Hilos page key', () => {
    for (const key of Object.values(HilosPages)) {
      expect(HILOS_ROUTE_DECLARATIONS[key].path).toBeTypeOf('string')
      expect(HILOS_ROUTE_DECLARATIONS[key].admin).toBeTypeOf('boolean')
    }
    expect(Object.keys(HILOS_ROUTE_DECLARATIONS)).toHaveLength(
      Object.values(HilosPages).length,
    )
  })

  it('mounts admin paths under /hilos and personal/public pages at the root', () => {
    // Root-routed framework pages: the public footer pages plus the personal
    // profile pages. Every other Hilos page is admin and lives under /hilos.
    const rootPages = new Set<string>([
      HilosPages.PROFILE,
      HilosPages.PROFILE_SIGN_IN,
      HilosPages.PROFILE_NOTIFICATIONS,
      HilosPages.PROFILE_SESSIONS,
      HilosPages.PROFILE_DEVICES,
      HilosPages.PROFILE_SECURITY,
      HilosPages.PROFILE_DATA,
      HilosPages.PROFILE_AGREEMENTS,
      HilosPages.PROFILE_AGREEMENTS_HISTORY,
      ...HILOS_FOOTER_LINKS.map((l) => l.page),
    ])
    for (const [page, declaration] of Object.entries(
      HILOS_ROUTE_DECLARATIONS,
    )) {
      expect(declaration.path.startsWith('/hilos')).toBe(!rootPages.has(page))
      // The address is not the rule — a project may mount its admin anywhere —
      // but the framework's own rows must agree with their own convention, or a
      // mistyped flag hides the verifier form from a page that needs it.
      expect(declaration.admin).toBe(declaration.path.startsWith('/hilos'))
    }
  })

  it('derives the path map from the declarations', () => {
    expect(HILOS_PAGE_ROUTES).toEqual(
      Object.fromEntries(
        Object.entries(HILOS_ROUTE_DECLARATIONS).map(([page, declaration]) => [
          page,
          declaration.path,
        ]),
      ),
    )
  })

  it('exposes the public framework pages as footer links with root routes', () => {
    expect(HILOS_FOOTER_LINKS.map((link) => link.page)).toEqual([
      HilosPages.ABOUT,
      HilosPages.TERMS,
      HilosPages.PRIVACY,
      HilosPages.LICENSE,
    ])
    for (const link of HILOS_FOOTER_LINKS) {
      expect(link.label).toBeTypeOf('string')
      expect(HILOS_PAGE_ROUTES[link.page]).toBeTypeOf('string')
      expect(HILOS_PAGE_ROUTES[link.page].startsWith('/hilos')).toBe(false)
    }
  })

  it('keeps the static legal pages ahead of document and revision routes', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS, {
      fallback: HilosPages.DASHBOARD,
    })
    expect(router.match('/hilos/legal/terms/2026-09-27')).toEqual({
      page: HilosPages.LEGAL_REVISION,
      params: { documentKey: 'terms', revisionId: '2026-09-27' },
      admin: true,
    })
    expect(router.match('/hilos/legal/acceptances').page).toBe(
      HilosPages.LEGAL_ACCEPTANCES,
    )
    expect(router.match('/hilos/legal/settings').page).toBe(
      HilosPages.LEGAL_SETTINGS,
    )
  })

  it('routes its own templated paths back to their page keys', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS, {
      fallback: HilosPages.DASHBOARD,
    })
    expect(router.match('/hilos').page).toBe(HilosPages.DASHBOARD)
    expect(router.match('/profile').page).toBe(HilosPages.PROFILE)
    expect(router.match('/about').page).toBe(HilosPages.ABOUT)
    expect(router.match('/hilos/users')).toEqual({
      page: HilosPages.USERS,
      params: {},
      admin: true,
    })
    // The optional tail narrows the list to the people past a deadline (HIL-945).
    expect(router.match('/hilos/users/terms')).toEqual({
      page: HilosPages.USERS,
      params: { lapsed: 'terms' },
      admin: true,
    })
    expect(router.match('/hilos/user/5')).toEqual({
      page: HilosPages.USER,
      params: { userId: '5' },
      admin: true,
    })
    expect(router.match('/hilos/billing/stripe/payments')).toEqual({
      page: HilosPages.BILLING_PAYMENTS,
      params: { providerId: 'stripe' },
      admin: true,
    })
  })
})

describe('the log viewer route', () => {
  it('resolves to the bare path when nothing is chosen yet', () => {
    // Entered from the section tree, the viewer names no file: the unfilled
    // tail is cut together with its slashes rather than left as empty segments.
    expect(resolveHilosPath(HilosPages.LOGS_VIEW)).toBe('/hilos/logs/view')
  })

  it('resolves to the file it is showing once one is chosen', () => {
    expect(
      resolveHilosPath(HilosPages.LOGS_VIEW, {
        nodeId: '-',
        source: 'live',
        stream: 'worker-0.log',
      }),
    ).toBe('/hilos/logs/view/-/live/worker-0.log')
  })

  it('keeps the address whole when only part of the tail is known', () => {
    // A half-filled address would open the wrong file: the slots are
    // positional, and a skipped node would slide the source into its place.
    expect(resolveHilosPath(HilosPages.LOGS_VIEW, { source: 'live' })).toBe(
      '/hilos/logs/view/live',
    )
  })

  it('routes both its bare and its full address to the viewer page', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS, {
      fallback: HilosPages.DASHBOARD,
    })
    expect(router.match('/hilos/logs/view')).toEqual({
      page: HilosPages.LOGS_VIEW,
      params: {},
      admin: true,
    })
    expect(router.match('/hilos/logs/view/-/1756166400/worker-0.log')).toEqual({
      page: HilosPages.LOGS_VIEW,
      params: {
        nodeId: '-',
        source: '1756166400',
        stream: 'worker-0.log',
      },
      admin: true,
    })
    // The rotations page carries its state filter in an optional tail (HIL-903).
    expect(router.match('/hilos/logs/rotations')).toEqual({
      page: HilosPages.LOGS_ROTATIONS,
      params: {},
      admin: true,
    })
    expect(router.match('/hilos/logs/rotations/due')).toEqual({
      page: HilosPages.LOGS_ROTATIONS,
      params: { state: 'due' },
      admin: true,
    })
  })
})
