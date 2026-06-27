// Angular binding of the core Connection machine (core-and-connection.md):
// the transport state bridged into an Angular signal. The core owns the
// machine; this function only subscribes to it (multiframework-core.md).
import {
  DestroyRef,
  Injector,
  assertInInjectionContext,
  inject,
  signal,
} from '@angular/core'
import type { Signal } from '@angular/core'
import type { ConnectionState, HilosConnection } from '@hilos/core'

/** Options for {@link connectionStateSignal}. */
export interface ConnectionStateSignalOptions {
  /**
   * Injector whose DestroyRef scopes the state subscription. When omitted,
   * the call must happen in an injection context (e.g. a component field
   * initializer), and that context's injector is used.
   */
  injector?: Injector
}

/**
 * Expose a connection's machine state as a readonly Angular signal.
 *
 * The state subscription is released when the owning injector is destroyed,
 * mirroring `toSignal` semantics.
 *
 * @param connection The core connection to mirror.
 * @param options See {@link ConnectionStateSignalOptions}.
 */
export function connectionStateSignal(
  connection: HilosConnection,
  options?: ConnectionStateSignalOptions,
): Signal<ConnectionState> {
  if (options?.injector === undefined) {
    assertInInjectionContext(connectionStateSignal)
  }
  const injector = options?.injector ?? inject(Injector)
  const state = signal(connection.state)
  const unsubscribe = connection.on('state', (next) => {
    state.set(next)
  })
  injector.get(DestroyRef).onDestroy(unsubscribe)
  return state.asReadonly()
}
