// An entity collection: the typed access point for ONE entity type, sitting
// over the scope manager's reference resolution. Where `EntityStore` keys raw
// snapshots by (entityType, id) and `ScopeManager.entitySignal` resolves a raw
// snapshot most-specific-first, a collection binds a single `type` plus its
// `project` read so callers get a typed entity instead of an untyped snapshot.
// It is the ONE place a project's `fields: unknown` is coerced to a domain type
// — selectors and views read the typed result, never the raw fields.
//
// A collection is NOT a list: it has no order and no membership ("all bots" is a
// list or table). It is the type's reference-resolution facade — `Bots.signal(id)`
// reads the one bot wherever a list, table, or page-data slot referenced it.

import { computedSignal, type ReadonlySignal } from './signal.js'
import { type EntityId, type EntityRef } from './EntityStore.js'
import { type Entity } from './entity.js'
import { type ScopeManager } from './ScopeManager.js'

/** The typed access point for one entity type. */
export interface EntityCollection<E extends Entity> {
  /** The canonical entity type this collection resolves. */
  readonly type: string

  /**
   * A reference to one entity of this type.
   *
   * @param id The entity's stable id.
   */
  ref(id: EntityId): EntityRef

  /**
   * The entity, typed and resolved reactively in the current scope stack:
   * `undefined` until it streams, then every committed change.
   *
   * @param target The entity's id, or a full reference to it.
   */
  signal(target: EntityId | EntityRef): ReadonlySignal<E | undefined>
}

/**
 * Create a typed collection for one entity type: it builds references, and
 * resolves them through the scope manager into the typed entity via `project` —
 * the single coercion point from raw `fields` to a domain entity.
 *
 * @param scopes The scope manager that resolves references most-specific-first.
 * @param type The canonical entity type the collection owns.
 * @param project Map a raw committed `fields` record to the typed entity.
 */
export function entityCollection<E extends Entity>(
  scopes: ScopeManager,
  type: string,
  project: (fields: Readonly<Record<string, unknown>>) => E,
): EntityCollection<E> {
  const ref = (id: EntityId): EntityRef => ({ type, id })

  return {
    type,
    ref,
    signal(target) {
      const entityRef = typeof target === 'object' ? target : ref(target)

      return computedSignal(() => {
        const snapshot = scopes.entitySignal(entityRef).get()

        return snapshot ? project(snapshot.fields) : undefined
      })
    },
  }
}
