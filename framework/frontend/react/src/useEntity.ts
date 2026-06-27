// React binding of the core entity resolver (data-model.md): the
// per-framework wrapper over the plain-function core selector. Data lives
// once in the core stores; references are resolved here, reactively.
import { useMemo } from 'react'
import type { EntityRef, EntitySnapshot, ScopeManager } from '@hilos/core'
import { useSignal } from './useSignal.js'

/**
 * Resolve an entity reference most-specific-first as React state.
 *
 * The resolution is keyed by the reference's value (type and id), so an
 * inline object literal re-created on every render keeps one stable
 * subscription.
 *
 * @param manager The scope manager owning the stores.
 * @param ref The entity reference to resolve.
 */
export function useEntity(
  manager: ScopeManager,
  ref: EntityRef,
): EntitySnapshot | undefined {
  const entity = useMemo(
    () => manager.entitySignal({ type: ref.type, id: ref.id }),
    [manager, ref.type, ref.id],
  )

  return useSignal(entity)
}
