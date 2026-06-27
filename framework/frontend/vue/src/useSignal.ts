// Vue bridge of the core signal primitive: a core ReadonlySignal mirrored
// into Vue reactivity through the neutral subscribe seam. The adapter never
// shares a reactivity engine with the core — the mirror is what keeps a
// duplicated engine copy harmless (multiframework-core.md).
import { onScopeDispose, shallowReadonly, shallowRef } from 'vue'
import type { Ref } from 'vue'
import { subscribeSignal } from '@hilos/core'
import type { ReadonlySignal } from '@hilos/core'

/**
 * Mirror a core signal into a readonly reactive ref.
 *
 * Call it inside a component `setup()` or an `effectScope()`: the
 * subscription is released when the calling scope is disposed.
 *
 * @param source The core signal to mirror.
 */
export function useSignal<T>(source: ReadonlySignal<T>): Readonly<Ref<T>> {
  const value = shallowRef(source.get())
  const unsubscribe = subscribeSignal(source, (next) => {
    value.value = next
  })
  onScopeDispose(unsubscribe)
  return shallowReadonly(value)
}
