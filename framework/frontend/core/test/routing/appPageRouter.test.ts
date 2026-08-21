import { describe, expect, it } from 'vitest'
import { createAppPageRouter } from '../../src/routing/appPageRouter.js'

describe('createAppPageRouter', () => {
  it('matches the project routes it is given', () => {
    const router = createAppPageRouter(
      {
        main: { path: '/', admin: false },
        profile: { path: '/profile', admin: false },
      },
      { fallback: 'main' },
    )

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
    const router = createAppPageRouter(
      { main: { path: '/', admin: false } },
      { fallback: 'main' },
    )

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
    const router = createAppPageRouter(
      { hilos: { path: '/home', admin: false } },
      { fallback: 'main' },
    )

    expect(router.match('/home')).toEqual({
      page: 'hilos',
      params: {},
      admin: false,
    })
  })

  it('overrides the surface type along with the path on a key collision', () => {
    const router = createAppPageRouter(
      { hilos_profile: { path: '/profile', admin: true } },
      { fallback: 'main' },
    )

    expect(router.match('/profile').admin).toBe(true)
  })

  it('falls back to the configured page for an unmatched path', () => {
    const router = createAppPageRouter(
      { main: { path: '/', admin: false } },
      { fallback: 'main' },
    )

    expect(router.match('/nope')).toEqual({
      page: 'main',
      params: {},
      admin: false,
    })
  })
})
