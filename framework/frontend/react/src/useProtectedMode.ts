// React binding of the core connection's protected-mode state
// (core-and-connection.md): the freeze the backend announces on the welcome
// frame and on the pushed `protected_mode` frame, delivered through
// useSyncExternalStore. The core owns the state; this hook only subscribes to
// it (multiframework-core.md).
import { useCallback, useSyncExternalStore } from 'react'
import type { HilosConnection, ProtectedModeStatus } from '@hilos/core'

/**
 * Expose a connection's protected-mode state as React state.
 *
 * The subscription follows the component lifecycle: React subscribes on mount
 * and releases the subscription on unmount or when `connection` changes.
 *
 * @param connection The core connection to mirror.
 */
export function useProtectedMode(
  connection: HilosConnection,
): ProtectedModeStatus {
  const subscribe = useCallback(
    (onStoreChange: () => void) =>
      connection.on('protectedMode', () => {
        onStoreChange()
      }),
    [connection],
  )
  const getSnapshot = useCallback(() => connection.protectedMode, [connection])
  return useSyncExternalStore(subscribe, getSnapshot)
}
