// Vue binding of the core connection's dragging-repair mark (HIL-831): whether the
// reconnect under way has been retrying long enough for its backoff pauses to have
// reached the ceiling, mirrored into Vue reactivity. The core owns the state; this
// composable only subscribes to it (multiframework-core.md).
import { shallowReadonly, shallowRef, toValue, watch } from 'vue'
import type { MaybeRefOrGetter, Ref } from 'vue'
import type { HilosConnection } from '@hilos/core/base'

/**
 * Expose a connection's dragging-repair mark as a readonly reactive ref.
 *
 * Call it inside a component `setup()` or an `effectScope()`: the subscription
 * is released when the calling scope is disposed.
 * The subscription moves when the source connection changes.
 *
 * @param connection The core connection object, ref, or getter to mirror.
 */
export function useReconnectDragging(
  connection: MaybeRefOrGetter<HilosConnection>,
): Readonly<Ref<boolean>> {
  const dragging = shallowRef(toValue(connection).reconnectDragging)
  watch(
    () => toValue(connection),
    (source, _previous, onCleanup) => {
      dragging.value = source.reconnectDragging
      onCleanup(
        source.on('reconnectDragging', (next) => {
          dragging.value = next
        }),
      )
    },
    { immediate: true },
  )
  return shallowReadonly(dragging)
}
