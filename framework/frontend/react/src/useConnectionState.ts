// React binding of the core Connection machine (core-and-connection.md): the
// transport state delivered through useSyncExternalStore. The core owns the
// machine; this hook only subscribes to it (multiframework-core.md).
import { useCallback, useSyncExternalStore } from 'react'
import type { ConnectionState, HilosConnection } from '@hilos/core'

/**
 * Expose a connection's machine state as React state.
 *
 * The subscription follows the component lifecycle: React subscribes on mount
 * and releases the subscription on unmount or when `connection` changes.
 *
 * @param connection The core connection to mirror.
 */
export function useConnectionState(
  connection: HilosConnection,
): ConnectionState {
  const subscribe = useCallback(
    (onStoreChange: () => void) =>
      connection.on('state', () => {
        onStoreChange()
      }),
    [connection],
  )
  const getSnapshot = useCallback(() => connection.state, [connection])
  return useSyncExternalStore(subscribe, getSnapshot)
}
