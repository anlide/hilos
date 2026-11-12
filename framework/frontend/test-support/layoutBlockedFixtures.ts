import {
  ACCOUNT_BLOCKED_ACTION_DISMISS,
  ActionLifecycle,
  bindAccessLoss,
  bindAccountBlocked,
  bindSessionScope,
  bindSignOut,
  ScopeManager,
  SIGNAL_HANDSHAKE_RESPONSE,
  type ConnectionState,
  type HilosConnection,
  type HilosConnectionEventMap,
  type HilosRouter,
} from '@hilos/core/base'
import { SIGNAL_TYPE_ACTION_SUCCESS } from '../core/src/protocol/constants.js'
import { layoutChromeRouter } from './layoutChromeFixtures.js'

/** Raw cold refusal, before the real session scope ingests it. */
export const BLOCKED_GREETING = {
  entities: { currentUser: null },
  data: { accountBlocked: { identifier: 'maria@example.com' } },
}

/** Own the card's raw source, identity, document latch and action lifecycle. */
export async function layoutBlockedSession(
  router: HilosRouter = layoutChromeRouter().router,
) {
  const listeners = new Map<
    keyof HilosConnectionEventMap,
    Set<(payload: never) => void>
  >()
  const sent: { action: string; data: unknown; requestId?: string }[] = []
  const releases: (() => void)[] = []
  let state: ConnectionState = 'connected'
  let released = false
  let releasing: Promise<void> | undefined

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
      try {
        // Reject pending actions while their readers are still attached.
        emit('state', state)
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
        // Session scope and ActionLifecycle have no separate dispose handles.
        listeners.clear()
        await Promise.resolve()
      }
      if (errors.length) throw new AggregateError(errors)
    })()
    return releasing
  }

  try {
    const scopes = new ScopeManager()
    bindSessionScope(source as unknown as HilosConnection, scopes)
    const actions = new ActionLifecycle(source)
    releases.push(bindSignOut(scopes, actions))
    releases.push(bindAccessLoss(scopes, router))
    releases.push(bindAccountBlocked(scopes, actions, source))
    return {
      sent,
      // Typed source events, without transport parsing or inferred state.
      handshake(payload: Record<string, unknown>): void {
        if (released) return
        emit('projectSignal', {
          kind: 'project',
          type: SIGNAL_HANDSHAKE_RESPONSE,
          data: payload,
          envelope: { type: SIGNAL_HANDSHAKE_RESPONSE, data: payload },
        })
      },
      succeed(requestId: string | undefined): void {
        if (released) return
        emit('actionSuccess', {
          kind: 'actionSuccess',
          action: ACCOUNT_BLOCKED_ACTION_DISMISS,
          requestId,
          message: undefined,
          reply: undefined,
          envelope: {
            type: SIGNAL_TYPE_ACTION_SUCCESS,
            data: { action: ACCOUNT_BLOCKED_ACTION_DISMISS },
            requestId,
          },
        })
      },
      release,
    }
  } catch (error) {
    try {
      await release()
    } catch (releaseError) {
      throw new AggregateError([error, releaseError], undefined, {
        cause: releaseError,
      })
    }
    throw error
  }
}
