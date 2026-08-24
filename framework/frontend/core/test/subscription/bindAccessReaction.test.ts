import { describe, expect, it } from 'vitest'
import { bindAccessReaction } from '../../src/subscription/bindAccessReaction.js'
import { type HilosRouter } from '../../src/routing/HilosRouter.js'
import { type PageSubscriptionError } from '../../src/protocol/pageError.js'
import { type PageRouteMatch } from '../../src/routing/PageRouter.js'
import { computedSignal, createSignal } from '../../src/state/signal.js'

/** Who the handshake response says is behind this session, if anybody. */
interface FakeSessionUser {
  id: number
  admin: boolean
}

/**
 * A navigator double: the two signals the reaction reads, and a log of the two
 * calls it can make. Only those four members are touched, so the rest of
 * HilosRouter is stubbed to nothing.
 *
 * The two inputs are DERIVED from one session value rather than set
 * independently, because that is how they behave in the app: both come off the
 * same handshake answer, so signing out moves them in one write and each
 * listener reads the other input already fresh. Two loose signals would let a
 * test arrange a half-signed-out state that cannot occur.
 */
function fakeRouter(route: PageRouteMatch, user: FakeSessionUser | null) {
  const currentRoute = createSignal<PageRouteMatch>(route)
  const pageError = createSignal<PageSubscriptionError | null>(null)
  const session = createSignal<FakeSessionUser | null>(user)
  const isAdmin = computedSignal(() => session.get()?.admin === true)
  const userId = computedSignal(() => session.get()?.id ?? null)
  const calls: string[] = []

  const router = {
    currentRoute,
    pageError,
    denyCurrentPage: () => calls.push('deny'),
    awaitPageAnswer: () => calls.push('await'),
  } as unknown as HilosRouter

  return { router, currentRoute, pageError, session, isAdmin, userId, calls }
}

/** The 403 an administrative page shows a visitor it refuses. */
const forbidden: PageSubscriptionError = {
  page: 'hilos_backup',
  httpCode: 403,
  errorCode: 'forbidden',
  message: 'Access forbidden',
}

/** An administrator standing on an administrative page. */
const administrator: FakeSessionUser = { id: 7, admin: true }

describe('bindAccessReaction', () => {
  it('draws the denial when the marker is lost on an administrative route', () => {
    const { router, session, isAdmin, userId, calls } = fakeRouter(
      { page: 'hilos_backup', params: {}, admin: true },
      administrator,
    )
    bindAccessReaction(router, isAdmin, userId)

    session.set({ id: 7, admin: false })

    expect(calls).toEqual(['deny'])
  })

  it('stays silent when the marker is lost on a non-administrative route', () => {
    const { router, session, isAdmin, userId, calls } = fakeRouter(
      { page: 'profile', params: {}, admin: false },
      administrator,
    )
    bindAccessReaction(router, isAdmin, userId)

    session.set({ id: 7, admin: false })

    expect(calls).toEqual([])
  })

  it('waits for the answer, and draws no 403, when the identity is lost on an administrative route', () => {
    const { router, session, isAdmin, userId, calls } = fakeRouter(
      { page: 'hilos_backup', params: {}, admin: true },
      administrator,
    )
    bindAccessReaction(router, isAdmin, userId)

    session.set(null)

    expect(calls).toEqual(['await'])
  })

  it('stays silent when the identity is lost on a non-administrative route', () => {
    const { router, session, isAdmin, userId, calls } = fakeRouter(
      { page: 'profile', params: {}, admin: false },
      administrator,
    )
    bindAccessReaction(router, isAdmin, userId)

    session.set(null)

    expect(calls).toEqual([])
  })

  it('returns the page to its just-navigated state when the marker is gained', () => {
    const { router, pageError, session, isAdmin, userId, calls } = fakeRouter(
      { page: 'hilos_backup', params: {}, admin: true },
      { id: 7, admin: false },
    )
    pageError.set(forbidden)
    bindAccessReaction(router, isAdmin, userId)

    session.set(administrator)

    expect(calls).toEqual(['await'])
  })

  it('ignores a gained marker while no 403 is displayed', () => {
    const { router, session, isAdmin, userId, calls } = fakeRouter(
      { page: 'hilos_backup', params: {}, admin: true },
      { id: 7, admin: false },
    )
    bindAccessReaction(router, isAdmin, userId)

    session.set(administrator)

    expect(calls).toEqual([])
  })

  it('leaves an error of another kind alone when the marker is gained', () => {
    const { router, pageError, session, isAdmin, userId, calls } = fakeRouter(
      { page: 'user', params: { id: '10' }, admin: false },
      { id: 7, admin: false },
    )
    pageError.set({
      page: 'user',
      httpCode: 404,
      errorCode: 'not_found',
      message: 'No such user',
    })
    bindAccessReaction(router, isAdmin, userId)

    session.set(administrator)

    expect(calls).toEqual([])
  })

  it('stops reacting to either input once unbound', () => {
    const { router, session, isAdmin, userId, calls } = fakeRouter(
      { page: 'hilos_backup', params: {}, admin: true },
      administrator,
    )
    const stop = bindAccessReaction(router, isAdmin, userId)

    stop()
    session.set({ id: 7, admin: false })
    session.set(null)

    expect(calls).toEqual([])
  })
})
