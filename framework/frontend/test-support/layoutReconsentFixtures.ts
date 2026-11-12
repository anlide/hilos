import {
  ActionLifecycle,
  applyServerTime,
  bindAccountStanding,
  bindLegalReconsent,
  bindSessionScope,
  bindSignOut,
  hilosLegalReconsent,
  hilosToasts,
  offsetMs,
  ScopeManager,
  SIGNAL_HANDSHAKE_RESPONSE,
  SIGNAL_TYPE_ACTION_ERROR,
  type ConnectionState,
  type HilosAccountStanding,
  type HilosConnection,
  type HilosConnectionEventMap,
  type HilosLegalReconsentContent,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import { SIGNAL_TYPE_ACTION_SUCCESS } from '../core/src/protocol/constants.js'

type ReconsentFacts = Partial<
  Pick<HilosAccountStanding, 'frozen' | 'window' | 'lapsed'>
>

/** The original session input, including an omitted deletion grace period. */
export function layoutReconsentHandshake(facts: ReconsentFacts | null) {
  return {
    data: {
      accountStanding:
        facts === null
          ? null
          : {
              shown: facts.frozen === true ? 'frozen' : 'none',
              blocked: false,
              frozen: false,
              deletionEffectiveAt: null,
              lapsed: [],
              window: [],
              mergedInto: null,
              mergedIntoName: null,
              ...facts,
            },
    },
    entities: {
      currentUser: facts === null ? null : { id: 2, name: 'Bob' },
      impersonatedBy: null,
    },
  }
}

/** Own the real legal readers and the lifecycle of their raw session source. */
export async function layoutReconsentSession() {
  const listeners = new Map<
    keyof HilosConnectionEventMap,
    Set<(payload: never) => void>
  >()
  const sent: { action: string; data: unknown; requestId?: string }[] = []
  const releases: (() => void)[] = []
  let state: ConnectionState = 'connected'
  let released = false
  let releasing: Promise<void> | undefined
  let ownsReconsent = false

  function emit<E extends keyof HilosConnectionEventMap>(
    event: E,
    payload: HilosConnectionEventMap[E],
  ): void {
    const errors: unknown[] = []
    for (const listener of listeners.get(event) ?? []) {
      try {
        listener(payload as never)
      } catch (error) {
        errors.push(error)
      }
    }
    if (errors.length) throw new AggregateError(errors)
  }

  const source = {
    get state() {
      return state
    },
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
  } satisfies Pick<HilosConnection, 'state' | 'on' | 'sendAction'>

  function release(): Promise<void> {
    if (releasing) return releasing
    released = true
    state = 'disconnected'
    releasing = (async () => {
      const errors: unknown[] = []
      function attempt(cleanup: () => void): void {
        try {
          cleanup()
        } catch (error) {
          errors.push(error)
        }
      }
      // Reject pending work and stop its timers before detaching the readers.
      attempt(() => emit('state', state))
      for (const unbind of releases.splice(0).reverse()) attempt(unbind)
      try {
        // Session scope and ActionLifecycle have no separate disposal receipt.
        listeners.clear()
        await Promise.resolve()
      } finally {
        if (ownsReconsent) {
          // A closed window's frozen content is not reset by close alone.
          attempt(() => hilosLegalReconsent.reset())
          attempt(() => hilosToasts.clear())
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
    releases.push(bindSignOut(scopes, actions))
    releases.push(bindLegalReconsent(scopes, actions))
    ownsReconsent = true
    return {
      sent,
      handshake(payload: ReturnType<typeof layoutReconsentHandshake>): void {
        if (released) return
        // Source injection only; transport parsing belongs to connection tests.
        emit('projectSignal', {
          kind: 'project',
          type: SIGNAL_HANDSHAKE_RESPONSE,
          data: payload,
          envelope: { type: SIGNAL_HANDSHAKE_RESPONSE, data: payload },
        })
      },
      succeed(
        requestId: string | undefined,
        action: string,
        reply?: HilosLegalReconsentContent,
      ): void {
        if (released) return
        emit('actionSuccess', {
          kind: 'actionSuccess',
          action,
          requestId,
          message: undefined,
          reply,
          envelope: {
            type: SIGNAL_TYPE_ACTION_SUCCESS,
            data: { action, ...(reply === undefined ? {} : { reply }) },
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
          requestId,
          reason,
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
    const errors = [error]
    try {
      await release()
    } catch (cleanupError) {
      errors.push(cleanupError)
    }
    if (errors.length > 1) {
      throw new AggregateError(errors, undefined, { cause: error })
    }
    throw error
  }
}

/** Fix only the reminder's date, restoring the entry offset before real time. */
export async function withLayoutReconsentClock(
  vi: typeof Vitest.vi,
  run: () => Promise<void>,
): Promise<void> {
  const entryOffset = offsetMs()
  try {
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
    vi.setSystemTime(Date.UTC(2026, 10, 1))
    applyServerTime(Date.now())
    await run()
  } finally {
    try {
      applyServerTime(Date.now() + entryOffset)
    } finally {
      vi.useRealTimers()
    }
  }
}
