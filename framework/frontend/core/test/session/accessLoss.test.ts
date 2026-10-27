import { afterEach, describe, expect, it, vi } from 'vitest'
import { type HilosConnection, type ProjectSignal } from '../../src/index.js'
import {
  ACCESS_LOSS_COPY,
  bindAccessLoss,
  hilosAccessLossReason,
  type AccessLossReason,
} from '../../src/session/accessLoss.js'
import {
  bindSessionScope,
  sessionAccessLossReason,
  type SessionScopeOptions,
} from '../../src/session/sessionScope.js'
import { createHilosRouter } from '../../src/routing/HilosRouter.js'
import { createPageRouter } from '../../src/routing/PageRouter.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { computedSignal, subscribeSignal } from '../../src/state/signal.js'
import { bindPageScope } from '../../src/subscription/bindPageScope.js'

const releases: Array<() => void> = []
afterEach(() => {
  for (const release of releases.splice(0)) release()
})

/** Session and page binders consume the same synchronous project emitter. */
function setup(options: SessionScopeOptions = {}) {
  const listeners: Array<(signal: ProjectSignal) => void> = []
  const sent: string[] = []
  const forgetPageFrames = vi.fn()
  const source = {
    state: 'connected',
    reconnectDragging: false,
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal')
        listeners.push(listener as (signal: ProjectSignal) => void)
      return () => {}
    },
    send(text: string): boolean {
      sent.push(text)
      return true
    },
    forgetPageFrames,
    tableWindowDescriptors: () => ({}),
  }
  const connection = source as unknown as HilosConnection
  const scopes = new ScopeManager()
  bindSessionScope(connection, scopes, options)
  const pages = bindPageScope(connection, scopes)
  let path = '/old'
  const router = createHilosRouter(
    createPageRouter({
      old: { path: '/old', admin: false },
      next: { path: '/next', admin: false },
    }),
    pages,
    {
      pathname: () => path,
      pushState: (next) => {
        path = next
      },
      replaceState: (next) => {
        path = next
      },
      onPopState: () => () => {},
    },
  )
  const release = bindAccessLoss(scopes, router, options)
  releases.push(release)
  pages.releaseOnSession()
  router.start()
  const emit = (type: string, data: unknown): void => {
    for (const listener of listeners) {
      listener({
        kind: 'project',
        type,
        data,
        envelope: {},
      } as unknown as ProjectSignal)
    }
  }
  const handshake = (
    id: number | null,
    data: Record<string, unknown> = {},
  ): void => {
    emit('handshake_response', {
      entities: {
        [options.currentUserSlot ?? 'currentUser']: id === null ? null : { id },
      },
      data: { accountBlocked: null, ...data },
    })
  }
  const answer = (): void =>
    emit('page_response', {
      page: 'old',
      payload: { data: { privateValue: 'private' } },
    })
  return { scopes, router, sent, forgetPageFrames, handshake, answer, release }
}

/** The blocked surface supplied by the same identity-loss frame. */
function lossData(reason: AccessLossReason): Record<string, unknown> {
  return {
    accessLossReason: reason,
    accountBlocked:
      reason === 'account_blocked' ? { identifier: 'ada@example.com' } : null,
  }
}

