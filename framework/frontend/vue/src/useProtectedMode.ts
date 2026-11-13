// Vue binding of the core connection's protected-mode state
// (core-and-connection.md): the freeze the backend announces on the welcome
// frame and on the pushed `protected_mode` frame, mirrored into Vue reactivity.
// The core owns the state; this composable only subscribes to it
// (multiframework-core.md).
import { shallowReadonly, shallowRef, toValue, watch } from 'vue'
import type { MaybeRefOrGetter, Ref } from 'vue'
import type { HilosConnection, ProtectedModeStatus } from '@hilos/core/base'

/**
 * Expose a connection's protected-mode state as a readonly reactive ref.
 *
 * Call it inside a component `setup()` or an `effectScope()`: the subscription
 * is released when the calling scope is disposed.
 * The subscription moves when the source connection changes.
 *
 * @param connection The core connection object, ref, or getter to mirror.
 */
export function useProtectedMode(
  connection: MaybeRefOrGetter<HilosConnection>,
): Readonly<Ref<ProtectedModeStatus>> {
  const status = shallowRef(toValue(connection).protectedMode)
  watch(
    () => toValue(connection),
    (source, _previous, onCleanup) => {
      status.value = source.protectedMode
      onCleanup(
        source.on('protectedMode', (next) => {
          status.value = next
        }),
      )
    },
    { immediate: true },
  )
  return shallowReadonly(status)
}
