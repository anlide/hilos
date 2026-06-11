// Angular bridge of the core signal primitive: a core ReadonlySignal
// mirrored into an Angular signal through the neutral subscribe seam. The
// adapter never shares a reactivity engine with the core — the mirror is
// what keeps a duplicated engine copy harmless (multiframework-core.md).
import {
  DestroyRef,
  Injector,
  assertInInjectionContext,
  inject,
  signal,
} from '@angular/core'
import type { Signal } from '@angular/core'
import { subscribeSignal } from '@hilos/core'
import type { ReadonlySignal } from '@hilos/core'

/** Options for {@link hilosSignal} and the selector wrappers built on it. */
export interface HilosSignalOptions {
  /**
   * Injector whose DestroyRef scopes the subscription. When omitted, the
   * call must happen in an injection context (e.g. a component field
   * initializer), and that context's injector is used.
   */
  injector?: Injector
}

/**
 * Mirror a core signal into a readonly Angular signal.
 *
 * The subscription is released when the owning injector is destroyed,
 * mirroring `toSignal` semantics.
 *
 * @param source The core signal to mirror.
 * @param options See {@link HilosSignalOptions}.
 */
export function hilosSignal<T>(
  source: ReadonlySignal<T>,
  options?: HilosSignalOptions,
): Signal<T> {
  if (options?.injector === undefined) {
    assertInInjectionContext(hilosSignal)
  }
  const injector = options?.injector ?? inject(Injector)
  const value = signal(source.get())
  const unsubscribe = subscribeSignal(source, (next) => {
    value.set(next)
  })
  injector.get(DestroyRef).onDestroy(unsubscribe)

  return value.asReadonly()
}
