import { describe, expect, it } from 'vitest'
import { bindAccessReaction } from '../../src/subscription/bindAccessReaction.js'
import { type HilosRouter } from '../../src/routing/HilosRouter.js'
import {
  PAGE_ERROR_NOT_SERVED,
  type PageSubscriptionError,
} from '../../src/protocol/pageError.js'
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
 *
 * The node's admin view mode is a signal of its own (HIL-1253): it is the
 * node's fact, not the person's, and it does not move under a living process.
 * In the app it lands from the same answer ahead of the marker, so a case sets
 * it before the session moves, never after.
 *
 * @param route The route the tab stands on.
 * @param user Who the handshake response says is behind the session.
 * @param viewModeOn Whether the node is in the admin view mode.
 */
function fakeRouter(
  route: PageRouteMatch,
  user: FakeSessionUser | null,
  viewModeOn = false,
) {
  const currentRoute = createSignal<PageRouteMatch>(route)
  const pageError = createSignal<PageSubscriptionError | null>(null)
  const session = createSignal<FakeSessionUser | null>(user)
  const isAdmin = computedSignal(() => session.get()?.admin === true)
  const userId = computedSignal(() => session.get()?.id ?? null)
  const viewMode = createSignal(viewModeOn)
  const calls: string[] = []

  const router = {
    currentRoute,
    pageError,
    denyCurrentPage: () => calls.push('deny'),
    awaitPageAnswer: () => calls.push('await'),
  } as unknown as HilosRouter

  return {
    router,
    currentRoute,
    pageError,
    session,
    isAdmin,
    userId,
    viewMode,
    calls,
  }
}

/** The 403 an administrative page shows a visitor it refuses. */
const forbidden: PageSubscriptionError = {
  page: 'hilos_backup',
  httpCode: 403,
  errorCode: 'forbidden',
  message: 'Access forbidden',
}

/** The 404 used when this project registered no page class for the route. */
const notServed: PageSubscriptionError = {
  page: 'hilos_backup',
  httpCode: 404,
  errorCode: PAGE_ERROR_NOT_SERVED,
  message: 'Subscription failed',
}

/** An administrator standing on an administrative page. */
const administrator: FakeSessionUser = { id: 7, admin: true }

