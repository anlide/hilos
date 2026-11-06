import { describe, expect, it } from 'vitest'
import { createAppPageRouter } from '../../src/routing/appPageRouter.js'
import { HilosPages } from '../../src/routing/hilosPages.js'

describe('createAppPageRouter', () => {
  it('splits Analytics session identities from list periods in the real catalog', () => {
    const router = createAppPageRouter({})
    for (const [path, page, params] of [
      [
        '/hilos/analytics/browser-session/64',
        HilosPages.ANALYTICS_BROWSER_SESSION,
        { browserSessionId: '64' },
      ],
      [
        '/hilos/analytics/browser-session/%2B64',
        HilosPages.ANALYTICS_BROWSER_SESSION,
        { browserSessionId: '+64' },
      ],
      [
        '/hilos/analytics/browser-session/0',
        HilosPages.ANALYTICS_BROWSER_SESSION,
        { browserSessionId: '0' },
      ],
      [
        '/hilos/analytics/browser-session/9007199254740992',
        HilosPages.ANALYTICS_BROWSER_SESSION,
        { browserSessionId: '9007199254740992' },
      ],
      [
        '/hilos/analytics/browser-sessions',
        HilosPages.ANALYTICS_BROWSER_SESSIONS,
        {},
      ],
      [
        '/hilos/analytics/browser-sessions/today',
        HilosPages.ANALYTICS_BROWSER_SESSIONS,
        { period: 'today' },
      ],
      [
        '/hilos/analytics/browser-sessions/this-hour',
        HilosPages.ANALYTICS_BROWSER_SESSIONS,
        { period: 'this-hour' },
      ],
      [
        '/hilos/analytics/browser-sessions/this-week',
        HilosPages.ANALYTICS_BROWSER_SESSIONS,
        { period: 'this-week' },
      ],
      [
        '/hilos/analytics/browser-sessions/any-time',
        HilosPages.ANALYTICS_BROWSER_SESSIONS,
        { period: 'any-time' },
      ],
      [
        '/hilos/analytics/browser-sessions/unknown',
        HilosPages.ANALYTICS_BROWSER_SESSIONS,
        { period: 'unknown' },
      ],
      [
        '/hilos/analytics/browser-sessions/64%0A',
        HilosPages.ANALYTICS_BROWSER_SESSIONS,
        { period: '64\n' },
      ],
      [
        '/hilos/analytics/browser-sessions/%252B64',
        HilosPages.ANALYTICS_BROWSER_SESSIONS,
        { period: '%2B64' },
      ],
    ] as const) {
      expect(router.match(path)).toEqual({ page, params, admin: true })
      expect(router.path(page, params)).toBe(path)
    }
    for (const tail of [
      '64',
      '+64',
      '%2B64',
      '%2b%36%34',
      '0',
      '001',
      '9007199254740992',
    ]) {
      expect(router.match(`/hilos/analytics/browser-sessions/${tail}`)).toEqual(
        { page: null, params: {}, admin: false },
      )
      expect(
        router.path(HilosPages.ANALYTICS_BROWSER_SESSIONS, {
          period: decodeURIComponent(tail),
        }),
      ).toBeUndefined()
    }
    for (const browserSessionId of ['today', '64\n', '%2B64']) {
      expect(
        router.path(HilosPages.ANALYTICS_BROWSER_SESSION, { browserSessionId }),
      ).toBeUndefined()
      expect(
        router.match(
          `/hilos/analytics/browser-session/${encodeURIComponent(browserSessionId)}`,
        ),
      ).toEqual({ page: null, params: {}, admin: false })
    }
    expect(router.match('/hilos/analytics/person/7')).toEqual({
      page: HilosPages.ANALYTICS_PERSON,
      params: { userId: '7' },
      admin: true,
    })
    expect(router.match('/hilos/analytics/people')).toEqual({
      page: HilosPages.ANALYTICS_PEOPLE,
      params: {},
      admin: true,
    })
    for (const tail of [
      '%',
      '%2',
      '%GG',
      '%C3%28',
      '%ED%A0%80',
      '%F4%90%80%80',
    ]) {
      for (const prefix of ['browser-session', 'browser-sessions']) {
        expect(router.match(`/hilos/analytics/${prefix}/${tail}`)).toEqual({
          page: null,
          params: {},
          admin: false,
        })
      }
    }
  })

  it('keeps constrained project routes beside plain routes and the catalog', () => {
    const router = createAppPageRouter({
      entity: {
        path: '/records/{id}',
        admin: false,
        paramPatterns: { id: '\\+?\\d+' },
      },
      list: { path: '/records/{period?}', admin: false },
      plain: { path: '/project/{id}', admin: false },
    })
    for (const [path, page, params] of [
      ['/records/64', 'entity', { id: '64' }],
      ['/records/%2B64', 'entity', { id: '+64' }],
      ['/records/today', 'list', { period: 'today' }],
      ['/records/unknown', 'list', { period: 'unknown' }],
      ['/records', 'list', {}],
      ['/project/word', 'plain', { id: 'word' }],
    ] as const) {
      expect(router.match(path)).toEqual({ page, params, admin: false })
      expect(router.path(page, params)).toBe(path)
    }
    expect(router.path('entity', { id: 'today' })).toBeUndefined()
    expect(router.match('/hilos/user/42')).toEqual({
      page: HilosPages.USER,
      params: { userId: '42' },
      admin: true,
    })
  })

  it('shares one codec between project routes and the framework catalog', () => {
    const router = createAppPageRouter({
      main: { path: '/', admin: false },
      project: { path: '/project/{id}', admin: false },
    })
    const value = 'raw /?#%2F🌍'
    for (const [page, params] of [
      ['project', { id: value }],
      [HilosPages.DAEMON_WORKERS, { nodeId: value }],
      [HilosPages.LOGS_VIEW, { nodeId: value, source: 'live' }],
    ] as const) {
      const path = router.path(page, params)!
      expect(path).toContain(encodeURIComponent(value))
      expect(router.match(path).params).toEqual(params)
      expect(router.match(path).page).toBe(page)
    }
  })

  it('matches the project routes it is given', () => {
    const router = createAppPageRouter({
      main: { path: '/', admin: false },
      profile: { path: '/profile', admin: false },
    })

    expect(router.match('/')).toEqual({
      page: 'main',
      params: {},
      admin: false,
    })
    expect(router.match('/profile')).toEqual({
      page: 'profile',
      params: {},
      admin: false,
    })
  })

  it('mounts the Hilos admin catalog alongside the project routes', () => {
    const router = createAppPageRouter({ main: { path: '/', admin: false } })

    expect(router.match('/hilos')).toEqual({
      page: 'hilos',
      params: {},
      admin: true,
    })
    expect(router.match('/hilos/user/42')).toEqual({
      page: 'hilos_user',
      params: { userId: '42' },
      admin: true,
    })
  })

  it('lets a project route win when its key collides with an admin one', () => {
    const router = createAppPageRouter({
      hilos: { path: '/home', admin: false },
    })

    expect(router.match('/home')).toEqual({
      page: 'hilos',
      params: {},
      admin: false,
    })
  })

  it('overrides the surface type along with the path on a key collision', () => {
    const router = createAppPageRouter({
      hilos_profile: { path: '/profile', admin: true },
    })

    expect(router.match('/profile').admin).toBe(true)
  })

  it('does not turn an unknown path beside the admin catalog into the dashboard', () => {
    const router = createAppPageRouter({ main: { path: '/', admin: false } })

    expect(router.match('/nope')).toEqual({
      page: null,
      params: {},
      admin: false,
    })
    expect(router.match('/hilos')).toEqual({
      page: 'hilos',
      params: {},
      admin: true,
    })
  })
})
