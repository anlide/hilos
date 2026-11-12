import {
  ActionLifecycle,
  bindImpersonation,
  bindSessionScope,
  bindSignOut,
  ScopeManager,
  SIGNAL_HANDSHAKE_RESPONSE,
  type HilosConnection,
  type HilosConnectionEventMap,
  type ProjectSignal,
} from '@hilos/core/base'

/** Exact Layout session frames, independent of ThemeMenu's account examples. */
export function layoutThemeSession() {
  const listeners = new Map<
    keyof HilosConnectionEventMap,
    Set<(payload: never) => void>
  >()
  function emit<E extends keyof HilosConnectionEventMap>(
    event: E,
    payload: HilosConnectionEventMap[E],
  ): void {
    for (const listener of listeners.get(event) ?? [])
      listener(payload as never)
  }
  const source = {
    sendAction: () => true,
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
  const releases: (() => void)[] = []
  function release(): void {
    try {
      emit('state', 'disconnected')
    } finally {
      try {
        for (const unbind of releases.splice(0).reverse()) unbind()
      } finally {
        // bindSessionScope has no release handle; its source owns the listener.
        listeners.clear()
      }
    }
  }
  try {
    const scopes = new ScopeManager()
    bindSessionScope(source as unknown as HilosConnection, scopes)
    const actions = new ActionLifecycle(source)
    releases.push(bindSignOut(scopes, actions))
    releases.push(bindImpersonation(scopes, actions))
    return {
      handshake(payload: Record<string, unknown>): void {
        emit('projectSignal', {
          kind: 'project',
          type: SIGNAL_HANDSHAKE_RESPONSE,
          data: payload,
          envelope: {},
        } as ProjectSignal)
      },
      release,
    }
  } catch (error) {
    release()
    throw error
  }
}
