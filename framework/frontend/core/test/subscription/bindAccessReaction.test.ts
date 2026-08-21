import { describe, expect, it } from 'vitest'
import { bindAccessReaction } from '../../src/subscription/bindAccessReaction.js'
import { type HilosRouter } from '../../src/routing/HilosRouter.js'
import { type PageSubscriptionError } from '../../src/protocol/pageError.js'
import { type PageRouteMatch } from '../../src/routing/PageRouter.js'
import { createSignal } from '../../src/state/signal.js'

/**
 * A navigator double: the two signals the reaction reads, and a log of the two
 * calls it can make. Only those four members are touched, so the rest of
 * HilosRouter is stubbed to nothing.
 */
function fakeRouter(route: PageRouteMatch) {
  const currentRoute = createSignal<PageRouteMatch>(route)
  const pageError = createSignal<PageSubscriptionError | null>(null)
  const calls: string[] = []

  const router = {
    currentRoute,
    pageError,
    denyCurrentPage: () => calls.push('deny'),
    awaitPageAnswer: () => calls.push('await'),
  } as unknown as HilosRouter

  return { router, currentRoute, pageError, calls }
}

/** The 403 an administrative page shows a visitor it refuses. */
const forbidden: PageSubscriptionError = {
  page: 'hilos_backup',
  httpCode: 403,
  errorCode: 'forbidden',
  message: 'Access forbidden',
}

describe('bindAccessReaction', () => {
  it('draws the denial when the marker is lost on an administrative route', () => {
    const { router, calls } = fakeRouter({
      page: 'hilos_backup',
      params: {},
      admin: true,
    })
    const isAdmin = createSignal(true)
    bindAccessReaction(router, isAdmin)

    isAdmin.set(false)

    expect(calls).toEqual(['deny'])
  })

  it('stays silent when the marker is lost on a non-administrative route', () => {
    const { router, calls } = fakeRouter({
      page: 'profile',
      params: {},
      admin: false,
    })
    const isAdmin = createSignal(true)
    bindAccessReaction(router, isAdmin)

    isAdmin.set(false)

    expect(calls).toEqual([])
  })

  it('returns the page to its just-navigated state when the marker is gained', () => {
    const { router, pageError, calls } = fakeRouter({
      page: 'hilos_backup',
      params: {},
      admin: true,
    })
    pageError.set(forbidden)
    const isAdmin = createSignal(false)
    bindAccessReaction(router, isAdmin)

    isAdmin.set(true)

    expect(calls).toEqual(['await'])
  })

  it('ignores a gained marker while no 403 is displayed', () => {
    const { router, calls } = fakeRouter({
      page: 'hilos_backup',
      params: {},
      admin: true,
    })
    const isAdmin = createSignal(false)
    bindAccessReaction(router, isAdmin)

    isAdmin.set(true)

    expect(calls).toEqual([])
  })

  it('leaves an error of another kind alone when the marker is gained', () => {
    const { router, pageError, calls } = fakeRouter({
      page: 'user',
      params: { id: '10' },
      admin: false,
    })
    pageError.set({
      page: 'user',
      httpCode: 404,
      errorCode: 'not_found',
      message: 'No such user',
    })
    const isAdmin = createSignal(false)
    bindAccessReaction(router, isAdmin)

    isAdmin.set(true)

    expect(calls).toEqual([])
  })

  it('stops reacting once unbound', () => {
    const { router, calls } = fakeRouter({
      page: 'hilos_backup',
      params: {},
      admin: true,
    })
    const isAdmin = createSignal(true)
    const stop = bindAccessReaction(router, isAdmin)

    stop()
    isAdmin.set(false)

    expect(calls).toEqual([])
  })
})
