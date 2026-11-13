// Vue binding of the core connection's first-frame hold (core-and-connection.md):
// whether the shell should still draw nothing, mirrored into Vue reactivity. The
// core owns the decision — a browser hint and the welcome that answers it; this
// composable only subscribes to it (multiframework-core.md).
import { readonly, shallowRef, toValue, watch } from 'vue'
import type { MaybeRefOrGetter, Ref } from 'vue'
import type { HilosConnection } from '@hilos/core/base'

/**
 * Expose a connection's first-frame hold as a readonly reactive ref.
 *
 * True only while the shell has to wait to be told what to draw, and only on a
 * browser that has met maintenance on this node before. It never says the node
 * is frozen — that is `useProtectedMode`, and it is only worth reading once this
 * one is false.
 *
 * Call it inside a component `setup()` or an `effectScope()`: the subscription
 * is released when the calling scope is disposed.
 * The subscription moves when the source connection changes.
 *
 * @param connection The core connection object, ref, or getter to mirror.
 */
export function useFirstFrameHold(
  connection: MaybeRefOrGetter<HilosConnection>,
): Readonly<Ref<boolean>> {
  const held = shallowRef(toValue(connection).firstFrameHeld)
  watch(
    () => toValue(connection),
    (source, _previous, onCleanup) => {
      held.value = source.firstFrameHeld
      onCleanup(
        source.on('firstFrameHold', (next) => {
          held.value = next
        }),
      )
    },
    { immediate: true },
  )
  return readonly(held)
}
