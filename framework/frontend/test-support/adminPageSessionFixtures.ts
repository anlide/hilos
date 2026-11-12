// Controlled session frames enter the same bindings as application bootstrap.
import {
  ScopeManager,
  SIGNAL_HANDSHAKE_RESPONSE,
  bindAdminAccess,
  bindSessionScope,
  type HilosConnection,
  type ProjectSignal,
} from '@hilos/core/base'

/** Bind actual core session/access state without opening a socket. */
export function adminPageSession() {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const connection = {
    on(event: string, listener: (payload: never) => void): () => void {
      const projectListener = listener as (signal: ProjectSignal) => void
      if (event === 'projectSignal') listeners.add(projectListener)
      return () => listeners.delete(projectListener)
    },
  } as unknown as HilosConnection
  const scopes = new ScopeManager()
  bindSessionScope(connection, scopes)
  const releaseAccess = bindAdminAccess(scopes)

  return {
    /**
     * Publish one backend-authored identity and node mode through the bindings.
     *
     * @param user The current person, or null for a guest.
     * @param viewMode The node's admin view mode.
     */
    handshake(
      user: { id: number; admin: boolean } | null,
      viewMode: boolean,
    ): void {
      const signal = {
        kind: 'project',
        type: SIGNAL_HANDSHAKE_RESPONSE,
        data: {
          entities: {
            currentUser: user === null ? null : { ...user, name: 'Olena' },
          },
          data: { adminViewMode: viewMode },
        },
        envelope: {},
      } as unknown as ProjectSignal
      for (const listener of listeners) listener(signal)
    },
    /** Release after the native view: unbinding changes the global access. */
    dispose(): void {
      try {
        releaseAccess()
      } finally {
        listeners.clear()
      }
    },
  }
}
