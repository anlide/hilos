// Vue binding of the core Connection machine (core-and-connection.md): the
// transport state mirrored into Vue reactivity. The core owns the machine;
// this composable only subscribes to it (multiframework-core.md).
import { readonly, shallowRef, toValue, watch } from 'vue'
import type { MaybeRefOrGetter, Ref } from 'vue'
import type { ConnectionState, HilosConnection } from '@hilos/core/base'

/**
 * Expose a connection's machine state as a readonly reactive ref.
 *
 * Call it inside a component `setup()` or an `effectScope()`: the state
 * subscription is released when the calling scope is disposed.
 * The subscription moves when the source connection changes.
 *
 * @param connection The core connection object, ref, or getter to mirror.
 */
export function useConnectionState(
  connection: MaybeRefOrGetter<HilosConnection>,
): Readonly<Ref<ConnectionState>> {
  const state = shallowRef(toValue(connection).state)
  watch(
    () => toValue(connection),
    (source, _previous, onCleanup) => {
      state.value = source.state
      onCleanup(
        source.on('state', (next) => {
          state.value = next
        }),
      )
    },
    { immediate: true },
  )
  return readonly(state)
}
