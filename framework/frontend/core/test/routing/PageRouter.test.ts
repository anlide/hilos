import { describe, expect, it } from 'vitest'
import { createPageRouter } from '../../src/routing/PageRouter.js'

describe('createPageRouter', () => {
  it('matches a static path whole with no params', () => {
    const router = createPageRouter(
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

  it('captures a single route param', () => {
    const router = createPageRouter(
      { user: { path: '/user/{id}', admin: false } },
      { fallback: 'main' },
    )
    expect(router.match('/user/42')).toEqual({
      page: 'user',
      params: { id: '42' },
      admin: false,
    })
  })

  it('captures multiple params across nested segments', () => {
    const router = createPageRouter(
      {
        item: {
          path: '/hilos/i18n/translate/group/{groupId}/item/{itemId}',
          admin: true,
        },
      },
      { fallback: 'main' },
    )
    expect(router.match('/hilos/i18n/translate/group/7/item/19')).toEqual({
      page: 'item',
      params: { groupId: '7', itemId: '19' },
      admin: true,
    })
  })

  it('falls back when no template matches', () => {
    const router = createPageRouter(
      { user: { path: '/user/{id}', admin: false } },
      { fallback: 'main' },
    )
    expect(router.match('/nope')).toEqual({
      page: 'main',
      params: {},
      admin: false,
    })
  })

  it('prefers a static path over a param route at the same depth', () => {
    const router = createPageRouter(
      {
        list: { path: '/hilos/users', admin: true },
        detail: { path: '/hilos/users/{userId}', admin: true },
      },
      { fallback: 'main' },
    )
    expect(router.match('/hilos/users')).toEqual({
      page: 'list',
      params: {},
      admin: true,
    })
    expect(router.match('/hilos/users/9')).toEqual({
      page: 'detail',
      params: { userId: '9' },
      admin: true,
    })
  })

  it('does not match across a segment boundary', () => {
    const router = createPageRouter(
      { user: { path: '/user/{id}', admin: false } },
      { fallback: 'main' },
    )
    expect(router.match('/user/1/2')).toEqual({
      page: 'main',
      params: {},
      admin: false,
    })
    expect(router.match('/user/')).toEqual({
      page: 'main',
      params: {},
      admin: false,
    })
  })

  it('treats a literal hyphen segment as a literal, not a regex range', () => {
    const router = createPageRouter(
      { mcp: { path: '/hilos/mcp-skills', admin: true } },
      { fallback: 'main' },
    )
    expect(router.match('/hilos/mcp-skills')).toEqual({
      page: 'mcp',
      params: {},
      admin: true,
    })
    expect(router.match('/hilos/mcpXskills')).toEqual({
      page: 'main',
      params: {},
      admin: false,
    })
  })

  it('carries the declared surface type onto the match', () => {
    const router = createPageRouter(
      {
        main: { path: '/', admin: false },
        panel: { path: '/panel', admin: true },
        record: { path: '/panel/record/{recordId}', admin: true },
      },
      { fallback: 'main' },
    )
    expect(router.match('/').admin).toBe(false)
    expect(router.match('/panel').admin).toBe(true)
    expect(router.match('/panel/record/3').admin).toBe(true)
  })

  it('takes the surface type of an unmatched path from the fallback page', () => {
    const publicFallback = createPageRouter(
      {
        main: { path: '/', admin: false },
        panel: { path: '/panel', admin: true },
      },
      { fallback: 'main' },
    )
    // A typo in an administrative url is not an administrative surface: that
    // route does not exist, so the fallback page answers with its own type.
    expect(publicFallback.match('/pannel').admin).toBe(false)

    const adminFallback = createPageRouter(
      {
        main: { path: '/', admin: false },
        panel: { path: '/panel', admin: true },
      },
      { fallback: 'panel' },
    )
    expect(adminFallback.match('/pannel').admin).toBe(true)
  })

  it('treats a fallback page with no declaration as not administrative', () => {
    const router = createPageRouter(
      { panel: { path: '/panel', admin: true } },
      { fallback: 'missing' },
    )
    expect(router.match('/nope')).toEqual({
      page: 'missing',
      params: {},
      admin: false,
    })
  })
})
