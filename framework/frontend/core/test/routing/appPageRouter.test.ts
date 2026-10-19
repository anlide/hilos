import { describe, expect, it } from 'vitest'
import { createAppPageRouter } from '../../src/routing/appPageRouter.js'
import { HilosPages } from '../../src/routing/hilosPages.js'

describe('createAppPageRouter', () => {
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
