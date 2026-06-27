// Vue binding of the core entity resolver (data-model.md): the per-framework
// wrapper over the plain-function core selector. Data lives once in the core
// stores; references are resolved here, reactively.
import type { Ref } from 'vue'
import type { EntityRef, EntitySnapshot, ScopeManager } from '@hilos/core'
import { useSignal } from './useSignal.js'

/**
 * Resolve an entity reference most-specific-first as a readonly reactive ref.
 *
 * Call it inside a component `setup()` or an `effectScope()`. The reference
 * is fixed at call time; resolve a changing reference by recreating the
 * binding.
 *
 * @param manager The scope manager owning the stores.
 * @param ref The entity reference to resolve.
 */
export function useEntity(
  manager: ScopeManager,
  ref: EntityRef,
): Readonly<Ref<EntitySnapshot | undefined>> {
  return useSignal(manager.entitySignal(ref))
}
