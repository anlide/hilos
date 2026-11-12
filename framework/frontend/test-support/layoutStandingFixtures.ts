import {
  ActionLifecycle,
  applyServerTime,
  bindAccountStanding,
  bindImpersonation,
  bindLegalReconsent,
  bindSessionScope,
  bindSignOut,
  hilosLegalReconsent,
  offsetMs,
  ScopeManager,
  SIGNAL_HANDSHAKE_RESPONSE,
  SIGNAL_TYPE_ACTION_ERROR,
  type HilosConnection,
  type HilosConnectionEventMap,
  type ProjectSignal,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import { SIGNAL_TYPE_ACTION_SUCCESS } from '../core/src/protocol/constants.js'

export const STANDING_NOW = 1_800_000_000_000

/** Raw standing and identity inputs, before the session scope ingests them. */
export function layoutStandingGreeting(
  shown: string,
  impersonated = false,
  deletionEffectiveAt: number | null = null,
  deletionGraceDays: number | null = null,
): Record<string, unknown> {
  return {
    data: {
      serverTimeMs: STANDING_NOW,
      accountStanding: {
        shown,
        blocked: shown === 'blocked',
        frozen: shown === 'frozen',
        deletionEffectiveAt,
        deletionGraceDays,
        lapsed: [],
        window: [],
        mergedInto: null,
        mergedIntoName: null,
      },
    },
    entities: {
      currentUser: { id: 2, name: 'Bob' },
      impersonatedBy: impersonated ? { id: 1, name: 'Ada' } : null,
    },
  }
}

/** Bind the real standing readers and retain ownership of their raw source. */
export async function layoutStandingSession() {
  const listeners = new Map<
    keyof HilosConnectionEventMap,
    Set<(payload: never) => void>
  >()
  const sent: { action: string; data: unknown; requestId?: string }[] = []
  const releases: (() => void)[] = []
  let released = false
  let releasing: Promise<void> | undefined
  let ownsReconsent = false

  function emit<E extends keyof HilosConnectionEventMap>(
    event: E,
    payload: HilosConnectionEventMap[E],
  ): void {
    for (const listener of listeners.get(event) ?? [])
      listener(payload as never)
  }
  const source = {
    sendAction(action, data, requestId) {
      if (released) return false
      sent.push({ action, data, requestId })
      return true
    },
    on(event, listener) {
      if (released) return () => {}
      const subscriptions = listeners.get(event) ?? new Set()
      listeners.set(event, subscriptions)
      const notify = (payload: never): void => listener(payload)
      subscriptions.add(notify)
      return () => {
        subscriptions.delete(notify)
      }
    },
  } satisfies Pick<HilosConnection, 'on' | 'sendAction'>

  function release(): Promise<void> {
    if (releasing) return releasing
    released = true
    releasing = (async () => {
      const errors: unknown[] = []
      try {
        // Settle pending actions before detaching any of their readers.
        emit('state', 'disconnected')
      } catch (error) {
        errors.push(error)
      }
      try {
        for (const unbind of releases.splice(0).reverse()) {
          try {
            unbind()
          } catch (error) {
            errors.push(error)
          }
        }
      } finally {
        try {
          // bindSessionScope and ActionLifecycle expose no dispose handle.
          listeners.clear()
          await Promise.resolve()
        } finally {
          // Closing an already closed window does not reset its content store.
          if (ownsReconsent) hilosLegalReconsent.reset()
        }
      }
      if (errors.length) throw new AggregateError(errors)
    })()
    return releasing
  }

  try {
    const scopes = new ScopeManager()
    bindSessionScope(source as unknown as HilosConnection, scopes)
    const actions = new ActionLifecycle(source)
    releases.push(bindAccountStanding(scopes, actions))
    releases.push(bindImpersonation(scopes, actions))
    // Layout reads signed-in identity as well as the standing and takeover.
    releases.push(bindSignOut(scopes, actions))
    releases.push(bindLegalReconsent(scopes, actions))
    ownsReconsent = true
    return {
      sent,
      // A typed source event, not transport parsing or a physical welcome.
      handshake(payload: Record<string, unknown>): void {
        if (released) return
        emit('projectSignal', {
          kind: 'project',
          type: SIGNAL_HANDSHAKE_RESPONSE,
          data: payload,
          envelope: {},
        } as ProjectSignal)
      },
      succeed(requestId: string | undefined, action: string): void {
        if (released) return
        emit('actionSuccess', {
          kind: 'actionSuccess',
          action,
          requestId,
          message: undefined,
          reply: undefined,
          envelope: {
            type: SIGNAL_TYPE_ACTION_SUCCESS,
            data: { action },
            requestId,
          },
        })
      },
      refuse(
        requestId: string | undefined,
        reason: string,
        action: string,
      ): void {
        if (released) return
        emit('actionError', {
          kind: 'actionError',
          action,
          reason,
          requestId,
          errorCode: undefined,
          errorType: undefined,
          errorDetail: undefined,
          envelope: {
            type: SIGNAL_TYPE_ACTION_ERROR,
            data: { action, reason },
            requestId,
          },
        })
      },
      release,
    }
  } catch (error) {
    await release()
    throw error
  }
}

/** Own only Date and minute intervals, preserving the entry server offset. */
export async function withLayoutStandingClock(
  vi: typeof Vitest.vi,
  run: () => Promise<void>,
): Promise<void> {
  const entryOffset = offsetMs()
  try {
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
    vi.setSystemTime(STANDING_NOW)
    await run()
  } finally {
    try {
      applyServerTime(Date.now() + entryOffset)
    } finally {
      vi.useRealTimers()
    }
  }
}
