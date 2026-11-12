import {
  ActionLifecycle,
  bindImpersonation,
  bindSessionScope,
  bindSignOut,
  ScopeManager,
  SIGNAL_HANDSHAKE_RESPONSE,
  SIGNAL_TYPE_ACTION_ERROR,
  type HilosConnection,
  type HilosConnectionEventMap,
} from '@hilos/core/base'
import { SIGNAL_TYPE_ACTION_SUCCESS } from '../core/src/protocol/constants.js'

/** Raw session publications and replies for Layout's two session controls. */
export async function layoutSessionControlsSession() {
  const listeners = new Map<
    keyof HilosConnectionEventMap,
    Set<(payload: never) => void>
  >()
  const sent: { action: string; data: unknown; requestId?: string }[] = []
  const releases: (() => void)[] = []
  let released = false
  let releasing: Promise<void> | undefined

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
        // Settle pending actions and their timers before detaching the source.
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
        // bindSessionScope and ActionLifecycle expose no dispose handle.
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
    // Both controls use this lifecycle, so their request ids cannot collide.
    const actions = new ActionLifecycle(source)
    releases.push(bindSignOut(scopes, actions))
    releases.push(bindImpersonation(scopes, actions))
    return {
      sent,
      // Typed source events, not a transport parser or a WebSocket welcome.
      handshake(payload: Record<string, unknown>): void {
        if (released) return
        emit('projectSignal', {
          kind: 'project',
          type: SIGNAL_HANDSHAKE_RESPONSE,
          data: payload,
          envelope: { type: SIGNAL_HANDSHAKE_RESPONSE, data: payload },
        })
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
