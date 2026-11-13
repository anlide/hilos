// Vue binding of the core connection's frozen-replica state
// (core-and-connection.md): whether anything the open page reads is a copy whose
// source node this one cannot reach, mirrored into Vue reactivity. The core owns
// the state; this composable only subscribes to it (multiframework-core.md).
import { shallowReadonly, shallowRef, toValue, watch } from 'vue'
import type { MaybeRefOrGetter, Ref } from 'vue'
import type { HilosConnection, RtStalenessStatus } from '@hilos/core/base'

/**
 * Expose a connection's frozen-replica state as a readonly reactive ref.
 *
 * Call it inside a component `setup()` or an `effectScope()`: the subscription
 * is released when the calling scope is disposed.
 * The subscription moves when the source connection changes.
 *
 * @param connection The core connection object, ref, or getter to mirror.
 */
export function useRtStaleness(
  connection: MaybeRefOrGetter<HilosConnection>,
): Readonly<Ref<RtStalenessStatus>> {
  const status = shallowRef(toValue(connection).rtStaleness)
  watch(
    () => toValue(connection),
    (source, _previous, onCleanup) => {
      status.value = source.rtStaleness
      onCleanup(
        source.on('rtStaleness', (next) => {
          status.value = next
        }),
      )
    },
    { immediate: true },
  )
  return shallowReadonly(status)
}
