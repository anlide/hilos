// Angular binding of the core entity resolver (data-model.md): the
// per-framework wrapper over the plain-function core selector. Data lives
// once in the core stores; references are resolved here, reactively.
import { Injector, assertInInjectionContext, inject } from '@angular/core'
import type { Signal } from '@angular/core'
import type { EntityRef, EntitySnapshot, ScopeManager } from '@hilos/core'
import { hilosSignal, type HilosSignalOptions } from './hilosSignal.js'

/**
 * Resolve an entity reference most-specific-first as a readonly Angular
 * signal.
 *
 * The reference is fixed at call time; resolve a changing reference by
 * recreating the binding.
 *
 * @param manager The scope manager owning the stores.
 * @param ref The entity reference to resolve.
 * @param options See {@link HilosSignalOptions}.
 */
export function entitySignal(
  manager: ScopeManager,
  ref: EntityRef,
  options?: HilosSignalOptions,
): Signal<EntitySnapshot | undefined> {
  if (options?.injector === undefined) {
    assertInInjectionContext(entitySignal)
  }
  const injector = options?.injector ?? inject(Injector)

  return hilosSignal(manager.entitySignal(ref), { injector })
}
