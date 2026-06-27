import { describe, expect, it } from 'vitest'
import { createAppPageRouter } from '../../src/routing/appPageRouter.js'

describe('createAppPageRouter', () => {
  it('matches the project routes it is given', () => {
    const router = createAppPageRouter(
      { main: '/', profile: '/profile' },
      { fallback: 'main' },
    )

    expect(router.match('/')).toEqual({ page: 'main', params: {} })
    expect(router.match('/profile')).toEqual({ page: 'profile', params: {} })
  })

  it('mounts the Hilos admin catalog alongside the project routes', () => {
    const router = createAppPageRouter({ main: '/' }, { fallback: 'main' })

    expect(router.match('/hilos')).toEqual({ page: 'hilos', params: {} })
    expect(router.match('/hilos/user/42')).toEqual({
      page: 'hilos_user',
      params: { userId: '42' },
    })
  })

  it('lets a project route win when its key collides with an admin one', () => {
    const router = createAppPageRouter({ hilos: '/home' }, { fallback: 'main' })

    expect(router.match('/home')).toEqual({ page: 'hilos', params: {} })
  })

  it('falls back to the configured page for an unmatched path', () => {
    const router = createAppPageRouter({ main: '/' }, { fallback: 'main' })

    expect(router.match('/nope')).toEqual({ page: 'main', params: {} })
  })
})
