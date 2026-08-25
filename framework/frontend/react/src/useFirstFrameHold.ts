// React binding of the core connection's first-frame hold
// (core-and-connection.md): whether the shell should still draw nothing,
// delivered through useSyncExternalStore. The core owns the decision — a browser
// hint and the welcome that answers it; this hook only subscribes to it
// (multiframework-core.md).
import { useCallback, useSyncExternalStore } from 'react'
import type { HilosConnection } from '@hilos/core'

/**
 * Expose a connection's first-frame hold as React state.
 *
 * True only while the shell has to wait to be told what to draw, and only on a
 * browser that has met maintenance on this node before. It never says the node
 * is frozen — that is `useProtectedMode`, and it is only worth reading once this
 * one is false.
 *
 * The subscription follows the component lifecycle: React subscribes on mount
 * and releases the subscription on unmount or when `connection` changes.
 *
 * @param connection The core connection to mirror.
 */
export function useFirstFrameHold(connection: HilosConnection): boolean {
  const subscribe = useCallback(
    (onStoreChange: () => void) =>
      connection.on('firstFrameHold', () => {
        onStoreChange()
      }),
    [connection],
  )
  const getSnapshot = useCallback(() => connection.firstFrameHeld, [connection])

  return useSyncExternalStore(subscribe, getSnapshot)
}