describe('accessLoss', () => {
  it('owns exactly the two approved explanations', () => {
    expect(ACCESS_LOSS_COPY).toEqual({
      session_ended: 'Your session has ended. Any unsaved changes were lost.',
      account_blocked: 'Any unsaved changes were lost.',
    })
  })

  it.each(['session_ended', 'account_blocked'] as const)(
    'latches %s before dropping page data, without subscribing or navigating',
    (reason) => {
      const s = setup()
      s.handshake(7)
      s.answer()
      const observed: Array<AccessLossReason | null> = []
      const pageValue = computedSignal(() =>
        s.scopes.page()?.data.signal('privateValue').get(),
      )
      const off = subscribeSignal(pageValue, () =>
        observed.push(hilosAccessLossReason.get()),
      )
      const sentBefore = [...s.sent]
      const forgottenBefore = s.forgetPageFrames.mock.calls.length

      s.handshake(null, lossData(reason))

      expect(hilosAccessLossReason.get()).toBe(reason)
      expect(observed).toEqual([reason])
      expect(pageValue.get()).toBeUndefined()
      expect(s.forgetPageFrames).toHaveBeenCalledTimes(forgottenBefore + 1)
      expect(s.sent).toEqual(sentBefore)
      expect(s.router.currentPath.get()).toBe('/old')
      expect(s.router.currentRoute.get().page).toBe('old')
      expect(s.router.pageLoading.get()).toBe(true)
      // The guest's eventual page answer cannot remove the explanation.
      s.answer()
      expect(hilosAccessLossReason.get()).toBe(reason)
      off()
    },
  )

  it.each(['session_ended', 'account_blocked'] as const)(
    'keeps %s through repeated anonymous frames and a reconnect with null reason',
    (reason) => {
      const s = setup()
      s.handshake(7)
      s.handshake(null, lossData(reason))
      const observed: unknown[] = []
      const off = subscribeSignal(hilosAccessLossReason, (value) =>
        observed.push(value),
      )
      const forgottenBefore = s.forgetPageFrames.mock.calls.length
      s.handshake(null, lossData(reason))
      s.handshake(null, { ...lossData(reason), accessLossReason: null })
      s.handshake(null, { accountBlocked: lossData(reason).accountBlocked })
      expect(sessionAccessLossReason(s.scopes).get()).toBeNull()
      expect(hilosAccessLossReason.get()).toBe(reason)
      expect(observed).toEqual([])
      expect(s.forgetPageFrames).toHaveBeenCalledTimes(forgottenBefore)
      off()
    },
  )

  it.each([
    {},
    { accessLossReason: 'session_ended' },
    lossData('account_blocked'),
  ])(
    'does not treat a cold guest or first blocked sign-in as live loss: %j',
    (data) => {
      const s = setup()
      s.handshake(null, data)
      expect(hilosAccessLossReason.get()).toBeNull()
    },
  )

  it.each([undefined, null, 'unknown', 42, ['session_ended']])(
    'does not latch voluntary logout or an unrecognized reason: %j',
    (reason) => {
      const s = setup()
      s.handshake(7)
      s.answer()
      const before = s.forgetPageFrames.mock.calls.length
      s.handshake(
        null,
        reason === undefined ? {} : { accessLossReason: reason },
      )
      expect(hilosAccessLossReason.get()).toBeNull()
      expect(s.router.pageLoading.get()).toBe(false)
      expect(s.forgetPageFrames).toHaveBeenCalledTimes(before)
    },
  )

  it('does not reuse the reason from an earlier handshake', () => {
    const s = setup()
    s.handshake(7, { accessLossReason: 'session_ended' })
    s.handshake(null)
    expect(hilosAccessLossReason.get()).toBeNull()
  })

  it.each(['session_ended', 'account_blocked'] as const)(
    'clears %s on successful identity restoration',
    (reason) => {
      const s = setup()
      s.handshake(7)
      s.handshake(null, lossData(reason))
      s.handshake(8)
      expect(hilosAccessLossReason.get()).toBeNull()
    },
  )

  it('clears session end on explicit navigation and keeps block until its card leaves', () => {
    const s = setup()
    s.handshake(7)
    s.handshake(null, lossData('session_ended'))
    s.router.navigate('/next')
    expect(hilosAccessLossReason.get()).toBeNull()
    s.handshake(7)
    s.handshake(null, lossData('account_blocked'))
    s.router.navigate('/old')
    expect(hilosAccessLossReason.get()).toBe('account_blocked')
    s.handshake(null)
    expect(hilosAccessLossReason.get()).toBeNull()
  })

  it('does not carry a session-end explanation into a new blocked sign-in card', () => {
    const s = setup()
    s.handshake(7)
    s.handshake(null, lossData('session_ended'))
    s.handshake(null, { accountBlocked: { identifier: 'other@example.com' } })
    expect(hilosAccessLossReason.get()).toBeNull()
  })

  it('uses the configured current-user slot and type', () => {
    const s = setup({
      currentUserSlot: 'member',
      currentUserEntityType: 'person',
    })
    s.handshake(7)
    s.handshake(null, lossData('session_ended'))
    expect(hilosAccessLossReason.get()).toBe('session_ended')
  })

  it('releases old identity, card and route listeners and ignores a late disposer', () => {
    const old = setup()
    old.handshake(7)
    old.handshake(null, lossData('account_blocked'))
    const current = setup()
    expect(hilosAccessLossReason.get()).toBeNull()
    current.handshake(8)
    current.handshake(null, lossData('session_ended'))
    old.release()
    old.router.navigate('/next')
    old.handshake(7)
    old.handshake(null, lossData('account_blocked'))
    expect(hilosAccessLossReason.get()).toBe('session_ended')
    current.release()
    expect(hilosAccessLossReason.get()).toBeNull()
    current.handshake(8)
    current.handshake(null, lossData('session_ended'))
    expect(hilosAccessLossReason.get()).toBeNull()
  })

  it('recognizes an already known identity when bound and forgets the old document latch', () => {
    const s = setup()
    s.handshake(7)
    const release = bindAccessLoss(s.scopes, s.router)
    releases.push(release)
    s.handshake(null, lossData('session_ended'))
    expect(hilosAccessLossReason.get()).toBe('session_ended')
    setup()
    expect(hilosAccessLossReason.get()).toBeNull()
  })
})