describe('bindAccessReaction', () => {
  it('draws the denial when the marker is lost on an administrative route', () => {
    const { router, session, isAdmin, userId, viewMode, calls } = fakeRouter(
      { page: 'hilos_backup', params: {}, admin: true },
      administrator,
    )
    bindAccessReaction(router, isAdmin, userId, viewMode)

    session.set({ id: 7, admin: false })

    expect(calls).toEqual(['deny'])
  })

  it('stays silent when the marker is lost on a non-administrative route', () => {
    const { router, session, isAdmin, userId, viewMode, calls } = fakeRouter(
      { page: 'profile', params: {}, admin: false },
      administrator,
    )
    bindAccessReaction(router, isAdmin, userId, viewMode)

    session.set({ id: 7, admin: false })

    expect(calls).toEqual([])
  })

  it('keeps an unserved-page 404 when the admin marker is lost', () => {
    const { router, pageError, session, isAdmin, userId, viewMode, calls } =
      fakeRouter(
        { page: 'hilos_backup', params: {}, admin: true },
        administrator,
      )
    pageError.set(notServed)
    bindAccessReaction(router, isAdmin, userId, viewMode)

    session.set({ id: 7, admin: false })

    expect(calls).toEqual([])
  })

  it('waits for the answer, and draws no 403, when the identity is lost on an administrative route', () => {
    const { router, session, isAdmin, userId, viewMode, calls } = fakeRouter(
      { page: 'hilos_backup', params: {}, admin: true },
      administrator,
    )
    bindAccessReaction(router, isAdmin, userId, viewMode)

    session.set(null)

    expect(calls).toEqual(['await'])
  })

  it('stays silent when the identity is lost on a non-administrative route', () => {
    const { router, session, isAdmin, userId, viewMode, calls } = fakeRouter(
      { page: 'profile', params: {}, admin: false },
      administrator,
    )
    bindAccessReaction(router, isAdmin, userId, viewMode)

    session.set(null)

    expect(calls).toEqual([])
  })

  it('keeps an unserved-page 404 when the identity is lost', () => {
    const { router, pageError, session, isAdmin, userId, viewMode, calls } =
      fakeRouter(
        { page: 'hilos_backup', params: {}, admin: true },
        administrator,
      )
    pageError.set(notServed)
    bindAccessReaction(router, isAdmin, userId, viewMode)

    session.set(null)

    expect(calls).toEqual([])
  })

  it('still waits after identity loss on an ordinary not-found page', () => {
    const { router, pageError, session, isAdmin, userId, viewMode, calls } =
      fakeRouter(
        { page: 'hilos_backup', params: {}, admin: true },
        administrator,
      )
    pageError.set({
      page: 'hilos_backup',
      httpCode: 404,
      errorCode: 'not_found',
      message: 'No such backup',
    })
    bindAccessReaction(router, isAdmin, userId, viewMode)

    session.set(null)

    expect(calls).toEqual(['await'])
  })

  it('returns the page to its just-navigated state when the marker is gained', () => {
    const { router, pageError, session, isAdmin, userId, viewMode, calls } =
      fakeRouter(
        { page: 'hilos_backup', params: {}, admin: true },
        { id: 7, admin: false },
      )
    pageError.set(forbidden)
    bindAccessReaction(router, isAdmin, userId, viewMode)

    session.set(administrator)

    expect(calls).toEqual(['await'])
  })

  it('ignores a gained marker while no 403 is displayed', () => {
    const { router, session, isAdmin, userId, viewMode, calls } = fakeRouter(
      { page: 'hilos_backup', params: {}, admin: true },
      { id: 7, admin: false },
    )
    bindAccessReaction(router, isAdmin, userId, viewMode)

    session.set(administrator)

    expect(calls).toEqual([])
  })

  it('leaves an error of another kind alone when the marker is gained', () => {
    const { router, pageError, session, isAdmin, userId, viewMode, calls } =
      fakeRouter(
        { page: 'user', params: { id: '10' }, admin: false },
        { id: 7, admin: false },
      )
    pageError.set({
      page: 'user',
      httpCode: 404,
      errorCode: 'not_found',
      message: 'No such user',
    })
    bindAccessReaction(router, isAdmin, userId, viewMode)

    session.set(administrator)

    expect(calls).toEqual([])
  })

  it('waits for the view, and draws no 403, when the marker is lost on an administrative route in the view mode', () => {
    const { router, session, isAdmin, userId, viewMode, calls } = fakeRouter(
      { page: 'hilos_users', params: {}, admin: true },
      administrator,
      true,
    )
    bindAccessReaction(router, isAdmin, userId, viewMode)

    session.set({ id: 7, admin: false })

    expect(calls).toEqual(['await'])
  })

  it('stays silent when the marker is lost on a non-administrative route in the view mode', () => {
    const { router, session, isAdmin, userId, viewMode, calls } = fakeRouter(
      { page: 'profile', params: {}, admin: false },
      administrator,
      true,
    )
    bindAccessReaction(router, isAdmin, userId, viewMode)

    session.set({ id: 7, admin: false })

    expect(calls).toEqual([])
  })

  it('drops the view and waits for the full page when the marker is gained by a viewer who was looking', () => {
    const { router, session, isAdmin, userId, viewMode, calls } = fakeRouter(
      { page: 'hilos_users', params: {}, admin: true },
      { id: 7, admin: false },
      true,
    )
    bindAccessReaction(router, isAdmin, userId, viewMode)

    session.set(administrator)

    expect(calls).toEqual(['await'])
  })

  it('still undoes a displayed 403 when the marker is gained in the view mode', () => {
    const { router, pageError, session, isAdmin, userId, viewMode, calls } =
      fakeRouter(
        { page: 'hilos_users', params: {}, admin: true },
        { id: 7, admin: false },
        true,
      )
    pageError.set(forbidden)
    bindAccessReaction(router, isAdmin, userId, viewMode)

    session.set(administrator)

    expect(calls).toEqual(['await'])
  })

  it('stays silent when the marker is gained on a non-administrative route in the view mode', () => {
    const { router, session, isAdmin, userId, viewMode, calls } = fakeRouter(
      { page: 'profile', params: {}, admin: false },
      { id: 7, admin: false },
      true,
    )
    bindAccessReaction(router, isAdmin, userId, viewMode)

    session.set(administrator)

    expect(calls).toEqual([])
  })

  it('leaves an error of another kind alone when the marker is gained in the view mode', () => {
    const { router, pageError, session, isAdmin, userId, viewMode, calls } =
      fakeRouter(
        { page: 'hilos_user', params: { id: '10' }, admin: true },
        { id: 7, admin: false },
        true,
      )
    pageError.set({
      page: 'hilos_user',
      httpCode: 404,
      errorCode: 'not_found',
      message: 'No such user',
    })
    bindAccessReaction(router, isAdmin, userId, viewMode)

    session.set(administrator)

    expect(calls).toEqual([])
  })

  it('waits exactly once when an administrator signs out on an administrative route in the view mode', () => {
    const { router, session, isAdmin, userId, viewMode, calls } = fakeRouter(
      { page: 'hilos_users', params: {}, admin: true },
      administrator,
      true,
    )
    bindAccessReaction(router, isAdmin, userId, viewMode)

    session.set(null)

    expect(calls).toEqual(['await'])
  })

  it('keeps an unserved-page 404 through both marker moves in the view mode', () => {
    const { router, pageError, session, isAdmin, userId, viewMode, calls } =
      fakeRouter(
        { page: 'hilos_backup', params: {}, admin: true },
        administrator,
        true,
      )
    pageError.set(notServed)
    bindAccessReaction(router, isAdmin, userId, viewMode)

    session.set({ id: 7, admin: false })
    session.set(administrator)

    expect(calls).toEqual([])
  })

  it('stops reacting to either input once unbound', () => {
    const { router, session, isAdmin, userId, viewMode, calls } = fakeRouter(
      { page: 'hilos_backup', params: {}, admin: true },
      administrator,
    )
    const stop = bindAccessReaction(router, isAdmin, userId, viewMode)

    stop()
    session.set({ id: 7, admin: false })
    session.set(null)

    expect(calls).toEqual([])
  })
})
