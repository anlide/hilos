// React bridge of the core signal primitive: a core ReadonlySignal delivered
// through useSyncExternalStore via the neutral subscribe seam. The adapter
// never shares a reactivity engine with the core — the mirror is what keeps
// a duplicated engine copy harmless (multiframework-core.md).
import { useCallback, useSyncExternalStore } from 'react'
import { subscribeSignal } from '@hilos/core'
import type { ReadonlySignal } from '@hilos/core'

/**
 * Mirror a core signal into React state.
 *
 * The subscription follows the component lifecycle: React subscribes on
 * mount and releases the subscription on unmount or when `source` changes.
 *
 * @param source The core signal to mirror.
 */
export function useSignal<T>(source: ReadonlySignal<T>): T {
  const subscribe = useCallback(
    (onStoreChange: () => void) =>
      subscribeSignal(source, () => {
        onStoreChange()
      }),
    [source],
  )
  const getSnapshot = useCallback(() => source.get(), [source])
  return useSyncExternalStore(subscribe, getSnapshot)
}
