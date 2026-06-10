// Vue binding of the core Connection machine (core-and-connection.md): the
// transport state mirrored into Vue reactivity. The core owns the machine;
// this composable only subscribes to it (multiframework-core.md).
import { onScopeDispose, readonly, shallowRef } from 'vue'
import type { Ref } from 'vue'
import type { ConnectionState, HilosConnection } from '@hilos/core'

/**
 * Expose a connection's machine state as a readonly reactive ref.
 *
 * Call it inside a component `setup()` or an `effectScope()`: the state
 * subscription is released when the calling scope is disposed.
 *
 * @param connection The core connection to mirror.
 */
export function useConnectionState(
  connection: HilosConnection,
): Readonly<Ref<ConnectionState>> {
  const state = shallowRef(connection.state)
  const unsubscribe = connection.on('state', (next) => {
    state.value = next
  })
  onScopeDispose(unsubscribe)
  return readonly(state)
}
