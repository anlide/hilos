// React binding of the core connection's dragging-repair mark (HIL-831): whether the
// reconnect under way has been retrying long enough for its backoff pauses to have
// reached the ceiling, delivered through useSyncExternalStore. The core owns the
// state; this hook only subscribes to it (multiframework-core.md).
import { useCallback, useSyncExternalStore } from 'react'
import type { HilosConnection } from '@hilos/core'

/**
 * Expose a connection's dragging-repair mark as React state.
 *
 * The subscription follows the component lifecycle: React subscribes on mount
 * and releases the subscription on unmount or when `connection` changes.
 *
 * @param connection The core connection to mirror.
 */
export function useReconnectDragging(connection: HilosConnection): boolean {
  const subscribe = useCallback(
    (onStoreChange: () => void) =>
      connection.on('reconnectDragging', () => {
        onStoreChange()
      }),
    [connection],
  )
  const getSnapshot = useCallback(
    () => connection.reconnectDragging,
    [connection],
  )
  return useSyncExternalStore(subscribe, getSnapshot)
}
