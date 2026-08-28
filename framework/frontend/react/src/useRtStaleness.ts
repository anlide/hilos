// React binding of the core connection's frozen-replica state
// (core-and-connection.md): whether anything the open page reads is a copy whose
// source node this one cannot reach, delivered through useSyncExternalStore. The
// core owns the state; this hook only subscribes to it (multiframework-core.md).
import { useCallback, useSyncExternalStore } from 'react'
import type { HilosConnection, RtStalenessStatus } from '@hilos/core'

/**
 * Expose a connection's frozen-replica state as React state.
 *
 * The subscription follows the component lifecycle: React subscribes on mount
 * and releases the subscription on unmount or when `connection` changes.
 *
 * @param connection The core connection to mirror.
 */
export function useRtStaleness(connection: HilosConnection): RtStalenessStatus {
  const subscribe = useCallback(
    (onStoreChange: () => void) =>
      connection.on('rtStaleness', () => {
        onStoreChange()
      }),
    [connection],
  )
  const getSnapshot = useCallback(() => connection.rtStaleness, [connection])
  return useSyncExternalStore(subscribe, getSnapshot)
}
