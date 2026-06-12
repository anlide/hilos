import { describe, expect, it } from 'vitest'
import { createPageRouter } from './PageRouter.js'

describe('createPageRouter', () => {
  it('matches a static path whole with no params', () => {
    const router = createPageRouter(
      { main: '/', profile: '/profile' },
      { fallback: 'main' },
    )
    expect(router.match('/')).toEqual({ page: 'main', params: {} })
    expect(router.match('/profile')).toEqual({ page: 'profile', params: {} })
  })

  it('captures a single route param', () => {
    const router = createPageRouter(
      { user: '/user/{id}' },
      { fallback: 'main' },
    )
    expect(router.match('/user/42')).toEqual({
      page: 'user',
      params: { id: '42' },
    })
  })

  it('captures multiple params across nested segments', () => {
    const router = createPageRouter(
      { item: '/hilos/i18n/translate/group/{groupId}/item/{itemId}' },
      { fallback: 'main' },
    )
    expect(router.match('/hilos/i18n/translate/group/7/item/19')).toEqual({
      page: 'item',
      params: { groupId: '7', itemId: '19' },
    })
  })

  it('falls back when no template matches', () => {
    const router = createPageRouter(
      { user: '/user/{id}' },
      { fallback: 'main' },
    )
    expect(router.match('/nope')).toEqual({ page: 'main', params: {} })
  })

  it('prefers a static path over a param route at the same depth', () => {
    const router = createPageRouter(
      { list: '/hilos/users', detail: '/hilos/users/{userId}' },
      { fallback: 'main' },
    )
    expect(router.match('/hilos/users')).toEqual({ page: 'list', params: {} })
    expect(router.match('/hilos/users/9')).toEqual({
      page: 'detail',
      params: { userId: '9' },
    })
  })

  it('does not match across a segment boundary', () => {
    const router = createPageRouter(
      { user: '/user/{id}' },
      { fallback: 'main' },
    )
    expect(router.match('/user/1/2')).toEqual({ page: 'main', params: {} })
    expect(router.match('/user/')).toEqual({ page: 'main', params: {} })
  })

  it('treats a literal hyphen segment as a literal, not a regex range', () => {
    const router = createPageRouter(
      { mcp: '/hilos/mcp-skills' },
      { fallback: 'main' },
    )
    expect(router.match('/hilos/mcp-skills')).toEqual({
      page: 'mcp',
      params: {},
    })
    expect(router.match('/hilos/mcpXskills')).toEqual({
      page: 'main',
      params: {},
    })
  })
})
