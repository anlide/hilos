// Vue binding of the core connection's protected-mode state
// (core-and-connection.md): the freeze the backend announces on the welcome
// frame and on the pushed `protected_mode` frame, mirrored into Vue reactivity.
// The core owns the state; this composable only subscribes to it
// (multiframework-core.md).
import { onScopeDispose, shallowReadonly, shallowRef } from 'vue'
import type { Ref } from 'vue'
import type { HilosConnection, ProtectedModeStatus } from '@hilos/core'

/**
 * Expose a connection's protected-mode state as a readonly reactive ref.
 *
 * Call it inside a component `setup()` or an `effectScope()`: the subscription
 * is released when the calling scope is disposed.
 *
 * @param connection The core connection to mirror.
 */
export function useProtectedMode(
  connection: HilosConnection,
): Readonly<Ref<ProtectedModeStatus>> {
  const status = shallowRef(connection.protectedMode)
  const unsubscribe = connection.on('protectedMode', (next) => {
    status.value = next
  })
  onScopeDispose(unsubscribe)
  return shallowReadonly(status)
}
