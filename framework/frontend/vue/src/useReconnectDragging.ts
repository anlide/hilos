// Vue binding of the core connection's dragging-repair mark (HIL-831): whether the
// reconnect under way has been retrying long enough for its backoff pauses to have
// reached the ceiling, mirrored into Vue reactivity. The core owns the state; this
// composable only subscribes to it (multiframework-core.md).
import { onScopeDispose, shallowReadonly, shallowRef } from 'vue'
import type { Ref } from 'vue'
import type { HilosConnection } from '@hilos/core'

/**
 * Expose a connection's dragging-repair mark as a readonly reactive ref.
 *
 * Call it inside a component `setup()` or an `effectScope()`: the subscription
 * is released when the calling scope is disposed.
 *
 * @param connection The core connection to mirror.
 */
export function useReconnectDragging(
  connection: HilosConnection,
): Readonly<Ref<boolean>> {
  const dragging = shallowRef(connection.reconnectDragging)
  const unsubscribe = connection.on('reconnectDragging', (next) => {
    dragging.value = next
  })
  onScopeDispose(unsubscribe)
  return shallowReadonly(dragging)
}
