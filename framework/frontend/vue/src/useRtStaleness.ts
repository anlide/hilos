// Vue binding of the core connection's frozen-replica state
// (core-and-connection.md): whether anything the open page reads is a copy whose
// source node this one cannot reach, mirrored into Vue reactivity. The core owns
// the state; this composable only subscribes to it (multiframework-core.md).
import { onScopeDispose, shallowReadonly, shallowRef } from 'vue'
import type { Ref } from 'vue'
import type { HilosConnection, RtStalenessStatus } from '@hilos/core'

/**
 * Expose a connection's frozen-replica state as a readonly reactive ref.
 *
 * Call it inside a component `setup()` or an `effectScope()`: the subscription
 * is released when the calling scope is disposed.
 *
 * @param connection The core connection to mirror.
 */
export function useRtStaleness(
  connection: HilosConnection,
): Readonly<Ref<RtStalenessStatus>> {
  const status = shallowRef(connection.rtStaleness)
  const unsubscribe = connection.on('rtStaleness', (next) => {
    status.value = next
  })
  onScopeDispose(unsubscribe)
  return shallowReadonly(status)
}
