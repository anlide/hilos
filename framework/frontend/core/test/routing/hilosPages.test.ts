import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { resolveHilosPath } from '../../src/routing/hilosAdmin.js'
import { createPageRouter } from '../../src/routing/PageRouter.js'
import { hilosUnbuiltPages } from '../../src/routing/hilosUnbuiltPages.js'
import {
  HilosPages,
  HILOS_ROUTE_DECLARATIONS,
  HILOS_PAGE_ROUTES,
  HILOS_FOOTER_LINKS,
} from '../../src/routing/hilosPages.js'

describe('HILOS_ROUTE_DECLARATIONS', () => {
  it('addresses channel Configuration beside the unchanged Overview and Deliveries', () => {
    const page = HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const php = readFileSync(
      new URL(
        '../../../../backend/Constants/HilosPageConstants.php',
        import.meta.url,
      ),
      'utf8',
    )

    expect(page).toBe('hilos_communications_channel_configuration')
    expect(php).toContain(
      `HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION = '${page}';`,
    )
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({
      path: '/hilos/communications/{channelId}/configuration',
      admin: true,
    })
    expect(resolveHilosPath(page, { channelId: 'email' })).toBe(
      '/hilos/communications/email/configuration',
    )
    expect(router.match('/hilos/communications/email/configuration')).toEqual({
      page,
      params: { channelId: 'email' },
      admin: true,
    })
    expect(
      resolveHilosPath(HilosPages.COMMUNICATIONS_CHANNEL, {
        channelId: 'email',
      }),
    ).toBe('/hilos/communications/email')
    expect(router.match('/hilos/communications/email')).toEqual({
      page: HilosPages.COMMUNICATIONS_CHANNEL,
      params: { channelId: 'email' },
      admin: true,
    })
    expect(router.match('/hilos/communications/email/deliveries').page).toBe(
      HilosPages.COMMUNICATIONS_DELIVERIES,
    )
    expect(hilosUnbuiltPages('vue').has(page)).toBe(false)
    expect(hilosUnbuiltPages('react').has(page)).toBe(false)
    expect(hilosUnbuiltPages('angular').has(page)).toBe(false)
  })

  it('addresses both Status admin pages without parameters and keeps their unbuilt views honest', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    for (const [page, path] of [
      [HilosPages.STATUS, '/hilos/status'],
      [HilosPages.STATUS_COMPONENTS, '/hilos/status/components'],
    ] as const) {
      expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({ path, admin: true })
      expect(resolveHilosPath(page)).toBe(path)
      expect(router.match(path)).toEqual({ page, params: {}, admin: true })
      for (const layer of ['react', 'angular'] as const) {
        expect(hilosUnbuiltPages(layer).has(page)).toBe(true)
      }
      expect(hilosUnbuiltPages('vue').has(page)).toBe(false)
      expect(hilosUnbuiltPages('vue', [page]).has(page)).toBe(false)
      expect(router.match(`${path}/7`).page).toBeNull()
    }
    expect(HilosPages.STATUS).toBe('hilos_status')
    expect(HilosPages.STATUS_COMPONENTS).toBe('hilos_status_components')
    expect(router.match('/status').page).toBeNull()
  })

  it('addresses the Moderation admin page without parameters with the PHP key', () => {
    const page = HilosPages.MODERATION
    const path = '/hilos/moderation'
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const php = readFileSync(
      new URL(
        '../../../../backend/Constants/HilosPageConstants.php',
        import.meta.url,
      ),
      'utf8',
    )

    expect(page).toBe('hilos_moderation')
    expect(php).toContain(`HILOS_MODERATION = '${page}';`)
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({ path, admin: true })
    expect(resolveHilosPath(page)).toBe(path)
    expect(router.match(path)).toEqual({ page, params: {}, admin: true })
    for (const layer of ['react', 'angular'] as const) {
      expect(hilosUnbuiltPages(layer).has(page)).toBe(true)
    }
    expect(hilosUnbuiltPages('vue').has(page)).toBe(false)
    expect(router.match(`${path}/profile_name`)).toEqual({
      page: HilosPages.MODERATION_KIND,
      params: { kind: 'profile_name' },
      admin: true,
    })
  })

  it('addresses a moderation kind with its PHP key and encoded kind parameter', () => {
    const page = HilosPages.MODERATION_KIND
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const php = readFileSync(
      new URL(
        '../../../../backend/Constants/HilosPageConstants.php',
        import.meta.url,
      ),
      'utf8',
    )

    expect(page).toBe('hilos_moderation_kind')
    expect(php).toContain(`HILOS_MODERATION_KIND = '${page}';`)
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({
      path: '/hilos/moderation/{kind}',
      admin: true,
    })
    expect(resolveHilosPath(page, { kind: 'project/kind with spaces' })).toBe(
      '/hilos/moderation/project%2Fkind%20with%20spaces',
    )
    expect(
      router.match('/hilos/moderation/project%2Fkind%20with%20spaces'),
    ).toEqual({
      page,
      params: { kind: 'project/kind with spaces' },
      admin: true,
    })
    expect(router.match('/hilos/moderation/profile_name/rules').page).toBeNull()
  })

  it('addresses Circle without params under Maintenance with the PHP key', () => {
    const page = HilosPages.MAINTENANCE_CIRCLE
    const path = '/hilos/maintenance/circle'
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const php = readFileSync(
      new URL(
        '../../../../backend/Constants/HilosPageConstants.php',
        import.meta.url,
      ),
      'utf8',
    )

    expect(page).toBe('hilos_maintenance_circle')
    expect(php).toContain(`HILOS_MAINTENANCE_CIRCLE = '${page}';`)
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({ path, admin: true })
    expect(resolveHilosPath(page)).toBe(path)
    expect(router.match(path)).toEqual({ page, params: {}, admin: true })
    expect(router.match('/hilos/maintenance')).toEqual({
      page: HilosPages.MAINTENANCE,
      params: {},
      admin: true,
    })
    expect(router.match(`${path}/7`).page).toBeNull()
    expect(hilosUnbuiltPages('vue').has(page)).toBe(false)
    for (const layer of ['react', 'angular'] as const) {
      expect(hilosUnbuiltPages(layer).has(page)).toBe(true)
    }
  })

  it('addresses all five i18n detail pages by code', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
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

  it('addresses a Daemon node with encoded params while static paths retain priority', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    expect(HilosPages.DAEMON_NODE).toBe('hilos_daemon_node')
    expect(HILOS_ROUTE_DECLARATIONS[HilosPages.DAEMON_NODE]).toEqual({
      path: '/hilos/daemon/{nodeId}',
      admin: true,
    })
    const nodeId = 'node / east%?'
    const path = resolveHilosPath(HilosPages.DAEMON_NODE, { nodeId })!
    expect(path).toBe('/hilos/daemon/node%20%2F%20east%25%3F')
    expect(router.match(path)).toEqual({
      page: HilosPages.DAEMON_NODE,
      params: { nodeId },
      admin: true,
    })
    expect(router.match('/hilos/daemon/env-mismatch')).toEqual({
      page: HilosPages.DAEMON_ENV_MISMATCH,
      params: {},
      admin: true,
    })
  })

  it('routes Daemon children through a required node segment', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
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
      expect(router.match(`/hilos/daemon/${tail}`)).toEqual({
        page: HilosPages.DAEMON_NODE,
        params: { nodeId: tail },
        admin: true,
      })
    }
    expect(router.match('/hilos/daemon/env-mismatch')).toEqual({
      page: HilosPages.DAEMON_ENV_MISMATCH,
      params: {},
      admin: true,
    })
    expect(router.match('/hilos/daemon/http/old-server').page).toBeNull()
  })

  it('addresses a browser session by its numeric id and opens it in every view layer', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const page = HilosPages.ANALYTICS_BROWSER_SESSION
    expect(page).toBe('hilos_analytics_browser_session')
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({
      path: '/hilos/analytics/browser-sessions/{browserSessionId}',
      admin: true,
    })
    expect(resolveHilosPath(page, { browserSessionId: '57' })).toBe(
      '/hilos/analytics/browser-sessions/57',
    )
    expect(router.match('/hilos/analytics/browser-sessions/57')).toEqual({
      page,
      params: { browserSessionId: '57' },
      admin: true,
    })
    expect(router.match('/hilos/analytics').page).toBe(HilosPages.ANALYTICS)
    expect(hilosUnbuiltPages('vue').has(page)).toBe(false)
    expect(hilosUnbuiltPages('react').has(page)).toBe(false)
    expect(hilosUnbuiltPages('angular').has(page)).toBe(false)
  })

  it('declares People without period route params and serves its Vue view', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const page = HilosPages.ANALYTICS_PEOPLE
    expect(page).toBe('hilos_analytics_people')
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({
      path: '/hilos/analytics/people',
      admin: true,
    })
    expect(resolveHilosPath(page, {})).toBe('/hilos/analytics/people')
    expect(router.match('/hilos/analytics/people')).toEqual({
      page,
      params: {},
      admin: true,
    })
    expect(hilosUnbuiltPages('vue').has(page)).toBe(false)
    for (const layer of ['react', 'angular'] as const) {
      expect(hilosUnbuiltPages(layer).has(page)).toBe(true)
    }
    for (const layer of ['vue', 'react', 'angular'] as const) {
      expect(hilosUnbuiltPages(layer).has(HilosPages.ANALYTICS_PERSON)).toBe(
        false,
      )
    }
  })

  it('addresses a person in Analytics by their account id and opens it in every view layer', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const page = HilosPages.ANALYTICS_PERSON
    expect(page).toBe('hilos_analytics_person')
    expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({
      path: '/hilos/analytics/people/{userId}',
      admin: true,
    })
    expect(resolveHilosPath(page, { userId: '7' })).toBe(
      '/hilos/analytics/people/7',
    )
    expect(router.match('/hilos/analytics/people/7')).toEqual({
      page,
      params: { userId: '7' },
      admin: true,
    })
    expect(router.match('/hilos/analytics/people').page).toBe(
      HilosPages.ANALYTICS_PEOPLE,
    )
    expect(router.match('/hilos/analytics/browser-sessions/7').page).toBe(
      HilosPages.ANALYTICS_BROWSER_SESSION,
    )
    expect(router.match('/hilos/user/7').page).toBe(HilosPages.USER)
    expect(hilosUnbuiltPages('vue').has(page)).toBe(false)
    expect(hilosUnbuiltPages('react').has(page)).toBe(false)
    expect(hilosUnbuiltPages('angular').has(page)).toBe(false)
  })

  it('routes a user to their roles, access, and sessions tabs', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const php = readFileSync(
      new URL(
        '../../../../backend/Constants/HilosPageConstants.php',
        import.meta.url,
      ),
      'utf8',
    )
    const userTabs = [
      [
        HilosPages.USER_ROLES,
        'hilos_user_roles',
        'HILOS_USER_ROLES',
        '/hilos/user/{userId}/roles',
        '/hilos/user/7/roles',
      ],
      [
        HilosPages.USER_ACCESS,
        'hilos_user_access',
        'HILOS_USER_ACCESS',
        '/hilos/user/{userId}/access',
        '/hilos/user/7/access',
      ],
      [
        HilosPages.USER_SESSIONS,
        'hilos_user_sessions',
        'HILOS_USER_SESSIONS',
        '/hilos/user/{userId}/sessions',
        '/hilos/user/7/sessions',
      ],
    ] as const

    for (const [page, key, phpKey, template, path] of userTabs) {
      const params = { userId: '7' }
      expect(page).toBe(key)
      expect(php).toContain(`${phpKey} = '${page}';`)
      expect(HILOS_ROUTE_DECLARATIONS[page]).toEqual({
        path: template,
        admin: true,
      })
      expect(resolveHilosPath(page, params)).toBe(path)
      expect(router.match(path)).toEqual({ page, params, admin: true })
    }

    expect(router.match('/hilos/user/7')).toEqual({
      page: HilosPages.USER,
      params: { userId: '7' },
      admin: true,
    })

    for (const layer of ['vue', 'react', 'angular'] as const) {
      const unbuilt = hilosUnbuiltPages(layer)
      const projectViews = userTabs.map(([page]) => page)
      for (const [page] of userTabs) {
        expect(unbuilt.has(page)).toBe(true)
        expect(hilosUnbuiltPages(layer, projectViews).has(page)).toBe(false)
      }
    }
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
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
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
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
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
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    expect(router.match('/hilos/logs/view')).toEqual({
      page: HilosPages.LOGS_VIEW,
      params: {},
      admin: true,
    })
    expect(
      router.match('/hilos/logs/view/-/1756166400000/worker-0.log'),
    ).toEqual({
      page: HilosPages.LOGS_VIEW,
      params: {
        nodeId: '-',
        source: '1756166400000',
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
