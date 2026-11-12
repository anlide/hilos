import {
  ActionLifecycle,
  applyServerTime,
  bindAccountStanding,
  bindAdminAccess,
  bindSessionScope,
  bindSignOut,
  ScopeManager,
  SIGNAL_HANDSHAKE_RESPONSE,
  type HilosConnection,
  type HilosConnectionEventMap,
  type ProjectSignal,
} from '@hilos/core/base'

/** Raw session identity and node mode, as carried by a handshake response. */
export function layoutAdminGreeting(
  user: { id: number; admin: boolean } | null,
  viewMode: boolean,
): Record<string, unknown> {
  return {
    entities: {
      currentUser: user === null ? null : { ...user, name: 'Olena' },
    },
    data: { adminViewMode: viewMode },
  }
}

/** Bind the real access reader; retain only raw publication and release handles. */
export function layoutAdminSession() {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const source = {
    on(event, listener) {
      if (event !== 'projectSignal') return () => {}
      const notify = (signal: ProjectSignal): void => listener(signal as never)
      listeners.add(notify)
      return () => {
        listeners.delete(notify)
      }
    },
  } satisfies Pick<HilosConnection, 'on'>
  let unbind: (() => void) | undefined
  function release(): void {
    try {
      unbind?.()
    } finally {
      unbind = undefined
      // bindSessionScope returns void, so the raw emitter owns its subscription.
      listeners.clear()
    }
  }
  try {
    const scopes = new ScopeManager()
    bindSessionScope(source as HilosConnection, scopes)
    unbind = bindAdminAccess(scopes)
    return {
      handshake(payload: Record<string, unknown>): void {
        const signal = {
          kind: 'project',
          type: SIGNAL_HANDSHAKE_RESPONSE,
          data: payload,
          envelope: {},
        } as ProjectSignal
        for (const listener of listeners) listener(signal)
      },
      release,
    }
  } catch (error) {
    release()
    throw error
  }
}

export const VIEW_MODE_NOW = 1_800_000_000_000

/** Bob's raw identity, node mode and standing, without a takeover input. */
export function layoutViewerGreeting(
  admin: boolean,
  viewMode: boolean,
  deletionEffectiveAt: number | null = null,
): Record<string, unknown> {
  return {
    data: {
      serverTimeMs: VIEW_MODE_NOW,
      adminViewMode: viewMode,
      accountStanding: {
        shown: deletionEffectiveAt === null ? 'none' : 'deletion_scheduled',
        blocked: false,
        frozen: false,
        deletionEffectiveAt,
        deletionGraceDays: null,
        lapsed: [],
        window: [],
        mergedInto: null,
        mergedIntoName: null,
      },
    },
    entities: { currentUser: { id: 2, name: 'Bob', admin } },
  }
}

/** Bind only the session readers used by the viewer strip and its neighbors. */
export function layoutViewModeSession() {
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
      // A dropped source settles pending actions and clears their timers.
      emit('state', 'disconnected')
    } finally {
      try {
        for (const unbind of releases.splice(0).reverse()) unbind()
      } finally {
        // The session binder has no release handle; its source owns the listener.
        listeners.clear()
        applyServerTime(Date.now())
      }
    }
  }
  try {
    const scopes = new ScopeManager()
    bindSessionScope(source as unknown as HilosConnection, scopes)
    const actions = new ActionLifecycle(source)
    releases.push(bindAdminAccess(scopes))
    releases.push(bindAccountStanding(scopes, actions))
    releases.push(bindSignOut(scopes, actions))
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
